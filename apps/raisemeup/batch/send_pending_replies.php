<?php
// 緊急性の低い普通の雑談を、即レスの機械的な印象を避けるため数分置いてから届けるための遅延キュー
// (pending_replies)の送信バッチ。cronは1分間隔で実行する想定。
// ConversationHandler::handleTextMessage()が緊急扱いと判定しなかった返信はここに積まれ、
// send_afterを過ぎたものだけpush APIで送信する(replyTokenは長時間保持できないため使わない)。
// 待機中に本人が畳みかけて次のメッセージを送ってきた場合は、ConversationHandler::flushPendingReplies()側で
// 先に送られていることがあるため、statusの排他更新(pending→sending)で二重送信を防ぐ
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
// 利用者本人とのやり取り用チャネル(webhook.php/ConversationHandlerの$lineClientと同じ)
$lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));

// 文字数に応じた「タイピング時間」を疑似的に作る(ConversationHandler::typingDelayMicrosecondsと同じ考え方だが、
// こちらはreplyTokenの制約が無いのでもう少し短めの範囲でよい)
function typingDelayMicroseconds(string $text): int
{
    $seconds = max(2, min(4, mb_strlen($text) * 0.08));
    return (int) ($seconds * 1_000_000);
}

$stmt = $pdo->prepare(
    'SELECT id, user_id, line_user_id, reply_text, sticker_package_id, sticker_id, claude_model FROM pending_replies WHERE status = "pending" AND send_after <= NOW() ORDER BY send_after'
);
$stmt->execute();
$dueReplies = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($dueReplies as $row) {
    // ConversationHandler::flushPendingReplies()側で既に処理されていないか、排他更新で確保する
    $claim = $pdo->prepare('UPDATE pending_replies SET status = "sending" WHERE id = ? AND status = "pending"');
    $claim->execute([$row['id']]);
    if ($claim->rowCount() === 0) {
        continue;
    }

    $lineClient->startLoadingAnimation($row['line_user_id'], 10);
    usleep(typingDelayMicroseconds($row['reply_text']));

    $sticker = $row['sticker_package_id'] !== null && $row['sticker_id'] !== null
        ? ['packageId' => $row['sticker_package_id'], 'stickerId' => $row['sticker_id']]
        : null;
    $sent = $lineClient->push($row['line_user_id'], $row['reply_text'], null, $sticker);
    if (!$sent) {
        error_log("send_pending_replies: push failed for pending_replies.id={$row['id']}");
    }

    $pdo->prepare(
        'INSERT INTO conversations (user_id, direction, message_type, content, claude_model) VALUES (?, "outbound", "text", ?, ?)'
    )->execute([$row['user_id'], $row['reply_text'], $row['claude_model']]);

    $pdo->prepare('UPDATE pending_replies SET status = "sent", sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
}
