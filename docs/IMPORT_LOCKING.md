# 同期ロック

Web、CLI、Cronは `import_locks` のDB leaseを共有します。共有サーバーや複数PHPプロセスでも機能し、ファイルシステムの共有方式に依存しないためです。

ロックはプロパティ単位で、所有者はSHA-256ハッシュのみ保存します。処理中はheartbeatで期限を延長し、正常・例外終了時に所有者一致を確認して解除します。期限切れleaseは次回取得またはmaintenance CLIで除去できます。内部所有者トークンは画面やログへ表示しません。

## 状態と障害時の扱い

同期履歴は `running`、`success`、`partial`、`failed`、`cancelled` を扱い、実行元、対象期間、heartbeat、件数、相関ID、利用者向けメッセージを保持できます。失敗は `oauth`、`google_api`、`network`、`database`、`rate_limit`、`validation`、`lock`、`unknown` に分類します。

stale leaseの通常整理は `php bin/maintenance.php --execute --target=import-locks` を使います。別所有者の有効なleaseは解除しません。
