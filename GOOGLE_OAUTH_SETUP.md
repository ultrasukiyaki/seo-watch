# Google OAuth / Search Console API 設定手順

> v0.10系では取得処理にプロパティ単位のDB leaseが追加されています。Web、CLI、Cronから同じプロパティを同時取得した場合、後から開始した処理は安全に中止されます。OAuthスコープとSearch Console APIの設定方法は従来から変更ありません。

**対象:** 10yendama SEO Watch  
**最終更新:** 2026-07-22

この文書では、10yendama SEO WatchからGoogle Search Console APIを利用するために必要な、Google OAuth 2.0の設定手順を説明します。

> [!IMPORTANT]
> アクセストークンや更新トークンを、Google Cloudから手動で発行・コピーする必要はありません。
>
> 利用者が用意するのは、Google Cloudで作成した次の2項目です。
>
> - OAuthクライアントID
> - OAuthクライアントシークレット
>
> これらをSEO Watchへ設定した後、「Googleと連携する」を実行すると、必要なトークンはSEO Watchが自動取得します。

---

## 1. 事前に用意するもの

設定を始める前に、次の条件を確認してください。

- Googleアカウントを持っている
- 対象サイトがGoogle Search Consoleへ登録されている
- そのSearch Consoleプロパティを閲覧できるGoogleアカウントである
- SEO WatchがインターネットからアクセスできるHTTPS URLへ設置されている
- SEO Watchのスーパーユーザーでログインできる

公開URLの例:

```text
https://seoranking.example.com/
```

OAuthコールバックURLの例:

```text
https://seoranking.example.com/oauth-callback.php
```

サブディレクトリへ設置した場合:

```text
https://example.com/seo-watch/oauth-callback.php
```

> [!WARNING]
> Googleへ登録するコールバックURLは、SEO Watchが表示するURLと完全一致させてください。
>
> `http`と`https`、ホスト名、ポート番号、ディレクトリ名、`/public`の有無、末尾のスラッシュはすべて区別されます。

---

## 2. Google Cloudプロジェクトを作成する

