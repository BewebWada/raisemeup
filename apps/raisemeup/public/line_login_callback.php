<?php
// LINEログイン(利用者用アカウント)の認可コードを受け取り、招待コードで特定したpendingユーザーに
// LINEのuserIdを連携する。連携コードの手入力(webhook.php側の既存経路)は非常時のフォールバックとして残す。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../src/ApplyDoneView.php';
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
// /apply/?done=1 は使えない。招待コードがまだ有効な段階(連携前のエラー・キャンセル等)は
// resume.php宛にリダイレクトして再表示できるが、招待コードはlinkLineUserId()の時点で
// DB上NULLにクリアされる(使い回し防止のため)ので、連携が成立した後は絶対に
// 「招待コードで再検索」してはいけない。連携成功後はuser_id/family_idが確定しているので、
// リダイレクトで再検索させず、その場で直接完了画面を描画する。
if (($_GET['error'] ?? '') !== '' || $code === '' || $pending === null || $pending['line_user_id'] !== null) {
    if ($pending !== null) {
        header('Location: /apply/resume.php?u=' . urlencode($state) . '&line_error=user');
    } else {
        // 招待コードが既に無効(連携済みで消費済み・存在しない等)。resume.php宛のリダイレクトでは
        // 招待コードが分からず再表示できないため、ここで直接「リンクが無効」画面を出す
        renderExpiredNotice();
    }
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
        // このUPDATEが失敗した場合、招待コードはまだNULL化されていないのでresume.php宛のリダイレクトで問題ない
        if ((int) $e->errorInfo[1] === 1062) {
            header('Location: /apply/resume.php?u=' . urlencode($state) . '&line_error=user_duplicate');
            exit;
        }
        throw $e;
    }

    // ここに到達した時点でinvite_codeは既にDB上でNULLになっている(linkLineUserIdの仕様)。
    // 以降は招待コードに頼らず、確定済みのuser_id/family_idで直接完了画面を描画する
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

    $familyIdStmt = $pdo->prepare(
        'SELECT family_account_id FROM user_family_links WHERE user_id = ? ORDER BY id ASC LIMIT 1'
    );
    $familyIdStmt->execute([(int) $pending['id']]);
    $familyId = $familyIdStmt->fetchColumn();
    if ($familyId === false) {
        renderExpiredNotice();
        exit;
    }

    renderDone((int) $pending['id'], (int) $familyId, $pdo, false);
    exit;
} catch (Throwable $e) {
    error_log('LINE login callback (user) failed: ' . $e->getMessage());
    // linkLineUserIdが既に成功済み(=招待コードは既にNULL)の可能性があるため、resume.php宛には
    // リダイレクトせず、招待コードがまだ有効な場合のみ再表示できるresume.php、無効ならexpired画面
    $stillPending = $userRepo->findByInviteCode($state);
    if ($stillPending !== null) {
        header('Location: /apply/resume.php?u=' . urlencode($state) . '&line_error=user');
        exit;
    }
    renderExpiredNotice();
    exit;
}
