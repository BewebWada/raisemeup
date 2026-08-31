<?php
// Stripeからのイベント通知を受けてsubscriptions.statusを同期する。
// トライアル終了後の実際の課金成功/失敗、解約はすべてStripe側が真実の情報源(source of truth)であり、
// このWebhookが唯一その結果をローカルDBに反映する経路(check_subscriptions.phpは通知バッチであり課金は行わない)。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/CancellationService.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$body = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''), Config::get('STRIPE_WEBHOOK_SECRET', ''));

try {
    $event = $stripe->constructEvent($body, $sigHeader);
} catch (Throwable $e) {
    error_log('Stripe webhook signature verification failed: ' . $e->getMessage());
    http_response_code(400);
    exit('Invalid signature');
}

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$subscriptionRepo = new SubscriptionRepository($pdo);
$userRepo = new UserRepository($pdo);
$familyRepo = new FamilyAccountRepository($pdo);
// 決済失敗の通知は「TAYORIサポート」チャネル(利用者本人の会話用アカウントとは別)から送る
$lineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
// 解約時のお別れメッセージは、利用者本人との会話用チャネルから送る
$userLineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
$cancellationService = new CancellationService($pdo, $stripe, $subscriptionRepo, $userRepo, $familyRepo, $userLineClient, MailClient::fromConfig());

$type = $event['type'] ?? '';
$object = $event['data']['object'] ?? [];

try {
    switch ($type) {
        case 'checkout.session.completed':
            handleCheckoutCompleted($subscriptionRepo, $object);
            break;

        case 'invoice.payment_succeeded':
            handleInvoicePaid($subscriptionRepo, $object);
            break;

        case 'invoice.payment_failed':
            handleInvoicePaymentFailed($pdo, $subscriptionRepo, $lineClient, $object);
            break;

        case 'customer.subscription.updated':
            handleSubscriptionUpdated($subscriptionRepo, $object);
            break;

        case 'customer.subscription.deleted':
            $sub = $subscriptionRepo->findByStripeSubscriptionId((string) ($object['id'] ?? ''));
            // 放置(未連携タイムアウト)による自動キャンセル(check_subscriptions.php)は既に
            // markAbandonedで処理済みのため、ここでは対象外にする(上書きしない)
            if ($sub !== null && $sub['status'] !== 'abandoned') {
                $subscriptionRepo->markCancelled((int) $sub['id']);
                $cancellationService->finalizeTermination((int) $sub['user_id'], (int) $sub['family_account_id']);
            }
            break;

        default:
            // 未対応のイベント種別は無視してよい(Stripe側には200を返す)
            break;
    }
} catch (Throwable $e) {
    error_log("Stripe webhook handling failed ({$type}): " . $e->getMessage());
    // 500を返すとStripeが自動リトライしてくれるので、想定外の失敗はここで返す
    http_response_code(500);
    exit('Internal error');
}

http_response_code(200);
echo 'OK';

function handleCheckoutCompleted(SubscriptionRepository $subscriptionRepo, array $session): void
{
    if (($session['mode'] ?? '') !== 'subscription') {
        return;
    }
    $localSubscriptionId = (int) ($session['client_reference_id'] ?? 0);
    $stripeSubscriptionId = (string) ($session['subscription'] ?? '');
    if ($localSubscriptionId === 0 || $stripeSubscriptionId === '') {
        return;
    }
    $subscriptionRepo->attachStripeSubscription($localSubscriptionId, $stripeSubscriptionId);
}

function handleInvoicePaid(SubscriptionRepository $subscriptionRepo, array $invoice): void
{
    $stripeSubscriptionId = (string) ($invoice['subscription'] ?? '');
    if ($stripeSubscriptionId === '') {
        return;
    }
    // trial_period_daysを指定してSubscriptionを作成すると、Stripeはトライアル開始と同時に
    // 金額0円の請求書を自動発行しinvoice.payment_succeededを送ってくる(実際の課金ではない)。
    // これをそのまま「支払い済み」として扱うと、トライアル期間中にもかかわらずstatusが
    // 'active'に変わってしまい、マイページの表示や無料期間終了リマインドが崩れるため無視する
    if ((int) ($invoice['amount_paid'] ?? 0) === 0) {
        return;
    }
    $sub = $subscriptionRepo->findByStripeSubscriptionId($stripeSubscriptionId);
    if ($sub === null) {
        return;
    }
    $periodEnd = isset($invoice['period_end']) ? unixToJst((int) $invoice['period_end']) : null;
    $subscriptionRepo->markActive((int) $sub['id'], $periodEnd);
}

