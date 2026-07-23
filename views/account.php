<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\EmailAddress;
use Tenyendama\SeoWatch\UserAccountPolicy;
use Tenyendama\SeoWatch\View;
?>
<div class="settings-grid">
<section class="card">
    <h2>アカウント情報</h2>
    <dl class="install-summary">
        <div><dt>ユーザー名</dt><dd><?=View::e($account['username'])?></dd></div>
        <div><dt>ロール</dt><dd><?=View::e(UserAccountPolicy::roleLabel((string)$account['role']))?></dd></div>
        <div><dt>状態</dt><dd><?=View::e(UserAccountPolicy::statusLabel((string)$account['account_status']))?></dd></div>
        <div><dt>メール</dt><dd><?=View::e(EmailAddress::mask($account['email']))?></dd></div>
        <div><dt>メール確認</dt><dd><?=$account['email_verified_at'] ? '確認済み' : '未確認'?></dd></div>
        <div><dt>最終ログイン</dt><dd><?=View::e($account['last_login_at'] ?? '記録なし')?></dd></div>
        <div><dt>パスワード変更</dt><dd><?=View::e($account['password_changed_at'] ?? '記録なし')?></dd></div>
    </dl>
    <?php if (!$account['email']): ?>
        <div class="alert warning">パスワード再設定に利用するメールアドレスが未登録です。
        <?php if ($isSuperuser): ?>メールが使えない場合は <code>php bin/reset-password.php --user=<?=View::e($account['username'])?></code> を利用できます。<?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<section class="card">
    <h2>メールアドレス変更</h2>
    <?php if (!$mailEnabled): ?><div class="alert warning">メール送信が無効のため変更を開始できません。<code>config/local.php</code>を設定してください。</div><?php endif; ?>
    <form method="post" action="index.php?r=account/email">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>現在のパスワード<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>新しいメールアドレス<input type="email" name="email" autocomplete="email" required></label>
        <button class="button primary" type="submit" <?=$mailEnabled ? '' : 'disabled'?>>確認メールを送信</button>
    </form>
</section>
<section class="card">
    <h2>パスワード変更</h2>
    <form method="post" action="index.php?r=account/password">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>現在のパスワード<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>新しいパスワード<input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
        <label>新しいパスワード（確認）<input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></label>
        <button class="button primary" type="submit">変更する</button>
    </form>
</section>
</div>
