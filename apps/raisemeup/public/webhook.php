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

$handler = new ConversationHandler(
    $pdo,
    $lineClient,
    new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'))
);

foreach ($events as $event) {
    if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
        try {
            $handler->handleTextMessage($event);
        } catch (Throwable $e) {
            error_log('Webhook event handling failed: ' . $e->getMessage());
        }
    }
    // sticker, image等は今回スコープ外。ログだけ残すか無視でよい
}

http_response_code(200);
echo 'OK';