function handleInvoicePaymentFailed(PDO $pdo, SubscriptionRepository $subscriptionRepo, LineClient $lineClient, array $invoice): void
{
    $stripeSubscriptionId = (string) ($invoice['subscription'] ?? '');
    if ($stripeSubscriptionId === '') {
        return;
    }
    $sub = $subscriptionRepo->findByStripeSubscriptionId($stripeSubscriptionId);
    if ($sub === null) {
        return;
    }
    $subscriptionRepo->markPastDue((int) $sub['id']);

    $stmt = $pdo->prepare(
        'SELECT u.display_name AS user_display_name, u.full_name AS user_full_name, fa.line_user_id AS family_line_user_id, p.name AS plan_name
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN family_accounts fa ON fa.id = s.family_account_id
         JOIN plans p ON p.id = s.plan_id
         WHERE s.id = ?'
    );
    $stmt->execute([$sub['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false || empty($row['family_line_user_id'])) {
        return;
    }
    // 家族への通知は、会話用の呼び名ではなくマイページ登録の氏名を優先する
    $displayName = $row['user_full_name'] ?: $row['user_display_name'] ?: 'ご利用者様';
    $text = "【TAYORI】{$displayName}様の{$row['plan_name']}のお支払いに失敗しました。"
        . "登録されているカード情報をご確認のうえ、Stripeからの請求メールに記載のリンクよりお支払い情報の更新をお願いいたします。"
        . "なお、更新が完了するまでの間もサービスは通常通りご利用いただけます。";
    $lineClient->push($row['family_line_user_id'], $text);
}

function handleSubscriptionUpdated(SubscriptionRepository $subscriptionRepo, array $subscription): void
{
    $sub = $subscriptionRepo->findByStripeSubscriptionId((string) ($subscription['id'] ?? ''));
    if ($sub === null) {
        return;
    }

    // マイページの解約ボタンだけでなく、Stripeの顧客ポータルから直接解約(予約)された場合も
    // 表示上の解約予定日を同期しておく。cancel_at_period_endが外れた(予約取消)場合はクリアする
    if (!empty($subscription['cancel_at_period_end']) && isset($subscription['cancel_at'])) {
        $subscriptionRepo->scheduleCancellation((int) $sub['id'], unixToJst((int) $subscription['cancel_at']));
    } elseif (empty($subscription['cancel_at_period_end']) && $sub['cancel_at'] !== null) {
        $subscriptionRepo->clearScheduledCancellation((int) $sub['id']);
    }

    $status = mapStripeSubscriptionStatus((string) ($subscription['status'] ?? ''));
    if ($status === null) {
        return;
    }
    $periodEnd = isset($subscription['current_period_end']) ? unixToJst((int) $subscription['current_period_end']) : null;
    switch ($status) {
        case 'active':
            $subscriptionRepo->markActive((int) $sub['id'], $periodEnd);
            break;
        case 'past_due':
            $subscriptionRepo->markPastDue((int) $sub['id']);
            break;
        case 'cancelled':
            $subscriptionRepo->markCancelled((int) $sub['id']);
            break;
    }
}

// trialing(こちらのtrial_ends_atで既に管理中)は意図的に対象外(null)にしている
function mapStripeSubscriptionStatus(string $stripeStatus): ?string
{
    return match ($stripeStatus) {
        'active' => 'active',
        'past_due', 'unpaid', 'paused' => 'past_due',
        'canceled', 'incomplete_expired' => 'cancelled',
        default => null,
    };
}

function unixToJst(int $timestamp): string
{
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return $dt->format('Y-m-d H:i:s');
}
