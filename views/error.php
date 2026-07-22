<?php use Tenyendama\SeoWatch\View; ?>
<section class="empty-state card">
    <div class="empty-icon">⚠️</div>
    <h2>処理できませんでした</h2>
    <p><?=View::e($message)?></p>
    <a class="button" href="index.php?r=dashboard">ダッシュボードへ戻る</a>
</section>
