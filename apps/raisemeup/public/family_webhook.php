<?php
// 「RaiseMeUpサポート」(ご家族向け通知専用アカウント)のWebhook。
// 利用者本人との会話ループ(webhook.php)とは別チャネル・別エンドポイント。
// Claudeは呼び出さず、招待コードでの連携判定のみを行う軽量な処理。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

$lineClient = new LineClient(
    Config::get('LINE_FAMILY_CHANNEL_SECRET'),
    Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN')
);

if (!$lineClient->verifySignature($body, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

$events = json_decode($body, true)['events'] ?? [];

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);

foreach ($events as $event) {
    if (($event['type'] ?? '') !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
        continue;
    }
    try {
        handleFamilyMessage($familyRepo, $lineClient, $event);
    } catch (Throwable $e) {
        error_log('Family webhook event handling failed: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';

function handleFamilyMessage(FamilyAccountRepository $familyRepo, LineClient $lineClient, array $event): void
{
    $lineUserId = $event['source']['userId'];
    $messageText = trim($event['message']['text']);
    $replyToken = $event['replyToken'];

    $existing = $familyRepo->findByLineUserId($lineUserId);
    if ($existing !== null) {
        $lineClient->reply($replyToken, '登録済みです。無料期間の終了案内などは、こちらのアカウントにお送りします。');
        return;
    }

    $pendingFamily = $familyRepo->findByInviteCode($messageText);
    if ($pendingFamily !== null) {
        $familyRepo->linkLineUserId((int) $pendingFamily['id'], $lineUserId);
        $lineClient->reply($replyToken, '登録が完了しました。無料期間の終了案内など、お支払いに関するご連絡をこちらのアカウントからお送りします。');
        return;
    }

    $lineClient->reply($replyToken, 'はじめまして。ご案内した連携コードを送っていただけますか?');
}
