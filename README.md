# 掲示板アプリ

PHPとMySQLを使用したシンプルなWeb掲示板アプリケーションです。

## 機能

- 投稿一覧表示
- 新規投稿作成
- いいね機能
- ブックマーク（保存）機能
- 返信（スレッド）機能
- 投稿のピン留め（固定）機能
- 自分の投稿の編集・削除
- レスポンシブデザイン
- バリデーション機能

## 必要な環境

- PHP 7.4以上
- MySQL 5.7以上
- Webサーバー（Apache、Nginx等）

## セットアップ手順

1. **データベースの作成**
   ```sql
   mysql -u root -p < sql/init.sql
   ```

2. **データベース設定の確認**
   `config/database.php` でデータベース接続情報を確認・変更してください。

3. **Webサーバーでプロジェクトを公開**
   - Apacheの場合：プロジェクトフォルダをDocumentRootに配置
   - PHP内蔵サーバーの場合：
     ```bash
     php -S localhost:8000
     ```

4. **ブラウザでアクセス**
   `http://localhost:8000` または `http://localhost/プロジェクトフォルダ名`

## ファイル構成

```
├── config/
│   └── database.php          # データベース接続設定
├── css/
│   └── style.css            # スタイルシート
├── sql/
│   └── init.sql             # データベース初期化SQL
├── index.php                # メインページ（投稿一覧）
├── post.php                 # 新規投稿ページ
└── README.md                # このファイル
```

## データベース構造

### posts テーブル
- `id`: 投稿ID（主キー、自動増分）
- `user_id`: 投稿ユーザーID（NULL許可）
- `title`: 投稿タイトル
- `content`: 投稿内容
- `author`: 投稿者名
- `created_at`: 作成日時
- `updated_at`: 更新日時

### post_likes テーブル
- `id`: いいねID（主キー、自動増分）
- `post_id`: 投稿ID
- `user_id`: いいねしたユーザーID
- `created_at`: 作成日時

### post_replies テーブル
- `id`: 返信ID（主キー、自動増分）
- `post_id`: 投稿ID
- `user_id`: 返信ユーザーID
- `content`: 返信内容
- `created_at`: 作成日時
- `updated_at`: 更新日時

## カスタマイズ

- データベース接続情報は `config/database.php` で変更
- スタイルは `css/style.css` でカスタマイズ可能
- 機能追加は各PHPファイルを編集

## 注意事項

- 本番環境では適切なセキュリティ対策を実装してください
- パスワードやAPIキーなどの機密情報は環境変数で管理することを推奨します



