<?php use Tenyendama\SeoWatch\Csrf; use Tenyendama\SeoWatch\View; ?>
<section class="card">
<h2><?=View::e($alert['rule_name'])?> <span class="status"><?=View::e($alert['severity'])?></span></h2>
<p><?=View::e($alert['description'])?></p>
<dl class="info-list">
<div><dt>ページ</dt><dd><a href="index.php?r=page-detail&amp;u=<?=rawurlencode($alert['normalized_page_url'])?>"><?=View::e($alert['normalized_page_url'])?></a></dd></div>
<div><dt>検索語</dt><dd><?=View::e($alert['query_text']??'—')?></dd></div>
<div><dt>比較期間</dt><dd><?=(int)$alert['comparison_days']?>日</dd></div>
<div><dt>初回／最終検知</dt><dd><?=$dateTime->time($alert['first_detected_at'])?> ／ <?=$dateTime->time($alert['last_detected_at'])?></dd></div>
</dl>
<form method="post" action="index.php?r=alerts/state" class="button-row"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><input type="hidden" name="alert_id" value="<?=(int)$alert['id']?>"><button class="button" name="action" value="unread">未読に戻す</button><button class="button" name="action" value="<?=empty($alert['hidden_at'])?'hide':'unhide'?>"><?=empty($alert['hidden_at'])?'非表示':'表示へ戻す'?></button></form>
<?php if($isSuperuser):?><?php if(!empty($alert['improvement_task_id'])):?><p><a class="button primary" href="index.php?r=improvements">改善タスク #<?=(int)$alert['improvement_task_id']?> を開く</a></p><?php else:?><form method="post" action="index.php?r=alerts/task"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><input type="hidden" name="alert_id" value="<?=(int)$alert['id']?>"><button class="button primary" type="submit">改善タスクへ追加</button></form><?php endif?><?php endif?>
</section>
<section class="table-card"><table><thead><tr><th>基準日</th><th>前期間</th><th>現期間</th><th>クリック</th><th>表示</th><th>CTR</th><th>順位</th><th>説明</th><th>実行</th></tr></thead><tbody>
<?php foreach($alert['occurrences'] as $o):?><tr><td><?=View::e($o['detected_for_date'])?></td>
<td><?=View::e($o['previous_start_date'])?>〜<?=View::e($o['previous_end_date'])?></td><td><?=View::e($o['current_start_date'])?>〜<?=View::e($o['current_end_date'])?></td>
<td><?=View::e($o['previous_clicks'])?> → <?=View::e($o['current_clicks'])?></td><td><?=View::e($o['previous_impressions'])?> → <?=View::e($o['current_impressions'])?></td>
<td><?=number_format((float)$o['previous_ctr']*100,2)?>% → <?=number_format((float)$o['current_ctr']*100,2)?>%</td><td><?=View::e($o['previous_position']??'—')?> → <?=View::e($o['current_position']??'—')?></td>
<td><?=View::e($o['explanation_snapshot'])?></td><td><?=View::e($o['run_status'])?> / <?=View::e($o['trigger_type'])?></td></tr><?php endforeach?>
</tbody></table></section>
