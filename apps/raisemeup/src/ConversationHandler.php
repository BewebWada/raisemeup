<?php
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/LineClient.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/FriendConfirmationService.php';
require_once __DIR__ . '/PersonRepository.php';
require_once __DIR__ . '/ScheduleRepository.php';
require_once __DIR__ . '/MedicationLogRepository.php';
require_once __DIR__ . '/RiskDetector.php';
require_once __DIR__ . '/RiskEventRepository.php';
require_once __DIR__ . '/FamilyAccountRepository.php';
require_once __DIR__ . '/FamilyMessageRepository.php';
require_once __DIR__ . '/FamilyThemeRepository.php';
require_once __DIR__ . '/DocumentTextRepository.php';
require_once __DIR__ . '/SubscriptionRepository.php';
require_once __DIR__ . '/SummaryRepository.php';
require_once __DIR__ . '/TopicCoverageRepository.php';
require_once __DIR__ . '/WeatherClient.php';
require_once __DIR__ . '/ImageProcessor.php';

class ConversationHandler
{
    private PDO $pdo;
    private LineClient $lineClient;
    private LineClient $familyLineClient;
    private ClaudeClient $claudeClient;
    private UserRepository $userRepo;
    private PersonRepository $personRepo;
    private ScheduleRepository $scheduleRepo;
    private MedicationLogRepository $medicationLogRepo;
    private RiskDetector $riskDetector;
    private RiskEventRepository $riskEventRepo;
    private FamilyAccountRepository $familyRepo;
    private FamilyMessageRepository $familyMessageRepo;
    private FamilyThemeRepository $familyThemeRepo;
    private DocumentTextRepository $documentTextRepo;
    private SubscriptionRepository $subscriptionRepo;
    private SummaryRepository $summaryRepo;
    private TopicCoverageRepository $topicCoverageRepo;
    private WeatherClient $weatherClient;

    // カテゴリごとの通知文言。ここに無いカテゴリは「気になる会話」の総称でまとめる
    private const RISK_CATEGORY_NOTIFY_LABELS = [
        'money_request' => '詐欺の可能性がある会話(金銭要求)',
        'personal_info_request' => '詐欺の可能性がある会話(個人情報の要求)',
        'urgency_pressure' => '詐欺の可能性がある会話(緊急性を煽る表現)',
        'isolation_attempt' => '詐欺の可能性がある会話(相談させない誘導)',
        'unfamiliar_contact' => '詐欺の可能性がある会話(不審な人物)',
        'health_concern' => '体調に関する気になる発言',
        'safety_concern' => '安全に関する気になる発言',
    ];

    // 家族へのリアルタイム通知は、様子見レベル(low)を除いた medium/high のみ行う
    // (lowはマイページの「安心レポート」への記録のみに留め、通知過多を避ける)
    private const RISK_NOTIFY_MIN_LEVEL = 'medium';

    // 家族への通知機能自体、寄り添いスタンダード以上の契約者限定の機能(寄り添いベーシックでは利用不可)。
    // premium_medicalはfamily_watchの上位互換プランのため合わせて含めている
    private const RISK_NOTIFY_PLAN_CODES = ['family_watch', 'premium_medical'];

    // 緊急扱いではない普通の雑談を遅らせる範囲(秒)。即レスだと機械的な印象になるため、
    // 「友だちが後で気づいて返信する」程度の自然な間(1〜4分)をランダムに置く
    private const DELAYED_REPLY_MIN_SECONDS = 60;
    private const DELAYED_REPLY_MAX_SECONDS = 240;

    // 1日あたりvisionで内容を認識する写真の上限枚数(暦日リセット)。超えた分は画像を見ずに返信する
    private const DAILY_IMAGE_RECOGNITION_LIMIT = 3;

    // 原稿対応モード(高解像度・原稿の文字起こし保存)は、家族への通知機能等と同じく寄り添いスタンダード
    // 以上の契約者限定。対象プランではベーシックより高い解像度で送り、1日の上限枚数も引き上げる
    private const DOCUMENT_MODE_PLAN_CODES = ['family_watch', 'premium_medical'];
    private const DAILY_IMAGE_RECOGNITION_LIMIT_STANDARD = 5;
    private const DOCUMENT_IMAGE_MAX_EDGE = 1568;
    private const DOCUMENT_IMAGE_QUALITY = 90;

    public function __construct(PDO $pdo, LineClient $lineClient, LineClient $familyLineClient, ClaudeClient $claudeClient)
    {
        $this->pdo = $pdo;
        $this->lineClient = $lineClient;
        $this->familyLineClient = $familyLineClient;
        $this->claudeClient = $claudeClient;
        $this->userRepo = new UserRepository($pdo);
        $this->personRepo = new PersonRepository($pdo);
        $this->scheduleRepo = new ScheduleRepository($pdo);
        $this->medicationLogRepo = new MedicationLogRepository($pdo);
        $this->riskDetector = new RiskDetector($pdo);
        $this->riskEventRepo = new RiskEventRepository($pdo);
        $this->familyRepo = new FamilyAccountRepository($pdo);
        $this->familyMessageRepo = new FamilyMessageRepository($pdo);
        $this->familyThemeRepo = new FamilyThemeRepository($pdo);
        $this->documentTextRepo = new DocumentTextRepository($pdo);
        $this->subscriptionRepo = new SubscriptionRepository($pdo);
        $this->summaryRepo = new SummaryRepository($pdo);
        $this->topicCoverageRepo = new TopicCoverageRepository($pdo);
        $this->weatherClient = new WeatherClient($pdo);
    }

    // 初回のみ、AIコンパニオン自身の軽い自己紹介(2〜3個)を生成して保存する(以降は固定して使い回す)。
    // LINE連携時ではなくここで遅延生成することで、新規ユーザーだけでなくこの機能導入前からの
    // 既存ユーザーにも、次の会話をきっかけに同じ経路で自然に行き渡る
    private function ensureCompanionPersona(array $user): array
    {
        if (!empty($user['companion_persona'])) {
            $decoded = json_decode((string) $user['companion_persona'], true);
            return is_array($decoded) ? $decoded : [];
        }

        $companionName = $user['companion_name'] ?: 'たより';
        $companionGender = ($user['companion_gender'] ?? null) === 'random' ? null : $user['companion_gender'];
        $facts = $this->claudeClient->generateCompanionPersona($companionName, $companionGender);
        if (!empty($facts)) {
            $this->userRepo->setCompanionPersona((int) $user['id'], $facts);
        }
        return $facts;
    }

    public function handleTextMessage(array $event): void
    {
        $this->processIncomingMessage(
            $event['source']['userId'],
            $event['message']['text'],
            $event['message']['id'],
            $event['replyToken'],
            'text'
        );
    }

