#!/usr/bin/env php
<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\MaintenanceService;

$options = getopt('', ['dry-run', 'execute', 'target:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/maintenance.php [--dry-run|--execute] [--target=auth|tokens|rate-limits|auth-audit|import-runs|import-locks]\n";
    echo "Default is --dry-run. Search Console data, page metadata, tasks, users, OAuth and settings are never deleted.\n";
    exit(0);
}
if (isset($options['dry-run'], $options['execute'])) {
    fwrite(STDERR, "--dry-run と --execute は同時に指定できません。\n");
    exit(2);
}

$services = require dirname(__DIR__) . '/app/bootstrap.php';
$maintenance = new MaintenanceService($services['pdo']);
$execute = isset($options['execute']);
try {
    $result = $maintenance->run($execute, isset($options['target']) ? (string)$options['target'] : null);
    printf("Mode: %s\n", $execute ? 'execute' : 'dry-run');
    foreach ($result as $target => $counts) {
        printf("%-14s planned=%d deleted=%d\n", $target, $counts['planned'], $counts['deleted']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
