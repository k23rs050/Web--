<?php
/**
 * 画像アップロード共通処理
 */

function ensureUploadDirs(): void
{
    $dirs = [
        __DIR__ . '/../uploads',
        __DIR__ . '/../uploads/avatars',
        __DIR__ . '/../uploads/posts',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    $htaccess = __DIR__ . '/../uploads/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n  Require all denied\n</FilesMatch>\n"
        );
    }
}

function ensureImageSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensureUploadDirs();

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL");
        }
    } catch (PDOException $e) {
        // 続行
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'image_path'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN image_path VARCHAR(255) NULL");
        }
    } catch (PDOException $e) {
        // 続行
    }
}

/**
 * @return array{ok:bool, path:?string, error:?string}
 */
function saveUploadedImage(array $file, string $subdir, string $prefix = 'img'): array
{
    ensureUploadDirs();

    if ($file === [] || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => '画像がサーバーの上限を超えています。',
        UPLOAD_ERR_FORM_SIZE => '画像が大きすぎます。',
        UPLOAD_ERR_PARTIAL => '画像のアップロードが途中で切れました。',
        UPLOAD_ERR_NO_TMP_DIR => '一時フォルダがありません。',
        UPLOAD_ERR_CANT_WRITE => 'ディスクへの書き込みに失敗しました。',
        UPLOAD_ERR_EXTENSION => '拡張機能によりアップロードが拒否されました。',
    ];
    $errCode = (int)$file['error'];
    if ($errCode !== UPLOAD_ERR_OK) {
        return [
            'ok' => false,
            'path' => null,
            'error' => $uploadErrors[$errCode] ?? '画像のアップロードに失敗しました。',
        ];
    }

    $maxBytes = 2 * 1024 * 1024; // 2MB
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
        return ['ok' => false, 'path' => null, 'error' => '画像は2MB以内にしてください。'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'path' => null, 'error' => '不正なアップロードです。'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($tmp);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    if ($mime === null || !isset($allowed[$mime])) {
        $imgInfo = @getimagesize($tmp);
        if (is_array($imgInfo) && !empty($imgInfo['mime'])) {
            $mime = $imgInfo['mime'];
        }
    }
    if ($mime === null || !isset($allowed[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => '画像は JPEG / PNG / GIF / WebP のみ対応です。'];
    }

    $subdir = trim($subdir, '/');
    $relativeDir = 'uploads/' . $subdir;
    $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        return ['ok' => false, 'path' => null, 'error' => '画像保存フォルダを作成できません。'];
    }
    if (!is_writable($absoluteDir)) {
        return ['ok' => false, 'path' => null, 'error' => '画像保存フォルダに書き込めません。'];
    }

    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    $relativePath = $relativeDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $absolutePath)) {
        return ['ok' => false, 'path' => null, 'error' => '画像の保存に失敗しました。'];
    }

    return ['ok' => true, 'path' => $relativePath, 'error' => null];
}

function deleteUploadedFile(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    if (!preg_match('#^uploads/(avatars|posts)/[A-Za-z0-9._-]+$#', $relativePath)) {
        return;
    }
    $absolute = __DIR__ . '/../' . $relativePath;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function avatarUrl(?string $avatar): ?string
{
    if ($avatar === null || $avatar === '') {
        return null;
    }
    return $avatar;
}
