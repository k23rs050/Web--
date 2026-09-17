<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';

ensureAdminSchema($pdo);
redirectIfLoggedIn();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // バリデーション
    if (empty($email)) {
        $error = 'メールアドレスを入力してください。';
    } elseif (empty($password)) {
        $error = 'パスワードを入力してください。';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, password, is_admin FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // ログイン成功
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['is_admin'] = ((int)($user['is_admin'] ?? 0) === 1);
                
                // ログイン成功メッセージをセッションに保存
                $_SESSION['login_success'] = true;
                
                // セッションを確実に保存
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                
                // リダイレクト前に出力バッファをクリア
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                // リダイレクト
                header('Location: index.php');
                exit();
            } else {
                $error = 'メールアドレスまたはパスワードが正しくありません。';
            }
        } catch (PDOException $e) {
            // エラーメッセージを取得
            $errorMessage = $e->getMessage();
            
            // テーブルが存在しない場合の特別なメッセージ
            if (strpos($errorMessage, "doesn't exist") !== false || 
                strpos($errorMessage, "Table") !== false) {
                $error = 'ユーザーテーブルが存在しません。データベースを初期化してください。';
            } else {
                // 開発環境では詳細なエラーを表示
                $error = 'ログイン処理中にエラーが発生しました。';
                // 開発環境の場合は詳細を表示（本番環境では非表示）
                if (ini_get('display_errors') || $_SERVER['SERVER_NAME'] === 'localhost') {
                    $error .= ' (' . htmlspecialchars($errorMessage) . ')';
                }
            }
            
            // エラーログに記録
            error_log('Login error: ' . $errorMessage);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>ログイン</h1>
            <a href="index.php" class="btn btn-secondary">ホームに戻る</a>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="post-form">
                <div class="form-group">
                    <label for="email">メールアドレス *</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">パスワード *</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">ログイン</button>
                    <a href="register.php" class="btn btn-secondary">新規登録</a>
                    <a href="admin_login.php" class="btn btn-secondary">管理者ログイン</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>

