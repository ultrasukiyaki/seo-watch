# Cronによる定期取得

Search Consoleデータを毎日自動取得するには、`bin/import.php` をCronから実行します。

## 最小構成

アプリの絶対パスが `/home/example/public_html/seo-watch` の場合:

```cron
15 4 * * * /usr/bin/php /home/example/public_html/seo-watch/bin/import.php --days=3
```

この例では毎日4時15分に直近3日分を再取得します。既存行は更新されるため、数日分を重ねて取得しても重複しません。
取得対象日は`America/Los_Angeles`（PT）で計算され、画面の表示タイムゾーンやCron実行環境のタイムゾーンには依存しません。CLIに表示する実行時刻はアプリの表示タイムゾーンです。

## PHPのパスが分からない場合

SSHが使える環境では次を確認します。

```bash
command -v php
php -v
```

共有サーバーではWeb版PHPとCLI版PHPのパスが異なる場合があります。ホスティング事業者の管理画面または公式マニュアルを確認してください。

## ラッパースクリプトを使う

`bin/cron.sh.example` は、アプリの設置パスを自動判定します。

```bash
cp bin/cron.sh.example bin/cron.sh
chmod 700 bin/cron.sh
```

PATH上の `php` を利用できる場合:

```cron
15 4 * * * /home/example/public_html/seo-watch/bin/cron.sh
```

PHPのフルパスが必要な場合:

```cron
15 4 * * * PHP_BIN=/usr/local/bin/php8.3 /home/example/public_html/seo-watch/bin/cron.sh
```

取得日数も環境変数で変更できます。

```cron
15 4 * * * PHP_BIN=/usr/local/bin/php8.3 IMPORT_DAYS=7 /home/example/public_html/seo-watch/bin/cron.sh
```

## ログを残す

書き込み可能なログディレクトリを用意したうえで、標準出力と標準エラーを追記します。

```cron
15 4 * * * /usr/bin/php /home/example/public_html/seo-watch/bin/import.php --days=3 >> /home/example/logs/seo-watch-cron.log 2>&1
```

ログにはOAuthトークン自体は出力されませんが、公開ディレクトリ外へ保存することを推奨します。

## 動作確認

Cronへ登録する前に、同じコマンドを手動実行してください。

```bash
php bin/doctor.php
php bin/import.php --days=3
```

主な失敗原因:

- CLI版PHPのバージョンが8.1未満
- CLI版PHPに`pdo_mysql`や`curl`がない
- `config/local.php`の読み取り権限がない
- OAuthアプリがTesting状態のまま7日を超えた
- Search Consoleプロパティが未選択
- CronのPHPパスまたはアプリ絶対パスが誤っている

## v0.10系の同期排他と履歴

Cronラッパーは `SEO_WATCH_IMPORT_SOURCE=cron` を設定し、同期履歴へ実行元を記録します。Webや別Cronが同じプロパティを取得中の場合、DB leaseにより多重実行は開始されません。

正常終了・例外終了では所有者一致を確認してleaseを解除します。プロセス強制終了などで残ったleaseは有効期限後にstaleとなります。

```bash
php bin/maintenance.php --dry-run --target=import-locks
php bin/maintenance.php --execute --target=import-locks
```

内部所有者トークンやPIDはCronログへ出力しません。
