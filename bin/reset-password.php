#!/usr/bin/env php
<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\PasswordPolicy;

require_once dirname(__DIR__) . '/app/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$options = getopt('', ['user:', 'email:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage:\n  php bin/reset-password.php --user=admin\n  php bin/reset-password.php --email=admin@example.com\n");
    exit(0);
}
if ((isset($options['user']) ? 1 : 0) + (isset($options['email']) ? 1 : 0) !== 1) {
    fwrite(STDERR, "--userまたは--emailのどちらか一方を指定してください。\n");
    exit(2);
}
$isTty = function_exists('stream_isatty')
    ? stream_isatty(STDIN)
    : (function_exists('posix_isatty') && posix_isatty(STDIN));
if (!$isTty) {
    fwrite(STDERR, "安全のため、対話TTY以外からは実行できません。\n");
    exit(2);
}

try {
    $services = require dirname(__DIR__) . '/app/bootstrap.php';
    $pdo = $services['pdo'];
    $field = isset($options['user']) ? 'username' : 'email';
    $value = (string)($options[isset($options['user']) ? 'user' : 'email'] ?? '');
    $stmt = $pdo->prepare("SELECT id, username, email, password_hash FROM admins WHERE {$field} = :value");
    $stmt->execute(['value' => $field === 'email' ? strtolower(trim($value)) : trim($value)]);
    $rows = $stmt->fetchAll();
    if (count($rows) !== 1) {
        throw new RuntimeException('対象ユーザーを一意に特定できません。');
    }
    $user = $rows[0];

    $readSecret = static function (string $prompt): string {
        fwrite(STDOUT, $prompt);
        $hidden = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');
        if ($hidden) {
            shell_exec('stty -echo');
        } else {
            fwrite(STDERR, "\n警告: この環境では入力を非表示にできません。\n");
        }
        $value = fgets(STDIN);
        if ($hidden) {
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
        if ($value === false) {
            throw new RuntimeException('入力を読み取れません。');
        }
        return rtrim($value, "\r\n");
    };

    $password = $readSecret('新しいパスワード: ');
    $confirmation = $readSecret('新しいパスワード（確認）: ');
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('確認用パスワードが一致しません。');
    }
    $error = PasswordPolicy::validate(
        $password,
        (string)$user['username'],
        $user['email'] !== null ? (string)$user['email'] : null,
        (string)$user['password_hash']
    );
    if ($error !== null) {
        throw new RuntimeException($error);
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE admins SET password_hash = :hash, password_changed_at = CURRENT_TIMESTAMP,
         session_version = session_version + 1, account_status = 'active' WHERE id = :id"
    )->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$user['id']]);
    $services['actionTokens']->invalidateForUser((int)$user['id']);
    $pdo->commit();
    $services['audit']->log('cli_password_reset', 'success', null, (int)$user['id']);
    fwrite(STDOUT, "パスワードを更新し、既存セッションと未使用トークンを無効化しました。\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "リセットに失敗しました: " . $e->getMessage() . "\n");
    exit(1);
}
