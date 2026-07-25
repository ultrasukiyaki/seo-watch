#!/usr/bin/env php
<?php
declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
extract($services, EXTR_SKIP);

$options = getopt('', ['property:', 'window:', 'as-of:', 'dry-run', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/detect-alerts.php [--property=ID] [--window=7|28] [--as-of=YYYY-MM-DD] [--dry-run] [--verbose]\n";
    exit(0);
}
try {
    $propertyId = isset($options['property']) ? filter_var($options['property'], FILTER_VALIDATE_INT) : null;
    $window = isset($options['window']) ? filter_var($options['window'], FILTER_VALIDATE_INT) : null;
    $asOf = isset($options['as-of']) ? (string)$options['as-of'] : null;
    if (($propertyId !== null && (int)$propertyId < 1)
        || ($window !== null && !in_array((int)$window, [7, 28], true))
        || ($asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1)) {
        throw new RuntimeException('引数が不正です。--help を確認してください。');
    }
    $properties = $propertyId !== null
        ? array_values(array_filter($propertyRepo->all(), static fn(array $p): bool => (int)$p['id'] === (int)$propertyId))
        : $propertyRepo->all();
    if (!$properties) {
        throw new RuntimeException('対象プロパティが見つかりません。');
    }
    $exit = 0;
    foreach ($properties as $property) {
        $result = $alertDetection->detect(
            (int)$property['id'],
            getenv('SEO_WATCH_ALERT_SOURCE') === 'cron' ? 'cron' : 'cli',
            null,
            $window === null ? null : (int)$window,
            $asOf,
            isset($options['dry-run'])
        );
        printf("%s: %s as-of=%s created=%d updated=%d occurrences=%d%s\n",
            $property['site_url'], $result['status'], $result['as_of'] ?? '-',
            $result['alerts_created'] ?? 0, $result['alerts_updated'] ?? 0,
            $result['occurrences_created'] ?? 0,
            isset($result['reason']) ? ' reason=' . $result['reason'] : ''
        );
        if (!isset($options['dry-run']) && (int)($result['run_id'] ?? 0) > 0) {
            $delivery = $alertDelivery->sendImmediate((int)$result['run_id']);
            printf("Mail: sent=%d failed=%d skipped=%d\n", $delivery['sent'], $delivery['failed'], $delivery['skipped']);
        }
        if ($result['status'] === 'failed') {
            $exit = 1;
        } elseif ($result['status'] === 'partial_success' && $exit === 0) {
            $exit = 2;
        }
    }
    exit($exit);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
