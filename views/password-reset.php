<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<div class="login-card">
    <h1>新しいパスワード</h1>
    <?php if ($error): ?><div class="alert danger"><?=View::e($error)?></div><?php endif; ?>
    <?php if ($valid): ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <input type="hidden" name="token" value="<?=View::e($token)?>">
        <label>新しいパスワード
            <input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required>
        </label>
        <label>新しいパスワード（確認）
            <input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required>
        </label>
        <button class="button primary" type="submit">パスワードを変更</button>
    </form>
    <?php else: ?>
    <p>このURLは利用できません。期限切れ、使用済み、または無効になった可能性があります。</p>
    <?php endif; ?>
    <p><a href="index.php?r=login">ログインへ戻る</a></p>
</div>
