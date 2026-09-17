<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';

ensureAdminSchema($pdo);

if (isLoggedIn() && isAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($name === '') {
        $error = '名前を入力してください。';
    } elseif ($email === '') {
        $error = 'メールアドレスを入力してください。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '有効なメールアドレスを入力してください。';
    } elseif ($password === '') {
        $error = 'パスワードを入力してください。';
    } elseif (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください。';
    } elseif ($password !== $password_confirm) {
        $error = 'パスワードと再パスワードが一致しません。';
    } elseif (strlen($name) > 100) {
        $error = '名前は100文字以内で入力してください。';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'このメールアドレスは既に登録されています。';
            } else {
                $_SESSION['admin_register_data'] = [
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ];
                header('Location: admin_register_confirm.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = '登録処理中にエラーが発生しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者登録 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
    <div class="container">
        <header>
            <h1>管理者登録</h1>
            <div class="header-actions">
                <a href="admin_login.php" class="btn btn-secondary">管理者ログイン</a>
                <a href="index.php" class="btn btn-secondary">ホームに戻る</a>
            </div>
        </header>

        <main>
            <p class="admin-login-note">管理者アカウントの新規登録画面です。</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="post-form">
                <div class="form-group">
                    <label for="name">名前 *</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required maxlength="100">
                </div>

                <div class="form-group">
                    <label for="email">メールアドレス *</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">パスワード *（8文字以上）</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="password_confirm">再パスワード *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">確認画面へ</button>
                    <a href="admin_login.php" class="btn btn-secondary">管理者ログイン</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
