<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;

$loggedIn = isset($auth) && $auth->check();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?=View::e($title ?? '')?> | <?=View::e($appName ?? '10yendama SEO Watch')?></title>
<link rel="stylesheet" href="assets/app.css?v=0.5.0">
</head>
<body>
<?php if ($loggedIn): ?>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php?r=dashboard">🔭 <span>10yendama<br>SEO Watch</span></a>
        <nav>
            <a class="<?=($route ?? '') === 'dashboard' ? 'active' : ''?>" href="index.php?r=dashboard">📊 ダッシュボード</a>
            <a class="<?=($route ?? '') === 'opportunities' ? 'active' : ''?>" href="index.php?r=opportunities">🚀 伸びしろ</a>
            <a class="<?=($route ?? '') === 'queries' ? 'active' : ''?>" href="index.php?r=queries">🔎 検索語</a>
            <a class="<?=in_array(($route ?? ''), ['pages', 'page-detail'], true) ? 'active' : ''?>" href="index.php?r=pages">📄 ページ</a>
            <?php if (!empty($isSuperuser)): ?>
            <a class="<?=($route ?? '') === 'users' ? 'active' : ''?>" href="index.php?r=users">👥 ユーザー管理</a>
            <a class="<?=($route ?? '') === 'settings' ? 'active' : ''?>" href="index.php?r=settings">⚙️ 設定</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <?php if (!empty($currentUser)): ?>
            <div class="signed-in-user">
                <strong><?=View::e($currentUser['username'])?></strong>
                <span><?=!empty($isSuperuser) ? 'スーパーユーザー' : '閲覧ユーザー'?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($activeProperty)): ?><div class="property-chip" title="<?=View::e($activeProperty['site_url'])?>"><?=View::e($activeProperty['site_url'])?></div><?php endif; ?>
            <form method="post" action="index.php?r=logout">
                <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
                <button class="link-button" type="submit">ログアウト</button>
            </form>
        </div>
    </aside>
    <main class="main-content">
        <header class="page-header"><h1><?=View::e($title ?? '')?></h1></header>
        <?php foreach (($flashes ?? []) as $flash): ?><div class="alert <?=View::e($flash['type'])?>"><?=View::e($flash['message'])?></div><?php endforeach; ?>
        <?=$content?>
    </main>
</div>
<?php else: ?>
    <?=$content?>
<?php endif; ?>
<script src="assets/app.js?v=0.5.0" defer></script>
</body>
</html>
