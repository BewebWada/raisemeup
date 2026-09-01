<?php
// サポートページ(support.php)に設置するサポートbotのエンドポイント(未ログインでも叩ける)。
// simulate_chat.phpと同じ考え方でコスト・悪用対策を設けている:
// - 1メッセージあたりの文字数上限(MAX_MESSAGE_LENGTH)
// - 1セッション(1回の訪問)あたりのやり取り回数上限(MAX_TURNS_PER_SESSION)
// - 同一IPからの1日あたりの合計やり取り回数上限(MAX_TURNS_PER_IP_PER_DAY)。
//   セッションを使い捨てて1の上限を迂回する対策として、こちらが実質的な最終防衛ラインになる
// 会話内容はサーバーに保存しない(support_chat_sessionsには件数のみ記録し本文は持たない)。
// 実際の利用者データ(users/conversations)とは完全に分離している。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/SupportFaq.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

const MAX_MESSAGE_LENGTH = 300;
const MAX_HISTORY_TURNS = 6;         // クライアントから送られてきた履歴のうち、直近何件まで信用するか
const MAX_TURNS_PER_SESSION = 10;    // 1セッションで送れるメッセージ数の上限
const MAX_TURNS_PER_IP_PER_DAY = 40; // 同一IPからの1日の合計上限

Env::load(__DIR__ . '/../../../.env');

header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['error' => 'method_not_allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['error' => 'invalid_request'], 400);
}

$message = trim((string) ($input['message'] ?? ''));
if ($message === '') {
    respond(['error' => 'empty_message'], 400);
}
if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
    $message = mb_substr($message, 0, MAX_MESSAGE_LENGTH);
}

$history = [];
if (is_array($input['history'] ?? null)) {
    foreach (array_slice($input['history'], -MAX_HISTORY_TURNS) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $history[] = [
            'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
            'content' => mb_substr((string) ($turn['content'] ?? ''), 0, MAX_MESSAGE_LENGTH),
        ];
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);

// --- 同一IPの1日あたり合計回数チェック(セッション使い捨てによる迂回への対策) ---
$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(turn_count), 0) FROM support_chat_sessions WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
);
$stmt->execute([$ip]);
if ((int) $stmt->fetchColumn() >= MAX_TURNS_PER_IP_PER_DAY) {
    respond([
        'error' => 'daily_limit_reached',
        'reply' => '本日のご質問受付上限に達しました。恐れ入りますが、また明日お試しいただくか、特定商取引法に基づく表記に記載のお問い合わせ先までご連絡ください。',
        'is_last_turn' => true,
    ]);
}

// --- セッションの特定・発行 ---
$sessionToken = is_string($input['session_token'] ?? null) ? $input['session_token'] : '';
$session = null;
if ($sessionToken !== '') {
    $stmt = $pdo->prepare('SELECT id, turn_count FROM support_chat_sessions WHERE session_token = ?');
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($session === null) {
    $sessionToken = bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO support_chat_sessions (session_token, ip_address, turn_count) VALUES (?, ?, 0)')
        ->execute([$sessionToken, $ip]);
    $session = ['turn_count' => 0];
}

if ((int) $session['turn_count'] >= MAX_TURNS_PER_SESSION) {
    respond([
        'error' => 'session_limit_reached',
        'session_token' => $sessionToken,
        'reply' => 'このセッションでのご質問受付上限に達しました。ページを再読み込みいただくか、特定商取引法に基づく表記に記載のお問い合わせ先までご連絡ください。',
        'is_last_turn' => true,
    ]);
}

$knowledgeBase = SupportFaq::buildKnowledgeBaseText(SupportFaq::getCategories());
$claudeClient = ClaudeClient::fromConfig();
$replyText = $claudeClient->generateSupportReply($history, $message, $knowledgeBase);

if ($replyText === '') {
    respond(['error' => 'generation_failed', 'session_token' => $sessionToken], 502);
}

$pdo->prepare('UPDATE support_chat_sessions SET turn_count = turn_count + 1 WHERE session_token = ?')
    ->execute([$sessionToken]);

$isLastTurn = ((int) $session['turn_count'] + 1) >= MAX_TURNS_PER_SESSION;

respond([
    'reply' => $replyText,
    'session_token' => $sessionToken,
    'is_last_turn' => $isLastTurn,
]);
