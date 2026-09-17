<?php
ob_start();
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/admin.php';
require_once 'config/profile.php';

ensureAdminSchema($pdo);
ensureProfileSchema($pdo);
refreshAdminSession($pdo);
requireLogin();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int)$_SESSION['user_id'];
$user = getUserProfile($pdo, $userId);

if (!$user) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$name = $user['name'];
$bio = (string)($user['bio'] ?? '');
$hobby = (string)($user['hobby'] ?? '');
$location = (string)($user['location'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
        $error = '不正なリクエストです。';
    } else {
        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $hobby = trim($_POST['hobby'] ?? '');
        $location = trim($_POST['location'] ?? '');

        if ($name === '') {
            $error = '名前を入力してください。';
        } elseif (mb_strlen($name) > 100) {
            $error = '名前は100文字以内で入力してください。';
        } elseif (mb_strlen($bio) > 1000) {
            $error = '自己紹介は1000文字以内で入力してください。';
        } elseif (mb_strlen($hobby) > 100) {
            $error = '趣味・興味は100文字以内で入力してください。';
        } elseif (mb_strlen($location) > 100) {
            $error = '居住地・所属は100文字以内で入力してください。';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE name = ? AND id <> ?");
                $stmt->execute([$name, $userId]);
                if ($stmt->fetch()) {
                    $error = 'この名前は既に使われています。';
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE users SET name = ?, bio = ?, hobby = ?, location = ? WHERE id = ?"
                    );
                    $stmt->execute([
                        $name,
                        $bio === '' ? null : $bio,
                        $hobby === '' ? null : $hobby,
                        $location === '' ? null : $location,
                        $userId,
                    ]);

                    // 表示名変更に合わせて投稿の author も更新
                    try {
                        $stmt = $pdo->prepare("UPDATE posts SET author = ? WHERE user_id = ?");
                        $stmt->execute([$name, $userId]);
                    } catch (PDOException $e) {
                        // author 更新に失敗してもプロフィール自体は保存済み
                    }

                    $_SESSION['user_name'] = $name;
                    $success = 'プロフィールを保存しました。';
                    $user = getUserProfile($pdo, $userId);
                }
            } catch (PDOException $e) {
                $error = '保存中にエラーが発生しました。';
                error_log('profile_edit: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プロフィール編集 - 掲示板アプリ</title>
    <link rel="stylesheet" href="css/style.css?v=7">
</head>
<body>
    <div class="container">
        <header>
            <h1>プロフィール編集</h1>
            <div class="header-actions">
                <a href="profile.php?id=<?php echo $userId; ?>" class="btn btn-secondary">プロフィールを見る</a>
                <a href="index.php" class="btn btn-secondary">トップページ</a>
            </div>
        </header>

        <main>
            <p class="admin-page-intro">自己紹介や趣味などを登録・編集できます。</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" class="post-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="form-group">
                    <label for="name">表示名 *</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required maxlength="100">
                </div>

                <div class="form-group">
                    <label for="bio">自己紹介（1000文字以内）</label>
                    <textarea id="bio" name="bio" rows="6" maxlength="1000" placeholder="あなたのことを自由に書いてください"><?php echo htmlspecialchars($bio); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="hobby">趣味・興味</label>
                    <input type="text" id="hobby" name="hobby" value="<?php echo htmlspecialchars($hobby); ?>" maxlength="100" placeholder="例: 料理、スポーツ、ゲーム">
                </div>

                <div class="form-group">
                    <label for="location">居住地・所属</label>
                    <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($location); ?>" maxlength="100" placeholder="例: 東京 / ○○大学">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">保存する</button>
                    <a href="profile.php?id=<?php echo $userId; ?>" class="btn btn-secondary">キャンセル</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
