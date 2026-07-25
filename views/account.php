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
<section class="card">
    <h2>変動通知設定</h2>
    <?php if (!$mailEnabled): ?><div class="alert warning">メール配送が停止中です。アプリ内通知は利用できます。</div><?php endif; ?>
    <?php if (empty($account['email_verified_at'])): ?><div class="alert warning">メール通知には確認済みメールアドレスが必要です。</div><?php endif; ?>
    <form method="post" action="index.php?r=alerts/preferences" class="stack-form">
      <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
      <label><input type="checkbox" name="in_app_enabled" value="1" <?=!empty($notificationPreference['in_app_enabled'])?'checked':''?>> アプリ内通知</label>
      <label><input type="checkbox" name="email_enabled" value="1" <?=!empty($notificationPreference['email_enabled'])?'checked':''?> <?=(!$mailEnabled||empty($account['email_verified_at']))?'disabled':''?>> メール通知</label>
      <label>配送方式<select name="delivery_mode"><option value="none">送信しない</option><option value="immediate" <?=$notificationPreference['delivery_mode']==='immediate'?'selected':''?>>即時</option><option value="daily_digest" <?=$notificationPreference['delivery_mode']==='daily_digest'?'selected':''?>>日次ダイジェスト</option></select></label>
      <label>最低重要度<select name="minimum_severity"><?php foreach(['info','warning','critical'] as $v):?><option value="<?=$v?>" <?=$notificationPreference['minimum_severity']===$v?'selected':''?>><?=$v?></option><?php endforeach?></select></label>
      <label>ダイジェスト時刻<input type="time" name="digest_time" value="<?=View::e(substr((string)$notificationPreference['digest_time'],0,5))?>"></label>
      <fieldset><legend>対象ルール</legend><?php $selected=json_decode((string)($notificationPreference['enabled_rule_types']??''),true); foreach($alertRuleKeys as $key):?><label><input type="checkbox" name="enabled_rule_types[]" value="<?=View::e($key)?>" <?=!is_array($selected)||in_array($key,$selected,true)?'checked':''?>> <?=View::e($key)?></label><?php endforeach?></fieldset>
      <button class="button primary" type="submit">通知設定を保存</button>
    </form>
</section>
</div>
