<?php use Tenyendama\SeoWatch\Csrf; use Tenyendama\SeoWatch\View; ?>
<p class="notice">割合とCTR差は小数で指定します（30%=0.3、2ポイント=0.02）。system rule keyは変更できません。</p>
<div class="stack-list"><?php foreach($rules as $rule):?><section class="card"><form method="post" action="index.php?r=alerts/rules/save" class="form-grid">
<input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><input type="hidden" name="rule_id" value="<?=(int)$rule['id']?>">
<label>表示名<input name="name" value="<?=View::e($rule['name'])?>" required></label><label>rule key<input value="<?=View::e($rule['rule_key'])?>" disabled></label>
<label>有効<input type="checkbox" name="enabled" value="1" <?=$rule['enabled']?'checked':''?>></label>
<label>比較期間<select name="comparison_days"><option value="7" <?=$rule['comparison_days']==7?'selected':''?>>7日</option><option value="28" <?=$rule['comparison_days']==28?'selected':''?>>28日</option></select></label>
<?php foreach(['minimum_impressions'=>'最低表示回数','minimum_clicks'=>'最低クリック数','absolute_change_threshold'=>'絶対変化量','relative_change_threshold'=>'相対変化率','ctr_point_threshold'=>'CTR絶対差','position_change_threshold'=>'順位変化量','rank_threshold'=>'順位閾値','maximum_ctr'=>'最大CTR','minimum_position'=>'最低順位','cooldown_days'=>'cooldown日数'] as $key=>$label):?><label><?=$label?><input type="number" step="any" min="0" name="<?=$key?>" value="<?=View::e($rule[$key]??'')?>"></label><?php endforeach?>
<label>重要度<select name="severity"><?php foreach(['info','warning','critical'] as $v):?><option value="<?=$v?>" <?=$rule['severity']===$v?'selected':''?>><?=$v?></option><?php endforeach?></select></label>
<button class="button primary" type="submit">保存</button></form><form method="post" action="index.php?r=alerts/rules/reset" data-confirm="このルールを初期値へ戻しますか？"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><input type="hidden" name="rule_id" value="<?=(int)$rule['id']?>"><button class="button" type="submit">初期値へ戻す</button></form></section><?php endforeach?></div>
