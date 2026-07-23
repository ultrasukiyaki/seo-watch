#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php bin/purge-auth-data.php\n期限切れトークン、30日より古いレート制限、180日より古い監査ログを削除します。\n");
    exit(0);
}
try {
    $services = require dirname(__DIR__) . '/app/bootstrap.php';
    $pdo = $services['pdo'];
    $tokens = $pdo->exec("DELETE FROM user_action_tokens WHERE expires_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)");
    $limits = $pdo->exec("DELETE FROM auth_rate_limits WHERE updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)");
    $logs = $pdo->exec("DELETE FROM authentication_audit_logs WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 180 DAY)");
    fwrite(STDOUT, "削除: tokens={$tokens}, rate_limits={$limits}, audit_logs={$logs}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "認証データを整理できませんでした: " . $e->getMessage() . "\n");
    exit(1);
}
