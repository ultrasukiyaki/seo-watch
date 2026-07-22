<?php use Tenyendama\SeoWatch\View; ?>
<?php
$isPage = $dimension === 'page';
$routeName = $isPage ? 'pages' : 'queries';
?>
<div class="table-card">
<table>
<thead><tr><th><?=$isPage ? '記事 / 正規化URL' : '検索語'?></th><th class="num">クリック</th><th class="num">表示回数</th><th class="num">CTR</th><th class="num">平均順位</th><th class="num">出現日数</th></tr></thead>
<tbody>
<?php if (!$result['rows']): ?><tr><td colspan="6" class="empty-cell">データがありません。</td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $row): ?>
<tr>
    <td class="wide-cell">
        <?php if ($isPage): ?>
            <div class="title-link-row"><a class="url-title" href="index.php?r=page-detail&amp;u=<?=rawurlencode((string)$row['label'])?>"><?=View::e($row['page_title'] ?: $row['label'])?></a><a class="external-link" href="<?=View::e($row['label'])?>" target="_blank" rel="noopener noreferrer" title="記事を開く">↗</a></div>
            <?php if ($row['page_title']): ?><span class="sub-url"><?=View::e($row['label'])?></span><?php endif; ?>
        <?php else: ?>
            <strong><?=View::e($row['label'])?></strong>
        <?php endif; ?>
    </td>
    <td class="num"><?=number_format((float)$row['clicks'])?></td>
    <td class="num"><?=number_format((float)$row['impressions'])?></td>
    <td class="num"><?=number_format((float)$row['ctr'] * 100, 2)?>%</td>
    <td class="num"><?=number_format((float)$row['position'], 1)?></td>
    <td class="num"><?=number_format((int)$row['active_days'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?=View::partial('partials/pagination', [
    'result' => $result,
    'baseQuery' => ['r' => $routeName, 'q' => $search],
    'pageParam' => 'p',
    'perPageParam' => 'pp',
    'allowedPerPage' => [25, 50, 100],
])?>
