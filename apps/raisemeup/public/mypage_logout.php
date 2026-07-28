<?php
session_start();
unset($_SESSION['mypage_family_id']);
header('Location: /mypage/');
exit;
