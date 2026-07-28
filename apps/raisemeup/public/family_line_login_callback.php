<?php
// LINEログイン(ご家族用アカウント)の認可コードを受け取り、招待コードで特定したpending家族に
// LINEのuserIdを連携する。連携コードの手入力(family_webhook.php側の既存経路)は非常時のフォールバックとして残す。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);

$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$redirectUri = $baseUrl . '/family_line_login_callback.php';

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$pending = $state !== '' ? $familyRepo->findByInviteCode($state) : null;

if (($_GET['error'] ?? '') !== '' || $code === '' || $pending === null || $pending['line_user_id'] !== null) {
    header('Location: /apply/?done=1&line_error=family');
    exit;
}

try {
    $client = new LineLoginClient(
        Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', ''),
        Config::get('LINE_FAMILY_LOGIN_CHANNEL_SECRET', '')
    );
    $token = $client->exchangeToken($code, $redirectUri);
    $profile = $client->getProfile($token['access_token']);
    $isFriend = $client->isFriend($token['access_token']);

    $familyRepo->linkLineUserId((int) $pending['id'], $profile['userId']);
    $_SESSION['line_friend_flag']['family'] = $isFriend;

    // 友だち追加も必須のため、ここで確認できた場合だけ本当の意味で連携完了(friend_confirmed_at)にする。
    // まだ友だち追加していない場合は、後からfamily_webhook.php側のfollowイベントで確認できた時点で
    // handleFamilyFollowEventが同じ処理を行う
    if ($isFriend) {
        $familyRepo->markFriendConfirmed((int) $pending['id']);
        $familyName = $pending['name'] ?: 'ご家族';
        $lineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
        $lineClient->push(
            $profile['userId'],
            "{$familyName}様、登録が完了しました。\n無料期間の終了案内など、お支払いに関するご連絡をこちらのアカウントからお送りします。"
        );
    }

    header('Location: /apply/?done=1');
    exit;
} catch (Throwable $e) {
    error_log('LINE login callback (family) failed: ' . $e->getMessage());
    header('Location: /apply/?done=1&line_error=family');
    exit;
}
