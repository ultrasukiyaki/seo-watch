# Search Performance Alerts

v0.12.0では、Search Consoleの重要な変化を説明可能な閾値ルールで検知します。

## 比較期間と基準日

- 直近7日とその前の7日、直近28日とその前の28日を比較します。
- PHPの現在日ではなく、対象プロパティの`search_performance`に保存済みの最新`data_date`を基準にします。
- `data_date`はAmerica/Los_Angeles基準のSearch Console実績日です。JSTなどへ変換して日付をずらしません。
- 前後期間の全日が揃わない場合や同期leaseが有効な場合は通知を作らず、検知をスキップします。

## ルール

掲載順位上昇・下落、クリック数増加・減少、表示回数増加・減少、CTR低下、低CTR改善候補、順位閾値への進入・離脱を用意しています。ページ単位またはページ＋検索語単位で集計します。CTRは期間合計クリック数÷期間合計表示回数、順位は表示回数加重平均です。

低CTR改善候補は単純な閾値判定であり、順位別の一般的CTRを保証するものではありません。初期状態は無効です。

## 実行

```bash
php bin/detect-alerts.php
php bin/detect-alerts.php --property=1 --window=7 --dry-run
php bin/send-alert-digest.php
```

CronはSearch Console同期、変動検知、ダイジェストの順に、完了時間を見込んだ間隔を空けて設定してください。

```cron
15 3 * * * /usr/bin/php /path/to/seo-watch/bin/import.php --days=3
30 3 * * * /usr/bin/php /path/to/seo-watch/bin/detect-alerts.php
*/15 * * * * /usr/bin/php /path/to/seo-watch/bin/send-alert-digest.php
```

同じ基準日の再実行はunique制約で重複を防ぎます。cooldown中も発生履歴は追記しますが、メール再送は抑止します。ユーザーの確認なしに改善タスクを自動作成することはありません。

> 通知はSearch Consoleデータの変化を示すものであり、Googleアップデートや記事修正との因果関係を断定するものではありません。
