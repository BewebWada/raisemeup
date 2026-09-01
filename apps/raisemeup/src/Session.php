<?php

// session_start()の全呼び出し元(mypage系・apply系のログインフロー)で共通のCookieセキュリティ設定を
// 強制するためのラッパー。サイトは常時HTTPS(HTTP→HTTPSへ301リダイレクト)のため、Secure/HttpOnly/
// SameSite=Laxを付与し、XSS発生時のセッションハイジャックやCSRFへの耐性を高める
// (php.iniの既定値のままsession_start()を直接呼ぶと、これらの属性が付かない)
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
