<?php
/**
 * 管理者機能用の共通処理（is_admin カラム確保・初期管理者作成）
 */
function ensureAdminSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hasAdminColumn = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
        $hasAdminColumn = (bool)$stmt->fetch();
    } catch (PDOException $e) {
        $hasAdminColumn = false;
    }

    if (!$hasAdminColumn) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
            $hasAdminColumn = true;
        } catch (PDOException $e) {
            return;
        }
    }

    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute(['admin@example.com']);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $hash = password_hash('admin1234', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET is_admin = 1, password = ?, name = ? WHERE id = ?");
                $stmt->execute([$hash, '管理者', (int)$existingId]);
            } else {
                $hash = password_hash('admin1234', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 1)"
                );
                $stmt->execute(['管理者', 'admin@example.com', $hash]);
            }
        }
    } catch (PDOException $e) {
        // 初期管理者作成に失敗しても続行
    }
}
function refreshAdminSession(PDO $pdo): void
{
    if (!isLoggedIn()) {
        $_SESSION['is_admin'] = false;
        return;
    }

    try {
        ensureAdminSchema($pdo);
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $_SESSION['is_admin'] = ((int)$stmt->fetchColumn() === 1);
    } catch (PDOException $e) {
        $_SESSION['is_admin'] = false;
    }
}

/**
 * 投稿を関連データごと削除する
 * @return array{ok:bool,title:?string,error:?string}
 */
function deletePostFully(PDO $pdo, int $postId): array
{
    try {
        $imagePath = null;
        try {
            $stmt = $pdo->prepare("SELECT id, title, image_path FROM posts WHERE id = ?");
            $stmt->execute([$postId]);
            $target = $stmt->fetch();
            if ($target) {
                $imagePath = $target['image_path'] ?? null;
            }
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT id, title FROM posts WHERE id = ?");
            $stmt->execute([$postId]);
            $target = $stmt->fetch();
        }

        if (!$target) {
            return ['ok' => false, 'title' => null, 'error' => '投稿が見つかりません。'];
        }

        $pdo->beginTransaction();

        // 外部キーがない関連テーブルも先に消す
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE post_id = ?");
        $stmt->execute([$postId]);

        try {
            $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id = ?");
            $stmt->execute([$postId]);
        } catch (PDOException $e) {
            // テーブルや制約差異があっても続行
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM post_bookmarks WHERE post_id = ?");
            $stmt->execute([$postId]);
        } catch (PDOException $e) {
            // 続行
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM post_replies WHERE post_id = ?");
            $stmt->execute([$postId]);
        } catch (PDOException $e) {
            // 続行
        }

        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$postId]);

        $pdo->commit();

        if ($imagePath && function_exists('deleteUploadedFile')) {
            deleteUploadedFile($imagePath);
        }

        return ['ok' => true, 'title' => (string)$target['title'], 'error' => null];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('deletePostFully: ' . $e->getMessage());
        return ['ok' => false, 'title' => null, 'error' => '削除中にエラーが発生しました。'];
    }
}
