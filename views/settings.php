<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<div class="settings-grid">
<?php if (!$displayTimezoneConfirmed): ?><div class="alert warning full-span">表示タイムゾーンが未確認です。設定画面で利用地域のタイムゾーンを確認してください。</div><?php endif; ?>
<section class="card">
    <h2>表示タイムゾーン</h2>
    <form method="post" action="index.php?r=settings/timezone">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>表示タイムゾーン
            <select name="display_timezone" required>
            <?php foreach ($timezoneIdentifiers as $timezone): ?>
                <option value="<?=View::e($timezone)?>" <?=$timezone === $dateTime->timezoneName() ? 'selected' : ''?>><?=View::e($timezone)?></option>
            <?php endforeach; ?>
            </select>
        </label>
        <p class="hint">IANAタイムゾーンを使用します。変更してもDB内のUTC日時は書き換えません。</p>
        <button class="button primary" type="submit">保存</button>
    </form>
    <dl class="install-summary">
        <div><dt>現在の表示例</dt><dd><?=View::e($dateTime->detail($dateTime->nowUtc()))?></dd></div>
        <div><dt>現在のUTC</dt><dd><?=View::e($dateTime->nowUtc()->format('Y-m-d H:i:s T'))?></dd></div>
        <div><dt>Search Console基準日</dt><dd><?=View::e($searchConsoleDate->today())?> PT</dd></div>
    </dl>
