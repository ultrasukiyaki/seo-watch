<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<form class="toolbar" method="get">
  <input type="hidden" name="r" value="alerts">
  <label><input type="checkbox" name="unread" value="1" <?=!empty($filters['unread'])?'checked':''?>> 未読のみ</label>
  <label>重要度<select name="severity"><option value="">すべて</option><?php foreach(['critical','warning','info'] as $v):?><option value="<?=$v?>" <?=($filters['severity']??'')===$v?'selected':''?>><?=$v?></option><?php endforeach?></select></label>
  <label><input type="checkbox" name="include_hidden" value="1" <?=!empty($filters['include_hidden'])?'checked':''?>> 非表示を含む</label>
  <button class="button" type="submit">絞り込む</button>
</form>
<?php if ($isSuperuser): ?><form method="post" action="index.php?r=alerts/detect" class="button-row"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><button class="button primary" type="submit">今すぐ検知</button></form><?php endif?>
<div class="table-card"><table><thead><tr><th>状態</th><th>重要度</th><th>種別</th><th>対象</th><th>期間</th><th>変化量</th><th>最終検知</th><th>回数</th><th>操作</th></tr></thead><tbody>
<?php foreach($alerts as $item):?><tr>
<td><?=empty($item['read_at'])?'未読':'既読'?></td><td><?=View::e($item['severity'])?></td>
<td><a href="index.php?r=alerts/detail&amp;id=<?=(int)$item['id']?>"><?=View::e($item['rule_name'])?></a></td>
<td class="wide-cell"><a href="index.php?r=page-detail&amp;u=<?=rawurlencode($item['normalized_page_url'])?>"><?=View::e($item['normalized_page_url'])?></a><?php if($item['query_text']):?><br><small><?=View::e($item['query_text'])?></small><?php endif?></td>
<td><?=(int)$item['comparison_days']?>日</td><td><?=View::e($item['absolute_delta']??'—')?></td>
<td><?=$dateTime->time($item['last_detected_at'])?></td><td><?=(int)$item['occurrence_count']?></td>
<td><form method="post" action="index.php?r=alerts/state"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><input type="hidden" name="alert_id" value="<?=(int)$item['id']?>"><button class="button small-button" name="action" value="<?=empty($item['hidden_at'])?'hide':'unhide'?>"><?=empty($item['hidden_at'])?'非表示':'解除'?></button></form></td>
</tr><?php endforeach?>
<?php if(!$alerts):?><tr><td colspan="9" class="empty-cell">該当する変動通知はありません。</td></tr><?php endif?>
</tbody></table></div>
<p class="muted">通知はSearch Consoleデータの変化を示すもので、Googleアップデートや記事修正との因果関係を断定するものではありません。</p>
