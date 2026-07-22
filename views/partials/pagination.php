<?php
use Tenyendama\SeoWatch\Paginator;
use Tenyendama\SeoWatch\View;

$pageParam = $pageParam ?? 'p';
$perPageParam = $perPageParam ?? 'pp';
$allowedPerPage = $allowedPerPage ?? [25, 50, 100];
$baseQuery = is_array($baseQuery ?? null) ? $baseQuery : [];
$currentPage = (int)($result['page'] ?? 1);
$perPage = (int)($result['per_page'] ?? $allowedPerPage[0]);
$totalPages = (int)($result['total_pages'] ?? 1);
$total = (int)($result['total'] ?? 0);

$urlFor = static function (int $page, ?int $size = null) use ($baseQuery, $pageParam, $perPageParam, $perPage): string {
    $query = $baseQuery;
    $query[$pageParam] = max(1, $page);
    $query[$perPageParam] = $size ?? $perPage;
    return 'index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
?>
<nav class="pagination async-pagination" aria-label="ページ送り">
    <div class="pagination-summary">
        <strong><?=number_format((int)($result['from'] ?? 0))?>〜<?=number_format((int)($result['to'] ?? 0))?></strong>
        <span>/ <?=number_format($total)?>件</span>
    </div>

    <div class="pagination-links">
        <?php if (!empty($result['has_previous'])): ?>
            <a class="button small-button" data-page-link href="<?=View::e($urlFor($currentPage - 1))?>">← 前へ</a>
        <?php else: ?>
            <span class="button small-button disabled" aria-disabled="true">← 前へ</span>
        <?php endif; ?>

        <div class="page-numbers" aria-label="ページ番号">
            <?php foreach (Paginator::window($currentPage, $totalPages) as $number): ?>
                <?php if ($number === null): ?>
                    <span class="page-ellipsis" aria-hidden="true">…</span>
                <?php elseif ($number === $currentPage): ?>
                    <span class="page-number active" aria-current="page"><?=number_format($number)?></span>
                <?php else: ?>
                    <a class="page-number" data-page-link href="<?=View::e($urlFor($number))?>"><?=number_format($number)?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($result['has_next'])): ?>
            <a class="button small-button" data-page-link href="<?=View::e($urlFor($currentPage + 1))?>">次へ →</a>
        <?php else: ?>
            <span class="button small-button disabled" aria-disabled="true">次へ →</span>
        <?php endif; ?>
    </div>

    <form method="get" class="pagination-size" data-page-size-form>
        <?php foreach ($baseQuery as $key => $value): ?>
            <input type="hidden" name="<?=View::e((string)$key)?>" value="<?=View::e((string)$value)?>">
        <?php endforeach; ?>
        <input type="hidden" name="<?=View::e($pageParam)?>" value="1">
        <label>表示件数
            <select name="<?=View::e($perPageParam)?>" data-page-size-select onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <?php foreach ($allowedPerPage as $size): ?>
                    <option value="<?=number_format((int)$size)?>" <?=$perPage === (int)$size ? 'selected' : ''?>><?=number_format((int)$size)?>件</option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</nav>
