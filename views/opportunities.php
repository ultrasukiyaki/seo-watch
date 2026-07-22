<?php use Tenyendama\SeoWatch\View; ?>
<?php if (!$activeProperty): ?>
<section class="empty-state card"><h2>プロパティを選択してください</h2><?php if (!empty($isSuperuser)): ?><a class="button primary" href="index.php?r=settings">設定を開く</a><?php else: ?><p>スーパーユーザーによる設定完了をお待ちください。</p><?php endif; ?></section>
<?php elseif (!$result['rows']): ?>
<section class="empty-state card"><h2>分析データがまだありません</h2><p>ダッシュボードからSearch Consoleデータを取り込んでな。</p></section>
<?php else: ?>
<div class="notice">同一検索語をページ・端末・国をまたいで集約し、掲載順位は表示回数による加重平均、CTRは合計クリック÷合計表示回数で再計算しています。複数ページへ出ている語はカニバリ候補として表示します。</div>
<section class="async-pager" data-async-pager data-route="opportunities" aria-live="polite">
    <div class="async-feedback" data-async-feedback hidden></div>
    <div class="async-pager-content" data-async-content>
        <?=View::partial('partials/opportunities-table', compact('result'))?>
    </div>
</section>
<?php endif; ?>
