<?php
// メールでのリマインド等、セッションが無い状態からでも申込み完了ページ(LINE連携ステップUI)を
// 再表示するための入口。招待コード(まだLINE未連携の場合のみ有効)をキーにする。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/ApplyDoneView.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$userRepo = new UserRepository($pdo);

$code = (string) ($_GET['u'] ?? '');
$user = $code !== '' ? $userRepo->findByInviteCode($code) : null;

if ($user === null) {
    renderExpiredNotice();
    exit;
}

$stmt = $pdo->prepare(
    'SELECT family_account_id FROM user_family_links WHERE user_id = ? ORDER BY id ASC LIMIT 1'
);
$stmt->execute([(int) $user['id']]);
$familyId = $stmt->fetchColumn();

if ($familyId === false) {
    renderExpiredNotice();
    exit;
}

renderDone((int) $user['id'], (int) $familyId, $pdo, false);
