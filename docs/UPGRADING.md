# アップデート手順

## 更新前

1. `config/local.php`をバックアップします。
2. MySQLデータベースをバックアップします。
3. 現在の`VERSION`を確認します。

## 更新

新しいリリースZIPの内容を、既存の設置先へ上書きします。

次のファイルは上書きしないでください。

```text
config/local.php
```

`config/local.php.example`はサンプルなので上書きして問題ありません。

## 更新後

1. 管理画面へアクセスします。
2. 必要なDB変更は初回アクセス時に自動適用されます。
3. `php bin/doctor.php`を実行します。
4. ダッシュボード、設定、ユーザー権限、データ取得を確認します。

## v0.5.0からv0.6.0

- DBスキーマ変更はありません。
- `bin/*.php`のshebangを`#!/usr/bin/env php`へ戻しました。
- heteml固有のCronパスは`bin/cron.sh.example`と`docs/HETEML.md`へ分離しました。
- インストーラーとドキュメントを汎用ホスティング向けに整理しました。
- `config/local.php`、OAuthトークン、取得済みデータは変更されません。
