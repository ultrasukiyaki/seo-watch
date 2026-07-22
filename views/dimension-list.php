<?php use Tenyendama\SeoWatch\View; ?>
<?php
$isPage = $dimension === 'page';
$routeName = $isPage ? 'pages' : 'queries';
?>
<?php if (!$activeProperty): ?>
<section class="empty-state card"><h2>プロパティを選択してください</h2><?php if (!empty($isSuperuser)): ?><a class="button primary" href="index.php?r=settings">設定を開く</a><?php else: ?><p>スーパーユーザーによる設定完了をお待ちください。</p><?php endif; ?></section>
<?php else: ?>
<form method="get" class="search-bar">
    <input type="hidden" name="r" value="<?=$routeName?>">
    <input name="q" value="<?=View::e($search)?>" placeholder="<?=$isPage ? 'URLを検索' : '検索語を検索'?>">
    <button class="button" type="submit">検索</button>
    <?php if ($search !== ''): ?><a class="button ghost" href="index.php?r=<?=$routeName?>">解除</a><?php endif; ?>
</form>
<section class="async-pager" data-async-pager data-route="<?=$routeName?>" aria-live="polite">
    <div class="async-feedback" data-async-feedback hidden></div>
    <div class="async-pager-content" data-async-content>
        <?=View::partial('partials/dimension-table', compact('dimension', 'result', 'search'))?>
    </div>
</section>
<?php endif; ?>
