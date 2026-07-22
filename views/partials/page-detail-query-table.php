<?php
use Tenyendama\SeoWatch\TrendChart;
use Tenyendama\SeoWatch\View;

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
<div class="table-card flat">
<table class="detail-query-table">
    <thead><tr><th>#</th><th>検索語 / 判定理由</th><th class="num">クリック</th><th class="num">表示</th><th class="num">CTR</th><th class="num">順位</th><th class="num">前期</th><th>順位推移</th><th class="num">Score</th></tr></thead>
    <tbody>
    <?php if (!$queryResult['rows']): ?><tr><td colspan="9" class="empty-cell">検索語データがありません。</td></tr><?php endif; ?>
    <?php foreach ($queryResult['rows'] as $index => $query): ?>
    <?php
        $positionValues = [];
        foreach ($query['trend'] as $point) {
            if ((float)$point['position'] > 0) {
                $positionValues[] = (float)$point['position'];
            }
        }
    ?>
    <tr>
        <td><?=number_format((int)$queryResult['from'] + $index)?></td>
        <td class="wide-cell"><strong><?=View::e($query['query_text'])?></strong><div class="reasons"><?=View::e(implode(' / ', $query['reasons']))?></div></td>
        <td class="num"><?=number_format((float)$query['current_clicks'])?><small class="table-delta <?=$deltaClass((float)$query['click_change'])?>"><?=$deltaText((float)$query['click_change'])?></small></td>
        <td class="num"><?=number_format((float)$query['current_impressions'])?><small class="table-delta <?=$deltaClass((float)$query['impression_change'])?>"><?=$deltaText((float)$query['impression_change'])?></small></td>
        <td class="num"><?=number_format((float)$query['current_ctr'] * 100, 2)?>%</td>
        <td class="num"><?=number_format((float)$query['current_position'], 1)?></td>
        <td class="num"><?=(float)$query['previous_position'] > 0 ? number_format((float)$query['previous_position'], 1) : '—'?></td>
        <td class="trend-cell"><?=TrendChart::sparkline($positionValues, true)?></td>
        <td class="num"><strong><?=number_format((float)$query['score'], 1)?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?=View::partial('partials/pagination', [
    'result' => $queryResult,
    'baseQuery' => ['r' => 'page-detail', 'u' => $pageUrl, 'days' => $days],
    'pageParam' => 'p',
    'perPageParam' => 'pp',
    'allowedPerPage' => [20, 50, 100],
])?>
