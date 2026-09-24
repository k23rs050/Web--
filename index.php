<?php
// 出力バッファリングを開始（リダイレクトエラーを防ぐ）
ob_start();

require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';
require_once 'config/profile.php';
require_once 'config/categories.php';
require_once 'config/upload.php';

ensureAdminSchema($pdo);
ensureProfileSchema($pdo);
ensureImageSchema($pdo);
refreshAdminSession($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['user_name'] ?? '';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_user_id INT NOT NULL,
        actor_user_id INT NOT NULL,
        actor_name VARCHAR(100) NOT NULL,
        post_id INT NOT NULL,
        type ENUM('like', 'reply') NOT NULL,
        message VARCHAR(255) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notifications_recipient_read_created (recipient_user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

function createNotification(PDO $pdo, int $recipientUserId, int $actorUserId, string $actorName, int $postId, string $type): void
{
    if ($recipientUserId === $actorUserId) {
        return;
    }

    $typeLabel = ($type === 'like') ? 'いいね' : '返信';
    $message = $actorName . 'さんがあなたの投稿に' . $typeLabel . 'しました。';

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (recipient_user_id, actor_user_id, actor_name, post_id, type, message)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$recipientUserId, $actorUserId, $actorName, $postId, $type, $message]);
}

function resolvePostOwnerId(PDO $pdo, int $postId, bool $hasPostUserIdColumn): int
{
    if ($hasPostUserIdColumn) {
        $stmt = $pdo->prepare("SELECT user_id, author FROM posts WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT author FROM posts WHERE id = ?");
    }
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    if (!$post) {
        return 0;
    }

    if (isset($post['user_id']) && $post['user_id'] !== null) {
        return (int)$post['user_id'];
    }

    $author = trim((string)($post['author'] ?? ''));
    if ($author === '') {
        return 0;
    }

    // 旧データなどで user_id が未設定の投稿向けフォールバック
    $stmt = $pdo->prepare("SELECT id FROM users WHERE name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$author]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function buildListUrl(string $sort, string $keyword, string $author, string $category, bool $savedOnly, int $page): string
{
    $params = [
        'sort' => $sort,
        'page' => max(1, $page),
    ];
    if ($keyword !== '') {
        $params['q'] = $keyword;
    }
    if ($author !== '') {
        $params['author'] = $author;
    }
    if ($category !== 'all') {
        $params['category'] = $category;
    }
    if ($savedOnly) {
        $params['saved'] = '1';
    }
    return 'index.php?' . http_build_query($params);
}

function canManagePostRow(array $post, ?int $currentUserId, string $currentUserName, bool $hasPostUserIdColumn): bool
{
    if ($currentUserId === null) {
        return false;
    }

    $postUserId = $post['user_id'] ?? null;
    if ($hasPostUserIdColumn && $postUserId !== null && $postUserId !== '') {
        if ((int)$postUserId === $currentUserId) {
            return true;
        }
    }

    $author = trim((string)($post['author'] ?? ''));
    $userName = trim($currentUserName);

    return $author !== '' && $author === $userName;
}

$hasPostUserIdColumn = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'user_id'");
    $hasPostUserIdColumn = (bool)$stmt->fetch();
} catch (PDOException $e) {
    $hasPostUserIdColumn = false;
}

if (!$hasPostUserIdColumn) {
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN user_id INT NULL");
        try {
            $pdo->exec(
                "ALTER TABLE posts ADD CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL"
            );
        } catch (PDOException $e) {
            // 外部キー追加に失敗してもカラム自体は利用可能
        }
        $hasPostUserIdColumn = true;
    } catch (PDOException $e) {
        $hasPostUserIdColumn = false;
    }
}

