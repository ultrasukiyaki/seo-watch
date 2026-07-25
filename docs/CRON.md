# Cronによる定期取得

Search Consoleデータを毎日自動取得し、変動検知とダイジェストを行うには、同期・検知・配送をこの順でCronから実行します。

## 最小構成

アプリの絶対パスが `/home/example/public_html/seo-watch` の場合:

```cron
15 4 * * * /usr/bin/php /home/example/public_html/seo-watch/bin/import.php --days=3
30 4 * * * /usr/bin/php /home/example/public_html/seo-watch/bin/detect-alerts.php
*/15 * * * * /usr/bin/php /home/example/public_html/seo-watch/bin/send-alert-digest.php
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

## PHP CLIのフルパス

Cronではラッパーシェルを使わず、各PHP CLIを直接実行します。Web版PHPとCLI版PHPのバージョンが異なる場合があるため、サーバーで確認したPHP CLIのフルパスを指定してください。

```bash
test -x /usr/local/bin/php8.3
/usr/local/bin/php8.3 -v
```

`/usr/local/bin/php8.3`は設置先サーバーの実在するパスへ置き換えます。PHPのフルパスをCronコマンドの先頭に指定する場合、`bin/*.php`のshebang変更は不要です。

`./bin/script.php`として直接実行する運用では、アプリのルートディレクトリで次を実行すると、全`bin/*.php`の1行目を実機のPHPフルパスへ変更できます。最後の`head`で全ファイルの変更結果を確認してください。

```bash
PHP_BIN=/usr/local/bin/php8.3
test -x "$PHP_BIN" || { echo "PHP CLIを実行できません: $PHP_BIN" >&2; exit 1; }
"$PHP_BIN" -v
find ./bin -maxdepth 1 -type f -name '*.php' \
  -exec sed -i "1s|^#!.*php[^[:space:]]*$|#!${PHP_BIN}|" {} +
head -n 1 ./bin/*.php
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
php bin/detect-alerts.php --dry-run
php bin/send-alert-digest.php --dry-run
```

主な失敗原因:

- CLI版PHPのバージョンが8.1未満
- CLI版PHPに`pdo_mysql`や`curl`がない
- `config/local.php`の読み取り権限がない
- OAuthアプリがTesting状態のまま7日を超えた
- Search Consoleプロパティが未選択
- CronのPHPパスまたはアプリ絶対パスが誤っている

## v0.10系の同期排他と履歴

Webや別Cronが同じプロパティを取得中の場合、DB leaseにより多重実行は開始されません。必要に応じてCronコマンドの先頭で`SEO_WATCH_IMPORT_SOURCE=cron`または`SEO_WATCH_ALERT_SOURCE=cron`を指定すると、実行元をCronとして履歴へ記録できます。

正常終了・例外終了では所有者一致を確認してleaseを解除します。プロセス強制終了などで残ったleaseは有効期限後にstaleとなります。

```bash
php bin/maintenance.php --dry-run --target=import-locks
php bin/maintenance.php --execute --target=import-locks
```

内部所有者トークンやPIDはCronログへ出力しません。
