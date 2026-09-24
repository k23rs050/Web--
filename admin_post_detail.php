<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';
require_once 'config/categories.php';
require_once 'config/upload.php';

ensureAdminSchema($pdo);
ensureImageSchema($pdo);
refreshAdminSession($pdo);
requireAdmin();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$postId = (int)($_GET['id'] ?? $_POST['post_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
        $error = '不正なリクエストです。ページを再読み込みしてから再度お試しください。';
    } elseif ($action === 'delete_post') {
        $result = deletePostFully($pdo, $postId);
        if ($result['ok']) {
            header('Location: admin_posts.php?deleted=1');
            exit;
        }
        $error = $result['error'] ?? '削除に失敗しました。';
    }
}

$post = null;
$replies = [];
$likeCount = 0;

if ($postId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if ($post) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
            $stmt->execute([$postId]);
            $likeCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT r.id, r.content, r.created_at, u.name AS user_name
                 FROM post_replies r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.post_id = ?
                 ORDER BY r.created_at ASC"
            );
            $stmt->execute([$postId]);
            $replies = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        $error = '投稿の取得に失敗しました。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>投稿詳細 - 管理者</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
    <div class="container">
        <header>
            <h1>投稿詳細</h1>
            <div class="header-actions">
                <a href="admin_posts.php" class="btn btn-secondary">投稿一覧に戻る</a>
                <a href="admin_users.php" class="btn btn-secondary">ユーザー一覧</a>
                <a href="index.php" class="btn btn-secondary">トップページ</a>
            </div>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!$post): ?>
                <div class="no-posts">
                    <p>投稿が見つかりません。</p>
                    <a href="admin_posts.php" class="btn btn-secondary">投稿一覧に戻る</a>
                </div>
            <?php else: ?>
                <article class="post admin-detail-card">
                    <div class="post-header">
                        <h2 class="post-title">
                            <?php if ((int)($post['is_pinned'] ?? 0) === 1): ?>
                                <span class="pinned-badge">固定</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($post['title']); ?>
                        </h2>
                        <div class="post-meta">
                            <span>ID: <?php echo (int)$post['id']; ?></span>
                            <span>投稿者: <?php echo htmlspecialchars($post['author']); ?></span>
                            <span>カテゴリ: <?php echo htmlspecialchars(formatCategoryLabel($post['category'] ?? '雑談')); ?></span>
                            <span>いいね: <?php echo $likeCount; ?></span>
                            <span>投稿: <?php echo date('Y年m月d日 H:i', strtotime($post['created_at'])); ?></span>
                            <span>更新: <?php echo date('Y年m月d日 H:i', strtotime($post['updated_at'])); ?></span>
                        </div>
                    </div>
                    <div class="post-content">
                        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                    </div>
                    <?php if (!empty($post['image_path'])): ?>
                        <div class="post-image-wrap">
                            <img class="post-image" src="<?php echo htmlspecialchars($post['image_path']); ?>" alt="投稿画像">
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="admin-detail-actions" onsubmit="return confirm('この投稿を削除しますか？');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                        <button type="submit" class="btn btn-danger">この投稿を削除</button>
                    </form>
                </article>

                <section class="replies">
                    <h3>返信（<?php echo count($replies); ?>件）</h3>
                    <?php if (empty($replies)): ?>
                        <p class="reply-empty">返信はありません。</p>
                    <?php else: ?>
                        <ul class="reply-list">
                            <?php foreach ($replies as $reply): ?>
                                <li class="reply-item">
                                    <p class="reply-content"><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>
                                    <div class="reply-meta">
                                        <span><?php echo htmlspecialchars($reply['user_name']); ?></span>
                                        <span><?php echo date('Y年m月d日 H:i', strtotime($reply['created_at'])); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
