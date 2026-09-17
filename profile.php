<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';
require_once 'config/profile.php';

ensureAdminSchema($pdo);
ensureProfileSchema($pdo);
refreshAdminSession($pdo);

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0 && isLoggedIn()) {
    $userId = (int)$_SESSION['user_id'];
}

$user = getUserProfile($pdo, $userId);
$isOwnProfile = isLoggedIn() && $user && ((int)$_SESSION['user_id'] === (int)$user['id']);

$postCount = 0;
$likeCount = 0;
$replyCount = 0;
$recentPosts = [];

if ($user) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $postCount = (int)$stmt->fetchColumn();

        // user_id 未設定の旧投稿も名前で補完カウント
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id IS NULL AND author = ?");
        $stmt->execute([$user['name']]);
        $postCount += (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE user_id = ?");
        $stmt->execute([$userId]);
        $likeCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_replies WHERE user_id = ?");
        $stmt->execute([$userId]);
        $replyCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, title, category, created_at, is_pinned
             FROM posts
             WHERE user_id = ? OR (user_id IS NULL AND author = ?)
             ORDER BY created_at DESC
             LIMIT 10"
        );
        $stmt->execute([$userId, $user['name']]);
        $recentPosts = $stmt->fetchAll();
    } catch (PDOException $e) {
        // 統計取得失敗時は0のまま
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $user ? htmlspecialchars($user['name']) . ' のプロフィール' : 'プロフィール'; ?> - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=7">
</head>
<body>
    <div class="container">
        <header>
            <h1>プロフィール</h1>
            <div class="header-actions">
                <?php if ($isOwnProfile): ?>
                    <a href="profile_edit.php" class="btn btn-primary">プロフィール編集</a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-secondary">トップページ</a>
            </div>
        </header>

        <main>
            <?php if (!$user): ?>
                <div class="no-posts">
                    <p>ユーザーが見つかりません。</p>
                    <a href="index.php" class="btn btn-secondary">トップに戻る</a>
                </div>
            <?php else: ?>
                <section class="profile-card">
                    <div class="profile-avatar" aria-hidden="true">
                        <?php echo htmlspecialchars(mb_substr($user['name'], 0, 1)); ?>
                    </div>
                    <div class="profile-main">
                        <h2 class="profile-name">
                            <?php echo htmlspecialchars($user['name']); ?>
                            <?php if ((int)($user['is_admin'] ?? 0) === 1): ?>
                                <span class="admin-badge">管理者</span>
                            <?php endif; ?>
                        </h2>
                        <p class="profile-meta">登録日: <?php echo date('Y年m月d日', strtotime($user['created_at'])); ?></p>

                        <dl class="profile-fields">
                            <div>
                                <dt>自己紹介</dt>
                                <dd>
                                    <?php if (trim((string)($user['bio'] ?? '')) !== ''): ?>
                                        <?php echo nl2br(htmlspecialchars($user['bio'])); ?>
                                    <?php else: ?>
                                        <span class="profile-empty">まだ自己紹介がありません。</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt>趣味・興味</dt>
                                <dd>
                                    <?php if (trim((string)($user['hobby'] ?? '')) !== ''): ?>
                                        <?php echo htmlspecialchars($user['hobby']); ?>
                                    <?php else: ?>
                                        <span class="profile-empty">未設定</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt>居住地・所属</dt>
                                <dd>
                                    <?php if (trim((string)($user['location'] ?? '')) !== ''): ?>
                                        <?php echo htmlspecialchars($user['location']); ?>
                                    <?php else: ?>
                                        <span class="profile-empty">未設定</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>

                        <div class="profile-stats">
                            <div class="profile-stat"><span class="profile-stat-num"><?php echo $postCount; ?></span><span>投稿</span></div>
                            <div class="profile-stat"><span class="profile-stat-num"><?php echo $likeCount; ?></span><span>いいね</span></div>
                            <div class="profile-stat"><span class="profile-stat-num"><?php echo $replyCount; ?></span><span>返信</span></div>
                        </div>
                    </div>
                </section>

                <section class="profile-posts">
                    <h3>最近の投稿</h3>
                    <?php if (empty($recentPosts)): ?>
                        <p class="profile-empty">まだ投稿がありません。</p>
                    <?php else: ?>
                        <ul class="profile-post-list">
                            <?php foreach ($recentPosts as $post): ?>
                                <li>
                                    <a href="index.php#post-<?php echo (int)$post['id']; ?>">
                                        <?php if ((int)($post['is_pinned'] ?? 0) === 1): ?>
                                            <span class="pinned-badge">固定</span>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($post['title']); ?>
                                    </a>
                                    <span class="profile-post-meta">
                                        <?php echo htmlspecialchars($post['category'] ?? '雑談'); ?>
                                        ・
                                        <?php echo date('Y/m/d H:i', strtotime($post['created_at'])); ?>
                                    </span>
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
