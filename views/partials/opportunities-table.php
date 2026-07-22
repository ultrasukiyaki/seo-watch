<?php use Tenyendama\SeoWatch\View; ?>
<div class="table-card">
<table>
<thead><tr><th>#</th><th>検索語 / 主なページ</th><th>理由</th><th class="num">ページ数</th><th class="num">表示</th><th class="num">クリック</th><th class="num">CTR</th><th class="num">順位</th><th class="num">前期順位</th><th class="num">Score</th></tr></thead>
<tbody>
<?php if (!$result['rows']): ?><tr><td colspan="10" class="empty-cell">分析データがありません。</td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $index => $item): ?>
<tr>
    <td><?=number_format((int)$result['from'] + $index)?></td>
    <td class="wide-cell"><strong><?=View::e($item['query_text'])?></strong><a class="sub-url internal-sub-link" href="index.php?r=page-detail&amp;u=<?=rawurlencode((string)$item['page_url'])?>"><?=View::e($item['page_title'] ?: $item['page_url'])?></a><?php if ($item['page_title']): ?><span class="sub-url faint"><?=View::e($item['page_url'])?></span><?php endif; ?></td>
    <td><div class="chips wrap"><?php foreach ($item['reasons'] as $reason): ?><span><?=View::e($reason)?></span><?php endforeach; ?></div></td>
    <td class="num"><?=number_format((int)$item['page_count'])?></td>
    <td class="num"><?=number_format((float)$item['current_impressions'])?></td>
    <td class="num"><?=number_format((float)$item['current_clicks'])?></td>
    <td class="num"><?=number_format((float)$item['current_ctr'] * 100, 2)?>%</td>
    <td class="num"><?=number_format((float)$item['current_position'], 1)?></td>
    <td class="num"><?=$item['previous_position'] ? number_format((float)$item['previous_position'], 1) : '—'?></td>
    <td class="num"><strong><?=number_format((float)$item['score'], 1)?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?=View::partial('partials/pagination', [
    'result' => $result,
    'baseQuery' => ['r' => 'opportunities'],
    'pageParam' => 'p',
    'perPageParam' => 'pp',
    'allowedPerPage' => [25, 50, 100],
])?>
