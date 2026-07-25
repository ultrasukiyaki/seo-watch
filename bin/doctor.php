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
        $services = require dirname(__DIR__) . '/app/bootstrap.php';
        extract($services, EXTR_SKIP);
        echo "[OK] Database connection and migrations\n";
        $migration = $pdo->query('SELECT migration_id, status, applied_at FROM schema_migrations ORDER BY started_at DESC LIMIT 1')->fetch();
        printf("[INFO] App version: v%s\n", trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
        printf("[%s] DB schema: %s (%s)\n", ($migration['status'] ?? '') === 'applied' ? 'OK' : 'NG', $migration['migration_id'] ?? 'unknown', $migration['status'] ?? 'unknown');
        $dbTimezone = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
        printf("[%s] DB session timezone: %s\n", $dbTimezone === '+00:00' ? 'OK' : 'NG', $dbTimezone);
        printf("[INFO] PHP timezone: %s\n", date_default_timezone_get());
        printf("[%s] Display timezone: %s%s\n", $displayTimezoneConfirmed ? 'OK' : 'WARN', $dateTime->timezoneName(), $displayTimezoneConfirmed ? '' : ' (unconfirmed)');
        printf("[INFO] UTC now: %s\n", $dateTime->isoUtc($dateTime->nowUtc()));
        printf("[INFO] Display now: %s\n", $dateTime->detail($dateTime->nowUtc()));
        printf("[OK] Search Console timezone/date: %s / %s\n", \Tenyendama\SeoWatch\SearchConsoleDate::TIMEZONE, $searchConsoleDate->today());
        $ruleCounts = $pdo->query('SELECT COUNT(*) total, SUM(enabled=1) enabled FROM alert_rules')->fetch();
        printf("[INFO] Alert rules: %d total / %d enabled\n", (int)$ruleCounts['total'], (int)$ruleCounts['enabled']);
        $lastAlertRun = $pdo->query('SELECT started_at,status FROM alert_detection_runs ORDER BY id DESC LIMIT 1')->fetch();
        printf("[INFO] Last alert detection: %s / %s\n", $lastAlertRun['started_at'] ?? 'never', $lastAlertRun['status'] ?? '-');
        printf("[%s] Alert email/digest: transport=%s\n", $mailer->enabled() ? 'OK' : 'WARN', $mailSettingsData['transport']);
        $latestSearchDate = $pdo->query('SELECT MAX(data_date) FROM search_performance')->fetchColumn();
        printf("[INFO] Latest Search Console date (PT DATE): %s\n", $latestSearchDate ?: 'none');
        echo "[INFO] Cron: import -> detect-alerts.php -> send-alert-digest.php\n";
    } catch (Throwable $e) {
        echo "[NG] Bootstrap: {$e->getMessage()}\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
