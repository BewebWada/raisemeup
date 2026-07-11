<?php
require_once __DIR__ . '/ScheduleRepository.php';

class ClaudeClient
{
    private string $apiKey;
    private string $model;

    private const FALLBACK_REPLY = 'すみません、少し聞き取れませんでした。もう一度お願いできますか?';

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    private const COMPANION_NAME_FALLBACKS = [
        'male' => ['タロウ', 'ケンジ', 'ヒロシ'],
        'female' => ['ハナ', 'サクラ', 'ミドリ'],
        'random' => ['ヒカリ', 'ツバサ', 'ソラ'],
    ];

    // 申込フォームで選ばれた性別(male/female/random)をもとに、AIが自己紹介する名前を1つ決める。
    // 失敗時はランダムな固定名にフォールバックする(申込フロー自体は止めない)。
    public function generateCompanionName(string $gender): string
    {
        $genderInstruction = match ($gender) {
            'male' => '男性らしい名前にしてください。',
            'female' => '女性らしい名前にしてください。',
            default => '性別は自由に決めてかまいません。',
        };

        $systemPrompt = <<<PROMPT
これから高齢者向け会話サービスのAIコンパニオンとして、ある利用者専属の話し相手になります。
{$genderInstruction}
利用者が親しみを持てる、温かみのある日本語の名前(下の名前、または呼び名として自然なもの)を1つだけ考えてください。
出力は名前の文字列のみ。説明・記号・カギカッコは一切付けないこと。
PROMPT;

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => 30,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => '名前を教えてください。']],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $fallbackPool = self::COMPANION_NAME_FALLBACKS[$gender] ?? self::COMPANION_NAME_FALLBACKS['random'];

        if ($response === false || $httpCode !== 200) {
            error_log("Claude generateCompanionName failed: HTTP {$httpCode} - curl error: {$curlError}");
            return $fallbackPool[array_rand($fallbackPool)];
        }

        $data = json_decode($response, true);
        $text = trim((string) ($data['content'][0]['text'] ?? ''));
        // 「」『』はマルチバイト文字なので、trim()のcharlistに渡すとバイト単位で処理され
        // UTF-8が壊れることがある(例:「く」で始まる名前のE3バイトだけが誤って削られる)。
        // preg_replaceの'u'修飾子を使い、文字単位で安全に取り除く
        $name = trim((string) preg_replace('/\A[「『"\']+|[」』"\']+\z/u', '', $text));
        // 万一Claudeが長文を返した場合は名前らしくないので使わず、フォールバックする
        if ($name === '' || mb_strlen($name) > 20) {
            return $fallbackPool[array_rand($fallbackPool)];
        }
        return $name;
    }

    /**
     * @param array $conversationHistory [['role' => 'user'|'assistant', 'content' => string], ...]
     * @param string $userMessage 今回受信したメッセージ
     * @param array $knownPersons 既知の人物名リスト(重複抽出を防ぐためのヒント)
     * @param array $knownSchedules ScheduleRepository::getUpcomingDetailsByUserIdの戻り値。要約とは別に渡す正確な予定一覧
     *   (要約は圧縮されているため、個別の予定について聞かれたときに正確な日付で答えるにはこちらが必要)
     * @param array $summaries SummaryRepository::getAllForUserの戻り値([type => 要約文])。バッチで事前生成された長期記憶
     * @param string $companionName AIが自己紹介する名前(users.companion_name)
     * @param string $userDisplayName 利用者本人の呼び名(users.display_name)
     * @return array ['reply_text' => string, 'persons' => [...], 'schedules' => [...]]
     */
    public function generateReplyAndExtract(array $conversationHistory, string $userMessage, array $knownPersons, array $knownSchedules, array $summaries, string $companionName, string $userDisplayName): array
    {
        $systemPrompt = $this->buildSystemPrompt($knownPersons, $knownSchedules, $summaries, $companionName, $userDisplayName);

        $messages = $conversationHistory;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // 稀にClaudeの出力がJSONとしてパースできないことがある(前置き文が付く等)。
        // 同一入力でも再試行すると成功することを確認済みなので、失敗時は1回だけ取り直す
        // (フォールバック文言を毎回利用者に返すより、多少レイテンシが増えても正確な応答を優先する)。
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $parsed = $this->callAndParse($systemPrompt, $messages, $attempt);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        error_log('Claude API: 2回とも構造化出力のパースに失敗したためフォールバック応答を返します');
        return $this->fallback();
    }

    private function callAndParse(string $systemPrompt, array $messages, int $attempt): ?array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $messages,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("Claude API call failed (attempt {$attempt}): curl error - {$curlError}");
            return null;
        }
        if ($httpCode !== 200) {
            error_log("Claude API call failed (attempt {$attempt}): HTTP {$httpCode} - {$response}");
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['content'][0]['text'] ?? '';
        $stopReason = $data['stop_reason'] ?? 'unknown';

        $parsed = $this->extractJson($text);
        if ($parsed === null) {
            error_log("Claude API reply JSON parse failed (attempt {$attempt}, stop_reason={$stopReason}): " . substr($text, 0, 1500));
            return null;
        }

        return $parsed;
    }

    // Claudeが指示に反して前置き文やコードフェンスを付けてくる場合に備え、
    // 最初の"{"〜最後の"}"だけを取り出してからパースする(素のjson_decodeより許容範囲が広い)
    private function extractJson(string $text): ?array
    {
        $clean = trim(preg_replace('/```json|```/', '', $text));
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
        return is_array($parsed) ? $parsed : null;
    }

    private function fallback(): array
    {
        return ['reply_text' => self::FALLBACK_REPLY, 'persons' => [], 'schedules' => []];
    }

    private const LOOKUP_TYPE_LABELS = [
        'schedule' => '予定',
        'person' => '人物',
        'conversation' => '過去の会話',
    ];

    /**
     * generateReplyAndExtractが"needs_lookup"を返したときの2ターン目。
     * 検索結果を渡して返信文だけを生成する(persons/schedulesの抽出は1ターン目の結果をそのまま使うため、ここではやり直さない)。
     */
    public function answerWithLookup(array $conversationHistory, string $userMessage, string $lookupType, string $lookupResultsText, string $companionName): string
    {
        $typeLabel = self::LOOKUP_TYPE_LABELS[$lookupType] ?? $lookupType;

        $systemPrompt = <<<PROMPT
あなたの名前は「{$companionName}」です。高齢者向け会話サービスのAIコンパニオンとして会話しています。
利用者からの質問に対して、追加で検索した{$typeLabel}の情報をもとに、親しみやすく温かい口調で
2〜3文程度で回答してください。検索結果に該当する情報が無ければ、正直に分からない旨を伝えてください。
出力は返信本文のみにしてください。前置き・JSON・見出しは不要です。

【検索結果】
{$lookupResultsText}
PROMPT;

        $messages = $conversationHistory;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => 300,
                'system' => $systemPrompt,
                'messages' => $messages,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("Claude answerWithLookup failed: curl error - {$curlError}");
            return self::FALLBACK_REPLY;
        }
        if ($httpCode !== 200) {
            error_log("Claude answerWithLookup failed: HTTP {$httpCode} - {$response}");
            return self::FALLBACK_REPLY;
        }

        $data = json_decode($response, true);
        $text = trim($data['content'][0]['text'] ?? '');
        return $text !== '' ? $text : self::FALLBACK_REPLY;
    }

    private const SUMMARY_INSTRUCTIONS = [
        'schedule' => 'これは高齢の利用者の今後の予定一覧です。家族が一目で状況を把握できるよう、3〜5文程度の自然な日本語で要約してください。個々の予定を機械的に列挙するのではなく、直近に何があるか・頻度の高い予定など全体像がわかるようにしてください。この要約は生成された日と異なる日に読まれることがあるため、「明日」「来週」のような相対的な日付表現は使わず、一覧に記載されている日付(月日・曜日)をそのまま使ってください。',
        'relationship' => 'これは高齢の利用者が会話の中で言及した人物とその関係性の記録です。主要な人物とその関係性、関わりの深さが伝わるよう3〜5文程度の自然な日本語で要約してください。',
        'preference' => 'これは高齢の利用者との会話ログです。この方の好み(食べ物、趣味、話題にすると喜ぶこと等)が読み取れる部分があれば3〜5文程度の自然な日本語でまとめてください。読み取れる情報が無ければ「特に記録なし」とだけ答えてください。',
        'routine' => 'これは高齢の利用者との会話ログです。この方の日常のルーティン(毎日/毎週の習慣、通っている場所、決まった予定等)が読み取れる部分があれば3〜5文程度の自然な日本語でまとめてください。読み取れる情報が無ければ「特に記録なし」とだけ答えてください。',
    ];

    /**
     * 会話のリアルタイム応答とは別に、バッチ処理から呼び出す想定の要約生成。
     * $summaryType は SummaryRepository::TYPES のいずれか。失敗時は空文字を返す(呼び出し側で既存の要約を維持する)。
     */
    public function summarize(string $summaryType, string $sourceText): string
    {
        if (trim($sourceText) === '') {
            return '';
        }

        $instruction = self::SUMMARY_INSTRUCTIONS[$summaryType]
            ?? '以下の情報を3〜5文程度の自然な日本語で要約してください。';

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => 500,
                'system' => $instruction . "\n\n出力は要約本文のみにしてください。前置き・見出し・箇条書き記号は不要です。",
                'messages' => [['role' => 'user', 'content' => $sourceText]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("Claude summarize({$summaryType}) failed: curl error - {$curlError}");
            return '';
        }
        if ($httpCode !== 200) {
            error_log("Claude summarize({$summaryType}) failed: HTTP {$httpCode} - {$response}");
            return '';
        }

        $data = json_decode($response, true);
        return trim($data['content'][0]['text'] ?? '');
    }

    private function buildSystemPrompt(array $knownPersons, array $knownSchedules, array $summaries, string $companionName, string $userDisplayName): string
    {
        $knownPersonsList = empty($knownPersons) ? 'なし' : implode('、', $knownPersons);
        $knownSchedulesList = empty($knownSchedules)
            ? 'なし'
            : implode("\n", array_map([ScheduleRepository::class, 'formatScheduleLine'], $knownSchedules));

        $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $todayText = $now->format('Y年n月j日') . '(' . $weekdays[(int) $now->format('w')] . ')';

        $summaryLabels = [
            'schedule' => '予定',
            'relationship' => '人間関係',
            'preference' => '好み',
            'routine' => '日常のルーティン',
        ];
        $summaryBlock = '(まだ蓄積されていません)';
        $summaryLines = [];
        foreach ($summaryLabels as $type => $label) {
            if (!empty($summaries[$type])) {
                $summaryLines[] = "◆{$label}\n{$summaries[$type]}";
            }
        }
        if (!empty($summaryLines)) {
            $summaryBlock = implode("\n\n", $summaryLines);
        }

        $userLabel = $userDisplayName !== '' ? "{$userDisplayName}さん" : '利用者';

        return <<<PROMPT
あなたの名前は「{$companionName}」です。高齢者向け会話サービスのAIコンパニオンとして、{$userLabel}専属の話し相手を務めています。
{$userLabel}と自然な世間話・雑談をしながら、以下のルールに従ってください。
自分の名前を聞かれたら「{$companionName}」と答えてください。

今日の日付は{$todayText}です。

【この利用者についてこれまでに分かっていること(定期的に自動更新される要約。参考情報として会話に自然に活かすこと)】
{$summaryBlock}

【今後の予定の正確な一覧】
上の「予定」の要約は概要であり、個々の予定が漏れている可能性があります。
「〇〇はいつだっけ」のように具体的な予定を聞かれたときは、要約ではなく必ずこちらの正確な一覧を見て答えてください。
{$knownSchedulesList}

【会話のトーン】
- 親しみやすく、温かい口調で話す
- 高齢者にとって読みやすい、平易な言葉を使う
- 1回の返信は短め(2〜3文程度)にする

【出力形式】
必ず以下のJSON形式のみで出力してください。前置きやMarkdownのコードフェンスは不要です。

{
  "reply_text": "利用者への返信文",
  "persons": [
    {"name": "人物名(呼称)", "relation": "関係性(例:息子、友人)", "notes": "補足情報があれば"}
  ],
  "schedules": [
    {
      "title": "予定の内容",
      "date_text": "会話に出た日付表現をそのまま(例:来週の火曜日、3週間後、12日から13日)",
      "location": "場所があれば",
      "date_spec": {"unit": "day/week/month/absolute/null", "amount": 0, "weekday": null, "day_of_month": null, "month": null, "day": null, "year": null},
      "date_spec_end": null,
      "time_spec": {"hour": null, "minute": null}
    }
  ],
  "needs_lookup": null
}

【date_specの埋め方(重要:自分で日付を計算しないこと。実際の日付計算はシステム側で行います)】
- "unit": 表現の種類。"day"(明日/明後日/N日後)、"week"(今週/来週/再来週/N週間後、曜日を伴う表現)、
  "month"(今月/来月/再来月、日にちを伴う表現)、"absolute"(「8月15日」のように相対表現を伴わない具体的な月日)、
  null(曖昧で種類も判断できない場合)のいずれか
- "amount": 単位ごとの個数(unitが"day"/"week"/"month"のときだけ使う。"absolute"や null のときは0でよい)
  - unitが"day"のとき: 今日からの日数(今日=0, 明日=1, 明後日=2, 3日後=3 ...)
  - unitが"week"のとき: 今週を0とした週数(今週=0, 来週=1, 再来週=2, 3週間後=3 ...)
  - unitが"month"のとき: 今月を0とした月数(今月=0, 来月=1, 再来月=2 ...)
- "weekday": unitが"week"のときだけ使う。目的の曜日を0(日)〜6(土)の数字で指定。曜日が明言されていなければnull
  (例:「3週間後」のように曜日を伴わない場合はnullでよい。システム側で今日と同じ曜日として扱う)
- "day_of_month": unitが"month"のときだけ使う。目的の日にち(1〜31)。日にちが明言されていなければnull
  (日にちが分からない「来月」だけの発言はdate_specの解決を諦めてよい)
- "month" / "day" / "year": unitが"absolute"のときだけ使う。"day"は1〜31の数字を必ず入れる。
  "month"(1〜12)は会話で言われていればその数字、言われていなければnull(システム側が今月・来月から自動で探す)。
  "year"は会話で明言されていなければnull(システム側が今年か来年かを自動判定する)。
- 例:「明日」→{"unit":"day","amount":1,"weekday":null,"day_of_month":null,"month":null,"day":null,"year":null}
     「来週の火曜日」→{"unit":"week","amount":1,"weekday":2,"day_of_month":null,"month":null,"day":null,"year":null}
     「3週間後」→{"unit":"week","amount":3,"weekday":null,"day_of_month":null,"month":null,"day":null,"year":null}
     「来月の1日」→{"unit":"month","amount":1,"weekday":null,"day_of_month":1,"month":null,"day":null,"year":null}
     「8月15日」→{"unit":"absolute","amount":0,"weekday":null,"day_of_month":null,"month":8,"day":15,"year":null}
     「2027年3月1日」→{"unit":"absolute","amount":0,"weekday":null,"day_of_month":null,"month":3,"day":1,"year":2027}
     「13日」(月が言われていない)→{"unit":"absolute","amount":0,"weekday":null,"day_of_month":null,"month":null,"day":13,"year":null}
     「そのうち」「今度」→{"unit":null,"amount":0,"weekday":null,"day_of_month":null,"month":null,"day":null,"year":null}

【date_spec_end(期間のある予定の場合のみ)】
- 「12日から13日」「来週の月曜から水曜まで」のように**期間**が語られた場合だけ、終了日を"date_spec_end"に
  date_specと全く同じ形式で入れる。単発の予定(期間ではない)の場合は"date_spec_end"はnullのままにする
- 終了日の月・年が会話で省略されている場合(例:「12日から13日」)は、開始日(date_spec)と同じ月・年のつもりで
  終了日の"month"/"year"にも開始日と同じ値を入れること(開始日が来月の日付ならterminalも来月の値にする)
- 例:「8月12日から13日に旅行」→
  date_spec: {"unit":"absolute","month":8,"day":12,"year":null,...},
  date_spec_end: {"unit":"absolute","month":8,"day":13,"year":null,...}

【time_specの埋め方】
- 会話で開始時刻が言及された場合だけ埋める。言及が無ければ{"hour":null,"minute":null}のままでよい(日にちだけの予定は非常に多いので、無理に推測しないこと)
- "hour"は必ず24時間表記(0〜23)の数値に変換すること。「午前」「朝」はそのままの数字、「昼」「午後」「夕方」「夜」等は24時間制に変換する
  (例:「昼の2時」→14、「午後7時」→19、「朝9時」→9、「夜9時」→21)
- "minute"は「半」なら30、「15分」のように具体的な指定があればその数値、指定が無く"hour"だけ言われた場合は0でよい
- 例:「明日の午後2時に病院」→ time_spec:{"hour":14,"minute":0}
     「来週の火曜日、朝9時半に」→ time_spec:{"hour":9,"minute":30}
     時刻の言及が無い場合 → time_spec:{"hour":null,"minute":null}
- date_spec_end(期間)側には時刻を付けない。time_specは常に開始日時点の時刻として扱われる

【needs_lookup(ほとんどの場合はnullでよい。むやみに使わないこと)】
利用者の質問が、上記の要約・「今後の予定の正確な一覧」・直近の会話履歴のいずれを見ても自信を持って正確に
答えられない場合だけ、"needs_lookup"に以下の形式で検索したい内容を入れてください。それ以外は必ずnullにすること。

{"type": "schedule または person または conversation", "query": "検索キーワード候補をカンマ区切りで2〜4個"}

- "schedule": 「今後の予定の正確な一覧」に載っていない予定について聞かれた場合
  (一覧は件数上限があるため、古い予定・件数からあふれた先の予定・既に終わった予定は載っていないことがある)
- "person": 上記の要約だけでは情報が足りない人物について聞かれた場合
- "conversation": 「前に言ってた」「先週話した内容」のように、具体的な過去のやり取りの中身を聞かれた場合
- "query"は検索対象のテキストと完全一致しないと見つからない単純な検索なので、利用者の言い回しをそのまま1つだけ
  使うのではなく、言い換え・類義語・関連しそうな単語も含めて2〜4個、カンマ区切りで挙げること
  (例:「昔やってたお店の話」→ "query": "お店,店,喫茶店,経営,商売")
- needs_lookupを設定した場合、reply_textの内容はどうせ使われないので適当な短い文字列でよい

【抽出ルール】
- personsとschedulesは、今回の会話で新たに言及された場合のみ含める。何もなければ空配列でよい
- 既知の人物(次のリストに含まれる): {$knownPersonsList}
  既知の人物が再度話題に出ただけの場合は、personsに重複して含めなくてよい
  (ただし新しい属性情報(誕生日・連絡先など)が語られた場合はnotesに記載する)
- 会話の内容が上記「今後の予定の正確な一覧」にある予定と同じものだと判断できる場合は、"title"をその一覧の表記と
  完全に同じ文字列にすること(表記を変えると別の予定として重複登録されてしまうため)。日付や場所など新しい情報が
  語られた場合はその回のschedulesに含めて更新できるようにする。明らかに別の新しい予定の場合のみ新しいtitleにする
PROMPT;
    }
}
