<?php
// plansテーブルのうちstripe_price_idが未設定の行について、Stripe側にProduct/Priceを作成しIDを保存する。
// Stripeの価格(Price)は作成後イミュータブルなので、既存プランのprice_yenを変更した場合は
// このスクリプトでは追従しない(手動でstripe_price_idをNULLに戻すか、Stripeダッシュボードで新Priceを作り
// 手動でDBを更新すること)。CLI専用、migrate.phpとは別に手動実行する想定。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/config.php';
$pdo = Database::connect($dbConfig);
$planRepo = new PlanRepository($pdo);
$stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));

$targets = $planRepo->getPlansNeedingStripeSync();

if (empty($targets)) {
    echo "[OK] no plans need Stripe sync.\n";
    exit;
}

foreach ($targets as $plan) {
    try {
        $product = $stripe->createProduct($plan['name'], $plan['description'] ?? '');
        $price = $stripe->createPrice($product['id'], (int) $plan['price_yen']);
        $planRepo->setStripeIds((int) $plan['id'], $product['id'], $price['id']);
        echo "[OK] {$plan['code']}: product={$product['id']} price={$price['id']}\n";
    } catch (Throwable $e) {
        echo "[NG] {$plan['code']}: " . $e->getMessage() . "\n";
    }
}
