<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<div class="login-card">
<h1>招待を受け入れる</h1>
<?php if ($error): ?><div class="alert danger"><?=View::e($error)?></div><?php endif; ?>
<?php if ($valid): ?>
<p>閲覧ユーザーのパスワードを設定してください。</p>
<form method="post">
<input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
<input type="hidden" name="token" value="<?=View::e($token)?>">
<label>新しいパスワード<input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
<label>新しいパスワード（確認）<input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></label>
<button class="button primary" type="submit">アカウントを有効化</button>
</form>
<?php else: ?><p>この招待URLは利用できません。</p><?php endif; ?>
</div>
