<?php
// 計画メンテナンスの事前告知を、利用者本人(TAYORI)とご家族(TAYORIサポート)双方のLINEへ一斉送信する。
// cronには登録しない想定の「手動実行」バッチ。メンテナンス前にSSHで直接叩く。
// 誤爆(二重送信・宛先ミス)を防ぐため、--sendを付けない限りは実送信せず件数と本文プレビューだけ表示する。
//
// 使い方:
//   php announce_maintenance.php "8/10(月) 2:00〜3:00"            ドライラン(何も送らない)
//   php announce_maintenance.php "8/10(月) 2:00〜3:00" --send      実際に送信する
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

$timeRange = $argv[1] ?? '';
$dryRun = !in_array('--send', $argv, true);

if ($timeRange === '') {
    fwrite(STDERR, "使い方: php announce_maintenance.php \"8/10(月) 2:00〜3:00\" [--send]\n");
    exit(1);
}

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);
// 利用者本人向け(TAYORI)とご家族向け(TAYORIサポート)は別チャネルなので送り分ける
$lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
$familyLineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));

$users = $pdo->query(
    "SELECT id, line_user_id, display_name, full_name, companion_name FROM users WHERE status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);

echo ($dryRun ? "[DRY RUN] " : "[SEND] ") . "対象時間帯: {$timeRange}\n";
echo "--- 利用者向けメッセージ例 ---\n";
echo userMessage($timeRange, $users[0]['companion_name'] ?? 'TAYORI') . "\n\n";
echo "--- ご家族向けメッセージ例 ---\n";
echo familyMessage($timeRange, $users[0]['companion_name'] ?? 'TAYORI', '〇〇') . "\n\n";

$userSent = 0;
$familySent = 0;

foreach ($users as $user) {
    if (empty($user['line_user_id'])) {
        continue;
    }
    $text = userMessage($timeRange, $user['companion_name'] ?: 'TAYORI');
    if ($dryRun) {
        $userSent++;
    } elseif ($lineClient->push($user['line_user_id'], $text)) {
        $userSent++;
    } else {
        error_log("announce_maintenance: push failed for user_id={$user['id']}");
    }

    $familyFacingName = (string) ($user['full_name'] ?: $user['display_name'] ?: '利用者');
    $recipients = $familyRepo->getNotifiableForUser((int) $user['id']);
    foreach ($recipients as $recipient) {
        $familyText = familyMessage($timeRange, $user['companion_name'] ?: 'TAYORI', $familyFacingName);
        if ($dryRun) {
            $familySent++;
        } elseif ($familyLineClient->push($recipient['line_user_id'], $familyText)) {
            $familySent++;
        } else {
            error_log("announce_maintenance: family push failed for family_account_id={$recipient['id']}");
        }
    }
}

echo "[DONE] " . ($dryRun ? "送信対象" : "送信済み") . ": 利用者={$userSent}件, ご家族={$familySent}件\n";
if ($dryRun) {
    echo "実際に送信するには --send を付けて再実行してください\n";
}

function userMessage(string $timeRange, string $companionName): string
{
    return "{$companionName}です。\n\n"
        . "{$timeRange}の間、システムメンテナンスのため、しばらくお返事ができなくなります。\n"
        . "終わり次第、また今まで通りお話しできますので、ご了承ください。";
}

function familyMessage(string $timeRange, string $companionName, string $familyFacingName): string
{
    return "【{$companionName}より】\n\n"
        . "{$timeRange}の間、システムメンテナンスのため、{$familyFacingName}様とのやり取り(お返事・見守り通知)が一時的に停止します。\n"
        . "終了次第、通常通り再開しますので、ご了承ください。";
}
