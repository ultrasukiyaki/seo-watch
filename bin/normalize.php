#!/usr/bin/env php
<?php
declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
extract($services, EXTR_SKIP);

$active = $propertyRepo->active();
if (!$active) {
    fwrite(STDERR, "分析対象プロパティが選択されていません。\n");
    exit(1);
}

$count = $dataMaintenance->normalizeExisting((int)$active['id']);
fwrite(STDOUT, number_format($count) . "行の既存URLを正規化しました。\n");
