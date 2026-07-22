<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<?php if (!$activeProperty): ?>
<section class="empty-state card">
    <div class="empty-icon">🛰️</div>
    <h2>分析対象がまだ選択されていません</h2>
    <?php if (!empty($isSuperuser)): ?>
    <p>設定画面からGoogle Search Consoleを連携し、プロパティを選択してください。</p>
    <a class="button primary" href="index.php?r=settings">設定を開く</a>
    <?php else: ?>
    <p>分析対象プロパティがまだ選択されていません。スーパーユーザーによる設定完了をお待ちください。</p>
    <?php endif; ?>
</section>
<?php elseif (!$summary || !$summary['range']): ?>
<section class="empty-state card">
    <div class="empty-icon">📥</div>
    <h2>Search Consoleデータを取り込もう</h2>
    <?php if (!empty($isSuperuser)): ?>
    <p>まず7〜90日分を取り込むと、狙い目検索語と伸ばすべき記事を判定できるで。</p>
    <form method="post" action="index.php?r=import/run" class="inline-form centered">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <select name="days"><option value="7">7日</option><option value="28" selected>28日</option><option value="56">56日</option><option value="90">90日</option></select>
        <button class="button primary" type="submit">今すぐ取り込む</button>
    </form>
    <?php else: ?>
    <p>分析データはまだありません。スーパーユーザーが取り込みを実行すると、ここに結果が表示されます。</p>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="toolbar">
    <div class="range-label">集計期間: <?=View::e($summary['range']['current_start'])?> 〜 <?=View::e($summary['range']['current_end'])?></div>
    <?php if (!empty($isSuperuser)): ?>
    <form method="post" action="index.php?r=import/run" class="inline-form">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <select name="days"><option value="7">直近7日を再取得</option><option value="28" selected>直近28日を再取得</option><option value="56">直近56日を再取得</option><option value="90">直近90日を再取得</option></select>
        <button class="button" type="submit">データ更新</button>
    </form>
    <?php endif; ?>
</div>

<section class="stats-grid">
    <article class="stat-card"><span>クリック</span><strong><?=number_format($summary['clicks'])?></strong></article>
    <article class="stat-card"><span>表示回数</span><strong><?=number_format($summary['impressions'])?></strong></article>
    <article class="stat-card"><span>CTR</span><strong><?=number_format($summary['ctr'] * 100, 2)?>%</strong></article>
    <article class="stat-card"><span>平均掲載順位</span><strong><?=number_format($summary['position'], 1)?></strong></article>
</section>

<div class="two-column">
<section class="card">
    <div class="card-head"><div><h2>🚀 伸ばすべき記事</h2><p>正規化URL単位で、検索需要・順位・CTR差を集計</p></div></div>
    <?php if (!$pageOpportunities): ?><p class="muted">候補がまだありません。</p><?php else: ?>
    <div class="stack-list">
        <?php foreach ($pageOpportunities as $index => $item): ?>
        <article class="opportunity-card">
            <div class="rank-number"><?=($index + 1)?></div>
            <div class="grow min-width-0">
                <div class="title-link-row"><a class="url-title" href="index.php?r=page-detail&amp;u=<?=rawurlencode((string)$item['page_url'])?>"><?=View::e($item['page_title'] ?: $item['page_url'])?></a><a class="external-link" href="<?=View::e($item['page_url'])?>" target="_blank" rel="noopener noreferrer" title="記事を開く">↗</a></div>
                <?php if ($item['page_title']): ?><span class="sub-url"><?=View::e($item['page_url'])?></span><?php endif; ?>
                <div class="chips"><?php foreach ($item['queries'] as $query): ?><span><?=View::e($query)?></span><?php endforeach; ?></div>
                <div class="mini-metrics"><span>表示 <?=number_format($item['impressions'])?></span><span>クリック <?=number_format($item['clicks'])?></span><span>順位 <?=number_format($item['position'], 1)?></span></div>
            </div>
            <div class="score-badge"><?=number_format($item['score'], 1)?><small>score</small></div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head"><div><h2>🔎 狙い目検索語</h2><p>検索語単位へ統合し、4〜20位・CTR不足・上昇傾向を優先</p></div><a href="index.php?r=opportunities">すべて見る</a></div>
    <?php if (!$opportunities): ?><p class="muted">候補がまだありません。</p><?php else: ?>
    <div class="stack-list compact">
        <?php foreach ($opportunities as $item): ?>
        <article class="query-card">
            <div class="grow min-width-0">
                <strong><?=View::e($item['query_text'])?></strong>
                <?php if ($item['page_title']): ?><a class="sub-url internal-sub-link" href="index.php?r=page-detail&amp;u=<?=rawurlencode((string)$item['page_url'])?>"><?=View::e($item['page_title'])?></a><?php endif; ?>
                <div class="reasons"><?=View::e(implode(' / ', $item['reasons']))?></div>
                <div class="mini-metrics"><span>順位 <?=number_format((float)$item['current_position'], 1)?></span><span>表示 <?=number_format((float)$item['current_impressions'])?></span><span>CTR <?=number_format((float)$item['current_ctr'] * 100, 2)?>%</span><?php if ((int)$item['page_count'] > 1): ?><span><?=number_format((int)$item['page_count'])?>ページ</span><?php endif; ?></div>
            </div>
            <div class="score-badge small"><?=number_format((float)$item['score'], 1)?></div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
</div>
<?php endif; ?>
