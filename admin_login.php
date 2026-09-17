<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';

ensureAdminSchema($pdo);

if (isLoggedIn() && isAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '') {
        $error = 'メールアドレスを入力してください。';
    } elseif ($password === '') {
        $error = 'パスワードを入力してください。';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, email, password, is_admin FROM users WHERE email = ?"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ((int)$user['is_admin'] !== 1) {
                    $error = '管理者権限がありません。一般ログインをご利用ください。';
                } else {
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['is_admin'] = true;
                    $_SESSION['login_success'] = true;
                    $_SESSION['admin_login'] = true;

                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = 'メールアドレスまたはパスワードが正しくありません。';
            }
        } catch (PDOException $e) {
            $error = 'ログイン処理中にエラーが発生しました。';
            error_log('Admin login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ログイン - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
    <div class="container">
        <header>
            <h1>管理者ログイン</h1>
            <div class="header-actions">
                <a href="admin_register.php" class="btn btn-secondary">管理者新規登録</a>
                <a href="login.php" class="btn btn-secondary">一般ログイン</a>
                <a href="index.php" class="btn btn-secondary">ホームに戻る</a>
            </div>
        </header>

        <main>
            <p class="admin-login-note">管理者専用のログイン画面です。ログイン後はトップページへ移動します。</p>

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
                    <button type="submit" class="btn btn-primary">管理者としてログイン</button>
                    <a href="admin_register.php" class="btn btn-secondary">管理者新規登録</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
