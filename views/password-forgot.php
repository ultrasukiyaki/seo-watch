<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<div class="login-card">
    <h1>パスワード再設定</h1>
    <p class="muted">ユーザー名または登録メールアドレスを入力してください。</p>
    <?php if ($message): ?><div class="alert success"><?=View::e($message)?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>ユーザー名またはメールアドレス
            <input name="identifier" autocomplete="username" required>
        </label>
        <button class="button primary" type="submit">再設定方法を送信</button>
    </form>
    <p><a href="index.php?r=login">ログインへ戻る</a></p>
</div>
