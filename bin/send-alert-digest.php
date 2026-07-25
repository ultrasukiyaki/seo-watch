#!/usr/bin/env php
<?php
declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
extract($services, EXTR_SKIP);
$options = getopt('', ['user:', 'date:', 'dry-run', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/send-alert-digest.php [--user=ID] [--date=YYYY-MM-DD] [--dry-run] [--verbose]\n";
    exit(0);
}
try {
    $userId = isset($options['user']) ? filter_var($options['user'], FILTER_VALIDATE_INT) : null;
    $date = isset($options['date']) ? (string)$options['date'] : (new DateTimeImmutable('now', new DateTimeZone($dateTime->timezoneName())))->format('Y-m-d');
    if (($userId !== null && (int)$userId < 1) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('引数が不正です。--help を確認してください。');
    }
    if (isset($options['dry-run'])) {
        echo "DRY-RUN: メール送信とDB更新は行いません。\n";
        exit(0);
    }
    $result = $alertDelivery->sendDigests($userId === null ? null : (int)$userId, $date);
    printf("Digest: sent=%d failed=%d skipped=%d\n", $result['sent'], $result['failed'], $result['skipped']);
    exit($result['failed'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
