<?php
// 週次バッチ(cron想定)。プランに関わらず、ご家族向けLINE(TAYORIサポート)が連携済みの利用者について、
// 既存の4種類の要約(schedule/relationship/preference/routine)から「今週の様子」ダイジェストを作成し、
// 家族に送る。見守りアラート・安否チェックイン(寄り添いスタンダード以上限定)とは別軸の、
// ベーシックプランでも家族LINE連携に意味を持たせるための機能。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/SummaryRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$claude = new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'));
$summaryRepo = new SummaryRepository($pdo);
$familyRepo = new FamilyAccountRepository($pdo);
// 家族向け通知は「TAYORIサポート」チャネルから送る(利用者本人とのTAYORIチャネルとは別アカウント)
$familyLineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);
$isoDayOfWeek = (int) $now->format('N'); // 1(月)〜7(日)
$weekStart = (clone $now)->modify('-' . ($isoDayOfWeek - 1) . ' days')->format('Y-m-d');

$checkStmt = $pdo->prepare(
    'SELECT 1 FROM family_digest_log WHERE user_id = ? AND week_start = ?'
);
$insertStmt = $pdo->prepare(
    'INSERT IGNORE INTO family_digest_log (user_id, week_start) VALUES (?, ?)'
);

$users = $pdo->query(
    "SELECT id, display_name, companion_name FROM users WHERE status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);

$sentCount = 0;
$skipCount = 0;

foreach ($users as $user) {
    $userId = (int) $user['id'];

    $checkStmt->execute([$userId, $weekStart]);
    if ($checkStmt->fetch() !== false) {
        echo "[SKIP] user_id={$userId}: already sent for week {$weekStart}\n";
        $skipCount++;
        continue;
    }

    $recipients = $familyRepo->getNotifiableForUser($userId);
    if (empty($recipients)) {
        // 家族LINE未連携。連携され次第その週から対象になるよう、ここではログを残さない
        continue;
    }

    $summaries = $summaryRepo->getAllForUser($userId);
    $digest = $claude->generateFamilyDigest(
        $summaries,
        (string) ($user['display_name'] ?? '利用者'),
        (string) ($user['companion_name'] ?? 'TAYORI')
    );

    if ($digest === '') {
        echo "[SKIP] user_id={$userId}: no shareable summary content yet\n";
        continue;
    }

    $header = "【{$user['companion_name']}より、今週の{$user['display_name']}様の様子です】\n\n";
    $text = $header . $digest;

    $pushedToAny = false;
    foreach ($recipients as $recipient) {
        if ($familyLineClient->push($recipient['line_user_id'], $text)) {
            $pushedToAny = true;
        }
    }

    if ($pushedToAny) {
        $insertStmt->execute([$userId, $weekStart]);
        echo "[OK] user_id={$userId}: digest sent to " . count($recipients) . " recipient(s)\n";
        $sentCount++;
    } else {
        echo "[SKIP] user_id={$userId}: push failed for all recipients\n";
    }
}

echo "[DONE] family digest: sent={$sentCount}, skipped={$skipCount}, week_start={$weekStart}\n";
