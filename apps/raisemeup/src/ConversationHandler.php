<?php
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/LineClient.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/PersonRepository.php';
require_once __DIR__ . '/ScheduleRepository.php';
require_once __DIR__ . '/MedicationLogRepository.php';
require_once __DIR__ . '/RiskDetector.php';
require_once __DIR__ . '/RiskEventRepository.php';
require_once __DIR__ . '/FamilyAccountRepository.php';
require_once __DIR__ . '/FamilyMessageRepository.php';
require_once __DIR__ . '/FamilyThemeRepository.php';
require_once __DIR__ . '/SubscriptionRepository.php';
require_once __DIR__ . '/SummaryRepository.php';
require_once __DIR__ . '/WeatherClient.php';

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
    private SubscriptionRepository $subscriptionRepo;
    private SummaryRepository $summaryRepo;
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
        $this->subscriptionRepo = new SubscriptionRepository($pdo);
        $this->summaryRepo = new SummaryRepository($pdo);
        $this->weatherClient = new WeatherClient($pdo);
    }

    public function handleTextMessage(array $event): void
    {
        $lineUserId = $event['source']['userId'];
        $userMessage = $event['message']['text'];
        $lineMessageId = $event['message']['id'];
        $replyToken = $event['replyToken'];

        // ① 送信元の判定。利用者本人としてLINE連携済みでなければ、招待コードでの連携や
        //    通知専用の家族アカウントへの対応だけを行い、通常の会話フロー(Claude呼び出し)へは進まない
        $user = $this->userRepo->findByLineUserId($lineUserId);
        if ($user === null) {
            $this->resolveUnlinkedSender($lineUserId, trim($userMessage), $lineMessageId, $replyToken);
            return;
        }

        // ②.5 即レスだと機械的な印象になるため、「入力中...」アニメーションを表示しておく。
        // 実際の一時停止は返信直前(⑦.5)で行うので、ここでは長めの上限を渡しておくだけでよい
        $this->lineClient->startLoadingAnimation($lineUserId, 20);

        // ③ inbound会話を記録(LINEからの重複配信はline_message_idのUNIQUE制約でIGNOREされる)
        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (user_id, line_message_id, direction, message_type, content) VALUES (?, ?, "inbound", "text", ?)'
        );
        $insertStmt->execute([$user['id'], $lineMessageId, $userMessage]);

        if ($insertStmt->rowCount() === 0) {
            // 既に処理済みのメッセージ(再送)。二重返信・二重処理を避けるためここで終了する
            error_log("Duplicate LINE message ignored: {$lineMessageId}");
            return;
        }
        $conversationId = (int) $this->pdo->lastInsertId();

        // ③.5 直近で「危機感のある」安否確認通知(send_proactive_messages.phpのURGENT_SILENCE_HOURS超過)が
        // 家族に送られていた場合、今回inboundが確認できた時点で「連絡が取れました」の解除連絡を送る。
        // Claude呼び出しの成否に関わらず安否確認としての価値があるので、なるべく早い段階で行う
        $this->resolveUrgentSilenceIfPending((int) $user['id'], (string) $user['display_name']);

        // ④ Claude API呼び出し
        $history = $this->buildRecentHistory((int) $user['id'], $conversationId);
        // どちらも上限付き(人物・予定がどれだけ蓄積されてもプロンプトサイズを頭打ちにするため)。
        // 上限からあふれた分はrelationship/schedule要約側でカバーされる
        $knownPersons = $this->personRepo->getNamesByUserId((int) $user['id'], 30);
        $knownSchedules = $this->scheduleRepo->getUpcomingDetailsByUserId((int) $user['id'], 15);
        $summaries = $this->summaryRepo->getAllForUser((int) $user['id']);
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
            $medicationStatusToday
        );

        // ④.5 ご家族からの伝言を今回の返信で伝えられたと判定された場合、配信済みにして家族へ確認連絡を送る
        if (!empty($pendingFamilyMessages) && !empty($result['family_message_delivered'])) {
            $this->familyMessageRepo->markDelivered(array_column($pendingFamilyMessages, 'id'));
            $this->notifyFamilyOfMessageDelivered((int) $user['id'], (string) $user['display_name']);
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

        // ⑤.5 要約・正確な一覧だけでは自信を持って答えられないとAIが判断した場合、DBを検索して2ターン目で回答し直す
        $replyText = $result['reply_text'] ?? '';
        $lookup = $result['needs_lookup'] ?? null;
        if (is_array($lookup) && in_array($lookup['type'] ?? null, ['schedule', 'person', 'conversation', 'web'], true) && !empty(trim((string) ($lookup['query'] ?? '')))) {
            $replyText = $this->performLookupAndAnswer((int) $user['id'], $history, $userMessage, $lookup, $companionName);
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

        // ⑥ リスク検知(キーワードマッチング)。詐欺だけでなく、健康・安全に関する気になる発言も対象
        $risk = $this->riskDetector->check($userMessage);
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
                $this->notifyFamilyOfRisk((int) $user['id'], (string) $user['display_name'], $risk, $userMessage, $riskEventId);
            }
        }

        // ⑦ outbound会話を記録
        $this->pdo->prepare(
            'INSERT INTO conversations (user_id, direction, message_type, content, claude_model) VALUES (?, "outbound", "text", ?, ?)'
        )->execute([$user['id'], $replyText, Config::get('CLAUDE_MODEL')]);

        // ⑦.5 文章の長さに応じて一呼吸置く(即レスの機械的な印象を避けるため)。
        // replyTokenの有効期限に余裕を持たせるため、上限は控えめにしている
        usleep($this->typingDelayMicroseconds($replyText));

        // ⑧ LINEへ返信
        $this->lineClient->reply($replyToken, $replyText);
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
        if ($user === null) {
            return; // 未連携の送信元からの位置情報は扱わない(招待コード連携はテキストメッセージのみ対応)
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
            (string) $user['display_name'],
            ['latitude' => $latitude, 'longitude' => $longitude]
        );

        $this->lineClient->reply($replyToken, '教えてくれてありがとうございます。安心しました。');
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

            $this->userRepo->linkLineUserId($userId, $lineUserId);
            $displayName = $pendingUser['display_name'] ?: 'あなた';
            $companionName = $pendingUser['companion_name'] ?: 'たより';
            $welcomeText = "はじめまして。{$displayName}さんのお話し相手になります、{$companionName}です。これからよろしくお願いしますね。";
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
