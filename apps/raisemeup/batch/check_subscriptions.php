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
require_once __DIR__ . '/../src/MailClient.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

// LINE未連携(users.status='pending')のまま何日で「放置」扱いにするか。後で調整しやすいよう定数化しておく
const ABANDON_TIMEOUT_DAYS = 3;

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
// 家族への通知は「TAYORIサポート」チャネル(利用者本人の会話用アカウントとは別)から送る
$lineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));

// SMTP未設定の間はメール送信自体をスキップする(LINE通知チャネルと同様、無ければ何もしないだけ)
$mailClient = null;
if (Config::get('SMTP_HOST', '') !== '') {
    $mailClient = new MailClient(
        Config::get('SMTP_HOST', ''),
        (int) Config::get('SMTP_PORT', '587'),
        Config::get('SMTP_USERNAME', ''),
        Config::get('SMTP_PASSWORD', ''),
        Config::get('SMTP_USERNAME', ''),
        Config::get('SMTP_FROM_NAME', 'TAYORI')
    );
}

// 家族のline_user_idが設定されていれば送信し、無ければ何もしない(通知チャネルが無いだけで、契約状態には影響しない)
function notifyFamily(LineClient $lineClient, ?string $familyLineUserId, string $text): void
{
    if ($familyLineUserId === null || $familyLineUserId === '') {
        return;
    }
    $lineClient->push($familyLineUserId, $text);
}

// familyのemailが設定されていれば送信し、無ければ何もしない。送信失敗はログに残すのみで処理は止めない
function notifyFamilyEmail(?MailClient $mailClient, ?string $email, string $subject, string $body): void
{
    if ($mailClient === null || empty($email)) {
        return;
    }
    try {
        $mailClient->send($email, $subject, $body);
    } catch (Throwable $e) {
        error_log('Reminder email send failed: ' . $e->getMessage());
    }
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
        $text = "【TAYORI】{$displayName}様の無料期間は、あと3日で終了します(" . substr($row['trial_ends_at'], 0, 10) . "まで)。"
            . "登録済みのお支払い方法で、自動的に{$priceText}のお支払いに移行します。特にお手続きは不要です。";
    } else {
        $text = "【TAYORI】{$displayName}様の無料期間は、あと3日で終了します(" . substr($row['trial_ends_at'], 0, 10) . "まで)。"
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
    $text = "【TAYORI】{$displayName}様の無料期間が終了しました。引き続きご利用いただくには、お支払い情報のご登録をお願いいたします。"
        . "なお、ご登録が完了するまでの間もサービスは通常通りご利用いただけます。";
    notifyFamily($lineClient, $row['family_line_user_id'], $text);
}
echo "[OK] subscriptions marked trial_expired: " . count($expired) . "\n";

// --- 3. LINE連携(友だち追加まで)が放置された申込みの検知 ---
// 本人(users.status='pending')・ご家族(family_accounts.line_user_id IS NULL、または
// LINEログインはしたが友だち追加がfriend_confirmed_at IS NULLでまだ確認できていない)の
// どちらか一方でも連携が完了しないままABANDON_TIMEOUT_DAYS日を超えた契約は、一度も使われていない
// サービスへの課金を防ぐためStripe契約をキャンセルし、再申込みができるようfamily_accounts.emailを
// 解放する(UNIQUE制約が永久に再申込みを妨げないように)。本人・家族とも「LINEログイン+友だち追加」
// までが必須のため、どちらか片方だけ済んでいても対象になる。
$stripeClient = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));
$subscriptionRepo = new SubscriptionRepository($pdo);
$familyRepo = new FamilyAccountRepository($pdo);
$resumeBaseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/') . '/apply/resume.php?u=';

$familyIncompleteCondition = '(fa.line_user_id IS NULL OR fa.friend_confirmed_at IS NULL)';

