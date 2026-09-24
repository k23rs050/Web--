<?php
/**
 * セッション状態を確認するデバッグ用ファイル
 * ブラウザでこのファイルにアクセスして、セッション状態を確認してください
 */
require_once 'config/session.php';

echo "<h1>セッション状態確認</h1>";
echo "<pre>";
echo "セッションID: " . session_id() . "\n";
echo "セッション名: " . session_name() . "\n";
echo "セッション状態: " . (session_status() === PHP_SESSION_ACTIVE ? 'アクティブ' : '非アクティブ') . "\n\n";

echo "セッション変数:\n";
print_r($_SESSION);

echo "\n\nログイン状態: " . (isLoggedIn() ? 'ログイン中' : '未ログイン') . "\n";

if (isLoggedIn()) {
    echo "ユーザーID: " . $_SESSION['user_id'] . "\n";
    echo "ユーザー名: " . $_SESSION['user_name'] . "\n";
    echo "メールアドレス: " . $_SESSION['user_email'] . "\n";
}
echo "</pre>";

echo "<p><a href='index.php'>ホームに戻る</a></p>";
echo "<p><a href='login.php'>ログインページ</a></p>";
?>


