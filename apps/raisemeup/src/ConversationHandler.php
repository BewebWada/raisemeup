<?php
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/LineClient.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/FamilyAccountRepository.php';
require_once __DIR__ . '/PersonRepository.php';
require_once __DIR__ . '/ScheduleRepository.php';
require_once __DIR__ . '/RiskDetector.php';
require_once __DIR__ . '/SummaryRepository.php';

class ConversationHandler
{
    private PDO $pdo;
    private LineClient $lineClient;
    private ClaudeClient $claudeClient;
    private UserRepository $userRepo;
    private FamilyAccountRepository $familyRepo;
    private PersonRepository $personRepo;
    private ScheduleRepository $scheduleRepo;
    private RiskDetector $riskDetector;
    private SummaryRepository $summaryRepo;

    public function __construct(PDO $pdo, LineClient $lineClient, ClaudeClient $claudeClient)
    {
        $this->pdo = $pdo;
        $this->lineClient = $lineClient;
        $this->claudeClient = $claudeClient;
        $this->userRepo = new UserRepository($pdo);
        $this->familyRepo = new FamilyAccountRepository($pdo);
        $this->personRepo = new PersonRepository($pdo);
        $this->scheduleRepo = new ScheduleRepository($pdo);
        $this->riskDetector = new RiskDetector($pdo);
        $this->summaryRepo = new SummaryRepository($pdo);
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

        // ④ Claude API呼び出し
        $history = $this->buildRecentHistory((int) $user['id']);
        // どちらも上限付き(人物・予定がどれだけ蓄積されてもプロンプトサイズを頭打ちにするため)。
        // 上限からあふれた分はrelationship/schedule要約側でカバーされる
        $knownPersons = $this->personRepo->getNamesByUserId((int) $user['id'], 30);
        $knownSchedules = $this->scheduleRepo->getUpcomingDetailsByUserId((int) $user['id'], 15);
        $summaries = $this->summaryRepo->getAllForUser((int) $user['id']);
        $result = $this->claudeClient->generateReplyAndExtract($history, $userMessage, $knownPersons, $knownSchedules, $summaries);

        // ⑤ 人物・予定の抽出結果をUPSERT
        foreach ($result['persons'] ?? [] as $person) {
            $this->personRepo->upsert((int) $user['id'], $person, $conversationId);
        }
        foreach ($result['schedules'] ?? [] as $schedule) {
            $this->scheduleRepo->upsert((int) $user['id'], $schedule, $conversationId);
        }

        // ⑤.5 要約・正確な一覧だけでは自信を持って答えられないとAIが判断した場合、DBを検索して2ターン目で回答し直す
        $replyText = $result['reply_text'] ?? '';
        $lookup = $result['needs_lookup'] ?? null;
        if (is_array($lookup) && in_array($lookup['type'] ?? null, ['schedule', 'person', 'conversation'], true) && !empty(trim((string) ($lookup['query'] ?? '')))) {
            $replyText = $this->performLookupAndAnswer((int) $user['id'], $history, $userMessage, $lookup);
        }

        // ⑥ リスク検知(キーワードマッチング)
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
            // 家族への通知は今回のスコープ外(後続フェーズで実装)
        }

        // ⑦ outbound会話を記録
        $this->pdo->prepare(
            'INSERT INTO conversations (user_id, direction, message_type, content, claude_model) VALUES (?, "outbound", "text", ?, ?)'
        )->execute([$user['id'], $replyText, Config::get('CLAUDE_MODEL')]);

        // ⑧ LINEへ返信
        $this->lineClient->reply($replyToken, $replyText);
    }

    /**
     * まだusersに(line_user_idで)紐付いていない送信元からのメッセージを処理する。
     * ・既に通知専用として連携済みの家族アカウントなら定型文を返す
     * ・利用者本人の招待コードなら連携してウェルカムメッセージを返す(以降のメッセージから通常の会話フローに入る)
     * ・家族の招待コード(通知を受け取りたい場合の任意コード)なら連携して定型文を返す
     * ・どれにも一致しなければ招待コードの入力を促す
     * いずれの分岐でも replyToken を使い切って終了するため、呼び出し元はこの後の通常フローへは進まない。
     */
    private function resolveUnlinkedSender(string $lineUserId, string $messageText, string $lineMessageId, string $replyToken): void
    {
        $family = $this->familyRepo->findByLineUserId($lineUserId);
        if ($family !== null) {
            $this->lineClient->reply($replyToken, '通知専用のアカウントとして登録済みです。ご本人様の見守り会話には、ご本人様のLINEアカウントをご利用ください。');
            return;
        }

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
            $welcomeText = "はじめまして。{$displayName}さんのお話し相手になります、Raise Me Upです。これからよろしくお願いしますね。";
            $this->pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
            )->execute([$userId, $welcomeText]);
            $this->lineClient->reply($replyToken, $welcomeText);
            return;
        }

        $pendingFamily = $this->familyRepo->findByInviteCode($messageText);
        if ($pendingFamily !== null) {
            $this->familyRepo->linkLineUserId((int) $pendingFamily['id'], $lineUserId);
            $this->lineClient->reply($replyToken, '通知専用のアカウントとして登録しました。無料期間の終了案内などをこちらにお送りします。');
            return;
        }

        $this->lineClient->reply($replyToken, 'はじめまして。ご案内した招待コードを送っていただけますか?');
    }

    /**
     * needs_lookupで指定された種類をDBから検索し、その結果をもとに2ターン目でAIに回答させる。
     * persons/schedulesの抽出は1ターン目の結果をそのまま使うので、ここでは返信文だけを作り直す。
     */
    private function performLookupAndAnswer(int $userId, array $history, string $userMessage, array $lookup): string
    {
        $type = $lookup['type'];
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

        return $this->claudeClient->answerWithLookup($history, $userMessage, $type, $resultsText);
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

    private function buildRecentHistory(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT direction, content FROM conversations WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn($row) => [
            'role' => $row['direction'] === 'inbound' ? 'user' : 'assistant',
            'content' => $row['content'],
        ], $rows);
    }
}
