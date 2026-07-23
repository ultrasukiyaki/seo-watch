# インストール手順

## 1. 動作環境を確認する

必要な環境:

- PHP 8.1以上
- MySQLまたは互換DB
- PHP拡張: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`
- HTTPSで公開できるURL
- Google Search ConsoleへアクセスできるGoogleアカウント

SEO WatchはComposerやNode.jsを使わず、PHPとMySQLだけで動作します。Apache系共有サーバーが最も簡単です。Nginxでは `.htaccess` が使えないため、同等のアクセス拒否設定が必要です。

## 2. データベースを作成する

空のデータベースを1個作成し、次を控えます。

- DBホスト
- DBポート
- DB名
- DBユーザー名
- DBパスワード

共有サーバーでは、DBホストが `localhost` ではない場合があります。管理画面に表示された値を使用してください。

## 3. ファイルを配置する

例:

```text
https://example.com/seo-watch/
```

このURLで使う場合、公開ディレクトリの `seo-watch/` へリリースZIPの中身を配置します。

```text
seo-watch/
├── .htaccess
├── index.php
├── install.php
├── oauth-callback.php
├── app/
├── assets/
├── bin/
├── config/
├── database/
└── views/
```

FTPクライアントが隠しファイルを除外している場合、`.htaccess` を忘れずに転送してください。

一般的なパーミッション例:

```text
ディレクトリ: 705 または 755
通常ファイル: 604 または 644
.htaccess: 604 または 644
config/local.php: 600を推奨
```

実際の推奨値はホスティング事業者の仕様を優先してください。

## 4. Webサーバーの保護を確認する

### Apache

同梱の `.htaccess` が次を行います。

- HTTPからHTTPSへの転送
- ディレクトリ一覧の禁止
- 内部ディレクトリへのアクセス拒否
- 設定・SQL・Markdown・シェル等の公開拒否
- 基本的なセキュリティヘッダー付与

サーバー側で `AllowOverride None` が設定されていると `.htaccess` は無視されます。その場合はVirtualHost設定へ同等のルールを追加してください。

### Nginx

`deploy/nginx-location.conf.example` を参考に、内部ディレクトリと機密ファイルを拒否してください。PHP-FPMのソケットや公開ルートは環境に合わせて設定します。

## 5. Google OAuthクライアントを準備する

詳細は [GOOGLE_OAUTH_SETUP.md](../GOOGLE_OAUTH_SETUP.md) を参照してください。

インストーラーを先に開くと、Google Cloudへ登録する正確なコールバックURLが表示されます。

例:

```text
https://example.com/seo-watch/oauth-callback.php
```

Google Cloudでは、OAuthクライアントの種類を **ウェブ アプリケーション** にしてください。

## 6. インストーラーを実行する

ブラウザで次を開きます。

```text
https://example.com/seo-watch/install.php
```

入力項目:

- 公開ベースURL
- 表示タイムゾーン（IANA識別子。画面・メール・CLI用。DB保存はUTC）
- Search Console確定データ待機日数
- MySQL接続情報
- スーパーユーザー名、回復用メールアドレス、パスワード
- Google OAuthクライアントIDとシークレット

インストーラーは次を実行します。

- DB接続確認
- テーブル作成
- 初期マイグレーション
- スーパーユーザー作成
- 暗号化キー生成
- `config/local.php` 作成

アカウント回復メールは安全のため初期状態で無効です。インストール後に`config/local.php.example`を参考に`mail`設定を追加し、設定画面から確認済みスーパーユーザー宛てのテスト送信を行ってください。詳細は[アカウント回復・認証運用](ACCOUNT_RECOVERY.md)を参照してください。

## 7. Googleと連携する

1. スーパーユーザーでログインします。
2. 「設定」から「Googleと連携する」を実行します。
3. Search Consoleを管理しているGoogleアカウントを選択します。
4. 読み取り権限を許可します。
5. 分析対象プロパティを選択します。
6. 初回データを取り込みます。

前期間比較をすぐ確認したい場合は、最初に56日以上を取り込むと分析しやすくなります。

## 8. インストール後の確認

次のURLが403になることを確認します。

```text
https://example.com/seo-watch/config/local.php
https://example.com/seo-watch/database/schema.sql
https://example.com/seo-watch/app/bootstrap.php
```

また、HTTPでアクセスした場合にHTTPSへ転送されることを確認してください。

### インストール後の推奨操作

1. SEO Watchへログインします。
2. 設定画面からGoogleと連携します。
3. Search Consoleプロパティを選択します。
4. 初回データを取得します。
5. Cronによる定期取得を設定します。

CLIを利用できる場合:

```bash
php bin/doctor.php
```

すべて `[OK]` なら基本設定は完了です。
