<?php
// LINEログイン(利用者用アカウント)の認可コードを受け取り、招待コードで特定したpendingユーザーに
// LINEのuserIdを連携する。連携コードの手入力(webhook.php側の既存経路)は非常時のフォールバックとして残す。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$userRepo = new UserRepository($pdo);

$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$redirectUri = $baseUrl . '/line_login_callback.php';

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$pending = $state !== '' ? $userRepo->findByInviteCode($state) : null;

if (($_GET['error'] ?? '') !== '' || $code === '' || $pending === null || $pending['line_user_id'] !== null) {
    header('Location: /apply/?done=1&line_error=user');
    exit;
}

try {
    $client = new LineLoginClient(
        Config::get('LINE_LOGIN_CHANNEL_ID', ''),
        Config::get('LINE_LOGIN_CHANNEL_SECRET', '')
    );
    $token = $client->exchangeToken($code, $redirectUri);
    $profile = $client->getProfile($token['access_token']);
    $isFriend = $client->isFriend($token['access_token']);

    $userRepo->linkLineUserId((int) $pending['id'], $profile['userId']);

    // 友だち追加も必須のため、ここで確認できた場合だけ本当の意味でオンボード完了(active)にする。
    // 友だち追加だけではLINEのトーク一覧に何も表示されない(ユーザーから話しかけるまで会話が始まらない)ため、
    // こちらから最初のメッセージを送ってトークを開始する。
    // まだ友だち追加していない場合は、後からwebhook.php側のfollowイベントで確認できた時点で
    // ConversationHandler::handleFollowEventが同じ処理を行う(pendingのままにしておく)
    if ($isFriend) {
        $userRepo->markOnboarded((int) $pending['id']);
        $displayName = $pending['display_name'] ?: 'あなた';
        $companionName = $pending['companion_name'] ?: 'たより';
        $greeting = "{$displayName}さん、はじめまして!{$companionName}です。\nこれから、よろしくお願いします。\n何でも気軽に話しかけてくださいね。";
        $lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
        if ($lineClient->push($profile['userId'], $greeting)) {
            // send_proactive_messages.phpの無連絡検知(conversationsの最終更新から判定)に影響するため、
            // outboundメッセージは通常の会話と同様にここでも記録しておく
            $pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
            )->execute([(int) $pending['id'], $greeting]);
        }
    }

    header('Location: /apply/?done=1');
    exit;
} catch (Throwable $e) {
    error_log('LINE login callback (user) failed: ' . $e->getMessage());
    header('Location: /apply/?done=1&line_error=user');
    exit;
}
