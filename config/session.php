<?php
// セッション管理
if (session_status() === PHP_SESSION_NONE) {
    // セッション設定
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// ログイン状態をチェックする関数
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_name']);
}

// 管理者かどうかをチェック
function isAdmin() {
    return isLoggedIn() && !empty($_SESSION['is_admin']);
}

// ログインが必要なページで使用
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// 管理者ログインが必要なページで使用
function requireAdmin() {
    if (!isLoggedIn()) {
        header('Location: admin_login.php');
        exit;
    }
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

// ログイン済みの場合はリダイレクト
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}
?>