</section>
<section class="card">
    <div class="card-head"><div><h2>Google Search Console</h2><p>読み取り専用スコープで接続</p></div><span class="status <?=$oauthConnected ? 'connected' : 'disconnected'?>"><?=$oauthConnected ? '接続済み' : '未接続'?></span></div>
    <label class="copy-label">承認済みリダイレクトURI<input readonly value="<?=View::e($redirectUri)?>"></label>
    <div class="button-row">
        <?php if (!$oauthConnected): ?><a class="button primary" href="index.php?r=oauth/start">Googleと連携する</a>
        <?php else: ?>
        <a class="button" href="index.php?r=oauth/start">再認証</a>
        <form method="post" action="index.php?r=properties/refresh"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><button class="button" type="submit">プロパティ更新</button></form>
        <form method="post" action="index.php?r=oauth/disconnect" data-confirm="Google連携を解除しますか？"><input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>"><button class="button danger-button" type="submit">連携解除</button></form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <h2>メール配送</h2>
    <dl class="install-summary">
        <div><dt>メール機能</dt><dd><?=View::e(['disabled'=>'未設定','php_mail'=>'PHP mail()','smtp'=>'SMTP'][$mailSettings['transport']] ?? '未設定')?></dd></div>
        <div><dt>送信元名称</dt><dd><?=View::e($mailFromName ?: '未設定')?></dd></div>
        <div><dt>送信元アドレス</dt><dd><?=View::e($mailFromAddress)?></dd></div>
        <div><dt>PHP mail()</dt><dd><?=$mailFunctionAvailable ? '利用可能（受付結果であり到達保証ではありません）' : '利用不可'?></dd></div>
        <div><dt>SMTP</dt><dd><?=View::e($mailSettings['smtp_host'] ? $mailSettings['smtp_host'] . ':' . $mailSettings['smtp_port'] : '未設定')?></dd></div>
        <div><dt>SMTPパスワード</dt><dd><?=empty($mailSettings['smtp_password_ciphertext']) ? '未設定' : '設定済み'?></dd></div>
        <div><dt>最終接続テスト</dt><dd><?=View::e(($mailSettings['last_connection_test_status'] ?? '未実施') . ' ' . ($mailSettings['last_connection_test_at'] ?? ''))?></dd></div>
        <div><dt>最終テストメール</dt><dd><?=View::e(($mailSettings['last_test_mail_status'] ?? '未実施') . ' ' . ($mailSettings['last_test_mail_at'] ?? ''))?></dd></div>
    </dl>
    <form method="post" action="index.php?r=mail/settings">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>配送方式<select name="transport">
            <option value="disabled" <?=$mailSettings['transport']==='disabled'?'selected':''?>>使用しない</option>
            <option value="php_mail" <?=$mailSettings['transport']==='php_mail'?'selected':''?>>PHP mail()</option>
            <option value="smtp" <?=$mailSettings['transport']==='smtp'?'selected':''?>>SMTP</option>
        </select></label>
        <label>送信元名称<input name="from_name" value="<?=View::e($mailSettings['from_name'])?>"></label>
        <label>Fromアドレス<input type="email" name="from_address" value="<?=View::e($mailSettings['from_address'])?>"></label>
        <label>Reply-To（任意）<input type="email" name="reply_to" value="<?=View::e($mailSettings['reply_to'])?>"></label>
        <label>Envelope-From（任意）<input type="email" name="envelope_from" value="<?=View::e($mailSettings['envelope_from'])?>"></label>
        <label>SMTPホスト<input name="smtp_host" value="<?=View::e($mailSettings['smtp_host'])?>"></label>
        <label>ポート<input type="number" name="smtp_port" min="1" max="65535" value="<?=(int)$mailSettings['smtp_port']?>"></label>
        <label>暗号化<select name="smtp_encryption">
            <option value="starttls" <?=$mailSettings['smtp_encryption']==='starttls'?'selected':''?>>STARTTLS</option>
            <option value="tls" <?=$mailSettings['smtp_encryption']==='tls'?'selected':''?>>TLS接続</option>
            <option value="none" <?=$mailSettings['smtp_encryption']==='none'?'selected':''?>>暗号化なし</option>
        </select></label>
        <label><input type="checkbox" name="smtp_auth_enabled" value="1" <?=!empty($mailSettings['smtp_auth_enabled'])?'checked':''?>> SMTP認証を使用</label>
        <label>SMTPユーザー名<input name="smtp_username" autocomplete="off" value="<?=View::e($mailSettings['smtp_username'])?>"></label>
        <label>SMTPパスワード<input type="password" name="smtp_password" autocomplete="new-password" placeholder="••••••••••••"></label>
        <p class="hint">空欄の場合は現在値を維持します。</p>
        <label><input type="checkbox" name="smtp_password_delete" value="1"> 現在のSMTPパスワードを削除</label>
        <label>接続タイムアウト（秒）<input type="number" name="smtp_timeout" min="1" max="60" value="<?=(int)$mailSettings['smtp_timeout']?>"></label>
        <button class="button primary" type="submit">メール設定を保存</button>
    </form>
    <p class="hint">実際のSPF・DKIM・DMARC合否は、SMTPサーバーとDNS設定によって決まります。SEO Watchは配送認証の合格を保証しません。PHP mail()よりSMTPを推奨します。</p>
    <form method="post" action="index.php?r=mail/connection-test">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <button class="button" type="submit" <?=$mailSettings['transport']==='smtp'?'':'disabled'?>>SMTP接続テスト（メール送信なし）</button>
    </form>
    <form method="post" action="index.php?r=mail/test">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>現在のパスワード<input type="password" name="current_password" autocomplete="current-password" required></label>
        <button class="button" type="submit" <?=($mailEnabled && $superuserAccount['email_verified_at']) ? '' : 'disabled'?>>確認済みアドレスへテスト送信</button>
    </form>
</section>

