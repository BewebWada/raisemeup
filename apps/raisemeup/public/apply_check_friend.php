<?php
// 申込完了画面の「次へ」ボタンから呼ばれる。LINEのfollowイベントを待たず、
// その場でMessaging API(プロフィール取得)を使って友だち追加済みかを能動的に確認する。
// 確認できなければ、注意文つきで同じ画面(未完了の状態)を再表示する。
//
// セッションに申込み結果がある場合(同一端末で最初から操作している場合)はそちらを使うが、
// コピーしたURLをご本人の端末(別端末・セッションなし)で開いている場合はhiddenで渡された
// 招待コードで対象を特定し、resume.php宛にリダイレクトする。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /apply/');
    exit;
}

$target = (string) ($_POST['target'] ?? '');
$code = (string) ($_POST['code'] ?? '');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$userRepo = new UserRepository($pdo);
$familyRepo = new FamilyAccountRepository($pdo);

$userId = (int) ($_SESSION['apply_result']['user_id'] ?? 0);
$familyId = (int) ($_SESSION['apply_result']['family_id'] ?? 0);
$hasSession = $userId !== 0 && $familyId !== 0;
$userInviteCode = null; // resume.php宛のリダイレクトには常にご利用者様側の招待コードが要る

if (!$hasSession) {
    if ($code === '') {
        header('Location: /apply/');
        exit;
    }
    if ($target === 'user') {
        $u = $userRepo->findByInviteCode($code);
        if ($u === null) {
            header('Location: /apply/');
            exit;
        }
        $userId = (int) $u['id'];
        $userInviteCode = $code;
    } elseif ($target === 'family') {
        $f = $familyRepo->findByInviteCode($code);
        if ($f === null) {
            header('Location: /apply/');
            exit;
        }
        $familyId = (int) $f['id'];
        $stmt = $pdo->prepare(
            'SELECT u.invite_code FROM user_family_links l JOIN users u ON u.id = l.user_id
             WHERE l.family_account_id = ? ORDER BY l.id ASC LIMIT 1'
        );
        $stmt->execute([$familyId]);
        $userInviteCode = (string) $stmt->fetchColumn();
    } else {
        header('Location: /apply/');
        exit;
    }
}

if ($target === 'user') {
    $user = $userRepo->find($userId);
    if ($user !== null && $user['line_user_id'] !== null && $user['status'] === 'pending') {
        $lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
        if ($lineClient->getProfile($user['line_user_id']) !== null) {
            FriendConfirmationService::confirmUser($pdo, $userRepo, $lineClient, $user);
        } else {
            $_SESSION['friend_check_failed'] = 'user';
        }
    }
} elseif ($target === 'family') {
    $family = $familyRepo->find($familyId);
    if ($family !== null && $family['line_user_id'] !== null && $family['friend_confirmed_at'] === null) {
        $lineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
        if ($lineClient->getProfile($family['line_user_id']) !== null) {
            FriendConfirmationService::confirmFamily($familyRepo, $lineClient, $family);
        } else {
            $_SESSION['friend_check_failed'] = 'family';
        }
    }
}

if ($hasSession) {
    header('Location: /apply/?done=1');
} else {
    header('Location: /apply/resume.php?u=' . urlencode((string) $userInviteCode));
}
exit;
