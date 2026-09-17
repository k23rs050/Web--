<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';

ensureAdminSchema($pdo);
refreshAdminSession($pdo);
requireAdmin();

$keyword = trim((string)($_GET['q'] ?? ''));
if (strlen($keyword) > 100) {
    $keyword = substr($keyword, 0, 100);
}

$whereSql = '';
$params = [];
if ($keyword !== '') {
    $whereSql = "WHERE u.name LIKE ? OR u.email LIKE ?";
    $like = '%' . $keyword . '%';
    $params = [$like, $like];
}

$stmt = $pdo->prepare(
    "SELECT
        u.id,
        u.name,
        u.email,
        u.is_admin,
        u.created_at,
        COALESCE(pc.post_count, 0) AS post_count,
        COALESCE(lc.like_count, 0) AS like_count,
        COALESCE(rc.reply_count, 0) AS reply_count
     FROM users u
     LEFT JOIN (
        SELECT user_id, COUNT(*) AS post_count FROM posts WHERE user_id IS NOT NULL GROUP BY user_id
     ) pc ON pc.user_id = u.id
     LEFT JOIN (
        SELECT user_id, COUNT(*) AS like_count FROM post_likes GROUP BY user_id
     ) lc ON lc.user_id = u.id
     LEFT JOIN (
        SELECT user_id, COUNT(*) AS reply_count FROM post_replies GROUP BY user_id
     ) rc ON rc.user_id = u.id
     $whereSql
     ORDER BY u.id ASC"
);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザー一覧 - 管理者</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
    <div class="container">
        <header>
            <h1>ユーザー一覧</h1>
            <div class="header-actions">
                <a href="admin_posts.php" class="btn btn-secondary">投稿管理</a>
                <a href="index.php" class="btn btn-secondary">トップページ</a>
                <a href="logout.php" class="btn btn-secondary">ログアウト</a>
            </div>
        </header>

        <main>
            <p class="admin-page-intro">登録ユーザーの一覧を確認できます。</p>

            <form method="GET" class="admin-search-form">
                <input
                    type="text"
                    name="q"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                    maxlength="100"
                    placeholder="名前・メールで検索"
                >
                <button type="submit" class="btn btn-primary">検索</button>
                <a href="admin_users.php" class="btn btn-secondary">リセット</a>
            </form>

            <p class="list-controls-result"><?php echo count($users); ?>人のユーザー</p>

            <?php if (empty($users)): ?>
                <div class="no-posts"><p>ユーザーがいません。</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名前</th>
                                <th>メール</th>
                                <th>権限</th>
                                <th>投稿数</th>
                                <th>いいね数</th>
                                <th>返信数</th>
                                <th>登録日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo (int)$user['id']; ?></td>
                                    <td>
                                        <a class="user-name-link" href="profile.php?id=<?php echo (int)$user['id']; ?>">
                                            <?php echo htmlspecialchars($user['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ((int)$user['is_admin'] === 1): ?>
                                            <span class="admin-badge">管理者</span>
                                        <?php else: ?>
                                            一般
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$user['post_count']; ?></td>
                                    <td><?php echo (int)$user['like_count']; ?></td>
                                    <td><?php echo (int)$user['reply_count']; ?></td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($user['created_at'])); ?></td>
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
