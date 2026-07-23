<?php use Tenyendama\SeoWatch\View; ?>
<section class="card">
<h2>認証監査ログ</h2><p class="hint">表示時刻: <?=View::e($dateTime->timezoneName())?></p>
<form method="get" class="inline-form">
    <input type="hidden" name="r" value="audit">
    <input name="event_type" value="<?=View::e($filters['event_type'] ?? '')?>" placeholder="イベント種別">
    <select name="outcome"><option value="">すべての結果</option><option value="success">success</option><option value="failure">failure</option><option value="sent">sent</option></select>
    <input type="date" name="from" value="<?=View::e($filters['from'] ?? '')?>">
    <input type="date" name="to" value="<?=View::e($filters['to'] ?? '')?>">
    <button class="button" type="submit">絞り込み</button>
</form>
<div class="table-card flat"><table>
<thead><tr><th>日時</th><th>イベント</th><th>結果</th><th>実行者</th><th>対象</th></tr></thead>
<tbody>
<?php if (!$result['rows']): ?><tr><td colspan="5">記録はありません。</td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $row): ?><tr>
<td><?=$dateTime->time($row['created_at'])?></td><td><?=View::e($row['event_type'])?></td><td><?=View::e($row['outcome'])?></td>
<td><?=View::e($row['actor_user_id'] ?? '-')?></td><td><?=View::e($row['subject_user_id'] ?? '-')?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php
$lastPage = max(1, (int)ceil($result['total'] / $result['perPage']));
$query = array_filter($filters, static fn($value): bool => $value !== '');
?>
<nav class="pagination" aria-label="監査ログページ">
<?php if ($result['page'] > 1): ?><a class="button" href="index.php?<?=View::e(http_build_query($query + ['r' => 'audit', 'p' => $result['page'] - 1]))?>">前へ</a><?php endif; ?>
<span><?=$result['page']?> / <?=$lastPage?>ページ（<?=number_format($result['total'])?>件）</span>
<?php if ($result['page'] < $lastPage): ?><a class="button" href="index.php?<?=View::e(http_build_query($query + ['r' => 'audit', 'p' => $result['page'] + 1]))?>">次へ</a><?php endif; ?>
</nav>
</section>
