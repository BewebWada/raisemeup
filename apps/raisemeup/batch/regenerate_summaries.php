<?php
// リアルタイムのLINE Webhook処理とは別に、定期実行(cron等)で呼び出す想定のバッチ。
// 会話ログ・予定・人物データから4種類の要約(schedule/relationship/preference/routine)を
// user_summariesに反映し、あわせて過去の予定をcompletedにする。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/SummaryRepository.php';
require_once __DIR__ . '/../src/ScheduleRepository.php';
require_once __DIR__ . '/../src/MedicationLogRepository.php';
require_once __DIR__ . '/../src/ConversationInsightRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$claude = ClaudeClient::fromConfig();
$summaryRepo = new SummaryRepository($pdo);
$medicationLogRepo = new MedicationLogRepository($pdo);
$insightRepo = new ConversationInsightRepository($pdo);

// --- 0a. 過去の予定をcompletedにする(期間の場合は終了日、単発なら開始日を基準に、今日より前なら完了扱い) ---
$completedCount = $pdo->exec(
    "UPDATE schedules SET status = 'completed'
     WHERE status = 'upcoming'
       AND COALESCE(scheduled_end_at, scheduled_at) IS NOT NULL
       AND COALESCE(scheduled_end_at, scheduled_at) < CURDATE()"
);
echo "[OK] schedules marked completed: {$completedCount}\n";

// --- 0b. 日付がついに確定しなかった古い予定(「そのうち」等)をcancelledにする。
//          これが無いと、日付未定のままの予定だけは自動completedの対象にならず永遠に残ってしまう ---
$staleCount = $pdo->exec(
    "UPDATE schedules SET status = 'cancelled'
     WHERE status = 'upcoming'
       AND scheduled_at IS NULL AND scheduled_end_at IS NULL
       AND created_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
);
echo "[OK] schedules with no date, marked cancelled after 90 days: {$staleCount}\n";

// --- 0c. リマインド送信済みだが確認が取れないまま日をまたいだ服薬記録を「見送り」として確定する ---
$missedMedicationCount = $medicationLogRepo->markStalePendingAsMissed();
echo "[OK] medication logs marked missed: {$missedMedicationCount}\n";

function regenerateScheduleSummary(PDO $pdo, ClaudeClient $claude, SummaryRepository $summaryRepo, int $userId): void
{
    $scheduleRepo = new ScheduleRepository($pdo);
    $rows = $scheduleRepo->getUpcomingDetailsByUserId($userId);

    if (empty($rows)) {
        $summaryRepo->upsert($userId, 'schedule', '現在登録されている予定はありません。', null);
        echo "  [OK] schedule summary: no data\n";
        return;
    }

    $lines = array_map([ScheduleRepository::class, 'formatScheduleLine'], $rows);

    $summary = $claude->summarize('schedule', implode("\n", $lines));
    if ($summary === '') {
        echo "  [SKIP] schedule summary: generation failed\n";
        return;
    }
    $summaryRepo->upsert($userId, 'schedule', $summary, null);
    echo "  [OK] schedule summary updated\n";
}

function regenerateRelationshipSummary(PDO $pdo, ClaudeClient $claude, SummaryRepository $summaryRepo, int $userId): void
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.canonical_name, p.mention_count, p.notes,
                pa.attribute_type, pa.attribute_value
         FROM persons p
         LEFT JOIN person_attributes pa ON pa.person_id = p.id AND pa.is_current = 1
         WHERE p.user_id = ?
         ORDER BY p.mention_count DESC"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $summaryRepo->upsert($userId, 'relationship', 'まだ人物の情報は記録されていません。', null);
        echo "  [OK] relationship summary: no data\n";
        return;
    }

    $byPerson = [];
    foreach ($rows as $r) {
        $key = $r['id'];
        if (!isset($byPerson[$key])) {
            $byPerson[$key] = [
                'name' => $r['canonical_name'],
                'mentions' => $r['mention_count'],
                'notes' => $r['notes'],
                'attrs' => [],
            ];
        }
        if ($r['attribute_type']) {
            $byPerson[$key]['attrs'][] = "{$r['attribute_type']}:{$r['attribute_value']}";
        }
    }

    $lines = [];
    foreach ($byPerson as $p) {
        $line = "・{$p['name']}(言及{$p['mentions']}回)";
        if (!empty($p['attrs'])) {
            $line .= ' / ' . implode(', ', $p['attrs']);
        }
        if ($p['notes']) {
            $line .= ' / メモ:' . $p['notes'];
        }
        $lines[] = $line;
    }

    $summary = $claude->summarize('relationship', implode("\n", $lines));
    if ($summary === '') {
        echo "  [SKIP] relationship summary: generation failed\n";
        return;
    }
    $summaryRepo->upsert($userId, 'relationship', $summary, null);
    echo "  [OK] relationship summary updated\n";
}

