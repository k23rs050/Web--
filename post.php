<?php
require_once 'config/database.php';
require_once 'config/session.php';

// このページはログイン必須
requireLogin();

$error = '';
$success = '';

$stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'user_id'");
$hasPostUserIdColumn = (bool)$stmt->fetch();

$hasPostCategoryColumn = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'category'");
    $hasPostCategoryColumn = (bool)$stmt->fetch();
} catch (PDOException $e) {
    $hasPostCategoryColumn = false;
}

if (!$hasPostCategoryColumn) {
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT '雑談'");
        $hasPostCategoryColumn = true;
    } catch (PDOException $e) {
        $hasPostCategoryColumn = false;
    }
}

$categoryOptions = ['料理', 'スポーツ', '娯楽', '勉強', '雑談', '連絡'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = trim((string)($_POST['category'] ?? '雑談'));

    // ログインしている前提でセッションから名前を取得
    $author = $_SESSION['user_name'] ?? '';
    $userId = $_SESSION['user_id'] ?? null;
    
    // バリデーション
    if (empty($title)) {
        $error = 'タイトルを入力してください。';
    } elseif (empty($content)) {
        $error = '内容を入力してください。';
    } elseif ($hasPostCategoryColumn && !in_array($category, $categoryOptions, true)) {
        $error = 'カテゴリを選択してください。';
    } elseif (empty($author)) {
        $error = '投稿者名を取得できませんでした。もう一度ログインし直してください。';
    } elseif (strlen($title) > 255) {
        $error = 'タイトルは255文字以内で入力してください。';
    } elseif (strlen($author) > 100) {
        $error = '投稿者名は100文字以内で入力してください。';
    } elseif ($hasPostUserIdColumn && empty($userId)) {
        $error = 'ユーザー情報を取得できませんでした。もう一度ログインし直してください。';
    } else {
        try {
            if ($hasPostUserIdColumn) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, content, author, user_id, category) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $content, $author, $userId, $category]);
                } catch (PDOException $e) {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, content, author, user_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $content, $author, $userId]);
                }
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, content, author, category) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $content, $author, $category]);
                } catch (PDOException $e) {
                    $stmt = $pdo->prepare("INSERT INTO posts (title, content, author) VALUES (?, ?, ?)");
                    $stmt->execute([$title, $content, $author]);
                }
            }
            $success = '投稿が正常に作成されました。';
            // フォームをクリア
            $title = $content = '';
            $category = '雑談';
        } catch (PDOException $e) {
            $error = '投稿の作成に失敗しました。';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規投稿 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=6">
</head>
<body>
    <div class="container">
        <header>
            <h1>新規投稿</h1>
            <a href="index.php" class="btn btn-secondary">掲示板に戻る</a>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" class="post-form">
                <div class="form-group">
                    <label for="title">タイトル *</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title ?? ''); ?>" required maxlength="255">
                </div>

                <div class="form-group">
                    <label for="category">カテゴリ *</label>
                    <select id="category" name="category" required>
                        <?php foreach ($categoryOptions as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo (($category ?? '雑談') === $c) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>投稿者名</label>
                    <div class="logged-in-author"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                </div>

                <div class="form-group">
                    <label for="content">内容 *</label>
                    <textarea id="content" name="content" rows="10" required><?php echo htmlspecialchars($content ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">投稿する</button>
                    <a href="index.php" class="btn btn-secondary">キャンセル</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>



