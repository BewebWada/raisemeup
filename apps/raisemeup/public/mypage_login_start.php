<?php
// マイページの「LINEでログイン」ボタンの遷移先。CSRF対策のstateを発行してLINEの認可画面へリダイレクトする
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
Session::start();

if (Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', '') === '') {
    header('Location: /mypage/?login_error=1');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['mypage_login_state'] = $state;

$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$client = new LineLoginClient(
    Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', ''),
    Config::get('LINE_FAMILY_LOGIN_CHANNEL_SECRET', '')
);

header('Location: ' . $client->buildAuthorizeUrl($baseUrl . '/mypage_login_callback.php', $state, false));
exit;
