<?php
// マイページの「お支払いを管理」ボタンの遷移先。Stripeの顧客ポータルセッションを発行してリダイレクトするだけ
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$familyId = $_SESSION['mypage_family_id'] ?? null;
if ($familyId === null) {
    header('Location: /mypage/');
    exit;
}

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$family = (new FamilyAccountRepository($pdo))->find((int) $familyId);

if ($family === null || empty($family['stripe_customer_id'])) {
    header('Location: /mypage/');
    exit;
}

// カード未登録時の「カードを登録する」ボタンからは ?flow=payment_method_update を付けてもらい、
// ポータルのトップ画面を経由せずカード追加画面へ直接ディープリンクする
$allowedFlows = ['payment_method_update'];
$flowType = $_GET['flow'] ?? null;
if (!in_array($flowType, $allowedFlows, true)) {
    $flowType = null;
}

try {
    $stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));
    $baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
    $portalSession = $stripe->createBillingPortalSession($family['stripe_customer_id'], $baseUrl . '/mypage/', $flowType);
    header('Location: ' . $portalSession['url']);
    exit;
} catch (Throwable $e) {
    error_log('Stripe billing portal session creation failed: ' . $e->getMessage());
    header('Location: /mypage/?billing_error=1');
    exit;
}