    // LINEが返すスタンプのkeywordsは英語のみのため、会話ログ・Claudeへのコンテキストとして扱いやすいよう
    // よく使われる語だけ日本語に変換する辞書(全網羅ではない)。キーは小文字化して照合し、
    // 辞書に無い語はtranslateStickerKeyword()で元の英語のまま残す(情報を落とさないため)
    private const STICKER_KEYWORD_JA = [
        // 挨拶
        'hello' => 'こんにちは', 'hi' => 'やあ', 'hey' => 'やあ',
        'good morning' => 'おはよう', 'good afternoon' => 'こんにちは', 'good evening' => 'こんばんは',
        'good night' => 'おやすみ', 'goodbye' => 'さようなら', 'bye' => 'バイバイ', 'see you' => 'またね',
        'welcome' => 'ようこそ', 'nice to meet you' => 'はじめまして',
        // 肯定・同意・了解
        'yes' => 'はい', 'no' => 'いいえ', 'ok' => 'オーケー', 'okay' => 'オーケー', 'sure' => 'もちろん',
        'of course' => 'もちろん', 'got it' => '了解', 'affirmative' => 'はい', 'roger' => '了解',
        'agreed' => '賛成', 'deal' => '決まり', 'no problem' => '問題ないよ', 'sounds good' => 'いいね',
        // 感謝・謝罪
        'thank you' => 'ありがとう', 'thanks' => 'ありがとう', 'sorry' => 'ごめんね', 'my bad' => 'ごめんね',
        'apologies' => 'ごめんね', 'excuse me' => 'すみません',
        // 称賛
        'well done' => 'よくやった', 'good job' => 'よくやった', 'great' => 'すごい', 'awesome' => 'すごい',
        'amazing' => 'すごい', 'nice' => 'いいね', 'perfect' => '完璧', 'excellent' => '素晴らしい',
        'congratulations' => 'おめでとう', 'congrats' => 'おめでとう', 'bravo' => 'ブラボー',
        // 感情
        'happy' => '嬉しい', 'sad' => '悲しい', 'angry' => '怒ってる', 'surprised' => 'びっくり',
        'excited' => 'ワクワク', 'love' => '大好き', 'like' => '好き', 'cute' => 'かわいい',
        'funny' => '面白い', 'laughing' => '笑ってる', 'lol' => '笑', 'crying' => '泣いてる',
        'worried' => '心配', 'tired' => '疲れた', 'sleepy' => '眠い', 'bored' => '暇', 'shy' => '恥ずかしい',
        'embarrassed' => '恥ずかしい', 'confused' => '困った', 'scared' => '怖い', 'nervous' => '緊張',
        'proud' => '誇らしい', 'relieved' => 'ほっとした', 'jealous' => 'うらやましい',
        // 相槌・驚き
        'wow' => 'わあ', 'really' => '本当に', 'what' => 'え', 'oh no' => 'あらら', 'haha' => 'はは',
        'hehe' => 'ふふ', 'yay' => 'やった', 'woohoo' => 'わーい', 'ugh' => 'うーん', 'hmm' => 'んー',
        'oops' => 'おっと',
        // 励まし
        'good luck' => '頑張って', 'cheer up' => '元気出して', "you can do it" => 'できるよ',
        'fighting' => 'ファイト', "don't worry" => '心配しないで', 'take care' => '気をつけてね',
        'get well soon' => 'お大事に',
        // 食事
        'delicious' => '美味しい', 'yummy' => '美味しい', 'hungry' => 'お腹すいた', "let's eat" => '食べよう',
        'cheers' => '乾杯',
        // その他
        'please' => 'お願い', 'miss you' => '会いたいな', 'hug' => 'ハグ', 'kiss' => 'キス',
        'high five' => 'ハイタッチ', 'clap' => '拍手', 'applause' => '拍手', 'busy' => '忙しい',
        'fine' => '元気', "i'm fine" => '元気だよ', 'stop' => 'ストップ', 'wait' => '待って',
    ];

    private function translateStickerKeyword(string $keyword): string
    {
        $normalized = mb_strtolower(trim($keyword));
        return self::STICKER_KEYWORD_JA[$normalized] ?? $keyword;
    }

    /**
     * LINEスタンプが送られてきた場合、通常の会話フローに乗せて自然に反応させる。
     * スタンプ自体はClaudeに渡せないため、公式スタンプに付与されているkeywords(例:「楽しい」「ありがとう」)を
     * 「(スタンプを送りました: 楽しい、嬉しい)」のような説明文に変換し、通常のテキストメッセージと同じ
     * processIncomingMessage()に流す(persons/schedules抽出やリスク検知等の後処理もそのまま共通化するため)。
     * LINEのkeywordsは英語のみで返るため、translateStickerKeyword()でよく使う語だけ日本語化する
     * (辞書に無い語はそのまま英語で残す)。keywordsが取れない(カスタム/クリエイターズスタンプ等)場合は、
     * 種類が分からない旨だけ伝える
     */
    public function handleStickerMessage(array $event): void
    {
        $keywords = array_map([$this, 'translateStickerKeyword'], $event['message']['keywords'] ?? []);
        $description = !empty($keywords) ? implode('、', array_slice($keywords, 0, 5)) : '';
        $userMessage = $description !== '' ? "(スタンプを送りました: {$description})" : '(スタンプを送りました)';

        $this->processIncomingMessage(
            $event['source']['userId'],
            $userMessage,
            $event['message']['id'],
            $event['replyToken'],
            'sticker'
        );
    }

    // handleTextMessage/handleStickerMessage共通の本体。$messageTypeはconversations.message_typeへの記録に使う
    private function processIncomingMessage(string $lineUserId, string $userMessage, string $lineMessageId, string $replyToken, string $messageType): void
    {
        // ① 送信元の判定。利用者本人としてLINE連携済みでなければ、招待コードでの連携や
        //    通知専用の家族アカウントへの対応だけを行い、通常の会話フロー(Claude呼び出し)へは進まない
        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null) {
            $this->resolveUnlinkedSender($lineUserId, trim($userMessage), $lineMessageId, $replyToken);
            return;
        }
        if ($user['status'] === 'terminated') {
            // 解約済み(CancellationService::finalizeTermination)。お別れメッセージは解約確定時に
            // 送信済みのため、以降は課金対象外のClaude呼び出しを避けて何も返さない
            return;
        }

        // ①.5 リスク検知(キーワードマッチングのみ、Claude不要)を前倒しして判定しておく。
        // 即レスすべきかどうかの最終判定(⑥.5)でも使うほか、ここで検知できていれば
        // Claudeの応答を待たずに「入力中...」を出せる
        $earlyRisk = $this->riskDetector->check($userMessage);

        // ②.5 普通の雑談は数分後にゆっくり返す設計にしたため(⑥.5以降を参照)、ここで
        // 「入力中...」を出すのはリスク検知など即レスが確定している場合のみに限る
        // (数分後に届く返信のために先にアニメーションだけ出すと、途中で消えて不自然になるため)
        if ($earlyRisk !== null) {
            $this->lineClient->startLoadingAnimation($lineUserId, 20);
        }

