# heteml向け補足

本体は汎用構成です。heteml固有のPHPパスはソースコードへ書き込まず、Cron側で指定します。

## 配置

独自ドメインまたはサブドメインの公開フォルダ配下へ、リリースZIPを展開します。

例:

```text
/web/公開フォルダ/seo-watch/
```

公開URL:

```text
https://example.com/seo-watch/
```

FTP転送時は `.htaccess` を含めてください。

## MySQL

hetemlコントロールパネルでデータベースを作成し、画面に表示された次の値をインストーラーへ入力します。

- DBサーバー
- DB名
- DBユーザー名
- DBパスワード

DBサーバーは `localhost` と決め打ちしないでください。

## PHP

設置先ドメインのPHPを8.1以上へ設定します。Web版とCron版でPHP実行パスが異なる場合があります。

`bin/import.php` などの1行目は、配布版では次の汎用shebangです。

```text
#!/usr/bin/env php
```

heteml固有パスへ直接書き換える必要はありません。Cronコマンドで指定します。

アカウント回復用メールはPHPの`mail()`を利用します。送信可否、Fromアドレス、迷惑メール対策はheteml側の現在の仕様と独自ドメインのDNS設定を確認してください。SMTPライブラリは使用しません。

例:

```cron
15 4 * * * PHP_BIN=/usr/local/bin/php8.3 /home/users/ACCOUNT/web/PUBLIC_FOLDER/seo-watch/bin/cron.sh
```

サーバー構成によってPHPパスは異なります。現在の値はhetemlの管理画面・公式マニュアルで確認してください。

## 推奨パーミッション

hetemlの案内を優先しつつ、一般的には次を目安にします。

```text
ディレクトリ: 705
通常ファイル: 604
.htaccess: 604
config/local.php: 600
bin/cron.sh: 700
```

## OAuth

Google Cloudへ登録するリダイレクトURIは、インストーラーに表示されたHTTPS URLと完全一致させます。

```text
https://example.com/seo-watch/oauth-callback.php
```

`/public`や末尾スラッシュを追加しないでください。
## hetemlでのタイムゾーン

PHPの標準タイムゾーンにかかわらず、SEO Watchの内部処理とMySQL接続セッションはUTC、利用者向け表示は管理画面で選んだIANAタイムゾーンを使用します。CronのSearch Console対象日はPT基準です。

## v0.10系の運用

同期排他はファイルロックではなくMySQLの `import_locks` テーブルを使うため、Web PHPとCron PHPが別プロセスでも共通して機能します。heteml固有パスをアプリへ埋め込む必要はありません。

アップデート後はDBをバックアップしたうえで、CLI PHPから次を確認してください。

```bash
php bin/doctor.php
php bin/maintenance.php --dry-run
```

maintenanceはSearch Console分析データや改善タスクを削除しません。`--execute` はdry-runの件数を確認してから指定してください。
