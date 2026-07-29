<?php
// 申込完了画面の「次へ」ボタンから呼ばれる。LINEのfollowイベントを待たず、
// その場でMessaging API(プロフィール取得)を使って友だち追加済みかを能動的に確認する。
// 確認できなければ、注意文つきで同じ画面(未完了の状態)を再表示する。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['apply_result'])) {
    header('Location: /apply/');
    exit;
}

$target = (string) ($_POST['target'] ?? '');
$userId = (int) $_SESSION['apply_result']['user_id'];
$familyId = (int) $_SESSION['apply_result']['family_id'];

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);

if ($target === 'user') {
    $userRepo = new UserRepository($pdo);
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
    $familyRepo = new FamilyAccountRepository($pdo);
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

header('Location: /apply/?done=1');
exit;
