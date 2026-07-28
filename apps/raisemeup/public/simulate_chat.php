<?php
// トップページの公開チャットシミュレーション(未ログイン訪問者向け、認証なしで叩けるエンドポイント)。
// コスト・悪用対策として以下の上限を設けている:
// - 1メッセージあたりの文字数上限(MAX_MESSAGE_LENGTH)
// - 1セッション(1回の体験)あたりのやり取り回数上限(MAX_TURNS_PER_SESSION)
// - 同一IPからの1日あたりの合計やり取り回数上限(MAX_TURNS_PER_IP_PER_DAY)。
//   セッションを使い捨てて1の上限を迂回する対策として、こちらが実質的な最終防衛ラインになる
// 会話内容はサーバーに保存しない(demo_chat_sessionsには件数のみ記録し本文は持たない)。
// 実際の利用者データ(users/conversations)とは完全に分離している。
// 「既知の予定」はクライアント(JS)側で保持し毎回送り直してもらう形で、実際の会話ループが
// DBの既知の予定一覧をプロンプトに再注入する仕組みを軽量に再現し、応答精度をそろえている。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

const MAX_MESSAGE_LENGTH = 200;
const MAX_HISTORY_TURNS = 6;         // クライアントから送られてきた履歴のうち、直近何件まで信用するか
const MAX_TURNS_PER_SESSION = 6;     // 1セッションで送れるメッセージ数の上限
const MAX_TURNS_PER_IP_PER_DAY = 30; // 同一IPからの1日の合計上限
const MAX_KNOWN_SCHEDULES = 10;      // クライアントから送られてくる「既知の予定」のうち、直近何件まで信用するか

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

// クライアント(JS)側でこのセッション中に保持している「既知の予定」。サーバー側では永続化せず、
// 実際の会話ループ(generateReplyAndExtract)がDBの既知の予定一覧を毎回プロンプトに再注入するのと
// 同じ役割を、リクエストごとに渡し直してもらう形で軽量に再現している
$knownSchedules = [];
if (is_array($input['known_schedules'] ?? null)) {
    foreach (array_slice($input['known_schedules'], -MAX_KNOWN_SCHEDULES) as $schedule) {
        if (!is_array($schedule) || trim((string) ($schedule['title'] ?? '')) === '') {
            continue;
        }
        $knownSchedules[] = [
            'title' => mb_substr((string) $schedule['title'], 0, 50),
            'when' => mb_substr((string) ($schedule['when'] ?? ''), 0, 50),
        ];
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);

// --- 同一IPの1日あたり合計回数チェック(セッション使い捨てによる迂回への対策) ---
$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(turn_count), 0) FROM demo_chat_sessions WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
);
$stmt->execute([$ip]);
if ((int) $stmt->fetchColumn() >= MAX_TURNS_PER_IP_PER_DAY) {
    respond([
        'error' => 'daily_limit_reached',
        'reply' => '今日はたくさんの方に体験していただいたみたい。また明日試してみてね。',
        'is_last_turn' => true,
    ]);
}

// --- セッションの特定・発行 ---
$sessionToken = is_string($input['session_token'] ?? null) ? $input['session_token'] : '';
$session = null;
if ($sessionToken !== '') {
    $stmt = $pdo->prepare('SELECT id, turn_count FROM demo_chat_sessions WHERE session_token = ?');
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($session === null) {
    $sessionToken = bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO demo_chat_sessions (session_token, ip_address, turn_count) VALUES (?, ?, 0)')
        ->execute([$sessionToken, $ip]);
    $session = ['turn_count' => 0];
}

if ((int) $session['turn_count'] >= MAX_TURNS_PER_SESSION) {
    respond([
        'error' => 'session_limit_reached',
        'session_token' => $sessionToken,
        'reply' => '今日の体験はここまでだよ。続きが気になったら、ぜひ本物のTAYORIを試してみてね。',
        'is_last_turn' => true,
    ]);
}

$claudeClient = new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'));
$isLastTurn = ((int) $session['turn_count'] + 1) >= MAX_TURNS_PER_SESSION;
$result = $claudeClient->generateDemoReply($history, $message, $knownSchedules, $isLastTurn);

if ($result['reply_text'] === '') {
    respond(['error' => 'generation_failed', 'session_token' => $sessionToken], 502);
}

$pdo->prepare('UPDATE demo_chat_sessions SET turn_count = turn_count + 1 WHERE session_token = ?')
    ->execute([$sessionToken]);

respond([
    'reply' => $result['reply_text'],
    'new_schedule' => $result['new_schedule'],
    'session_token' => $sessionToken,
    'is_last_turn' => $isLastTurn,
]);
