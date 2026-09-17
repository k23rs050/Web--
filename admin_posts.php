<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';

ensureAdminSchema($pdo);
refreshAdminSession($pdo);
requireAdmin();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $success = '投稿を削除しました。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
        $error = '不正なリクエストです。ページを再読み込みしてから再度お試しください。';
    } elseif ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $result = deletePostFully($pdo, $postId);
        if ($result['ok']) {
            header('Location: admin_posts.php?deleted=1');
            exit;
        }
        $error = $result['error'] ?? '削除に失敗しました。';
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));
if (strlen($keyword) > 100) {
    $keyword = substr($keyword, 0, 100);
}

$whereSql = '';
$params = [];
if ($keyword !== '') {
    $whereSql = "WHERE p.title LIKE ? OR p.content LIKE ? OR p.author LIKE ?";
    $like = '%' . $keyword . '%';
    $params = [$like, $like, $like];
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.title,
            p.author,
            p.category,
            p.is_pinned,
            p.created_at,
            p.updated_at,
            COALESCE(plc.like_count, 0) AS like_count,
            COALESCE(rc.reply_count, 0) AS reply_count
         FROM posts p
         LEFT JOIN (
            SELECT post_id, COUNT(*) AS like_count FROM post_likes GROUP BY post_id
         ) plc ON plc.post_id = p.id
         LEFT JOIN (
            SELECT post_id, COUNT(*) AS reply_count FROM post_replies GROUP BY post_id
         ) rc ON rc.post_id = p.id
         $whereSql
         ORDER BY p.created_at DESC"
    );
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
} catch (PDOException $e) {
    $posts = [];
    $error = $error !== '' ? $error : '投稿一覧の取得に失敗しました。';
    error_log('admin_posts list: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>投稿管理 - 管理者</title>
    <link rel="stylesheet" href="css/style.css?v=6">
</head>
<body>
    <div class="container">
        <header>
            <h1>投稿管理</h1>
            <div class="header-actions">
                <a href="admin_users.php" class="btn btn-secondary">ユーザー一覧</a>
                <a href="index.php" class="btn btn-secondary">トップページ</a>
                <a href="logout.php" class="btn btn-secondary">ログアウト</a>
            </div>
        </header>

        <main>
            <p class="admin-page-intro">すべての投稿の一覧確認・詳細表示・削除ができます。</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="GET" class="admin-search-form">
                <input
                    type="text"
                    name="q"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                    maxlength="100"
                    placeholder="タイトル・内容・投稿者で検索"
                >
                <button type="submit" class="btn btn-primary">検索</button>
                <a href="admin_posts.php" class="btn btn-secondary">リセット</a>
            </form>

            <p class="list-controls-result"><?php echo count($posts); ?>件の投稿</p>

            <?php if (empty($posts)): ?>
                <div class="no-posts"><p>投稿がありません。</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>タイトル</th>
                                <th>投稿者</th>
                                <th>カテゴリ</th>
                                <th>いいね</th>
                                <th>返信</th>
                                <th>固定</th>
                                <th>投稿日時</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td><?php echo (int)$post['id']; ?></td>
                                    <td><?php echo htmlspecialchars($post['title']); ?></td>
                                    <td><?php echo htmlspecialchars($post['author']); ?></td>
                                    <td><?php echo htmlspecialchars($post['category'] ?? '雑談'); ?></td>
                                    <td><?php echo (int)$post['like_count']; ?></td>
                                    <td><?php echo (int)$post['reply_count']; ?></td>
                                    <td><?php echo ((int)($post['is_pinned'] ?? 0) === 1) ? '固定' : '-'; ?></td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($post['created_at'])); ?></td>
                                    <td class="admin-actions">
                                        <a class="btn btn-secondary" href="admin_post_detail.php?id=<?php echo (int)$post['id']; ?>">詳細</a>
                                        <form method="POST" action="admin_posts.php" class="inline-form" onsubmit="return confirm('この投稿を削除しますか？');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="delete_post">
                                            <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                            <button type="submit" class="btn btn-danger">削除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
