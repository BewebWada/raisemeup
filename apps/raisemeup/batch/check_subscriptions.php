<?php
// 無料期間の状態を毎日チェックするバッチ(regenerate_summaries.phpと同様にcronで実行する想定)。
// - トライアル終了3日前: 家族へリマインドのLINE push通知(文言はStripeでカード登録済みか否かで出し分け)
// - トライアル終了時: カード未登録(payment_customer_ref無し)の契約のみsubscriptions.statusをtrial_expiredに
//   遷移させ、家族へ終了通知のLINE push通知。カード登録済み(Stripe管理下)の契約はStripe側で自動課金が
//   走り、その結果(成功/失敗)はstripe_webhook.php(invoice.payment_succeeded/failed)がstatusに反映するので、
//   このバッチでは触らない。
// 方針により、利用者本人(users.status)には一切触れない(支払い未設定でもBot応答は止めない)。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
// 家族への通知は「RaiseMeUpサポート」チャネル(利用者本人の会話用アカウントとは別)から送る
$lineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));

// 家族のline_user_idが設定されていれば送信し、無ければ何もしない(通知チャネルが無いだけで、契約状態には影響しない)
function notifyFamily(LineClient $lineClient, ?string $familyLineUserId, string $text): void
{
    if ($familyLineUserId === null || $familyLineUserId === '') {
        return;
    }
    $lineClient->push($familyLineUserId, $text);
}

// --- 1. トライアル終了3日前のリマインド ---
// trial_ends_atの「日付」がちょうど3日後のtrial契約だけを抽出するので、cronが毎日1回実行される限り
// 送信済みフラグを持たなくても自然に1回だけ発火する
$stmt = $pdo->query(
    "SELECT s.id, u.display_name AS user_display_name, fa.line_user_id AS family_line_user_id,
            p.name AS plan_name, p.price_yen, s.trial_ends_at, s.payment_customer_ref
     FROM subscriptions s
     JOIN users u ON u.id = s.user_id
     JOIN family_accounts fa ON fa.id = s.family_account_id
     JOIN plans p ON p.id = s.plan_id
     WHERE s.status = 'trial' AND DATE(s.trial_ends_at) = DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
);
$reminderCount = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $displayName = $row['user_display_name'] ?: 'ご利用者様';
    $priceText = "{$row['plan_name']}(月額" . number_format((int) $row['price_yen']) . "円)";
    if (!empty($row['payment_customer_ref'])) {
        // Stripeでカード登録済み: 何もしなくても自動課金される旨の案内のみ
        $text = "【Raise Me Up】{$displayName}様の無料期間は、あと3日で終了します(" . substr($row['trial_ends_at'], 0, 10) . "まで)。"
            . "登録済みのお支払い方法で、自動的に{$priceText}のお支払いに移行します。特にお手続きは不要です。";
    } else {
        $text = "【Raise Me Up】{$displayName}様の無料期間は、あと3日で終了します(" . substr($row['trial_ends_at'], 0, 10) . "まで)。"
            . "引き続きご利用いただくには、{$priceText}へのお支払い情報のご登録をお願いいたします。";
    }
    notifyFamily($lineClient, $row['family_line_user_id'], $text);
    $reminderCount++;
}
echo "[OK] trial reminder (3 days before) sent: {$reminderCount}\n";

// --- 2. トライアル終了(カード未登録=Stripe未連携の契約のみ対象) ---
// カード登録済みの契約はStripe側で自動課金が走り、その成否はstripe_webhook.phpがstatusに反映するので
// ここでは触らない(trial_expiredに倒してしまうとWebhookの'active'反映と競合する)。
$stmt = $pdo->query(
    "SELECT s.id, u.display_name AS user_display_name, fa.line_user_id AS family_line_user_id
     FROM subscriptions s
     JOIN users u ON u.id = s.user_id
     JOIN family_accounts fa ON fa.id = s.family_account_id
     WHERE s.status = 'trial' AND s.trial_ends_at < NOW() AND s.payment_customer_ref IS NULL"
);
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdo->exec(
    "UPDATE subscriptions SET status = 'trial_expired'
     WHERE status = 'trial' AND trial_ends_at < NOW() AND payment_customer_ref IS NULL"
);

foreach ($expired as $row) {
    $displayName = $row['user_display_name'] ?: 'ご利用者様';
    $text = "【Raise Me Up】{$displayName}様の無料期間が終了しました。引き続きご利用いただくには、お支払い情報のご登録をお願いいたします。"
        . "なお、ご登録が完了するまでの間もサービスは通常通りご利用いただけます。";
    notifyFamily($lineClient, $row['family_line_user_id'], $text);
}
echo "[OK] subscriptions marked trial_expired: " . count($expired) . "\n";
