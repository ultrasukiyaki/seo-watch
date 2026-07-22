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
    $end = isset($options['end'])
        ? new DateTimeImmutable((string)$options['end'])
        : (new DateTimeImmutable('today', new DateTimeZone('America/Los_Angeles')))->modify("-{$lag} days");
    if (isset($options['start'])) {
        $start = new DateTimeImmutable((string)$options['start']);
    } else {
        $days = max(1, min(365, (int)($options['days'] ?? 3)));
        $start = $end->modify('-' . ($days - 1) . ' days');
    }
    if ($start > $end) throw new RuntimeException('開始日は終了日以前にしてください。');

    printf("[%s] Import %s: %s -> %s\n", date('c'), $active['site_url'], $start->format('Y-m-d'), $end->format('Y-m-d'));
    $rows = $importer->import($active, $start->format('Y-m-d'), $end->format('Y-m-d'));
    printf("Done: %s rows\n", number_format($rows));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
