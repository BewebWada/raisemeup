<?php
// LINEログイン(利用者用アカウント)の認可コードを受け取り、招待コードで特定したpendingユーザーに
// LINEのuserIdを連携する。連携コードの手入力(webhook.php側の既存経路)は非常時のフォールバックとして残す。
//
// この画面を見るのはご利用者様ご本人(多くは高齢者)の端末なので、renderUserOnlyDone()で
// 料金・プラン・ご家族の連携状況といった本人に関係の無い情報を一切出さない専用画面を出す。
// セッション依存の/apply/?done=1(ご家族向けの完了画面)にはリダイレクトしない。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../src/ApplyDoneView.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$userRepo = new UserRepository($pdo);
$addFriendUrl = Config::get('LINE_ADD_FRIEND_URL', '');

$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$redirectUri = $baseUrl . '/line_login_callback.php';

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$pending = $state !== '' ? $userRepo->findByInviteCode($state) : null;

if ($pending === null) {
    // 招待コードが既に無効(連携済みで消費済み・存在しない等)。招待コードでは
    // 本人を再特定できないため、汎用の「リンクが無効」画面を出す
    renderExpiredNotice();
    exit;
}

if (($_GET['error'] ?? '') !== '' || $code === '' || $pending['line_user_id'] !== null) {
    // LINE側での同意キャンセル等。招待コードはまだNULL化されていないので、その場のpending情報で
    // そのまま案内画面(未連携状態)を出せる
    renderUserOnlyDone($pending, false, $addFriendUrl, 'user');
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
        // ここで一意制約違反になるので、原因が分かるようエラー種別を分けて案内する。
        // このUPDATEが失敗した場合、招待コードはまだNULL化されていない
        if ((int) $e->errorInfo[1] === 1062) {
            renderUserOnlyDone($pending, false, $addFriendUrl, 'user_duplicate');
            exit;
        }
        throw $e;
    }

    // ここに到達した時点でinvite_codeは既にDB上でNULLになっている(linkLineUserIdの仕様、
    // 招待リンクの使い回し防止)。以降は招待コードに頼らず、その場で直接完了画面を描画する
    if ($isFriend) {
        $lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
        try {
            FriendConfirmationService::confirmUser($pdo, $userRepo, $lineClient, ['id' => $pending['id'], 'display_name' => $pending['display_name'], 'companion_name' => $pending['companion_name'], 'line_user_id' => $profile['userId']]);
        } catch (Throwable $e) {
            // 挨拶メッセージ送信・家族への通知が失敗しても、LINE連携自体は既に成功しているので
            // 完了画面の表示は妨げない(ベストエフォート)
            error_log('FriendConfirmationService::confirmUser failed after LINE login: ' . $e->getMessage());
        }
    }

    renderUserOnlyDone($pending, $isFriend, $addFriendUrl);
    exit;
} catch (Throwable $e) {
    error_log('LINE login callback (user) failed: ' . $e->getMessage());
    renderUserOnlyDone($pending, false, $addFriendUrl, 'user');
    exit;
}
