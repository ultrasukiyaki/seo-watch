<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<main class="login-wrap">
    <form method="post" class="login-card">
        <div class="brand login-brand">🔭 10yendama SEO Watch</div>
        <h1>管理画面ログイン</h1>
        <?php if ($error): ?><div class="alert danger"><?=View::e($error)?></div><?php endif; ?>
        <?php foreach (($flashes ?? []) as $flash): ?><div class="alert <?=View::e($flash['type'])?>"><?=View::e($flash['message'])?></div><?php endforeach; ?>
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>ユーザー名<input name="username" autocomplete="username" required autofocus></label>
        <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button primary wide-button" type="submit">ログイン</button>
        <p><a href="index.php?r=password-forgot">パスワードを忘れた方</a></p>
    </form>
    <?php require __DIR__ . '/partials/footer.php'; ?>
</main>