if ($hasPostUserIdColumn) {
    try {
        $pdo->exec(
            "UPDATE posts p
             INNER JOIN (
                 SELECT name, MIN(id) AS id
                 FROM users
                 GROUP BY name
             ) u ON u.name = p.author
             SET p.user_id = u.id
             WHERE p.user_id IS NULL"
        );
    } catch (PDOException $e) {
        // 既存投稿の user_id 補完に失敗しても続行
    }
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_post_likes_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            CONSTRAINT fk_post_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uk_post_likes_post_user (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    // いいね機能用テーブル作成に失敗した場合は後続処理でエラー表示
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_post_replies_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            CONSTRAINT fk_post_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    // 返信機能用テーブル作成に失敗した場合は後続処理でエラー表示
}

$isAdmin = isAdmin();


$hasPostPinnedColumn = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'is_pinned'");
    $hasPostPinnedColumn = (bool)$stmt->fetch();
} catch (PDOException $e) {
    $hasPostPinnedColumn = false;
}

if (!$hasPostPinnedColumn) {
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0");
        $hasPostPinnedColumn = true;
    } catch (PDOException $e) {
        $hasPostPinnedColumn = false;
    }
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_bookmarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_post_bookmarks_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            CONSTRAINT fk_post_bookmarks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uk_post_bookmarks_post_user (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    // ブックマーク機能用テーブル作成に失敗した場合は後続処理でエラー表示
}

$hasPostCategoryColumn = ensureCategoryColumn($pdo);
$categoryFlatOptions = getCategoryFlatOptions();
$categoryFilter = trim((string)($_GET['category'] ?? 'all'));
if ($categoryFilter === '') {
    $categoryFilter = 'all';
}
if ($categoryFilter !== 'all' && (!$hasPostCategoryColumn || !isValidCategoryPath($categoryFilter))) {
    // 旧データの単一カテゴリ名も許可
    if (!$hasPostCategoryColumn || !array_key_exists($categoryFilter, $categoryFlatOptions)) {
        $topLevel = array_keys(getCategoryTree());
        if (!in_array($categoryFilter, $topLevel, true)) {
            $categoryFilter = 'all';
        }
    }
}

$hasPostImageColumn = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'image_path'");
    $hasPostImageColumn = (bool)$stmt->fetch();
} catch (PDOException $e) {
    $hasPostImageColumn = false;
}

$allowedSorts = ['newest', 'likes'];
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

$keyword = trim((string)($_GET['q'] ?? ''));
if (strlen($keyword) > 100) {
    $keyword = substr($keyword, 0, 100);
}
$authorKeyword = trim((string)($_GET['author'] ?? ''));
if (strlen($authorKeyword) > 100) {
    $authorKeyword = substr($authorKeyword, 0, 100);
}
$savedOnly = isLoggedIn() && (($_GET['saved'] ?? '') === '1');

