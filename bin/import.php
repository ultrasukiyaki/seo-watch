#!/usr/bin/env php
<?php
declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
extract($services, EXTR_SKIP);

$options = getopt('', ['days::', 'start::', 'end::']);
$active = $propertyRepo->active();
if (!$active) {
    fwrite(STDERR, "分析対象プロパティが未選択です。管理画面の設定から選択してください。\n");
    exit(1);
}

try {
    $lag = max(1, min(7, (int)$config->get('app.import_lag_days', 3)));
    $end = isset($options['end']) ? $searchConsoleDate->validate((string)$options['end']) : null;
    if (isset($options['start'])) {
        $start = $searchConsoleDate->validate((string)$options['start']);
    } else {
        $days = max(1, min(365, (int)($options['days'] ?? 3)));
        $range = $searchConsoleDate->importRange($days, $lag);
        $start = $range['start'];
        $end ??= $range['end'];
    }
    $end ??= $searchConsoleDate->importRange(1, $lag)['end'];
    if ($start > $end) throw new RuntimeException('開始日は終了日以前にしてください。');

    printf("[%s] Import %s: %s -> %s (Search Console date, PT)\n", $dateTime->detail($dateTime->nowUtc()), $active['site_url'], $start, $end);
    $source = getenv('SEO_WATCH_IMPORT_SOURCE') === 'cron' ? 'cron' : 'cli';
    $rows = $importer->import($active, $start, $end, 'web', $source);
    printf("Done: %s rows\n", number_format($rows));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
