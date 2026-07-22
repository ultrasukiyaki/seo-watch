#!/usr/bin/env php
<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\RuntimeEnvironment;

require dirname(__DIR__) . '/app/RuntimeEnvironment.php';

$configPath = dirname(__DIR__) . '/config/local.php';
$checks = RuntimeEnvironment::requirements($configPath, true);
$failed = false;

foreach ($checks as $name => $ok) {
    printf("[%s] %s\n", $ok ? 'OK' : 'NG', $name);
    $failed = $failed || !$ok;
}

if (!$failed) {
    try {
        require dirname(__DIR__) . '/app/bootstrap.php';
        echo "[OK] Database connection and migrations\n";
    } catch (Throwable $e) {
        echo "[NG] Bootstrap: {$e->getMessage()}\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
