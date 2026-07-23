<?php use Tenyendama\SeoWatch\View; ?>
<section class="card">
<h1>一度だけ表示する再設定URL</h1>
<div class="alert warning">このURLは再表示できません。対象ユーザーへ安全な方法で渡してください。</div>
<label>有効期限: <?=View::e($expiresAt)?><input readonly value="<?=View::e($manualUrl)?>"></label>
<p><a class="button" href="index.php?r=users">ユーザー管理へ戻る</a></p>
</section>