1. [Google Cloud Console](https://console.cloud.google.com/)を開きます。
2. 画面上部のプロジェクト選択メニューを開きます。
3. **「新しいプロジェクト」**を選択します。
4. 任意のプロジェクト名を入力します。

例:

```text
10yendama SEO Watch
```

5. 作成したプロジェクトへ切り替えます。

既存プロジェクトを利用しても構いませんが、管理しやすさと認証情報の分離を考えると、SEO Watch専用プロジェクトを推奨します。

---

## 3. Google Search Console APIを有効化する

1. Google Cloud Consoleで、SEO Watch用プロジェクトを選択します。
2. **「APIとサービス」→「ライブラリ」**を開きます。
3. 次のAPIを検索します。

```text
Google Search Console API
```

4. APIを開き、**「有効にする」**を押します。

Search Console APIへのリクエストにはOAuth 2.0による認可が必要です。

公式資料:

- [Authorize Requests | Search Console API](https://developers.google.com/webmaster-tools/v1/how-tos/authorizing)

---

## 4. Google Auth Platformを初期設定する

Google Cloud Consoleのメニューから、**「Google Auth Platform」**を開きます。

初回は **「始める」または「Get started」** が表示されます。

現在のGoogle Auth Platformは、おおむね次の画面に分かれています。

- ブランディング
- 対象 / Audience
- データアクセス / Data Access
- クライアント / Clients

Google Cloud Consoleの表示は更新されることがあり、メニュー名が多少異なる場合があります。

---

## 5. ブランディングを設定する

**「ブランディング」**で、OAuth同意画面へ表示するアプリ情報を設定します。

入力例:

```text
アプリ名:
10yendama SEO Watch

ユーザーサポートメール:
管理者のGoogleアカウント

デベロッパーの連絡先:
管理者のメールアドレス
```

個人利用やテスト中は、最低限の情報で開始できます。

組織内や一般ユーザーへ公開する場合は、次のページも用意することを推奨します。

- アプリのホームページ
- プライバシーポリシー
- 利用規約
- 問い合わせ先

公式資料:

- [Manage OAuth App Branding](https://support.google.com/cloud/answer/15549049)

---

## 6. 利用対象を設定する

**「対象 / Audience」**を開きます。

### 個人のGoogleアカウントや一般のGmailを使う場合

```text
ユーザータイプ:
外部 / External
```

を選択します。

### Google Workspace組織内だけで使う場合

利用中のGoogle Workspace環境で選択可能なら、**内部 / Internal**を利用できます。

### テストユーザーを追加する

公開ステータスが**テスト / Testing**の場合、SEO Watchと連携するGoogleアカウントを「テストユーザー」へ追加します。

例:

```text
your-account@gmail.com
```

Search Consoleを閲覧できるGoogleアカウントを追加してください。

> [!CAUTION]
> OAuthアプリがTesting状態の場合、テストユーザーによる認可は7日後に期限切れになります。
>
> `access_type=offline`で取得した更新トークンも対象です。Cronによる継続運用を行う場合は、後述の「テスト運用と本番運用」を確認してください。

公式資料:

- [Manage App Audience](https://support.google.com/cloud/answer/15549945)

---

## 7. Search Consoleの読み取りスコープを追加する

**「データアクセス / Data Access」**を開きます。

「スコープを追加または削除」を選び、次のスコープを追加します。

```text
https://www.googleapis.com/auth/webmasters.readonly
```

これはSearch Consoleデータの**読み取り専用スコープ**です。

SEO Watchはこの権限を使って、次のような情報を取得します。

- Search Consoleプロパティ一覧
- 検索語
- ページ
- クリック数
- 表示回数
- CTR
- 平均掲載順位
- 国やデバイスなどの分析ディメンション

サイト設定や記事本文を書き換える権限ではありません。

公式資料:

- [Authorize Requests | Search Console API](https://developers.google.com/webmaster-tools/v1/how-tos/authorizing)
- [OAuth 2.0 Scopes for Google APIs](https://developers.google.com/identity/protocols/oauth2/scopes)

---

## 8. OAuthクライアントを作成する

1. **「Google Auth Platform」→「クライアント / Clients」**を開きます。
2. **「クライアントを作成」**を押します。
3. アプリケーションの種類として、必ず次を選択します。

```text
ウェブ アプリケーション
```

「デスクトップアプリ」ではありません。

4. クライアント名を入力します。

例:

```text
10yendama SEO Watch Web
```

公式資料:

- [Manage OAuth Clients](https://support.google.com/cloud/answer/15549257)
- [Using OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)

---

## 9. 承認済みリダイレクトURIを登録する

OAuthクライアント作成画面の、**「承認済みのリダイレクトURI」**へSEO WatchのコールバックURLを登録します。

例:

```text
https://seoranking.example.com/oauth-callback.php
```

サブディレクトリへ設置した場合:

```text
https://example.com/seo-watch/oauth-callback.php
```

SEO Watchのインストール画面または設定画面に表示されたURLを、そのままコピーするのが安全です。

### 登録してはいけない例

```text
https://seoranking.example.com/
https://seoranking.example.com/install.php
https://seoranking.example.com/public/oauth-callback.php
https://seoranking.example.com/oauth-callback.php/
```

実際の設置構成と一致しないURLは使用できません。

### JavaScript生成元

SEO Watchの現在のOAuth処理では、**「承認済みのJavaScript生成元」への登録は不要**です。

入力後は、必ず**「保存」**を押してください。変更の反映に数分かかる場合があります。

---

## 10. クライアントIDとシークレットを取得する

OAuthクライアントを作成すると、次の2項目が発行されます。

```text
クライアントID
クライアントシークレット
```

クライアントIDは、通常次のような形式です。

```text
123456789012-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com
```

クライアントシークレットは、通常次のような形式です。

```text
GOCSPX-xxxxxxxxxxxxxxxxxxxxxxxx
```

> [!IMPORTANT]
> 「シークレットID」という別の値を探す必要はありません。
>
> SEO Watchへ入力するのは、同一OAuthクライアントから発行された「クライアントID」と「クライアントシークレット」の組み合わせです。

---

## 11. SEO Watchへ認証情報を設定する

### 新規インストール時

インストーラーのGoogle OAuth設定欄へ、次を入力します。

```text
Google OAuthクライアントID
Google OAuthクライアントシークレット
```

### インストール済みの場合

スーパーユーザーでログインし、設定画面から登録します。

手動で設定ファイルを編集する構成では、通常次のようになります。

```php
'google' => [
    'client_id' => '123456789012-xxxxxxxx.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-xxxxxxxxxxxxxxxx',
    'redirect_uri' => 'https://seoranking.example.com/oauth-callback.php',
],
```

> [!WARNING]
> クライアントシークレットをGitHubへコミットしないでください。
>
> `config/local.php`など、実際の認証情報が入ったファイルは公開リポジトリへ登録しないでください。

---

## 12. Googleアカウントと連携する

1. SEO Watchへスーパーユーザーでログインします。
2. **「設定」→「Googleと連携する」**を押します。
3. Search Consoleを利用しているGoogleアカウントを選択します。
4. Search Consoleの読み取り権限を確認します。
5. **「許可」**を押します。
6. SEO Watchへ戻ったら、利用するSearch Consoleプロパティを選択します。
7. データ取り込みを実行します。

認証が成功すると、SEO Watchは自動的に次を取得します。

- アクセストークン
- 更新トークン

アクセストークンは短時間で期限切れになりますが、SEO Watchは更新トークンを使って新しいアクセストークンを取得します。

トークンを利用者がコピーしたり、データベースへ手動登録したりする必要はありません。

SEO Watchでは、OAuthトークンを暗号化してデータベースへ保存します。

---

## 13. テスト運用と本番運用

### Testingのまま利用する場合

適している用途:

- 初回の接続確認
- 短期間の動作テスト
- 開発中の確認

注意点:

- テストユーザーの追加が必要
- 認可と更新トークンが7日で期限切れになる
- 期限切れ後はGoogleとの再連携が必要

### Productionへ切り替える場合

適している用途:

- Cronによる継続的なデータ取得
- 7日を超える常用
- 組織や複数利用者での運用

Google Auth Platformの「対象 / Audience」から、公開ステータスを**本番環境 / Production**へ変更します。

利用するスコープ、アプリの公開範囲、ユーザー数などによっては、GoogleによるOAuthアプリ確認が必要になる場合があります。

自分または限定した利用者だけで運用する未確認アプリでは、Googleの警告画面や利用者数制限が表示される場合があります。一般公開する場合は、ブランディング、ホームページ、プライバシーポリシー、確認済みドメインなどを整備してください。

公式資料:

- [OAuth App Verification](https://support.google.com/cloud/answer/13463073)
- [Unverified apps](https://support.google.com/cloud/answer/7454865)

---

## 14. Search Consoleプロパティの選び方

OAuth連携後、認証したGoogleアカウントがアクセスできるSearch Consoleプロパティが一覧表示されます。

URLプレフィックスプロパティの例:

```text
https://www.example.com/
```

ドメインプロパティの例:

```text
sc-domain:example.com
```

通常は、SEO Watchで分析したいサイト全体を含むプロパティを選択してください。

個別ページだけを登録したプロパティを選ぶと、その範囲のデータしか取得できません。

---

## 15. よくあるエラー

### エラー401: `invalid_client`

表示例:

```text
The OAuth client was not found.
```

主な原因:

- クライアントIDが間違っている
- プロジェクトIDやAPIキーを入力している
- クライアントシークレットをID欄へ入力している
- 削除済みのOAuthクライアントを使っている
- 別プロジェクトのIDとシークレットを組み合わせている
- コピー時に空白や改行が混入した

確認事項:

- クライアントIDの末尾が`.apps.googleusercontent.com`になっているか
- Google Cloudのコピーボタンから取り直したか
- IDとシークレットが同じOAuthクライアントのものか
- Google Cloud側で保存済みか

---

### エラー400: `redirect_uri_mismatch`

主な原因:

- Google Cloudへ登録したURLとSEO WatchのURLが一致していない
- `http`と`https`が異なる
- `www`の有無が異なる
- `/public`の有無が異なる
- サブディレクトリ名が異なる
- ポート番号が異なる
- 末尾のスラッシュが余分
- Google Cloud側で保存していない

Google Cloudの「承認済みのリダイレクトURI」へ、SEO Watchが表示するコールバックURLをそのまま登録してください。

---

### `OAuth stateが一致しません`

主な原因:

- 認証開始後にセッションCookieが失われた
- 古いOAuth認証タブを再利用した
- 複数回開始した古い認証画面から戻った
- ブラウザのCookieが無効
- ドメインやHTTPS設定が認証途中で変わった
- コールバック画面を更新した

対処:

1. 開いているGoogle認証タブを閉じる
2. SEO Watchへログインし直す
3. 設定画面から「Googleと連携する」を新しく開始する
4. 新しく開いた認証画面だけを利用する

コールバックURLを直接開いたり、エラー画面を更新したりしないでください。

---

### エラー403: `access_denied`

主な原因:

- Testing状態なのにテストユーザーへ追加されていない
- 別のGoogleアカウントを選択した
- 組織のGoogle Workspaceポリシーで拒否された
- 利用者が同意画面でキャンセルした

Google Auth Platformの「対象 / Audience」で、利用するGoogleアカウントをテストユーザーへ追加してください。

---

### 「このアプリはGoogleで確認されていません」

開発中または未確認のOAuthアプリで表示されることがあります。

自分で作成・管理しているGoogle Cloudプロジェクトであり、表示されているアプリ名と要求権限を確認できる場合は、詳細表示から続行できます。

第三者が作成した不明なOAuthアプリでは続行しないでください。

一般公開する場合は、GoogleのOAuthアプリ確認手続きを行ってください。

---

### OAuth連携できたがプロパティが表示されない

確認事項:

- 選択したGoogleアカウントが対象サイトのSearch Consoleへアクセスできるか
- Search Console APIを有効化したプロジェクトと、OAuthクライアントを作成したプロジェクトが同じか
- `webmasters.readonly`スコープが追加されているか
- URLプレフィックスとドメインプロパティを取り違えていないか
- Search Consoleで対象プロパティが正しく登録されているか

---

### 数日後にCron取得が失敗する

OAuthアプリがTesting状態の場合、更新トークンが7日で期限切れになることがあります。

継続運用では、公開ステータスをProductionへ切り替えた上で、SEO WatchからGoogle連携をやり直してください。

---

### クライアントシークレットを紛失した

Google Cloud ConsoleのOAuthクライアント画面で、シークレットの再発行またはローテーションを行います。

その後:

1. SEO Watchへ新しいシークレットを設定
2. 必要に応じてGoogle連携を解除
3. Googleとの連携を再実行
4. Cron取得を確認

古いシークレットが漏えいした可能性がある場合は、必ず無効化してください。

---

## 16. セキュリティ上の注意

- クライアントシークレットをGitHubへ登録しない
- OAuthトークンを公開しない
- `config/local.php`を公開領域から直接読めないようにする
- 本番環境ではHTTPSを必須にする
- スーパーユーザーのパスワードを使い回さない
- 閲覧専用ユーザーへ設定権限を与えない
- 不要になったOAuthクライアントやシークレットを削除する
- 漏えいが疑われる場合は、シークレットをローテーションしてGoogle連携をやり直す
- Googleアカウント側でも2段階認証を有効にする
- 本番DBと設定ファイルを定期的にバックアップする

---

## 17. 最終チェックリスト

v0.10系では、Google連携後に次も確認してください。

- 手動取得中に同じプロパティのCLI取得を開始すると多重実行メッセージになる
- 失敗時にアクセストークン、更新トークン、Google APIレスポンス全文が画面へ出ない
- 設定画面の同期履歴で実行状態と安全なメッセージを確認できる
- stale leaseは `php bin/maintenance.php --dry-run --target=import-locks` で予定件数だけ確認できる

Google Cloud側:

- [ ] SEO Watch用プロジェクトを作成した
- [ ] Google Search Console APIを有効化した
- [ ] Google Auth Platformのブランディングを設定した
- [ ] Audienceを設定した
- [ ] Testingの場合、Googleアカウントをテストユーザーへ追加した
- [ ] `webmasters.readonly`スコープを追加した
- [ ] 「ウェブ アプリケーション」のOAuthクライアントを作成した
- [ ] 正確な`oauth-callback.php`のURLを登録した
- [ ] OAuthクライアントの変更を保存した

SEO Watch側:

- [ ] 正しいクライアントIDを設定した
- [ ] 同じOAuthクライアントのシークレットを設定した
- [ ] スーパーユーザーでGoogle連携を実行した
- [ ] Search Consoleを利用しているGoogleアカウントを選択した
- [ ] 対象プロパティを選択した
- [ ] 初回データ取り込みに成功した
- [ ] Cronを使う場合、7日後にも更新できる運用状態か確認した

---

## 18. 設定全体の流れ

```text
Google Cloudプロジェクト作成
        ↓
Google Search Console APIを有効化
        ↓
Google Auth Platformを設定
        ↓
Audienceとテストユーザーを設定
        ↓
webmasters.readonlyスコープを追加
        ↓
Webアプリケーション用OAuthクライアントを作成
        ↓
oauth-callback.phpをリダイレクトURIへ登録
        ↓
クライアントIDとシークレットをSEO Watchへ設定
        ↓
スーパーユーザーで「Googleと連携する」
        ↓
アクセストークン・更新トークンを自動取得
        ↓
Search Consoleプロパティを選択
        ↓
データ取り込み
```

---

## 公式資料

- [Google Auth Platformを開始する](https://support.google.com/cloud/answer/15544987)
- [OAuthクライアントを管理する](https://support.google.com/cloud/answer/15549257)
- [OAuthアプリの利用対象を管理する](https://support.google.com/cloud/answer/15549945)
- [OAuthアプリのデータアクセスを管理する](https://support.google.com/cloud/answer/15549135)
- [WebサーバーアプリケーションでOAuth 2.0を使用する](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Search Console APIのリクエストを認可する](https://developers.google.com/webmaster-tools/v1/how-tos/authorizing)
- [OAuthアプリの確認](https://support.google.com/cloud/answer/13463073)
