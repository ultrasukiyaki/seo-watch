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