$currentPage = max(1, (int)($_GET['page'] ?? 1));
$postsPerPage = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = '不正なリクエストです。';
    } else {
        try {
            if ($action === 'toggle_like') {
                if (!isLoggedIn()) {
                    $error = 'いいねするにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);

                    $stmt = $pdo->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
                    $stmt->execute([$postId, $currentUserId]);
                    $exists = $stmt->fetch();

                    if ($exists) {
                        $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
                        $stmt->execute([$postId, $currentUserId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
                        $stmt->execute([$postId, $currentUserId]);

                        $postOwnerId = resolvePostOwnerId($pdo, $postId, $hasPostUserIdColumn);
                        if ($postOwnerId > 0) {
                            createNotification($pdo, $postOwnerId, (int)$currentUserId, $currentUserName, $postId, 'like');
                        }
                    }
                }
            } elseif ($action === 'toggle_bookmark') {
                if (!isLoggedIn()) {
                    $error = '保存するにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT id FROM post_bookmarks WHERE post_id = ? AND user_id = ?");
                    $stmt->execute([$postId, $currentUserId]);
                    $exists = $stmt->fetch();

                    if ($exists) {
                        $stmt = $pdo->prepare("DELETE FROM post_bookmarks WHERE post_id = ? AND user_id = ?");
                        $stmt->execute([$postId, $currentUserId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO post_bookmarks (post_id, user_id) VALUES (?, ?)");
                        $stmt->execute([$postId, $currentUserId]);
                    }
                }
            } elseif ($action === 'add_reply') {
                if (!isLoggedIn()) {
                    $error = '返信するにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);
                    $content = trim($_POST['reply_content'] ?? '');

                    if ($content === '') {
                        $error = '返信内容を入力してください。';
                    } elseif (strlen($content) > 1000) {
                        $error = '返信は1000文字以内で入力してください。';
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO post_replies (post_id, user_id, content) VALUES (?, ?, ?)"
                        );
                        $stmt->execute([$postId, $currentUserId, $content]);
                        $success = '返信を投稿しました。';

                        $postOwnerId = resolvePostOwnerId($pdo, $postId, $hasPostUserIdColumn);
                        if ($postOwnerId > 0) {
                            createNotification($pdo, $postOwnerId, (int)$currentUserId, $currentUserName, $postId, 'reply');
                        }
                    }
                }
            } elseif ($action === 'mark_notifications_read') {
                if (!isLoggedIn()) {
                    $error = '通知を既読にするにはログインが必要です。';
                } else {
                    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_user_id = ? AND is_read = 0");
                    $stmt->execute([$currentUserId]);
                }
            } elseif ($action === 'toggle_pin') {
                if (!isLoggedIn()) {
                    $error = 'ピン留めするにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);
                    if ($hasPostUserIdColumn) {
                        $stmt = $pdo->prepare("SELECT user_id, author FROM posts WHERE id = ?");
                    } else {
                        $stmt = $pdo->prepare("SELECT author FROM posts WHERE id = ?");
                    }
                    $stmt->execute([$postId]);
                    $targetPost = $stmt->fetch();

                    $isOwner = false;
                    if ($targetPost) {
                        $isOwner = canManagePostRow($targetPost, $currentUserId !== null ? (int)$currentUserId : null, $currentUserName, $hasPostUserIdColumn);
                    }

                    if (!$targetPost || (!$isOwner && !$isAdmin)) {
                        $error = '投稿者または管理者のみピン留めできます。';
                    } elseif (!$hasPostPinnedColumn) {
                        $error = 'この環境ではピン留め機能を利用できません。';
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE posts SET is_pinned = CASE WHEN is_pinned = 1 THEN 0 ELSE 1 END WHERE id = ?"
                        );
                        $stmt->execute([$postId]);
                    }
                }
            } elseif ($action === 'edit_post') {
                if (!isLoggedIn()) {
                    $error = '編集するにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);
                    $title = trim($_POST['title'] ?? '');
                    $content = trim($_POST['content'] ?? '');
                    $category = trim((string)($_POST['category'] ?? '雑談'));
                    $removeImage = isset($_POST['remove_image']);
                    $imageUpload = saveUploadedImage($_FILES['image'] ?? [], 'posts', 'post' . $postId);

                    if ($title === '' || $content === '') {
                        $error = 'タイトルと内容を入力してください。';
                    } elseif (strlen($title) > 255) {
                        $error = 'タイトルは255文字以内で入力してください。';
                    } elseif ($hasPostCategoryColumn && !isValidCategoryPath($category)) {
                        $error = 'カテゴリを選択してください。';
                    } elseif (!$imageUpload['ok']) {
                        $error = $imageUpload['error'] ?? '画像のアップロードに失敗しました。';
                    } else {
                        if ($hasPostUserIdColumn) {
                            $stmt = $pdo->prepare("SELECT user_id, author, image_path FROM posts WHERE id = ?");
                        } else {
                            $stmt = $pdo->prepare("SELECT author, image_path FROM posts WHERE id = ?");
                        }
                        try {
                            $stmt->execute([$postId]);
                            $targetPost = $stmt->fetch();
                        } catch (PDOException $e) {
                            if ($hasPostUserIdColumn) {
                                $stmt = $pdo->prepare("SELECT user_id, author FROM posts WHERE id = ?");
                            } else {
                                $stmt = $pdo->prepare("SELECT author FROM posts WHERE id = ?");
                            }
                            $stmt->execute([$postId]);
                            $targetPost = $stmt->fetch();
                            if ($targetPost) {
                                $targetPost['image_path'] = null;
                            }
                        }

                        $canEdit = $targetPost && canManagePostRow($targetPost, (int)$currentUserId, $currentUserName, $hasPostUserIdColumn);

                        if (!$canEdit) {
                            $error = '自分の投稿のみ編集できます。';
                            if (!empty($imageUpload['path'])) {
                                deleteUploadedFile($imageUpload['path']);
                            }
                        } else {
                            $imagePath = $targetPost['image_path'] ?? null;
                            if ($removeImage) {
                                deleteUploadedFile($imagePath);
                                $imagePath = null;
                            }
                            if (!empty($imageUpload['path'])) {
                                deleteUploadedFile($imagePath);
                                $imagePath = $imageUpload['path'];
                            }

                            if ($hasPostCategoryColumn && $hasPostImageColumn) {
                                $stmt = $pdo->prepare(
                                    "UPDATE posts SET title = ?, content = ?, category = ?, image_path = ? WHERE id = ?"
                                );
                                $stmt->execute([$title, $content, $category, $imagePath, $postId]);
                            } elseif ($hasPostCategoryColumn) {
                                $stmt = $pdo->prepare(
                                    "UPDATE posts SET title = ?, content = ?, category = ? WHERE id = ?"
                                );
                                $stmt->execute([$title, $content, $category, $postId]);
                            } elseif ($hasPostImageColumn) {
                                $stmt = $pdo->prepare(
                                    "UPDATE posts SET title = ?, content = ?, image_path = ? WHERE id = ?"
                                );
                                $stmt->execute([$title, $content, $imagePath, $postId]);
                            } else {
                                $stmt = $pdo->prepare(
                                    "UPDATE posts SET title = ?, content = ? WHERE id = ?"
                                );
                                $stmt->execute([$title, $content, $postId]);
                            }
                            $success = '投稿を編集しました。';
                        }
                    }
                }
            } elseif ($action === 'delete_post') {
                if (!isLoggedIn()) {
                    $error = '削除するにはログインが必要です。';
                } else {
                    $postId = (int)($_POST['post_id'] ?? 0);

                    if ($hasPostUserIdColumn) {
                        $stmt = $pdo->prepare("SELECT user_id, author FROM posts WHERE id = ?");
                    } else {
                        $stmt = $pdo->prepare("SELECT author FROM posts WHERE id = ?");
                    }
                    $stmt->execute([$postId]);
                    $targetPost = $stmt->fetch();

                    $canDelete = $targetPost && (
                        isAdmin()
                        || canManagePostRow($targetPost, (int)$currentUserId, $currentUserName, $hasPostUserIdColumn)
                    );

                    if (!$canDelete) {
                        $error = '削除する権限がありません。';
                    } else {
                        $result = deletePostFully($pdo, $postId);
                        if ($result['ok']) {
                            $success = '投稿を削除しました。';
                        } else {
                            $error = $result['error'] ?? '削除に失敗しました。';
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = '処理中にエラーが発生しました。';
        }
    }
}

// 投稿一覧を取得（いいね数とログインユーザーのいいね状態付き）
$whereParts = [];
$whereParams = [];
if ($keyword !== '') {
    $whereParts[] = "(p.title LIKE ? OR p.content LIKE ? OR p.author LIKE ?)";
    $likeKeyword = '%' . $keyword . '%';
    $whereParams[] = $likeKeyword;
    $whereParams[] = $likeKeyword;
    $whereParams[] = $likeKeyword;
}
if ($authorKeyword !== '') {
    $whereParts[] = "p.author LIKE ?";
    $whereParams[] = '%' . $authorKeyword . '%';
}
if ($categoryFilter !== 'all' && $hasPostCategoryColumn) {
    $filterValues = getCategoryFilterValues($categoryFilter);
    if (!empty($filterValues)) {
        $placeholders = implode(',', array_fill(0, count($filterValues), '?'));
        $whereParts[] = "p.category IN ($placeholders)";
        foreach ($filterValues as $v) {
            $whereParams[] = $v;
        }
    }
}
if ($savedOnly && isLoggedIn()) {
    $whereParts[] = "pb.user_id IS NOT NULL";
}
$whereSql = !empty($whereParts) ? ("WHERE " . implode(' AND ', $whereParts)) : '';

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM posts p
     LEFT JOIN post_bookmarks pb ON pb.post_id = p.id AND pb.user_id = ?
     $whereSql"
);
$stmt->execute(array_merge([$currentUserId], $whereParams));
$totalPosts = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalPosts / $postsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $postsPerPage;

$orderBySql = ($sort === 'likes')
    ? "COALESCE(plc.like_count, 0) DESC, p.created_at DESC"
    : "p.created_at DESC";
$orderBySql = ($hasPostPinnedColumn ? "COALESCE(p.is_pinned, 0) DESC, " : "") . $orderBySql;

$stmt = $pdo->prepare(
    "SELECT
        p.*,
        COALESCE(plc.like_count, 0) AS like_count,
        CASE
            WHEN ? IS NULL THEN 0
            WHEN pb.user_id IS NULL THEN 0
            ELSE 1
        END AS is_bookmarked,
        CASE
            WHEN ? IS NULL THEN 0
            WHEN plu.user_id IS NULL THEN 0
            ELSE 1
        END AS is_liked
    FROM posts p
    LEFT JOIN (
        SELECT post_id, COUNT(*) AS like_count
        FROM post_likes
        GROUP BY post_id
    ) plc ON plc.post_id = p.id
    LEFT JOIN post_bookmarks pb ON pb.post_id = p.id AND pb.user_id = ?
    LEFT JOIN post_likes plu ON plu.post_id = p.id AND plu.user_id = ?
    $whereSql
    ORDER BY $orderBySql
    LIMIT ? OFFSET ?"
);
$bindParams = array_merge([$currentUserId, $currentUserId, $currentUserId, $currentUserId], $whereParams);
$position = 1;
foreach ($bindParams as $param) {
    $stmt->bindValue($position, $param);
    $position++;
}
$stmt->bindValue($position, (int)$postsPerPage, PDO::PARAM_INT);
$stmt->bindValue($position + 1, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$postIds = array_column($posts, 'id');
$repliesByPost = [];
if (!empty($postIds)) {
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT
            r.id,
            r.post_id,
            r.user_id,
            r.content,
            r.created_at,
            u.name AS user_name
        FROM post_replies r
        INNER JOIN users u ON u.id = r.user_id
        WHERE r.post_id IN ($placeholders)
        ORDER BY r.created_at ASC"
    );
    $stmt->execute($postIds);
    $replies = $stmt->fetchAll();

    foreach ($replies as $reply) {
        $postId = (int)$reply['post_id'];
        if (!isset($repliesByPost[$postId])) {
            $repliesByPost[$postId] = [];
        }
        $repliesByPost[$postId][] = $reply;
    }
}

$notifications = [];
$unreadNotificationCount = 0;
if (isLoggedIn()) {
    $stmt = $pdo->prepare(
        "SELECT id, message, post_id, is_read, created_at
         FROM notifications
         WHERE recipient_user_id = ?
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $stmt->execute([$currentUserId]);
    $notifications = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
    $stmt->execute([$currentUserId]);
    $unreadNotificationCount = (int)$stmt->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=9">
</head>
<body>
    <div class="container">
        <header>
            <h1>掲示板アプリ</h1>
            <div class="header-actions">
                <?php if (isLoggedIn()): ?>
                    <?php if (isAdmin()): ?>
                        <a href="admin_posts.php" class="btn btn-admin">投稿管理</a>
                        <a href="admin_users.php" class="btn btn-admin">ユーザー一覧</a>
                    <?php endif; ?>
                    <a href="profile.php?id=<?php echo (int)$_SESSION['user_id']; ?>" class="btn btn-secondary">マイプロフィール</a>
                    <details class="notification-menu">
                        <summary class="btn btn-secondary">
                            通知<?php if ($unreadNotificationCount > 0): ?> (<?php echo $unreadNotificationCount; ?>)<?php endif; ?>
                        </summary>
                        <div class="notification-dropdown">
                            <?php if (empty($notifications)): ?>
                                <p class="notification-empty">通知はまだありません。</p>
                            <?php else: ?>
                                <form method="POST" class="notification-read-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="mark_notifications_read">
                                    <button type="submit" class="btn btn-secondary">すべて既読にする</button>
                                </form>
                                <ul class="notification-list">
                                    <?php foreach ($notifications as $notification): ?>
                                        <li class="notification-item <?php echo ((int)$notification['is_read'] === 0) ? 'notification-unread' : ''; ?>">
                                            <a href="index.php#post-<?php echo (int)$notification['post_id']; ?>">
                                                <?php echo htmlspecialchars($notification['message']); ?>
                                            </a>
                                            <span class="notification-date">
                                                <?php echo date('m/d H:i', strtotime($notification['created_at'])); ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </details>
                    <span class="user-info">
                        ようこそ、<a class="user-name-link" href="profile.php?id=<?php echo (int)$_SESSION['user_id']; ?>"><?php echo htmlspecialchars($_SESSION['user_name']); ?></a>さん
                        <?php if (isAdmin()): ?><span class="admin-badge">管理者</span><?php endif; ?>
                    </span>
                    <a href="post.php" class="btn btn-primary">新規投稿</a>
                    <a href="logout.php" class="btn btn-secondary">ログアウト</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">ログインして新規投稿</a>
                    <a href="register.php" class="btn btn-secondary">新規登録</a>
                    <a href="admin_login.php" class="btn btn-secondary">管理者ログイン</a>
                <?php endif; ?>
            </div>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['login_success'])): ?>
                <div class="alert alert-success">
                    <?php if (!empty($_SESSION['admin_login'])): ?>
                        管理者としてログインしました。投稿管理・ユーザー一覧から管理できます。
                        <?php unset($_SESSION['admin_login']); ?>
                    <?php else: ?>
                        ログインに成功しました。ようこそ、<?php echo htmlspecialchars($_SESSION['user_name']); ?>さん！
                    <?php endif; ?>
                </div>
                <?php unset($_SESSION['login_success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['logout_success'])): ?>
                <div class="alert alert-success">ログアウトしました。</div>
                <?php unset($_SESSION['logout_success']); ?>
            <?php endif; ?>
            
            <section class="list-controls">
                <form method="GET" class="list-controls-form">
                    <div class="list-controls-item">
                        <label for="sort">並び替え</label>
                        <select id="sort" name="sort">
                            <option value="newest" <?php echo ($sort === 'newest') ? 'selected' : ''; ?>>新着順</option>
                            <option value="likes" <?php echo ($sort === 'likes') ? 'selected' : ''; ?>>いいね順</option>
                        </select>
                    </div>
                    <div class="list-controls-item list-controls-category">
                        <label>カテゴリ</label>
                        <?php echo renderCategoryCascadeSelects($categoryFilter, 'category', 'filter-cat', false, true); ?>
                    </div>
                    <div class="list-controls-item list-controls-search">
                        <label for="q">キーワード検索</label>
                        <input
                            type="text"
                            id="q"
                            name="q"
                            value="<?php echo htmlspecialchars($keyword); ?>"
                            maxlength="100"
                            placeholder="タイトル・内容・投稿者で検索"
                        >
                    </div>
                    <div class="list-controls-item list-controls-author">
                        <label for="author">投稿者</label>
                        <input
                            type="text"
                            id="author"
                            name="author"
                            value="<?php echo htmlspecialchars($authorKeyword); ?>"
                            maxlength="100"
                            placeholder="投稿者名で検索"
                        >
                    </div>
                    <div class="list-controls-actions">
                        <?php if (isLoggedIn()): ?>
                            <label class="saved-only-toggle">
                                <input type="checkbox" name="saved" value="1" <?php echo $savedOnly ? 'checked' : ''; ?>>
                                保存済みのみ
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">適用</button>
                        <a href="index.php" class="btn btn-secondary">リセット</a>
                    </div>
                </form>
                <?php
                    $displayFrom = ($totalPosts === 0) ? 0 : ($offset + 1);
                    $displayTo = ($totalPosts === 0) ? 0 : ($offset + count($posts));
                ?>
                <p class="list-controls-result"><?php echo $totalPosts; ?>件中 <?php echo $displayFrom; ?> - <?php echo $displayTo; ?>件を表示</p>
            </section>

            <div class="posts-container">
                <?php if (empty($posts)): ?>
                    <div class="no-posts">
                        <p>まだ投稿がありません。</p>
                        <a href="post.php" class="btn btn-primary">最初の投稿を作成</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <article class="post" id="post-<?php echo (int)$post['id']; ?>">
                            <div class="post-header">
                                <h2 class="post-title">
                                    <?php if ((int)($post['is_pinned'] ?? 0) === 1): ?>
                                        <span class="pinned-badge">固定</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($post['title']); ?>
                                </h2>
                                <div class="post-meta">
                                    <span class="author">
                                        投稿者:
                                        <?php
                                            $authorProfileId = 0;
                                            if (!empty($post['user_id'])) {
                                                $authorProfileId = (int)$post['user_id'];
                                            } else {
                                                $authorProfileId = findUserIdByName($pdo, (string)($post['author'] ?? ''));
                                            }
                                        ?>
                                        <?php if ($authorProfileId > 0): ?>
                                            <a class="user-name-link" href="profile.php?id=<?php echo $authorProfileId; ?>">
                                                <?php echo htmlspecialchars($post['author']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($post['author']); ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($hasPostCategoryColumn): ?>
                                        <span class="category">カテゴリ: <?php echo htmlspecialchars(formatCategoryLabel($post['category'] ?? '雑談')); ?></span>
                                    <?php endif; ?>
                                    <span class="date"><?php echo date('Y年m月d日 H:i', strtotime($post['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="post-content">
                                <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                            </div>
                            <?php if (!empty($post['image_path'])): ?>
                                <div class="post-image-wrap">
                                    <a href="<?php echo htmlspecialchars($post['image_path']); ?>" target="_blank" rel="noopener">
                                        <img class="post-image" src="<?php echo htmlspecialchars($post['image_path']); ?>" alt="投稿画像">
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="post-actions">
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="toggle_bookmark">
                                    <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                    <button type="submit" class="btn btn-bookmark <?php echo ((int)$post['is_bookmarked'] === 1) ? 'btn-bookmark-active' : ''; ?>">
                                        <?php echo ((int)$post['is_bookmarked'] === 1) ? '保存済み' : '保存'; ?>
                                    </button>
                                </form>

                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="toggle_like">
                                    <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                    <button type="submit" class="btn btn-like <?php echo ((int)$post['is_liked'] === 1) ? 'btn-like-active' : ''; ?>">
                                        いいね <?php echo (int)$post['like_count']; ?>
                                    </button>
                                </form>

                                <?php
                                    $canManagePost = canManagePostRow(
                                        $post,
                                        $currentUserId !== null ? (int)$currentUserId : null,
                                        $currentUserName,
                                        $hasPostUserIdColumn
                                    );
                                    $canPinPost = isLoggedIn() && ($canManagePost || $isAdmin);
                                    $canDeletePost = isLoggedIn() && ($canManagePost || $isAdmin);
                                ?>
                                <?php if ($canPinPost): ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                        <button type="submit" class="btn btn-pin <?php echo ((int)($post['is_pinned'] ?? 0) === 1) ? 'btn-pin-active' : ''; ?>">
                                            <?php echo ((int)($post['is_pinned'] ?? 0) === 1) ? '固定解除' : 'ピン留め'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManagePost): ?>
                                    <details class="post-edit">
                                        <summary class="btn btn-edit">編集</summary>
                                        <form method="POST" class="edit-form" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="edit_post">
                                            <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                            <div class="form-group">
                                                <label>タイトル</label>
                                                <input type="text" name="title" maxlength="255" required value="<?php echo htmlspecialchars($post['title']); ?>">
                                            </div>
                                            <?php if ($hasPostCategoryColumn): ?>
                                                <div class="form-group">
                                                    <label>カテゴリ</label>
                                                    <?php echo renderCategoryCascadeSelects(
                                                        (string)($post['category'] ?? '雑談'),
                                                        'category',
                                                        'edit-cat-' . (int)$post['id'],
                                                        true,
                                                        false
                                                    ); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="form-group">
                                                <label>内容</label>
                                                <textarea name="content" rows="4" required><?php echo htmlspecialchars($post['content']); ?></textarea>
                                            </div>
                                            <?php if ($hasPostImageColumn): ?>
                                                <div class="form-group">
                                                    <label>画像</label>
                                                    <?php if (!empty($post['image_path'])): ?>
                                                        <div class="post-image-wrap post-image-edit">
                                                            <img class="post-image" src="<?php echo htmlspecialchars($post['image_path']); ?>" alt="現在の画像">
                                                        </div>
                                                        <label class="checkbox-inline">
                                                            <input type="checkbox" name="remove_image" value="1">
                                                            現在の画像を削除する
                                                        </label>
                                                    <?php endif; ?>
                                                    <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                                                    <p class="form-help">JPEG / PNG / GIF / WebP（2MB以内）・未選択なら現状維持</p>
                                                </div>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-primary">保存</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($canDeletePost): ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('この投稿を削除しますか？');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                        <button type="submit" class="btn btn-danger">削除</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if (!isLoggedIn()): ?>
                                <p class="post-login-note">いいね・編集・削除・ピン留めには<a href="login.php">ログイン</a>が必要です。</p>
                            <?php endif; ?>

                            <section class="replies">
                                <h3>返信</h3>
                                <?php if (empty($repliesByPost[(int)$post['id']])): ?>
                                    <p class="reply-empty">まだ返信がありません。</p>
                                <?php else: ?>
                                    <ul class="reply-list">
                                        <?php foreach ($repliesByPost[(int)$post['id']] as $reply): ?>
                                            <li class="reply-item">
                                                <p class="reply-content"><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>
                                                <div class="reply-meta">
                                                    <span>
                                                        <?php if (!empty($reply['user_id'])): ?>
                                                            <a class="user-name-link" href="profile.php?id=<?php echo (int)$reply['user_id']; ?>">
                                                                <?php echo htmlspecialchars($reply['user_name']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($reply['user_name']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span><?php echo date('Y年m月d日 H:i', strtotime($reply['created_at'])); ?></span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <?php if (isLoggedIn()): ?>
                                    <form method="POST" class="reply-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="add_reply">
                                        <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
                                        <textarea name="reply_content" rows="3" maxlength="1000" placeholder="返信を入力してください"></textarea>
                                        <button type="submit" class="btn btn-primary">返信する</button>
                                    </form>
                                <?php else: ?>
                                    <p class="reply-login-note">返信にはログインが必要です。</p>
                                <?php endif; ?>
                            </section>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(buildListUrl($sort, $keyword, $authorKeyword, $categoryFilter, $savedOnly, $currentPage - 1)); ?>">前へ</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a
                            class="btn <?php echo ($i === $currentPage) ? 'btn-primary' : 'btn-secondary'; ?>"
                            href="<?php echo htmlspecialchars(buildListUrl($sort, $keyword, $authorKeyword, $categoryFilter, $savedOnly, $i)); ?>"
                        >
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(buildListUrl($sort, $keyword, $authorKeyword, $categoryFilter, $savedOnly, $currentPage + 1)); ?>">次へ</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>



