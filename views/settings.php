<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<div class="settings-grid">
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

<section class="card full-span">
    <h2>最近の取り込み履歴</h2>
    <div class="table-card flat">
    <table><thead><tr><th>開始</th><th>期間</th><th>状態</th><th class="num">行数</th><th>メッセージ</th></tr></thead><tbody>
    <?php if (!$runs): ?><tr><td colspan="5" class="empty-cell">履歴はありません。</td></tr><?php endif; ?>
    <?php foreach ($runs as $run): ?><tr><td><?=View::e($run['started_at'])?></td><td><?=View::e($run['start_date'])?> 〜 <?=View::e($run['end_date'])?></td><td><span class="status <?=$run['status'] === 'success' ? 'connected' : ($run['status'] === 'failed' ? 'disconnected' : '')?>"><?=View::e($run['status'])?></span></td><td class="num"><?=number_format((int)$run['rows_imported'])?></td><td><?=View::e($run['message'] ?? '')?></td></tr><?php endforeach; ?>
    </tbody></table>
    </div>
</section>
</div>