        // ③ inbound会話を記録(LINEからの重複配信はline_message_idのUNIQUE制約でIGNOREされる)
        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content) VALUES (?, ?, "inbound", ?, ?)'
        );
        $insertStmt->execute([$user['id'], $lineMessageId, $messageType, $userMessage]);

        if ($insertStmt->rowCount() === 0) {
            // 既に処理済みのメッセージ(再送)。二重返信・二重処理を避けるためここで終了する
            error_log("Duplicate LINE message ignored: {$lineMessageId}");
            return;
        }
        $conversationId = (int) $this->pdo->lastInsertId();

        // ③.6 数分待ちの間に本人が畳みかけて次のメッセージを送ってきた場合、まだ未送信の
        // pending_replies(普通の雑談として遅延キューに入っている返信)が残っていることがある。
        // 新しい返信を作る前に古い返信を先に届けておく。こうしないと④のbuildRecentHistoryに
        // 「本人がまだ受け取っていない返信」が履歴として混ざってしまう
        $this->flushPendingReplies((int) $user['id']);

        // ③.5 直近で「危機感のある」安否確認通知(send_proactive_messages.phpのURGENT_SILENCE_HOURS超過)が
        // 家族に送られていた場合、今回inboundが確認できた時点で「連絡が取れました」の解除連絡を送る。
        // Claude呼び出しの成否に関わらず安否確認としての価値があるので、なるべく早い段階で行う
        $this->resolveUrgentSilenceIfPending((int) $user['id'], $this->familyFacingName($user));

        // ④ Claude API呼び出し
        $history = $this->buildRecentHistory((int) $user['id'], $conversationId);
        // どちらも上限付き(人物・予定がどれだけ蓄積されてもプロンプトサイズを頭打ちにするため)。
        // 上限からあふれた分はrelationship/schedule要約側でカバーされる
        $knownPersons = $this->personRepo->getNamesByUserId((int) $user['id'], 30);
        $knownSchedules = $this->scheduleRepo->getUpcomingDetailsByUserId((int) $user['id'], 15);
        $summaries = $this->summaryRepo->getAllForUser((int) $user['id']);
        $topicCoverage = $this->topicCoverageRepo->getAllForUser((int) $user['id']);
        $personaFacts = $this->ensureCompanionPersona($user);
        $companionName = $user['companion_name'] ?: 'たより';
        $weatherSummary = $this->getWeatherSummaryForAddress((string) $user['address']);
        $pendingFamilyMessages = $this->familyMessageRepo->getPendingForUser((int) $user['id']);
        $activeThemes = $this->familyThemeRepo->getActiveForUser((int) $user['id']);
        // 服薬リマインド機能はスキーマ未反映等でも会話自体は継続できるべきなので、取得失敗時は空扱いにする
        try {
            $medicationStatusToday = $this->medicationLogRepo->getTodayStatusForUser((int) $user['id']);
        } catch (Throwable $e) {
            error_log('MedicationLogRepository::getTodayStatusForUser failed: ' . $e->getMessage());
            $medicationStatusToday = [];
        }
        $recentDocumentTexts = $this->documentTextRepo->getRecentForPromptContext((int) $user['id']);
        $result = $this->claudeClient->generateReplyAndExtract(
            $history,
            $userMessage,
            $knownPersons,
            $knownSchedules,
            $summaries,
            $companionName,
            (string) $user['display_name'],
            $user['gender'],
            (string) $user['address'],
            $weatherSummary,
            $pendingFamilyMessages,
            $activeThemes,
            $medicationStatusToday,
            $topicCoverage,
            $personaFacts,
            $recentDocumentTexts
        );

        // 空気読みステップ(situation_read)の生成品質を確認するための一時的なログ出力。
        // 利用者には見せず、あくまで開発時のチューニング確認用
        if (!empty($result['situation_read'])) {
            error_log('situation_read: ' . $result['situation_read']);
        }

        // ④.5 ご家族からの伝言を今回の返信で伝えられたと判定された場合、配信済みにして家族へ確認連絡を送る
        if (!empty($pendingFamilyMessages) && !empty($result['family_message_delivered'])) {
            $this->familyMessageRepo->markDelivered(array_column($pendingFamilyMessages, 'id'));
            $this->notifyFamilyOfMessageDelivered((int) $user['id'], $this->familyFacingName($user));
        }

        // ⑤ 人物・予定の抽出結果をUPSERT
        foreach ($result['persons'] ?? [] as $person) {
            $this->personRepo->upsert((int) $user['id'], $person, $conversationId);
        }
        foreach ($result['schedules'] ?? [] as $schedule) {
            $this->scheduleRepo->upsert((int) $user['id'], $schedule, $conversationId);
        }

        // ⑤.35 「飲んだ」等、本日分の服薬確認が取れた場合は記録する(【今日のお薬状況】に載せたtitleと突き合わせ済み)。
        // 記録に失敗しても、本来の目的である返信そのものは止めない
        foreach ($result['medication_confirmed'] ?? [] as $medicationTitle) {
            $medicationTitle = trim((string) $medicationTitle);
            if ($medicationTitle !== '') {
                try {
                    $this->medicationLogRepo->markTaken((int) $user['id'], $medicationTitle);
                } catch (Throwable $e) {
                    error_log('MedicationLogRepository::markTaken failed: ' . $e->getMessage());
                }
            }
        }

        // ⑤.37 直近保存した書類の内容について、利用者から確認・訂正の発言が取れた場合は追記しておく
        $documentConfirmationNote = trim((string) ($result['document_confirmation_note'] ?? ''));
        if ($documentConfirmationNote !== '') {
            try {
                $this->documentTextRepo->appendConfirmationNote((int) $user['id'], $documentConfirmationNote);
            } catch (Throwable $e) {
                error_log('DocumentTextRepository::appendConfirmationNote failed: ' . $e->getMessage());
            }
        }

        // ⑤.36 自己紹介期間の話題カバレッジ用に、今回の会話で触れられたジャンルを記録する
        $this->topicCoverageRepo->touch((int) $user['id'], $result['topics_touched'] ?? []);

        // ⑤.4 「夜9時以降は連絡しないで」等の静かにしてほしい時間帯の申告があれば保存する
        // (send_proactive_messages.phpが、システム側からの声かけを控えるかどうかの判定に使う)
        $quietHours = $result['quiet_hours'] ?? null;
        if (is_array($quietHours) && array_key_exists('start', $quietHours) && array_key_exists('end', $quietHours)) {
            $this->userRepo->updateQuietHours((int) $user['id'], $quietHours['start'] ?: null, $quietHours['end'] ?: null);
        }

        // ⑤.45 「〇〇って呼びたい」のように、自分(TAYORI)の呼び名の希望があれば反映する。
        // 名前は自動生成せず、デフォルトは「たより」のまま。本人からの明確な希望があった場合だけ変える
        $requestedName = trim((string) ($result['requested_companion_name'] ?? ''));
        if ($requestedName !== '' && mb_strlen($requestedName) <= 30) {
            $this->userRepo->setCompanionName((int) $user['id'], $requestedName);
        }

        // ⑤.46 利用者本人の呼び名を、申込み時ではなく会話の中で自然に確認して初めて設定する。
        // 既に呼び名が分かっている場合はClaude側の指示でnullのままになる想定
        $learnedDisplayName = trim((string) ($result['learned_user_display_name'] ?? ''));
        if ($learnedDisplayName !== '' && mb_strlen($learnedDisplayName) <= 30) {
            $this->userRepo->setDisplayName((int) $user['id'], $learnedDisplayName);
        }

        // ⑤.42 気持ちが乗った場面でだけClaudeが選んだLINEスタンプ(通常はnull)。キーが未知の値だった場合は
        // 何も送らない(ClaudeClient::STICKERSに無いキーを幻覚で出した場合の安全側フォールバック)
        $sticker = $this->resolveSticker($result['sticker'] ?? null);

        // ⑤.5 要約・正確な一覧だけでは自信を持って答えられないとAIが判断した場合、DBを検索して2ターン目で回答し直す
        $replyText = $result['reply_text'] ?? '';
        $lookup = $result['needs_lookup'] ?? null;
        if (is_array($lookup) && in_array($lookup['type'] ?? null, ['schedule', 'person', 'conversation', 'web'], true) && !empty(trim((string) ($lookup['query'] ?? '')))) {
            $replyText = $this->performLookupAndAnswer((int) $user['id'], $history, $userMessage, $lookup, $companionName);
            // 検索結果を踏まえた別文面に差し替わるため、元のreply_textに合わせて選ばれたスタンプは使わない
            $sticker = null;
        }

        // ⑤.6 道案内のリクエストで目的地・移動手段の両方が確信を持って絞り込めた場合、
        // Googleマップのルート検索リンクを添える(どちらか一方だけでは送らない。ClaudeClientの
        // destination/travel_modeの指示側で、揃うまでreply_textで聞き返すよう制御している)。
        // 現在地(origin)は指定しない。地図アプリ側で開いた端末の現在地を起点に使ってくれるため
        // (自宅住所を使うと、外出先から聞かれた場合に不正確になる)
        $destination = trim((string) ($result['destination'] ?? ''));
        $travelMode = $result['travel_mode'] ?? null;
        if ($destination !== '' && in_array($travelMode, ['walking', 'transit', 'driving'], true)) {
            $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($destination)
                . '&travelmode=' . $travelMode;
            $replyText .= "\n" . $mapsUrl;
        }

        // ⑥ リスク検知結果の記録(判定自体は①.5で前倒し済み)。詐欺だけでなく、健康・安全に関する気になる発言も対象
        $risk = $earlyRisk;
        if ($risk !== null) {
            $this->pdo->prepare(
                'INSERT INTO risk_events (user_id, conversation_id, risk_pattern_id, matched_keywords, risk_level, status)
                 VALUES (?, ?, ?, ?, ?, "pending")'
            )->execute([
                $user['id'],
                $conversationId,
                $risk['pattern_id'],
                json_encode($risk['matched_keywords'], JSON_UNESCAPED_UNICODE),
                $risk['risk_level'],
            ]);
            $riskEventId = (int) $this->pdo->lastInsertId();

            // 様子見レベル(low)は通知せずマイページの「安心レポート」への記録のみに留める(通知過多の防止)
            if (RiskDetector::levelRank($risk['risk_level']) >= RiskDetector::levelRank(self::RISK_NOTIFY_MIN_LEVEL)) {
                $this->notifyFamilyOfRisk((int) $user['id'], $this->familyFacingName($user), $risk, $userMessage, $riskEventId);
            }
        }

        // ⑥.5 即レスすべき内容かどうかを判定する。詐欺・体調・安全のリスク検知/道案内の目的地確定/
        // Claudeが「即答すべき」と判断した内容(prompt_reply_needed。予定・時間の単純な事実確認・
        // 服薬確認やリマインドへの返答・困りごとやSOS的な言い回し)のいずれかに該当すれば即レス、
        // それ以外の普通の雑談は数分後にゆっくり返す(⑦'参照)
        $isUrgent = ($risk !== null)
            || ($destination !== '' && in_array($travelMode, ['walking', 'transit', 'driving'], true))
            || !empty($result['prompt_reply_needed']);

        if ($isUrgent) {
            // まだ「入力中...」を出していなければ(①.5の時点でリスク未検知だった場合)ここで出す
            if ($earlyRisk === null) {
                $this->lineClient->startLoadingAnimation($lineUserId, 20);
            }

            // リスク検知された会話では、Claudeの判断ミスで付いていた場合に備えてスタンプは送らない
            if ($risk !== null) {
                $sticker = null;
            }

            // ⑦ outbound会話を記録
            $this->pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content, claude_model) VALUES (?, "outbound", "text", ?, ?)'
            )->execute([$user['id'], $replyText, Config::get('CLAUDE_MODEL')]);

            // ⑦.5 文章の長さに応じて一呼吸置く(即レスの機械的な印象を避けるため)。
            // replyTokenの有効期限に余裕を持たせるため、上限は控えめにしている
            usleep($this->typingDelayMicroseconds($replyText));

            // ⑧ LINEへ返信
            $this->lineClient->reply($replyToken, $replyText, $sticker);
            return;
        }

        // ⑦' 普通の雑談は、即レスだと機械的な印象になるため1〜4分ランダムに置いてから返信する。
        // replyTokenは長時間保持できないためreply APIは使わず、pending_repliesにキューして
        // 別バッチ(send_pending_replies.php、cron 1分間隔)にpush API経由での送信を委ねる。
        // outbound会話の記録も実際の送信時に行う(ここで先に記録すると、待機中に本人が畳みかけて
        // きた場合の履歴に、まだ届いていない返信が混ざってしまうため)
        $sendAfterSeconds = random_int(self::DELAYED_REPLY_MIN_SECONDS, self::DELAYED_REPLY_MAX_SECONDS);
        $this->pdo->prepare(
            'INSERT INTO pending_replies (user_id, line_user_id, reply_text, sticker_package_id, sticker_id, send_after, claude_model)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
        )->execute([$user['id'], $lineUserId, $replyText, $sticker['packageId'] ?? null, $sticker['stickerId'] ?? null, $sendAfterSeconds, Config::get('CLAUDE_MODEL')]);
    }

    // 数分待ちの間に本人が畳みかけて次のメッセージを送ってきた場合、未送信のまま残っている
    // pending_replies行を新しい返信の処理前に先に届けておく(履歴の整合性を保つため)。
    // send_pending_replies.php(バッチ)と同じ行を二重送信しないよう、statusの排他更新で確保してから送る
    private function flushPendingReplies(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, line_user_id, reply_text, sticker_package_id, sticker_id, claude_model FROM pending_replies WHERE user_id = ? AND status = "pending" ORDER BY send_after'
        );
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim = $this->pdo->prepare('UPDATE pending_replies SET status = "sending" WHERE id = ? AND status = "pending"');
            $claim->execute([$row['id']]);
            if ($claim->rowCount() === 0) {
                // 既にバッチ側で処理中/処理済み
                continue;
            }

            $sticker = $row['sticker_package_id'] !== null && $row['sticker_id'] !== null
                ? ['packageId' => $row['sticker_package_id'], 'stickerId' => $row['sticker_id']]
                : null;
            $this->lineClient->push($row['line_user_id'], $row['reply_text'], null, $sticker);
            $this->pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content, claude_model) VALUES (?, "outbound", "text", ?, ?)'
            )->execute([$userId, $row['reply_text'], $row['claude_model']]);
            $this->pdo->prepare('UPDATE pending_replies SET status = "sent", sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
        }
    }

    // Claudeが返した"sticker"キー(warm/fun/sparkle等)をClaudeClient::STICKERSの実際のpackageId/stickerIdに変換する。
    // null、または未知のキー(幻覚)の場合は何も送らずnullを返す
    private function resolveSticker(?string $stickerKey): ?array
    {
        if ($stickerKey === null) {
            return null;
        }
        return ClaudeClient::STICKERS[$stickerKey] ?? null;
    }

    /**
     * 位置情報メッセージ(クイックリプライの位置情報ボタン、または通常の「+」メニューから送られたもの)を処理する。
     * テキストの会話フローとは異なり、Claude呼び出しは行わず定型の受領返信のみ返す。
     * urgent_silence_alertsが未解除のまま残っていれば、位置情報付きで家族への解除連絡を送る
     */
    public function handleLocationMessage(array $event): void
    {
        $lineUserId = $event['source']['userId'];
        $message = $event['message'];
        $lineMessageId = $message['id'];
        $replyToken = $event['replyToken'];

        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null || $user['status'] === 'terminated') {
            return; // 未連携、または解約済みの送信元からの位置情報は扱わない(招待コード連携はテキストメッセージのみ対応)
        }

        $latitude = (float) $message['latitude'];
        $longitude = (float) $message['longitude'];
        $address = $message['address'] ?? null;
        // 住所の実テキストはlocation_address列にのみ保存し、contentには入れない。contentは
        // buildRecentHistory()でそのままClaudeへの会話履歴に使われるため、実住所を含めると
        // 「利用者と同じ地域に住んでいる設定」の雑談用ペルソナ(buildSystemPromptの$areaLine)と噛み合って
        // 「そこ、近所だよ」のような、安否確認の文脈にそぐわない発言を誘発してしまう
        $content = '位置情報を送信しました';

        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content, latitude, longitude, location_address)
             VALUES (?, ?, "inbound", "location", ?, ?, ?, ?)'
        );
        $insertStmt->execute([$user['id'], $lineMessageId, $content, $latitude, $longitude, $address]);
        if ($insertStmt->rowCount() === 0) {
            return; // 既に処理済みのメッセージ(再送)
        }

        $this->resolveUrgentSilenceIfPending(
            (int) $user['id'],
            $this->familyFacingName($user),
            ['latitude' => $latitude, 'longitude' => $longitude]
        );

        $this->lineClient->reply($replyToken, '教えてくれてありがとうございます。安心しました。');
    }

    /**
     * 写真メッセージを処理する。1日3枚まで(暦日リセット)はリサイズしてClaude Visionで内容を認識して
     * 返信し、それを超えた分は画像を取得・解析せずにテキストのみで自然な返信を生成する(上限超過はコスト面の
     * 都合であり、利用者には機械的な断り文句に見えないようにする)。画像バイナリはリサイズ・認識後に破棄し、
     * サーバー上のどこにも保存しない。
     */
    public function handleImageMessage(array $event): void
    {
        $lineUserId = $event['source']['userId'];
        $lineMessageId = $event['message']['id'];
        $replyToken = $event['replyToken'];

        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null || $user['status'] === 'terminated') {
            return; // 未連携、または解約済みの送信元からの画像は扱わない(招待コード連携はテキストメッセージのみ対応)
        }

        // 重複配信ガードを最初に行う(line_message_idのUNIQUE制約)。これを最初に済ませておくことで、
        // 同じ画像の再送配信によって後述の日次カウントが二重加算されることも防げる
        $placeholderContent = '(写真を送りました)';
        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content) VALUES (?, ?, "inbound", "image", ?)'
        );
        $insertStmt->execute([$user['id'], $lineMessageId, $placeholderContent]);
        if ($insertStmt->rowCount() === 0) {
            error_log("Duplicate LINE image message ignored: {$lineMessageId}");
            return;
        }
        $conversationId = (int) $this->pdo->lastInsertId();

        $this->flushPendingReplies((int) $user['id']);
        $this->resolveUrgentSilenceIfPending((int) $user['id'], $this->familyFacingName($user));

        [$documentModeEnabled, $dailyLimit] = $this->resolveDocumentModeAndLimit((int) $user['id']);
        $countToday = $this->countImagesRecognizedTodayBefore((int) $user['id'], $conversationId);
        $context = $this->gatherImageContext($user, $conversationId);

        if ($countToday >= $dailyLimit) {
            $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, null, $this->imageLimitReachedNote($dailyLimit), false);
            return;
        }

        $content = $this->lineClient->getMessageContent($lineMessageId);
        $imageBase64 = $content !== null
            ? ($documentModeEnabled
                ? ImageProcessor::resizeToJpegBase64($content['binary'], self::DOCUMENT_IMAGE_MAX_EDGE, self::DOCUMENT_IMAGE_QUALITY)
                : ImageProcessor::resizeToJpegBase64($content['binary']))
            : null;

        if ($imageBase64 === null) {
            // LINEはimageと申告しているのにデコードできない技術的な失敗(上限超過とは別のケース)。
            // Claudeは呼ばず、静的なお詫び文言で即レスして無駄なAPI呼び出しを避ける
            error_log("Image fetch/resize failed for message {$lineMessageId}");
            $this->replyImageFetchFailure($user, $replyToken);
            return;
        }

        $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, $imageBase64, null, $documentModeEnabled);
    }

    // 原稿対応モード(高解像度・文字起こし保存)が有効かどうかと、その利用者に適用する1日の上限枚数を、
    // 現在のプランから解決する。handleImageMessage/handleFileMessageの両方から使う共通ロジック
    private function resolveDocumentModeAndLimit(int $userId): array
    {
        $planCode = $this->subscriptionRepo->getCurrentPlanCodeForUser($userId);
        $documentModeEnabled = in_array($planCode, self::DOCUMENT_MODE_PLAN_CODES, true);
        $dailyLimit = $documentModeEnabled ? self::DAILY_IMAGE_RECOGNITION_LIMIT_STANDARD : self::DAILY_IMAGE_RECOGNITION_LIMIT;
        return [$documentModeEnabled, $dailyLimit];
    }

    // 動画・大容量文書等をダウンロード・デコード試行するだけ無駄なので、この上限を超えるfileメッセージは
    // 画像判定を試みずに「見られない」応答にする。一般的なスマホ写真は原寸でもこれより十分小さい
    private const MAX_IMAGE_FILE_BYTES = 20 * 1024 * 1024;

    // 画像として読み取れないファイル添付(文書・動画等)が届いた場合の状況説明。
    // ClaudeClient::generateImageUnavailableReplyの$situationNoteとして渡す
    private const FILE_UNVIEWABLE_NOTE = '利用者から写真ではないファイル(文書や動画などの添付ファイル)が送られてきたため、中身を確認することができません。';

    /**
     * 「ファイルとして送信」されたメッセージ(LINEのクリップ📎アイコン等から送られたもの)を処理する。
     * スマホの「オリジナル画質で送る」設定等で写真が画像メッセージではなくファイルメッセージとして届く
     * ケースがあるため、まず画像としてデコードを試み、成功すればhandleImageMessageと全く同じ扱い
     * (1日3枚の上限にも同じくカウント)にする。デコードできなければ「見られないファイル」として、
     * 中身を見ずに自然な返信だけ返す(この場合は上限にカウントしない)。
     */
    public function handleFileMessage(array $event): void
    {
        $lineUserId = $event['source']['userId'];
        $lineMessageId = $event['message']['id'];
        $replyToken = $event['replyToken'];
        $fileName = trim((string) ($event['message']['fileName'] ?? ''));
        $fileSize = (int) ($event['message']['fileSize'] ?? 0);

        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null || $user['status'] === 'terminated') {
            return; // 未連携、または解約済みの送信元からのファイルは扱わない
        }

        $placeholderContent = $fileName !== '' ? "(ファイルを送りました: {$fileName})" : '(ファイルを送りました)';
        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content) VALUES (?, ?, "inbound", "other", ?)'
        );
        $insertStmt->execute([$user['id'], $lineMessageId, $placeholderContent]);
        if ($insertStmt->rowCount() === 0) {
            error_log("Duplicate LINE file message ignored: {$lineMessageId}");
            return;
        }
        $conversationId = (int) $this->pdo->lastInsertId();

        $this->flushPendingReplies((int) $user['id']);
        $this->resolveUrgentSilenceIfPending((int) $user['id'], $this->familyFacingName($user));

        $context = $this->gatherImageContext($user, $conversationId);

        if ($fileSize > self::MAX_IMAGE_FILE_BYTES) {
            $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, null, self::FILE_UNVIEWABLE_NOTE, false);
            return;
        }

        $content = $this->lineClient->getMessageContent($lineMessageId);
        if ($content === null) {
            error_log("File fetch failed for message {$lineMessageId}");
            $this->replyImageFetchFailure($user, $replyToken);
            return;
        }

        [$documentModeEnabled, $dailyLimit] = $this->resolveDocumentModeAndLimit((int) $user['id']);
        $imageBase64 = $documentModeEnabled
            ? ImageProcessor::resizeToJpegBase64($content['binary'], self::DOCUMENT_IMAGE_MAX_EDGE, self::DOCUMENT_IMAGE_QUALITY)
            : ImageProcessor::resizeToJpegBase64($content['binary']);
        if ($imageBase64 === null) {
            // 画像として読み取れないファイル(文書・動画等)。1日の認識上限にはカウントしない
            $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, null, self::FILE_UNVIEWABLE_NOTE, false);
            return;
        }

        // 画像として読み取れた場合は、写真アイコンから送られた画像と同じ扱いにする
        // (message_typeを"image"に統一し、1日の上限にも同じくカウントする)
        $this->pdo->prepare('UPDATE conversations SET message_type = "image" WHERE id = ?')->execute([$conversationId]);
        $countToday = $this->countImagesRecognizedTodayBefore((int) $user['id'], $conversationId);

        if ($countToday >= $dailyLimit) {
            $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, null, $this->imageLimitReachedNote($dailyLimit), false);
            return;
        }

        $this->respondToImageTurn($user, $conversationId, $lineUserId, $context, $imageBase64, null, $documentModeEnabled);
    }

    // 画像取得・デコードのAPI/ネットワーク的な失敗時の定型応答。上限超過やファイル種別判定とは別の
    // 「技術的な失敗」ケースなので、Claudeは呼ばず即レスでコストをかけない
    private function replyImageFetchFailure(array $user, string $replyToken): void
    {
        $replyText = 'ごめんね、うまく受け取れなかったみたい。もう一度送ってもらえるかな?';
        $this->lineClient->reply($replyToken, $replyText);
        $this->pdo->prepare(
            'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
        )->execute([$user['id'], $replyText]);
    }

    // 本日、$beforeConversationIdより前に届いた「認識対象になった画像」の枚数(暦日リセット)。
    // handleImageMessage/handleFileMessageの両方から、写真と判定した後の上限チェックに使う
    private function countImagesRecognizedTodayBefore(int $userId, int $beforeConversationId): int
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversations WHERE user_id = ? AND direction = "inbound" AND message_type = "image" AND DATE(created_at) = CURDATE() AND id < ?'
        );
        $countStmt->execute([$userId, $beforeConversationId]);
        return (int) $countStmt->fetchColumn();
    }

    private function imageLimitReachedNote(int $dailyLimit): string
    {
        return '利用者から写真が送られてきましたが、本日はすでに' . $dailyLimit
            . '枚分の写真を確認済みのため、システムの都合でこの写真の中身は見ることができません。';
    }

    // handleImageMessage/handleFileMessageで共通に使う、Claude呼び出し前のコンテキスト一式の収集。
    // handleTextMessageの④で集めている内容とほぼ同じもの(画像用に切り出した版)
    private function gatherImageContext(array $user, int $conversationId): array
    {
        return [
            'history' => $this->buildRecentHistory((int) $user['id'], $conversationId),
            'knownPersons' => $this->personRepo->getNamesByUserId((int) $user['id'], 30),
            'knownSchedules' => $this->scheduleRepo->getUpcomingDetailsByUserId((int) $user['id'], 15),
            'summaries' => $this->summaryRepo->getAllForUser((int) $user['id']),
            'topicCoverage' => $this->topicCoverageRepo->getAllForUser((int) $user['id']),
            'personaFacts' => $this->ensureCompanionPersona($user),
            'companionName' => $user['companion_name'] ?: 'たより',
            'weatherSummary' => $this->getWeatherSummaryForAddress((string) $user['address']),
            'pendingFamilyMessages' => $this->familyMessageRepo->getPendingForUser((int) $user['id']),
            'activeThemes' => $this->familyThemeRepo->getActiveForUser((int) $user['id']),
            'medicationStatusToday' => $this->getMedicationStatusTodaySafe((int) $user['id']),
            'recentDocumentTexts' => $this->documentTextRepo->getRecentForPromptContext((int) $user['id']),
        ];
    }

    private function getMedicationStatusTodaySafe(int $userId): array
    {
        try {
            return $this->medicationLogRepo->getTodayStatusForUser($userId);
        } catch (Throwable $e) {
            error_log('MedicationLogRepository::getTodayStatusForUser failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 画像を認識できた場合($imageBase64を渡す)、または見せられない事情がある場合($unavailableReasonを渡す。
     * 上限超過・画像として読み取れないファイル等)のいずれかでClaudeを呼び、共通の後処理
     * (人物・予定のUPSERT、服薬確認、話題カバレッジ、quiet_hours、pending_replies経由の返信キュー)を行う。
     * $imageBase64と$unavailableReasonはどちらか一方だけを渡すこと。$documentModeEnabledは
     * (画像がある場合のみ意味を持ち)寄り添いスタンダード以上かどうか。trueの場合、Claudeに原稿の
     * 文字起こしを依頼し、結果に含まれる"document_text"をdocument_textsへ保存する。
     */
    private function respondToImageTurn(array $user, int $conversationId, string $lineUserId, array $context, ?string $imageBase64, ?string $unavailableReason, bool $documentModeEnabled): void
    {
        if ($imageBase64 !== null) {
            $result = $this->claudeClient->generateImageReply(
                $context['history'],
                $imageBase64,
                '',
                $context['knownPersons'],
                $context['knownSchedules'],
                $context['summaries'],
                $context['companionName'],
                (string) $user['display_name'],
                $user['gender'],
                (string) $user['address'],
                $context['weatherSummary'],
                $context['pendingFamilyMessages'],
                $context['activeThemes'],
                $context['medicationStatusToday'],
                $context['topicCoverage'],
                $context['personaFacts'],
                $context['recentDocumentTexts'],
                $documentModeEnabled
            );
        } else {
            $result = $this->claudeClient->generateImageUnavailableReply(
                (string) $unavailableReason,
                $context['history'],
                $context['knownPersons'],
                $context['knownSchedules'],
                $context['summaries'],
                $context['companionName'],
                (string) $user['display_name'],
                $user['gender'],
                (string) $user['address'],
                $context['weatherSummary'],
                $context['pendingFamilyMessages'],
                $context['activeThemes'],
                $context['medicationStatusToday'],
                $context['topicCoverage'],
                $context['personaFacts'],
                $context['recentDocumentTexts']
            );
        }

        if (!empty($context['pendingFamilyMessages']) && !empty($result['family_message_delivered'])) {
            $this->familyMessageRepo->markDelivered(array_column($context['pendingFamilyMessages'], 'id'));
            $this->notifyFamilyOfMessageDelivered((int) $user['id'], $this->familyFacingName($user));
        }

        // 原稿対応モードでこの回に保存した書類のid。この回のschedulesが書類由来の場合、紐づけに使う
        $documentTextId = null;
        if ($documentModeEnabled && !empty($result['document_text'])) {
            try {
                $documentTextId = $this->documentTextRepo->save((int) $user['id'], $conversationId, trim((string) $result['document_text']));
            } catch (Throwable $e) {
                error_log('DocumentTextRepository::save failed: ' . $e->getMessage());
            }
        }

        foreach ($result['persons'] ?? [] as $person) {
            $this->personRepo->upsert((int) $user['id'], $person, $conversationId);
        }
        foreach ($result['schedules'] ?? [] as $schedule) {
            $this->scheduleRepo->upsert((int) $user['id'], $schedule, $conversationId, $documentTextId);
        }
        foreach ($result['medication_confirmed'] ?? [] as $medicationTitle) {
            $medicationTitle = trim((string) $medicationTitle);
            if ($medicationTitle !== '') {
                try {
                    $this->medicationLogRepo->markTaken((int) $user['id'], $medicationTitle);
                } catch (Throwable $e) {
                    error_log('MedicationLogRepository::markTaken failed: ' . $e->getMessage());
                }
            }
        }
        $this->topicCoverageRepo->touch((int) $user['id'], $result['topics_touched'] ?? []);

        $quietHours = $result['quiet_hours'] ?? null;
        if (is_array($quietHours) && array_key_exists('start', $quietHours) && array_key_exists('end', $quietHours)) {
            $this->userRepo->updateQuietHours((int) $user['id'], $quietHours['start'] ?: null, $quietHours['end'] ?: null);
        }

        $replyText = trim((string) ($result['reply_text'] ?? ''));
        if ($replyText === '') {
            $replyText = 'ありがとう、写真見せてくれて嬉しいな。';
        }
        $sticker = $this->resolveSticker($result['sticker'] ?? null);

        // 写真送信はテキストの雑談と同様、即レスだと機械的な印象になるため数分後にゆっくり返す
        // (⑦'と同じpending_replies経由。outbound会話の記録も実際の送信時に行われる)
        $sendAfterSeconds = random_int(self::DELAYED_REPLY_MIN_SECONDS, self::DELAYED_REPLY_MAX_SECONDS);
        $this->pdo->prepare(
            'INSERT INTO pending_replies (user_id, line_user_id, reply_text, sticker_package_id, sticker_id, send_after, claude_model)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
        )->execute([$user['id'], $lineUserId, $replyText, $sticker['packageId'] ?? null, $sticker['stickerId'] ?? null, $sendAfterSeconds, Config::get('CLAUDE_MODEL')]);
    }

    /**
     * LINEの友だち追加(followイベント)を処理する。
     * LINEログイン連携は済んでいるが、その時点では友だち追加していなかった利用者が、
     * 後からQRコード等で友だち追加した場合にここで初めてオンボード完了(active)にし、
     * ウェルカムメッセージを送る(line_login_callback.php側で友だち追加済みだった場合の処理と同じ)。
     * 友だち追加も必須のため、これが完了するまではcheck_subscriptions.phpの放置判定の対象のまま。
     */
    public function handleFollowEvent(array $event): void
    {
        $lineUserId = $event['source']['userId'] ?? '';
        if ($lineUserId === '') {
            return;
        }

        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null) {
            return; // LINEログイン未連携(招待コード経由も未実施)
        }

        if ($user['status'] === 'pending') {
            // 初回: LINEログイン済みだが友だち追加は初めて確認できたケース
            FriendConfirmationService::confirmUser($this->pdo, $this->userRepo, $this->lineClient, $user);
            return;
        }

        if ($user['friend_confirmed_at'] === null) {
            // オンボード済み(status='active')だが未確認 = ブロック後の再フォロー。挨拶は再送しない
            FriendConfirmationService::reconnectUser($this->userRepo, $this->lineClient, $user);
            return;
        }

        // 既に確認済み(friend_confirmed_at NOT NULL)での再フォローは何もしない(従来通り)
    }

    /**
     * LINE公式アカウントのブロック(unfollowイベント)を処理する。
     * friend_confirmed_atだけをNULLに戻し、statusには触れない
     * (放置判定など他ロジックへの副作用を避けるため)。ブロックされた相手に
     * メッセージは送れない(送っても失敗するだけ)ため、DB更新のみ行う。
     */
    public function handleUnfollowEvent(array $event): void
    {
        $lineUserId = $event['source']['userId'] ?? '';
        if ($lineUserId === '') {
            return;
        }

        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null || $user['friend_confirmed_at'] === null) {
            return;
        }

        $this->userRepo->clearFriendConfirmation((int) $user['id']);
    }

    // 住所からJMA予報区を判定し、天気・警報注意報の要約を取得する。住所未登録・判定不可・API失敗時は
    // 空文字を返す(ClaudeClient側で「情報が無い」場合の振る舞いに委ねるので、ここでは例外を出さない)
    private function getWeatherSummaryForAddress(string $address): string
    {
        if (trim($address) === '') {
            return '';
        }
        $areaCode = WeatherClient::resolveAreaCode($address);
        if ($areaCode === null) {
            return '';
        }
        return $this->weatherClient->getSummary($areaCode);
    }

    // 文字数に応じた「タイピング時間」を疑似的に作る。1文字あたり約80ms、2〜6秒の範囲に収める
    // (replyTokenの有効期限に余裕を持たせるため、上限は短めにしている)
    private function typingDelayMicroseconds(string $text): int
    {
        $seconds = max(2, min(6, mb_strlen($text) * 0.08));
        return (int) ($seconds * 1_000_000);
    }

    // ご家族向けLINE通知での呼びかけ名。会話用の呼び名(display_name)ではなく、マイページでご家族が
    // 登録した氏名(full_name)を優先する(氏名未登録の間だけ呼び名にフォールバックする)
    private function familyFacingName(array $user): string
    {
        return (string) ($user['full_name'] ?: $user['display_name'] ?: '');
    }

    /**
     * リスク検知(詐欺・健康・安全)を、紐づく家族全員(LINE連携済みかつ有効なリンクのみ)に
     * 「TAYORIサポート」チャネルからpush通知する。1件でも送信できればnotified扱いにする
     * (家族側のLINE連携が無い等で送信先が無い場合は、記録は残るが通知はできない)。
     */
    private function notifyFamilyOfRisk(int $userId, string $userDisplayName, array $risk, string $userMessage, int $riskEventId): void
    {
        $planCode = $this->subscriptionRepo->getCurrentPlanCodeForUser($userId);
        if (!in_array($planCode, self::RISK_NOTIFY_PLAN_CODES, true)) {
            return; // スタンダード等、対象外プラン(寄り添いベーシック)では検知の記録のみでLINE通知はしない
        }

        $recipients = $this->familyRepo->getNotifiableForUser($userId);
        if (empty($recipients)) {
            return;
        }

        $displayName = $userDisplayName !== '' ? $userDisplayName : 'ご利用者様';
        $categoryLabel = self::RISK_CATEGORY_NOTIFY_LABELS[$risk['category']] ?? '気になる会話';
        // 長文をそのまま送ると読みにくいため、抜粋にとどめる
        $excerpt = mb_substr($userMessage, 0, 80) . (mb_strlen($userMessage) > 80 ? '…' : '');

        $text = "【TAYORI】{$displayName}様との会話で、気になる内容がありました。\n\n"
            . "分類: {$categoryLabel}\n"
            . "ご本人の発言: 「{$excerpt}」\n\n"
            . "詳しくはマイページの「安心レポート」でご確認いただけます。心配な場合は、直接ご本人にご連絡ください。";

        $sentAny = false;
        foreach ($recipients as $recipient) {
            if ($this->familyLineClient->push($recipient['line_user_id'], $text)) {
                $sentAny = true;
            }
        }

        if ($sentAny) {
            $this->riskEventRepo->markNotified($riskEventId);
        }
    }

    /**
     * family_webhook.phpでキューイングされた伝言(family_messages)を今回の会話でTAYORIが伝え終えたことを、
     * 送り主の家族へ知らせる。伝言機能自体が寄り添いスタンダード以上限定のため、送信時点でも念のためプランを
     * 確認する(キューイング時から契約が変わっている可能性はゼロではないため)
     */
    private function notifyFamilyOfMessageDelivered(int $userId, string $userDisplayName): void
    {
        $planCode = $this->subscriptionRepo->getCurrentPlanCodeForUser($userId);
        if (!in_array($planCode, self::RISK_NOTIFY_PLAN_CODES, true)) {
            return;
        }
        $recipients = $this->familyRepo->getNotifiableForUser($userId);
        if (empty($recipients)) {
            return;
        }

        $displayName = $userDisplayName !== '' ? $userDisplayName : 'ご利用者様';
        $text = "【TAYORI】{$displayName}様に、お伝えいただいた伝言をお伝えしました。";
        foreach ($recipients as $recipient) {
            $this->familyLineClient->push($recipient['line_user_id'], $text);
        }
    }

    /**
     * send_proactive_messages.phpが送った安否確認系の家族通知(urgent_silence_alertsの「危機感のある」通知、
     * wellness_check_alertsのCHECKIN_WINDOWS枠での「まだ確認できていません」通知)が未解除(resolved_at IS NULL)
     * のまま残っている場合、今回inboundが確認できたことをもって家族へ「連絡が取れました」の解除連絡を送る。
     * 両方が同時に未解除でも、家族への連絡はまとめて1回にする。プラン対象外・送信先が無い場合でも、
     * 同じ通知を何度も再チェックしないよう判定結果(resolved_at)は必ず記録する。
     * $locationが渡された場合(位置情報メッセージでの解除時)は、地図リンクを解除連絡に添える
     */
    private function resolveUrgentSilenceIfPending(int $userId, string $userDisplayName, ?array $location = null): void
    {
        $hadPending = false;

        $stmt = $this->pdo->prepare(
            'SELECT id FROM urgent_silence_alerts WHERE user_id = ? AND resolved_at IS NULL ORDER BY notified_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $urgentAlertId = $stmt->fetchColumn();
        if ($urgentAlertId !== false) {
            $this->pdo->prepare('UPDATE urgent_silence_alerts SET resolved_at = NOW() WHERE id = ?')->execute([$urgentAlertId]);
            $hadPending = true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM wellness_check_alerts WHERE user_id = ? AND resolved_at IS NULL'
        );
        $stmt->execute([$userId]);
        $wellnessAlertIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($wellnessAlertIds)) {
            $placeholders = implode(',', array_fill(0, count($wellnessAlertIds), '?'));
            $this->pdo->prepare("UPDATE wellness_check_alerts SET resolved_at = NOW() WHERE id IN ({$placeholders})")
                ->execute($wellnessAlertIds);
            $hadPending = true;
        }

        if (!$hadPending) {
            return; // 未解除の通知は無い
        }

        $planCode = $this->subscriptionRepo->getCurrentPlanCodeForUser($userId);
        if (!in_array($planCode, self::RISK_NOTIFY_PLAN_CODES, true)) {
            return; // スタンダード等、対象外プラン(寄り添いベーシック)では解除の記録のみでLINE通知はしない
        }
        $recipients = $this->familyRepo->getNotifiableForUser($userId);
        if (empty($recipients)) {
            return;
        }

        $displayName = $userDisplayName !== '' ? $userDisplayName : 'ご利用者様';
        $text = "【TAYORI】{$displayName}様と連絡が取れました。ご心配をおかけしました。";
        if ($location !== null) {
            $text .= "\n本人から共有された現在地: https://maps.google.com/?q={$location['latitude']},{$location['longitude']}";
        }
        foreach ($recipients as $recipient) {
            $this->familyLineClient->push($recipient['line_user_id'], $text);
        }
    }

    /**
     * まだusersに(line_user_idで)紐付いていない送信元からのメッセージを処理する。
     * ・利用者本人の招待コードなら連携してウェルカムメッセージを返す(以降のメッセージから通常の会話フローに入る)
     * ・一致しなければ招待コードの入力を促す
     * (家族向けの通知連携は、別チャネル「TAYORIサポート」のfamily_webhook.phpで扱う)
     * いずれの分岐でも replyToken を使い切って終了するため、呼び出し元はこの後の通常フローへは進まない。
     */
    private function resolveUnlinkedSender(string $lineUserId, string $messageText, string $lineMessageId, string $replyToken): void
    {
        $pendingUser = $this->userRepo->findByInviteCode($messageText);
        if ($pendingUser !== null) {
            $userId = (int) $pendingUser['id'];

            // LINEからの二重配信で連携イベントを2回処理しないよう、通常の会話フローと同じINSERT IGNOREでガードする
            $insertStmt = $this->pdo->prepare(
                'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content) VALUES (?, ?, "inbound", "text", ?)'
            );
            $insertStmt->execute([$userId, $lineMessageId, $messageText]);
            if ($insertStmt->rowCount() === 0) {
                error_log("Duplicate LINE link message ignored: {$lineMessageId}");
                return;
            }

            // この経路はLINEからメッセージが届いている時点で友だち追加済みであることが保証されているため、
            // followイベントを待たずその場でオンボード完了にしてよい
            $this->userRepo->linkLineUserId($userId, $lineUserId);
            $this->userRepo->markOnboarded($userId);
            $companionName = $pendingUser['companion_name'] ?: 'たより';
            // display_nameはこの時点では未取得のため名前で呼びかけず、呼び名は会話の中でさりげなく尋ねる
            $welcomeText = "はじめまして。お話し相手になります、{$companionName}です。これからよろしくお願いしますね。\nよかったら、何てお呼びすればいいか教えてください。";
            $this->pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
            )->execute([$userId, $welcomeText]);
            $this->lineClient->reply($replyToken, $welcomeText);
            return;
        }

        $this->lineClient->reply($replyToken, 'はじめまして。ご案内した招待コードを送っていただけますか?');
    }

    /**
     * needs_lookupで指定された種類を検索(DB、または"web"の場合はAnthropicのWeb検索ツール)し、
     * その結果をもとに2ターン目でAIに回答させる。
     * persons/schedulesの抽出は1ターン目の結果をそのまま使うので、ここでは返信文だけを作り直す。
     */
    private function performLookupAndAnswer(int $userId, array $history, string $userMessage, array $lookup, string $companionName): string
    {
        $type = $lookup['type'];

        // "web"はDB検索ではなくAnthropicのサーバー側Web検索ツールを使うので、カンマ区切りの類義語展開はせず
        // 検索語句をそのままanswerWithWebSearchへ渡す
        if ($type === 'web') {
            return $this->claudeClient->answerWithWebSearch($history, $userMessage, trim((string) $lookup['query']), $companionName);
        }

        // Claudeにはカンマ区切りで類義語・言い換えを複数出させている(単純な部分一致検索の精度を補うため)
        $terms = array_slice(
            array_map(fn($t) => mb_substr(trim($t), 0, 50), explode(',', (string) $lookup['query'])),
            0,
            5
        );

        switch ($type) {
            case 'schedule':
                $rows = $this->scheduleRepo->search($userId, $terms);
                $resultsText = empty($rows)
                    ? '該当する予定は見つかりませんでした。'
                    : implode("\n", array_map([ScheduleRepository::class, 'formatScheduleLine'], $rows));
                break;

            case 'person':
                $rows = $this->personRepo->search($userId, $terms);
                $resultsText = empty($rows)
                    ? '該当する人物は見つかりませんでした。'
                    : implode("\n", array_map([PersonRepository::class, 'formatPersonLine'], $rows));
                break;

            case 'conversation':
            default:
                $rows = $this->searchConversations($userId, $terms);
                $resultsText = empty($rows)
                    ? '該当する過去の会話は見つかりませんでした。'
                    : implode("\n", array_map(fn($r) => substr($r['created_at'], 0, 10) . ': ' . $r['content'], $rows));
                break;
        }

        return $this->claudeClient->answerWithLookup($history, $userMessage, $type, $resultsText, $companionName);
    }

    /**
     * 要約にも「今後の予定の正確な一覧」にも載らない、過去の会話そのものの検索(needs_lookup=conversation用)。
     * $terms は複数キーワード(いずれか1つでも一致すればヒット)。
     */
    private function searchConversations(int $userId, array $terms, int $limit = 10): array
    {
        $terms = array_values(array_filter(array_map('trim', $terms), fn($t) => $t !== ''));
        if (empty($terms)) {
            return [];
        }

        $conditions = [];
        $params = [$userId];
        foreach ($terms as $term) {
            $conditions[] = 'content LIKE ?';
            $params[] = '%' . $term . '%';
        }

        $stmt = $this->pdo->prepare(
            "SELECT content, created_at FROM conversations
             WHERE user_id = ? AND direction = 'inbound' AND (" . implode(' OR ', $conditions) . ")
             ORDER BY created_at DESC LIMIT ?"
        );
        $params[] = $limit;
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // $excludeConversationIdは、直前に保存したばかりの今回のinbound発言のid。これを除外しないと、
    // ここで取得した履歴の最後に今回の発言が含まれた状態で、呼び出し元がさらに$userMessageを
    // 末尾に追加することになり、同じ発言がAPIリクエスト内で2回連続する不具合になる(修正済み、2026-07-21)
    private function buildRecentHistory(int $userId, int $excludeConversationId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT direction, content FROM conversations WHERE user_id = ? AND id != ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $excludeConversationId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn($row) => [
            'role' => $row['direction'] === 'inbound' ? 'user' : 'assistant',
            'content' => $row['content'],
        ], $rows);
    }
}
