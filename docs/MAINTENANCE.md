# メンテナンスCLI

`php bin/maintenance.php --dry-run`（省略時もdry-run）で予定件数を確認し、`--execute` でトランザクション内の整理を実行します。`--target=auth` または個別targetを指定できます。

対象は期限切れ・使用済みトークン、古い認証レート制限、保持期限超過の認証監査／同期実行履歴、stale同期ロックです。Search Console分析データ、記事メタデータ、改善タスクと履歴、ユーザー、OAuth、設定は削除しません。

## コマンド

```bash
php bin/maintenance.php --help
php bin/maintenance.php --dry-run
php bin/maintenance.php --execute
php bin/maintenance.php --dry-run --target=auth
php bin/maintenance.php --execute --target=import-runs
```

`--execute` は対象をトランザクション内で整理し、予定件数と削除件数を表示します。秘密情報、実トークン、利用者データの内容は表示しません。
