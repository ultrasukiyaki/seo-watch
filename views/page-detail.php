<?php
use Tenyendama\SeoWatch\TrendChart;
use Tenyendama\SeoWatch\View;
use Tenyendama\SeoWatch\Csrf;

$deltaClass = static function (float $value, bool $higherIsBetter = true): string {
    if (abs($value) < 0.00001) {
        return 'neutral';
    }
    $positive = $higherIsBetter ? $value > 0 : $value < 0;
    return $positive ? 'positive' : 'negative';
};
$deltaText = static function (float $value, int $decimals = 0, string $suffix = ''): string {
    $prefix = $value > 0 ? '+' : '';
    return $prefix . number_format($value, $decimals) . $suffix;
};
?>
<div class="detail-toolbar">
    <a class="button ghost" href="index.php?r=pages">← ページ一覧</a>
    <form method="get" class="inline-form">
        <input type="hidden" name="r" value="page-detail">
        <input type="hidden" name="u" value="<?=View::e($pageUrl)?>">
        <label class="period-select">比較期間
            <select name="days" onchange="this.form.submit()">
                <?php foreach ([7, 28, 56, 90] as $option): ?>
                    <option value="<?=$option?>" <?=$days === $option ? 'selected' : ''?>>直近<?=$option?>日</option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<section class="card article-hero">
    <div class="grow min-width-0">
        <div class="eyebrow">記事分析・<?=$days?>日比較</div>
        <h2><?=View::e($pageTitle)?></h2>
        <a class="article-url" href="<?=View::e($pageUrl)?>" target="_blank" rel="noopener noreferrer"><?=View::e($pageUrl)?> ↗</a>
        <div class="hero-meta">
            <span>集計 <?=View::e($detail['range']['current_start'])?>〜<?=View::e($detail['range']['current_end'])?></span>
            <span>比較 <?=View::e($detail['range']['previous_start'])?>〜<?=View::e($detail['range']['previous_end'])?></span>
            <span>検索語 <?=number_format((int)$detail['query_count'])?>件</span>
        </div>
    </div>
    <div class="priority-panel">
        <span>改善優先度</span>
        <strong><?=View::e($advice['priority'])?></strong>
        <small>記事Score <?=number_format((float)$detail['score'], 1)?></small>
    </div>
</section>

<section class="stats-grid detail-stats">
    <article class="stat-card">
        <span>クリック</span>
        <strong><?=number_format((float)$detail['current_clicks'])?></strong>
        <small class="metric-delta <?=$deltaClass((float)$detail['click_change'])?>">前期比 <?=$deltaText((float)$detail['click_change'])?></small>
    </article>
    <article class="stat-card">
        <span>表示回数</span>
        <strong><?=number_format((float)$detail['current_impressions'])?></strong>
        <small class="metric-delta <?=$deltaClass((float)$detail['impression_change'])?>">前期比 <?=$deltaText((float)$detail['impression_change'])?></small>
    </article>
    <article class="stat-card">
        <span>CTR</span>
        <strong><?=number_format((float)$detail['current_ctr'] * 100, 2)?>%</strong>
        <small class="metric-delta <?=$deltaClass((float)$detail['ctr_change'])?>">前期比 <?=$deltaText((float)$detail['ctr_change'] * 100, 2, 'pt')?></small>
    </article>
    <article class="stat-card">
        <span>平均掲載順位</span>
        <strong><?=number_format((float)$detail['current_position'], 1)?></strong>
        <small class="metric-delta <?=$deltaClass((float)$detail['position_change'])?>">前期比 <?=$deltaText((float)$detail['position_change'], 1)?></small>
    </article>
</section>

<section class="chart-grid">
    <article class="card chart-card">
        <div class="chart-title"><div><span>クリック推移</span><strong><?=number_format((float)$detail['current_clicks'])?></strong></div></div>
        <?=TrendChart::metricChart($detail['daily'], 'clicks')?>
    </article>
    <article class="card chart-card">
        <div class="chart-title"><div><span>表示回数推移</span><strong><?=number_format((float)$detail['current_impressions'])?></strong></div></div>
        <?=TrendChart::metricChart($detail['daily'], 'impressions')?>
    </article>
    <article class="card chart-card">
        <div class="chart-title"><div><span>掲載順位推移</span><strong><?=number_format((float)$detail['current_position'], 1)?></strong></div><small>上に行くほど良好</small></div>
        <?=TrendChart::metricChart($detail['daily'], 'position', true)?>
    </article>
</section>

