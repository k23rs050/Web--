<?php
require_once 'config/database.php';
require_once 'config/session.php';

redirectIfLoggedIn();

$error = '';
$success = '';

// セッションから登録データを取得
if (!isset($_SESSION['register_data'])) {
    header('Location: register.php');
    exit;
}

$register_data = $_SESSION['register_data'];
$name = $register_data['name'];
$email = $register_data['email'];
$password = $register_data['password'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm'])) {
        // 登録処理
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hashed_password]);
            
            // セッションをクリア
            unset($_SESSION['register_data']);
            
            $success = 'ユーザー登録が完了しました。ログインしてください。';
            // 3秒後にログインページへリダイレクト
            header('Refresh: 3; url=login.php');
        } catch (PDOException $e) {
            $error = '登録処理中にエラーが発生しました。';
        }
    } elseif (isset($_POST['back'])) {
        // 戻るボタンが押された場合
        header('Location: register.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登録確認 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>登録内容確認</h1>
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
                    <h2>以下の内容で登録しますか？</h2>
                    
                    <div class="form-group">
                        <label>名前</label>
                        <div class="confirm-value"><?php echo htmlspecialchars($name); ?></div>
                    </div>

                    <div class="form-group">
                        <label>メールアドレス</label>
                        <div class="confirm-value"><?php echo htmlspecialchars($email); ?></div>
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

