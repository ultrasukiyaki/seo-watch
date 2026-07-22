# Security Policy

## Supported versions

原則として最新リリースのみをサポートします。セキュリティ修正が公開された場合は、できるだけ早く最新版へ更新してください。

## Reporting a vulnerability

脆弱性の可能性がある内容は、公開Issueへ詳細を書かないでください。

リポジトリ所有者へ非公開で連絡できる手段を用意し、次を添えて報告してください。

- 影響を受けるバージョン
- 再現手順
- 想定される影響
- 検証に使用した環境
- 可能であれば修正案

GitHub Security Advisoriesを有効化している場合は、リポジトリの **Security → Report a vulnerability** を利用してください。

## Secret handling

次は公開リポジトリへコミットしないでください。

- `config/local.php`
- Google OAuthクライアントシークレット
- OAuthアクセストークン・更新トークン
- DBパスワード
- 本番DBダンプ
- Cronログやエラーログ

漏えいが疑われる場合は、Google OAuthクライアントシークレットをローテーションし、DB認証情報とスーパーユーザーパスワードも変更してください。
