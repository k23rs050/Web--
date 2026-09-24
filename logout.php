<?php
require_once 'config/session.php';

// セッションを破棄
$_SESSION = array();

// セッションクッキーを削除
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// セッションを破棄
session_destroy();

// 新しいセッションを開始してログアウトメッセージを表示
session_start();
$_SESSION['logout_success'] = true;

// ホーム画面へリダイレクト
header('Location: index.php');
exit;
?>

