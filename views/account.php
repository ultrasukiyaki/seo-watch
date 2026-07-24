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
        <div><dt>メール</dt><dd><?=View::e(EmailAddress::mask($account['email']) ?: '未設定')?></dd></div>
        <?php if ($account['pending_email']): ?><div><dt>変更待ち</dt><dd><?=View::e($account['pending_email'])?></dd></div><?php endif; ?>
        <div><dt>メール確認</dt><dd><?=$account['email_verified_at'] ? $dateTime->time($account['email_verified_at']) : '未確認'?></dd></div>
        <div><dt>最終ログイン</dt><dd><?=$dateTime->time($account['last_login_at'] ?? null)?></dd></div>
        <div><dt>パスワード変更</dt><dd><?=$dateTime->time($account['password_changed_at'] ?? null)?></dd></div>
    </dl>
    <?php if (!$account['email']): ?>
        <div class="alert warning">パスワード再設定に利用するメールアドレスが未登録です。
        <?php if ($isSuperuser): ?>メールが使えない場合は <code>php bin/reset-password.php --user=<?=View::e($account['username'])?></code> を利用できます。<?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<section class="card">
    <h2><?=$account['email'] ? 'メールアドレス変更' : 'メールアドレスを追加'?></h2>
    <?php if (!$mailEnabled): ?><div class="alert warning">メール配送が未設定のため、確認待ちとして保存します。配送設定後に確認メールを送信してください。</div><?php endif; ?>
    <?php if ($account['pending_email']): ?>
    <p>メールアドレス: 確認待ち<br><strong><?=View::e($account['pending_email'])?></strong></p>
    <div class="button-row">
      <?php if ($mailEnabled): ?><form method="post" action="index.php?r=account/email-send"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><button class="button" type="submit">確認メールを再送</button></form><?php endif; ?>
      <form method="post" action="index.php?r=account/email-cancel"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><button class="button danger-button" type="submit">登録を取り消す</button></form>
      <?php if (!$mailEnabled && $isSuperuser): ?><a class="button" href="index.php?r=settings">メール設定を開く</a><?php endif; ?>
    </div>
    <?php else: ?>
    <form method="post" action="index.php?r=account/email">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>現在のパスワード<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>新しいメールアドレス<input type="email" name="email" autocomplete="email" required></label>
        <button class="button primary" type="submit"><?=$mailEnabled ? '確認メールを送信' : '確認待ちとして保存'?></button>
    </form>
    <?php endif; ?>
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
