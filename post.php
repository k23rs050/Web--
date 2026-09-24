<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/categories.php';
require_once 'config/upload.php';

requireLogin();

ensureCategoryColumn($pdo);
ensureImageSchema($pdo);

$error = '';
$success = '';

$stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'user_id'");
$hasPostUserIdColumn = (bool)$stmt->fetch();

$hasPostCategoryColumn = ensureCategoryColumn($pdo);

$title = '';
$content = '';
$category = '雑談';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = trim((string)($_POST['category'] ?? '雑談'));

    $author = $_SESSION['user_name'] ?? '';
    $userId = $_SESSION['user_id'] ?? null;

    $imageUpload = saveUploadedImage($_FILES['image'] ?? [], 'posts', 'post');
    $imagePath = null;

    if (empty($title)) {
        $error = 'タイトルを入力してください。';
    } elseif (empty($content)) {
        $error = '内容を入力してください。';
    } elseif ($hasPostCategoryColumn && !isValidCategoryPath($category)) {
        $error = 'カテゴリを選択してください。';
    } elseif (empty($author)) {
        $error = '投稿者名を取得できませんでした。もう一度ログインし直してください。';
    } elseif (strlen($title) > 255) {
        $error = 'タイトルは255文字以内で入力してください。';
    } elseif (strlen($author) > 100) {
        $error = '投稿者名は100文字以内で入力してください。';
    } elseif ($hasPostUserIdColumn && empty($userId)) {
        $error = 'ユーザー情報を取得できませんでした。もう一度ログインし直してください。';
    } elseif (!$imageUpload['ok']) {
        $error = $imageUpload['error'] ?? '画像のアップロードに失敗しました。';
    } else {
        $imagePath = $imageUpload['path'];
        try {
            // image_path カラムを必ず使う（無い場合はここで追加して再実行）
            ensureImageSchema($pdo);

            if ($hasPostUserIdColumn) {
                $stmt = $pdo->prepare(
                    "INSERT INTO posts (title, content, author, user_id, category, image_path) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$title, $content, $author, $userId, $category, $imagePath]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO posts (title, content, author, category, image_path) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$title, $content, $author, $category, $imagePath]);
            }
            $success = '投稿が正常に作成されました。';
            if ($imagePath) {
                $success .= '（画像も添付しました）';
            }
            $title = $content = '';
            $category = '雑談';
        } catch (PDOException $e) {
            if ($imagePath) {
                deleteUploadedFile($imagePath);
            }
            $error = '投稿の作成に失敗しました。';
            error_log('post.php insert: ' . $e->getMessage());
        }
    }

    if ($error !== '' && !empty($imageUpload['path'])) {
        deleteUploadedFile($imageUpload['path']);
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規投稿 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=9">
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

            <form method="POST" class="post-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">タイトル *</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required maxlength="255">
                </div>

                <?php if ($hasPostCategoryColumn): ?>
                    <div class="form-group">
                        <label>カテゴリ *</label>
                        <p class="form-help">大・中・小の最大3階層から選べます（中・小は任意）。</p>
                        <?php echo renderCategoryCascadeSelects($category, 'category', 'post-cat', true, false); ?>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>投稿者名</label>
                    <div class="logged-in-author"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                </div>

                <div class="form-group">
                    <label for="content">内容 *</label>
                    <textarea id="content" name="content" rows="10" required><?php echo htmlspecialchars($content); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="image">画像（任意）</label>
                    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="form-help">JPEG / PNG / GIF / WebP（2MB以内）</p>
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