<div class="detail-columns">
<section class="card recommendation-card">
    <div class="card-head">
        <div><h2>🎯 改善アクション</h2><p>Search Consoleの実績から、着手順を判定</p></div>
        <div class="gain-badge"><span>推定クリック余地</span><strong>+<?=number_format((float)$advice['estimated_gain_clicks'], 1)?></strong><small>/ <?=$days?>日</small></div>
    </div>
    <div class="action-list">
        <?php foreach ($advice['actions'] as $action): ?>
        <article class="action-item">
            <span class="priority-tag priority-<?=View::e($action['priority'])?>"><?=View::e($action['priority'])?></span>
            <div><strong><?=View::e($action['title'])?></strong><p><?=View::e($action['description'])?></p>
            <?php if (!empty($isSuperuser)): ?>
                <form method="post" action="index.php?r=improvements/create">
                    <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
                    <input type="hidden" name="normalized_page_url" value="<?=View::e($pageUrl)?>">
                    <input type="hidden" name="task_type" value="content">
                    <input type="hidden" name="title" value="<?=View::e($action['title'])?>">
                    <input type="hidden" name="description" value="<?=View::e($action['description'])?>">
                    <input type="hidden" name="source_score" value="<?=View::e((string)$detail['score'])?>">
                    <button class="button small-button" type="submit">タスクへ追加</button>
                </form>
            <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card article-info-card">
    <div class="card-head"><div><h2>🧾 WordPress記事情報</h2><p>REST APIから本文構造を点検</p></div></div>
    <dl class="info-list">
        <div><dt>取得状態</dt><dd><span class="status <?=$inspection['status'] === 'success' ? 'connected' : 'disconnected'?>"><?=View::e($inspection['status'] === 'success' ? '取得済み' : '未取得')?></span></dd></div>
        <div><dt>最終更新</dt><dd><?=$dateTime->time($inspection['modified_at'] ?? null)?></dd></div>
        <div><dt>既存見出し</dt><dd><?=number_format((int)$advice['existing_heading_count'])?>件</dd></div>
    </dl>
    <?php if (!empty($inspection['headings'])): ?>
    <details class="heading-details">
        <summary>既存のH2・H3を見る</summary>
        <ol>
            <?php foreach ($inspection['headings'] as $heading): ?>
                <li class="heading-level-<?=number_format((int)$heading['level'])?>"><span>H<?=number_format((int)$heading['level'])?></span><?=View::e($heading['text'])?></li>
            <?php endforeach; ?>
        </ol>
    </details>
    <?php else: ?>
        <p class="muted small-text">本文見出しを取得できない場合でも、検索語データだけで改善案を生成します。</p>
    <?php endif; ?>
</section>
</div>

<div class="detail-columns suggestions-grid">
<section class="card">
    <div class="card-head"><div><h2>✍️ タイトル改善案</h2><p>上位検索語を自然に含める候補です。採用前に文字数と本文内容を確認してください。</p></div></div>
    <?php if (!$advice['title_suggestions']): ?>
        <p class="muted">現在のタイトルと検索語の一致度は良好です。</p>
    <?php else: ?>
    <ol class="suggestion-list">
        <?php foreach ($advice['title_suggestions'] as $suggestion): ?>
            <li><span><?=View::e($suggestion)?></span><small><?=function_exists('mb_strlen') ? mb_strlen($suggestion, 'UTF-8') : strlen($suggestion)?>文字</small></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head"><div><h2>🧩 追加見出し案</h2><p>既存タイトル・H2・H3に含まれていない検索語を優先</p></div></div>
    <?php if (!$advice['heading_suggestions']): ?>
        <p class="muted">主要検索語は既存のタイトル・見出しでおおむねカバーされています。</p>
    <?php else: ?>
    <div class="heading-suggestion-list">
        <?php foreach ($advice['heading_suggestions'] as $suggestion): ?>
        <article>
            <span class="heading-badge">H2</span>
            <div><strong><?=View::e($suggestion['heading'])?></strong><p><?=View::e($suggestion['reason'])?></p><span class="query-source">元検索語: <?=View::e($suggestion['query'])?></span></div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
</div>

<section class="card keyword-analysis-card">
    <div class="card-head"><div><h2>🔎 検索語別の伸びしろ</h2><p>改善Score順。順位の折れ線は選択期間の日別推移です。</p></div></div>
    <div class="async-pager" data-async-pager data-route="page-detail" aria-live="polite">
        <div class="async-feedback" data-async-feedback hidden></div>
        <div class="async-pager-content" data-async-content>
            <?=View::partial('partials/page-detail-query-table', compact('queryResult', 'pageUrl', 'days'))?>
        </div>
    </div>
</section>
