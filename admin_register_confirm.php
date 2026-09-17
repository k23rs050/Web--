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
$success = '';

if (!isset($_SESSION['admin_register_data'])) {
    header('Location: admin_register.php');
    exit;
}

$register_data = $_SESSION['admin_register_data'];
$name = $register_data['name'];
$email = $register_data['email'];
$password = $register_data['password'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm'])) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'このメールアドレスは既に登録されています。';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 1)"
                );
                $stmt->execute([$name, $email, $hashed_password]);

                unset($_SESSION['admin_register_data']);
                $success = '管理者登録が完了しました。管理者ログインしてください。';
                header('Refresh: 3; url=admin_login.php');
            }
        } catch (PDOException $e) {
            $error = '登録処理中にエラーが発生しました。';
        }
    } elseif (isset($_POST['back'])) {
        header('Location: admin_register.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者登録確認 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
    <div class="container">
        <header>
            <h1>管理者登録内容確認</h1>
            <a href="index.php" class="btn btn-secondary">ホームに戻る</a>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php else: ?>
                <div class="post-form">
                    <h2>以下の内容で管理者登録しますか？</h2>

                    <div class="form-group">
                        <label>名前</label>
                        <div class="confirm-value"><?php echo htmlspecialchars($name); ?></div>
                    </div>

                    <div class="form-group">
                        <label>メールアドレス</label>
                        <div class="confirm-value"><?php echo htmlspecialchars($email); ?></div>
                    </div>

                    <div class="form-group">
                        <label>権限</label>
                        <div class="confirm-value"><span class="admin-badge">管理者</span></div>
                    </div>

                    <div class="form-group">
                        <label>パスワード</label>
                        <div class="confirm-value">●●●●●●●●</div>
                    </div>

                    <form method="POST" class="form-actions">
                        <button type="submit" name="confirm" class="btn btn-primary">登録する</button>
                        <button type="submit" name="back" class="btn btn-secondary">戻る</button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