// 3a. 期限の1日前のリマインド(LINE連携ページへの再開リンクを送る)
$stmt = $pdo->query(
    "SELECT u.invite_code AS user_invite_code, u.display_name AS user_display_name,
            fa.email AS family_email, fa.name AS family_name,
            (u.status = 'pending') AS user_pending, " . $familyIncompleteCondition . " AS family_pending
     FROM subscriptions s
     JOIN users u ON u.id = s.user_id
     JOIN family_accounts fa ON fa.id = s.family_account_id
     WHERE s.status = 'trial' AND (u.status = 'pending' OR " . $familyIncompleteCondition . ")
       AND DATE(s.created_at) = DATE_SUB(CURDATE(), INTERVAL " . (ABANDON_TIMEOUT_DAYS - 1) . " DAY)"
);
$abandonReminderCount = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (empty($row['family_email'])) {
        continue;
    }
    $displayName = $row['user_display_name'] ?: 'ご利用者様';
    $pendingWho = ((bool) $row['user_pending'] && (bool) $row['family_pending'])
        ? "{$displayName}様・ご家族様どちらも"
        : ((bool) $row['user_pending'] ? "{$displayName}様の" : 'ご家族様の');
    $subject = '【TAYORI】LINE連携がまだ完了していません';
    $body = "{$row['family_name']}様\n\n"
        . "TAYORIへのお申込みありがとうございます。{$pendingWho}LINE連携(友だち追加を含む)がまだ完了していないようです。\n"
        . "ご本人・ご家族様どちらも、LINEログインと友だち追加の両方が必須のため、このまま完了しない場合、"
        . "明日にはお申込みを一旦キャンセルさせていただきます。\n\n"
        . (!empty($row['user_invite_code'])
            ? "以下のリンクから、LINE連携の手続きを再開できます。\n" . $resumeBaseUrl . urlencode($row['user_invite_code']) . "\n\n"
            : '')
        . "ご不明な点はsupport@tayori-net.jpまでご連絡ください。";
    notifyFamilyEmail($mailClient, $row['family_email'], $subject, $body);
    $abandonReminderCount++;
}
echo "[OK] abandonment reminder sent: {$abandonReminderCount}\n";

// 3b. 期限超過分の自動キャンセル。Stripeの解約に失敗した場合はabandoned化せず翌日に再試行する
// (課金停止を確認できていないのにemailを解放してしまうと、二重にサービスを使われる余地が生まれるため)
$stmt = $pdo->query(
    "SELECT s.id, s.payment_customer_ref, u.display_name AS user_display_name,
            fa.id AS family_id, fa.email AS family_email, fa.name AS family_name,
            (u.status = 'pending') AS user_pending, " . $familyIncompleteCondition . " AS family_pending
     FROM subscriptions s
     JOIN users u ON u.id = s.user_id
     JOIN family_accounts fa ON fa.id = s.family_account_id
     WHERE s.status = 'trial' AND (u.status = 'pending' OR " . $familyIncompleteCondition . ")
       AND s.created_at < DATE_SUB(NOW(), INTERVAL " . ABANDON_TIMEOUT_DAYS . " DAY)"
);
$abandonedCount = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!empty($row['payment_customer_ref'])) {
        try {
            $stripeClient->cancelSubscription($row['payment_customer_ref']);
        } catch (Throwable $e) {
            error_log("Stripe subscription cancel failed for subscription {$row['id']}: " . $e->getMessage());
            continue;
        }
    }

    $subscriptionRepo->markAbandoned((int) $row['id']);
    $familyRepo->clearEmail((int) $row['family_id']);

    if (!empty($row['family_email'])) {
        $displayName = $row['user_display_name'] ?: 'ご利用者様';
        $pendingWho = ((bool) $row['user_pending'] && (bool) $row['family_pending'])
            ? "{$displayName}様・ご家族様どちらの"
            : ((bool) $row['user_pending'] ? "{$displayName}様の" : 'ご家族様の');
        $subject = '【TAYORI】お申込みを一旦キャンセルしました';
        $body = "{$row['family_name']}様\n\n"
            . "{$pendingWho}LINE連携(友だち追加を含む)が完了しないまま" . ABANDON_TIMEOUT_DAYS . "日が経過したため、"
            . "お申込みを一旦キャンセルいたしました。料金は発生しておりません。\n\n"
            . "改めてご利用になりたい場合は、お手数ですが再度お申込みください。\n"
            . rtrim(Config::get('APP_BASE_URL', ''), '/') . "/apply/\n\n"
            . "ご不明な点はsupport@tayori-net.jpまでご連絡ください。";
        notifyFamilyEmail($mailClient, $row['family_email'], $subject, $body);
    }
    $abandonedCount++;
}
echo "[OK] abandoned subscriptions cancelled: {$abandonedCount}\n";
