<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/ConversationHandler.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

// --- 署名検証 ---
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

$lineClient = new LineClient(
    Config::get('LINE_CHANNEL_SECRET'),
    Config::get('LINE_CHANNEL_ACCESS_TOKEN')
);

if (!$lineClient->verifySignature($body, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

$events = json_decode($body, true)['events'] ?? [];

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);

// リスク検知時の家族向け通知は、利用者本人とのやり取り用チャネルとは別の「TAYORIサポート」から送る
$familyLineClient = new LineClient(
    Config::get('LINE_FAMILY_CHANNEL_SECRET'),
    Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN')
);

$handler = new ConversationHandler(
    $pdo,
    $lineClient,
    $familyLineClient,
    new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'))
);

foreach ($events as $event) {
    if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
        try {
            $handler->handleTextMessage($event);
        } catch (Throwable $e) {
            error_log('Webhook event handling failed: ' . $e->getMessage());
        }
    } elseif ($event['type'] === 'message' && $event['message']['type'] === 'location') {
        try {
            $handler->handleLocationMessage($event);
        } catch (Throwable $e) {
            error_log('Webhook location event handling failed: ' . $e->getMessage());
        }
    } elseif ($event['type'] === 'message' && $event['message']['type'] === 'image') {
        try {
            $handler->handleImageMessage($event);
        } catch (Throwable $e) {
            error_log('Webhook image event handling failed: ' . $e->getMessage());
        }
    } elseif ($event['type'] === 'message' && $event['message']['type'] === 'file') {
        try {
            $handler->handleFileMessage($event);
        } catch (Throwable $e) {
            error_log('Webhook file event handling failed: ' . $e->getMessage());
        }
    } elseif ($event['type'] === 'follow') {
        try {
            $handler->handleFollowEvent($event);
        } catch (Throwable $e) {
            error_log('Webhook follow event handling failed: ' . $e->getMessage());
        }
    }
    // sticker, audio等は今回スコープ外。ログだけ残すか無視でよい
}

http_response_code(200);
echo 'OK';