<section class="card">
    <h2>分析対象プロパティ</h2>
    <?php if (!$properties): ?><p class="muted">Google連携後にプロパティ一覧を取得します。</p><?php else: ?>
    <div class="property-list">
        <?php foreach ($properties as $property): ?>
        <form method="post" action="index.php?r=properties/activate" class="property-row <?=$property['is_active'] ? 'selected' : ''?>">
            <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
            <input type="hidden" name="property_id" value="<?=(int)$property['id']?>">
            <div class="grow min-width-0"><strong><?=View::e($property['site_url'])?></strong><small><?=View::e($property['permission_level'])?></small></div>
            <?php if ($property['is_active']): ?><span class="status connected">選択中</span><?php else: ?><button class="button small-button" type="submit">選択</button><?php endif; ?>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>データ取り込み</h2>
    <p>当日から<?=$importLagDays?>日前まで待ち、Search Consoleの確定データだけを保存します。日次CronはCLIから実行できます。</p>
    <pre>php bin/import.php --days=3</pre>
    <?php if ($activeProperty): ?>
    <div class="button-row">
        <form method="post" action="index.php?r=import/run" class="inline-form">
            <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
            <select name="days"><option value="7">7日</option><option value="28" selected>28日</option><option value="56">56日</option><option value="90">90日</option></select>
            <button class="button primary" type="submit">手動取り込み</button>
        </form>
        <form method="post" action="index.php?r=maintenance/normalize" data-confirm="既存データのURLを正規化します。実行しますか？">
            <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
            <button class="button" type="submit">既存URLを正規化</button>
        </form>
    </div>
    <p class="hint">v0.2.0へ更新後に一度だけ「既存URLを正規化」を実行すると、過去データの <code>#toc</code>、末尾スラッシュ、UTM等を記事単位へ統合できます。</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>サーバー環境診断</h2>
    <p class="hint">現在の環境でSEO Watchが正常に動作するかを確認できます。エラーや注意がある場合は、表示された案内に従ってください。</p>
    <div class="diagnostic-grid">
        <?php foreach ($diagnostics as $check): ?>
        <div class="diagnostic-row <?=$check['type']?>">
            <div class="diagnostic-label"><?=View::e($check['label'])?></div>
            <div class="diagnostic-status <?=View::e($check['type'])?>"><?=View::e($check['status'])?></div>
            <div class="diagnostic-message"><?=View::e($check['message'])?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <h2>Cron設定</h2>
    <p class="hint">以下のコマンド例をコピーして、サーバーのCronに登録してください。PHPのCLIパスは環境によって異なります。</p>
    <div class="cron-info-grid">
        <div><strong>Web PHP実行ファイル</strong><p><?=View::e($cliPhpPath)?></p></div>
        <div><strong>アプリケーションパス</strong><p><?=View::e($appRootPath)?></p></div>
        <div><strong>推奨Cronコマンド</strong><pre><?=View::e($cronImportCommand)?></pre></div>
        <div><strong>変動検知（同期完了後）</strong><pre><?=View::e($cronAlertCommand)?></pre></div>
        <div><strong>日次ダイジェスト</strong><pre><?=View::e($cronDigestCommand)?></pre></div>
        <div><strong>ラッパー利用例</strong><pre><?=View::e($cronWrapperCommand)?></pre></div>
        <div><strong>最終データ取得</strong><p><?=$lastRun ? $dateTime->time($lastRun['started_at'], false) . '（' . View::e($lastRun['start_date']) . '〜' . View::e($lastRun['end_date']) . '、PT）' : 'データ取得履歴がありません。'?></p></div>
    </div>
    <div class="hint">
        <p>サーバーによってPHP実行パスが異なるため、SSHや管理画面で <code>command -v php</code> を実行して確認してください。</p>
        <p>Google OAuthアプリがTesting状態の場合、更新トークンは7日で失効する可能性があります。まず手動取り込みが成功してからCronを登録してください。</p>
        <p><strong>config/local.php</strong> はWeb公開しないでください。</p>
    </div>
</section>

<section class="card full-span">
    <h2>最近の取り込み履歴</h2><p class="hint">表示時刻: <?=View::e($dateTime->timezoneName())?> / 期間: Search Console基準日（PT）</p>
    <div class="table-card flat">
    <table><thead><tr><th>開始</th><th>期間</th><th>状態</th><th class="num">行数</th><th>メッセージ</th></tr></thead><tbody>
    <?php if (!$runs): ?><tr><td colspan="5" class="empty-cell">履歴はありません。</td></tr><?php endif; ?>
    <?php foreach ($runs as $run): ?><tr><td><?=$dateTime->time($run['started_at'], false)?></td><td><?=View::e($run['start_date'])?> 〜 <?=View::e($run['end_date'])?></td><td><span class="status <?=$run['status'] === 'success' ? 'connected' : ($run['status'] === 'failed' ? 'disconnected' : '')?>"><?=View::e($run['status'])?></span></td><td class="num"><?=number_format((int)$run['rows_imported'])?></td><td><?=View::e($run['message'] ?? '')?></td></tr><?php endforeach; ?>
    </tbody></table>
    </div>
</section>
</div>