function regenerateConversationBasedSummary(PDO $pdo, ClaudeClient $claude, SummaryRepository $summaryRepo, int $userId, string $type): void
{
    $existing = $summaryRepo->getRawForUser($userId)[$type] ?? null;
    $previousContent = $existing['content'] ?? '';
    $lastMaxId = (int) ($existing['source_conversation_max_id'] ?? 0);

    // AI側の質問文(outbound)も含めて渡す。利用者の発言だけを切り出すと、何の質問に対する返答かという
    // 文脈が失われ、代名詞の指示先などをAIが誤って断定してしまうことがあるため(例:「ここ」が指す場所の誤読)。
    $stmt = $pdo->prepare(
        "SELECT id, direction, content FROM conversations
         WHERE user_id = ? AND id > ? AND content IS NOT NULL
         ORDER BY id ASC LIMIT 300"
    );
    $stmt->execute([$userId, $lastMaxId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "  [SKIP] {$type} summary: no new conversations since last run\n";
        return;
    }

    $newMaxId = $lastMaxId;
    $newTextParts = [];
    foreach ($rows as $r) {
        $label = $r['direction'] === 'outbound' ? '自分' : '利用者';
        $newTextParts[] = "{$label}: {$r['content']}";
        $newMaxId = (int) $r['id'];
    }
    $newText = implode("\n", $newTextParts);

    $sourceText = $previousContent !== ''
        ? "【これまでの要約】\n{$previousContent}\n\n【新しい発言】\n{$newText}"
        : $newText;

    $summary = $claude->summarize($type, $sourceText);
    if ($summary === '') {
        echo "  [SKIP] {$type} summary: generation failed\n";
        return;
    }
    $summaryRepo->upsert($userId, $type, $summary, $newMaxId);
    echo "  [OK] {$type} summary updated (through conversation id {$newMaxId})\n";
}

// preference/routineは利用者自身の発言(inbound)だけを見れば十分だが、こちらはAI自身の受け答えの
// 不自然さを自己レビューする用途なので、inbound/outbound両方を時系列で並べて渡す必要がある。
// あわせて、利用者からの要望・会話中のトラブルをconversation_insightsに運営者向けログとして残す
function regenerateConversationNotesSummary(PDO $pdo, ClaudeClient $claude, SummaryRepository $summaryRepo, ConversationInsightRepository $insightRepo, int $userId): void
{
    $type = 'conversation_notes';
    $existing = $summaryRepo->getRawForUser($userId)[$type] ?? null;
    $previousContent = $existing['content'] ?? '';
    $lastMaxId = (int) ($existing['source_conversation_max_id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT id, direction, content FROM conversations
         WHERE user_id = ? AND id > ? AND content IS NOT NULL
         ORDER BY id ASC LIMIT 300"
    );
    $stmt->execute([$userId, $lastMaxId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "  [SKIP] {$type} summary: no new conversations since last run\n";
        return;
    }

    $newMaxId = $lastMaxId;
    $newTextParts = [];
    foreach ($rows as $r) {
        $label = $r['direction'] === 'outbound' ? '自分' : '利用者';
        $newTextParts[] = "{$label}: {$r['content']}";
        $newMaxId = (int) $r['id'];
    }
    $newText = implode("\n", $newTextParts);

    $sourceText = $previousContent !== ''
        ? "【これまでの自己レビューメモ】\n{$previousContent}\n\n【新しい会話ログ】\n{$newText}"
        : $newText;

    $result = $claude->reviewConversation($sourceText);
    if ($result['self_review'] === '') {
        echo "  [SKIP] {$type} summary: generation failed\n";
        return;
    }
    $summaryRepo->upsert($userId, $type, $result['self_review'], $newMaxId);
    echo "  [OK] {$type} summary updated (through conversation id {$newMaxId})\n";

    foreach ($result['insights'] as $insight) {
        $insightRepo->insert($userId, $insight['type'], $insight['content'], $newMaxId);
    }
    if (!empty($result['insights'])) {
        echo '  [OK] conversation_insights recorded: ' . count($result['insights']) . "\n";
    }
}

// --- 1. アクティブな利用者ごとに5種類の要約を再生成 ---
$users = $pdo->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($users as $userId) {
    $userId = (int) $userId;
    echo "--- user_id={$userId} ---\n";
    regenerateScheduleSummary($pdo, $claude, $summaryRepo, $userId);
    regenerateRelationshipSummary($pdo, $claude, $summaryRepo, $userId);
    regenerateConversationBasedSummary($pdo, $claude, $summaryRepo, $userId, 'preference');
    regenerateConversationBasedSummary($pdo, $claude, $summaryRepo, $userId, 'routine');
    regenerateConversationNotesSummary($pdo, $claude, $summaryRepo, $insightRepo, $userId);
}

echo "[DONE] summary regeneration complete for " . count($users) . " user(s)\n";
