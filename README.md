# 10yendama SEO Watch

Google Search Consoleの実検索データを蓄積し、**伸ばすべき記事・狙い目検索語・改善候補**を提示するセルフホスト型SEO管理ツールです。

ComposerやNode.jsを使わず、PHPとMySQLだけで動作します。Apache系共有サーバーへ直接配置でき、CLI/Cronによる定期取得にも対応しています。

## 主な機能

- Google OAuth 2.0によるSearch Console読み取り専用連携
- 検索語・ページ・国・端末単位の日次パフォーマンス保存
- クリック数、表示回数、CTR、平均掲載順位の集計
- 掲載順位・CTR・表示回数から改善優先度を算出
- 記事単位の「伸ばすべき記事」ランキング
- 検索語ごとの推移、前期間比較、改善アクション
- WordPress REST APIから記事タイトル・更新日時・見出しを取得
- URLフラグメント、末尾スラッシュ、計測用パラメータの正規化
- 長大な一覧の非同期ページング
- スーパーユーザーと複数の閲覧専用ユーザー
- OAuthトークンのAES-256-GCM暗号化保存
- ブラウザ手動取得とCLI/Cron取得

## 動作要件

- PHP 8.1以上
- MySQLまたは互換DB
- PHP拡張: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`
- HTTPSで公開できるWeb環境
- Apache 2.4系、または同等のアクセス制御を設定したNginx
- Google Search Consoleへ登録済みのサイト

ComposerとNode.jsは不要です。

## クイックスタート

1. 空のMySQLデータベースを作成します。
2. リリースZIPをHTTPS公開ディレクトリへ展開します。
3. Apache利用時は、同梱の `.htaccess` が転送されていることを確認します。
4. `https://設置URL/install.php` を開きます。
5. 画面に表示されたコールバックURLをGoogle Cloudへ登録します。
6. DB情報、スーパーユーザー、OAuthクライアント情報を入力します。
7. ログイン後にGoogle連携とSearch Consoleプロパティ選択を行います。
8. 初回データを取り込みます。

詳しい手順は次を参照してください。

- [インストール手順](docs/INSTALL.md)
- [Google OAuth / Search Console API設定](GOOGLE_OAUTH_SETUP.md)
- [Cronによる定期取得](docs/CRON.md)
- [heteml向け補足](docs/HETEML.md)
- [アップデート手順](docs/UPGRADING.md)

## ユーザー権限

| 機能 | スーパーユーザー | 閲覧ユーザー |
|---|:---:|:---:|
| ダッシュボード・分析画面 | ✓ | ✓ |
| 記事詳細・改善提案 | ✓ | ✓ |
| Google OAuth・プロパティ設定 | ✓ | — |
| データ取り込み・URL正規化 | ✓ | — |
| 閲覧ユーザー作成・削除 | ✓ | — |

閲覧ユーザーは、メニューが非表示になるだけでなく、制限対象URLへ直接アクセスした場合もサーバー側で403になります。

## CLI

```bash
# 動作環境とDB接続を確認
php bin/doctor.php

# 直近3日分を取得
php bin/import.php --days=3

# 期間を指定して取得
php bin/import.php --start=2026-07-01 --end=2026-07-21

# 既存URLを再正規化
php bin/normalize.php

# テスト実行
php bin/test.php
```

`bin/*.php` のshebangは汎用の `#!/usr/bin/env php` です。共有サーバー固有のPHPパスはソースへ書き込まず、Cronコマンドまたは `PHP_BIN` 環境変数で指定します。

## ディレクトリ構成

```text
.
├── app/                    アプリケーションコード
├── assets/                 CSS / JavaScript
├── bin/                    CLI・Cron・開発用コマンド
├── config/                 ローカル設定
├── database/               初期スキーマ
├── deploy/                 Webサーバー設定例
├── docs/                   導入・運用ドキュメント
├── tests/                  単体テスト
├── views/                  画面テンプレート
├── index.php               管理画面
├── install.php             ブラウザインストーラー
└── oauth-callback.php      Google OAuthコールバック
```

## セキュリティ

- `config/local.php`、OAuthシークレット、DBパスワードをGitへ追加しないでください。
- 公開環境ではHTTPSを必須にしてください。
- Apache以外では、`app/`, `bin/`, `config/`, `database/`, `tests/`, `views/` をWebから拒否してください。
- インストール後は `config/local.php` と `database/schema.sql` がHTTP 403になることを確認してください。
- 脆弱性の報告方法は [SECURITY.md](SECURITY.md) を参照してください。

## 開発

```bash
# 全PHPファイルを構文確認
find . -name '*.php' -not -path './dist/*' -print0 \
  | xargs -0 -n1 php -l

# 単体テスト
php bin/test.php
```

コントリビューション手順は [CONTRIBUTING.md](CONTRIBUTING.md) を参照してください。

## ライセンス

[MIT License](LICENSE)
