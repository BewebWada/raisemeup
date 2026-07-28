<?php
// マイページ用のLINEログイン。申込時の「連携」(line_user_idを新規に紐づける)とは違い、
// 既にline_user_idが設定済みの家族アカウントを、そのLINEアカウントで「本人確認」してログインさせるだけの処理。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);

$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$redirectUri = $baseUrl . '/mypage_login_callback.php';

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = $_SESSION['mypage_login_state'] ?? null;
unset($_SESSION['mypage_login_state']);

if (($_GET['error'] ?? '') !== '' || $code === '' || $expectedState === null || !hash_equals($expectedState, $state)) {
    header('Location: /mypage/?login_error=1');
    exit;
}

try {
    $client = new LineLoginClient(
        Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', ''),
        Config::get('LINE_FAMILY_LOGIN_CHANNEL_SECRET', '')
    );
    $token = $client->exchangeToken($code, $redirectUri);
    $profile = $client->getProfile($token['access_token']);

    $family = $familyRepo->findByLineUserId($profile['userId']);
    if ($family === null) {
        header('Location: /mypage/?login_error=notfound');
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['mypage_family_id'] = (int) $family['id'];

    header('Location: /mypage/');
    exit;
} catch (Throwable $e) {
    error_log('Mypage LINE login callback failed: ' . $e->getMessage());
    header('Location: /mypage/?login_error=1');
    exit;
}
