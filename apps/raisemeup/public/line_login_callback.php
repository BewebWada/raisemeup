<?php
// LINEログイン(利用者用アカウント)の認可コードを受け取り、招待コードで特定したpendingユーザーに
// LINEのuserIdを連携する。連携コードの手入力(webhook.php側の既存経路)は非常時のフォールバックとして残す。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
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

// コピーしたURLを開くのはご本人の端末(申込み時と別端末)である前提のため、セッション依存の
// /apply/?done=1 ではなく、招待コードだけで完了画面を出せるresume.php宛にリダイレクトする
$resumeUrl = '/apply/resume.php?u=' . urlencode($state);

if (($_GET['error'] ?? '') !== '' || $code === '' || $pending === null || $pending['line_user_id'] !== null) {
    header('Location: ' . ($pending !== null ? $resumeUrl . '&line_error=user' : '/apply/?done=1&line_error=user'));
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

    try {
        $userRepo->linkLineUserId((int) $pending['id'], $profile['userId']);
    } catch (PDOException $e) {
        // users.line_user_idはUNIQUE制約付き。同じLINEアカウントで既に別の利用者様が連携済みの場合
        // (例: ご家族が2人目の利用者様を、1人目と同じご自身のLINEアカウントで連携しようとした場合)
        // ここで一意制約違反になるので、原因が分かるようエラー種別を分けて案内する
        if ((int) $e->errorInfo[1] === 1062) {
            header('Location: ' . $resumeUrl . '&line_error=user_duplicate');
            exit;
        }
        throw $e;
    }

    // 友だち追加も必須のため、ここで確認できた場合だけ本当の意味でオンボード完了(active)にする。
    // まだ友だち追加していない場合は、申込完了画面の「次へ」ボタン(能動チェック)か、
    // 後からwebhook.php側のfollowイベントで確認できた時点で同じ処理を行う(pendingのままにしておく)
    if ($isFriend) {
        $lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
        FriendConfirmationService::confirmUser($pdo, $userRepo, $lineClient, ['id' => $pending['id'], 'display_name' => $pending['display_name'], 'companion_name' => $pending['companion_name'], 'line_user_id' => $profile['userId']]);
    }

    header('Location: ' . $resumeUrl);
    exit;
} catch (Throwable $e) {
    error_log('LINE login callback (user) failed: ' . $e->getMessage());
    header('Location: ' . $resumeUrl . '&line_error=user');
    exit;
}
