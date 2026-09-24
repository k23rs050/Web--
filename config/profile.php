<?php
/**
 * プロフィール機能用の共通処理
 */
function ensureProfileSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'bio' => "ALTER TABLE users ADD COLUMN bio TEXT NULL",
        'hobby' => "ALTER TABLE users ADD COLUMN hobby VARCHAR(100) NULL",
        'location' => "ALTER TABLE users ADD COLUMN location VARCHAR(100) NULL",
        'avatar' => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL",
    ];

    foreach ($columns as $name => $sql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($name));
            if (!$stmt->fetch()) {
                $pdo->exec($sql);
            }
        } catch (PDOException $e) {
            // カラム追加に失敗しても続行
        }
    }
}

function findUserIdByName(PDO $pdo, string $name): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE name = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$name]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserProfile(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    ensureProfileSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, bio, hobby, location, avatar, is_admin, created_at
             FROM users
             WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (PDOException $e) {
        // avatar カラム未対応の古いDB向けフォールバック
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, email, bio, hobby, location, is_admin, created_at
                 FROM users
                 WHERE id = ?"
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                $user['avatar'] = null;
            }
            return $user ?: null;
        } catch (PDOException $e2) {
            return null;
        }
    }
}

function profileUrl(int $userId): string
{
    return 'profile.php?id=' . max(0, $userId);
}

function renderUserAvatarHtml(?array $user, string $extraClass = ''): string
{
    $name = (string)($user['name'] ?? '?');
    $avatar = trim((string)($user['avatar'] ?? ''));
    $class = trim('profile-avatar ' . $extraClass);

    if ($avatar !== '') {
        $fsPath = __DIR__ . '/../' . str_replace('\\', '/', $avatar);
        if (is_file($fsPath)) {
            $src = $avatar . '?v=' . filemtime($fsPath);
            return '<div class="' . htmlspecialchars($class) . ' profile-avatar-image" aria-hidden="true">'
                . '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($name) . '">'
                . '</div>';
        }
        // DBにパスがあってもファイルが無い場合は文字アイコンにフォールバック
    }

    return '<div class="' . htmlspecialchars($class) . '" aria-hidden="true">'
        . htmlspecialchars(mb_substr($name, 0, 1))
        . '</div>';
}
