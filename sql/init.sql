-- データベース作成
CREATE DATABASE IF NOT EXISTS bulletin_board CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bulletin_board;

-- ユーザーテーブル作成
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    bio TEXT NULL,
    hobby VARCHAR(100) NULL,
    location VARCHAR(100) NULL,
    avatar VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 投稿テーブル作成
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    category VARCHAR(100) NOT NULL DEFAULT '雑談',
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    author VARCHAR(100) NOT NULL,
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- いいねテーブル作成
CREATE TABLE IF NOT EXISTS post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_likes_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_post_likes_post_user (post_id, user_id)
);

-- ブックマークテーブル作成
CREATE TABLE IF NOT EXISTS post_bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_bookmarks_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_bookmarks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_post_bookmarks_post_user (post_id, user_id)
);

-- 返信テーブル作成
CREATE TABLE IF NOT EXISTS post_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_replies_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 初期管理者（パスワード: admin1234）
INSERT INTO users (name, email, password, is_admin) VALUES
('管理者', 'admin@example.com', '$2y$10$4UDjKK.HjwKxWCZH/wS05u.pCerltnAjpoG14SGtvAYy/UKXE/Wey', 1);

-- サンプルデータ挿入
INSERT INTO posts (title, content, author) VALUES 
('掲示板の使い方', 'この掲示板では自由に投稿できます。マナーを守ってご利用ください。', '管理者'),
('初回投稿', '掲示板のテスト投稿です。', 'テストユーザー');


