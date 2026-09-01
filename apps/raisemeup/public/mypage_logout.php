<?php
require_once __DIR__ . '/../src/Session.php';
Session::start();
unset($_SESSION['mypage_family_id']);
header('Location: /mypage/');
exit;
