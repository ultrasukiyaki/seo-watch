<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\AccountRecoveryService;
use Tenyendama\SeoWatch\Auth;
use Tenyendama\SeoWatch\AuthenticationAuditLogger;
use Tenyendama\SeoWatch\AuthRateLimiter;
use Tenyendama\SeoWatch\DisabledMailer;
use Tenyendama\SeoWatch\ImportLockService;
use Tenyendama\SeoWatch\SchemaManager;
use Tenyendama\SeoWatch\UserActionTokenRepository;
use Tenyendama\SeoWatch\UserRepository;
use Tenyendama\SeoWatch\Tests\FakeMailer;

require_once dirname(__DIR__) . '/app/autoload.php';
require_once __DIR__ . '/FakeMailer.php';
ini_set('session.save_path', sys_get_temp_dir());
session_start();

$dsn = getenv('SEO_WATCH_TEST_DSN') ?: '';
$user = getenv('SEO_WATCH_TEST_USER') ?: 'root';
$pass = getenv('SEO_WATCH_TEST_PASS') ?: '';
if ($dsn === '') {
    fwrite(STDOUT, "SKIP integration tests: SEO_WATCH_TEST_DSN is not set\n");
    exit(0);
}

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec('DROP DATABASE IF EXISTS seo_watch_test');
$server->exec('CREATE DATABASE seo_watch_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = new PDO($dsn . ';dbname=seo_watch_test', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+00:00'");

// v0.6.0相当の既存構造から移行する。
$pdo->exec(<<<'SQL'
CREATE TABLE admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(20) NOT NULL DEFAULT 'viewer',
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_admins_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE search_properties (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 site_url VARCHAR(2048) NOT NULL,
 site_hash BINARY(32) NOT NULL,
 permission_level VARCHAR(50) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 last_synced_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_property_hash (site_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE search_performance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 property_id BIGINT UNSIGNED NOT NULL,
 page_hash BINARY(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$legacyHash = password_hash('legacy-password-123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES ('admin', :hash, 'superuser')");
$stmt->execute(['hash' => $legacyHash]);

$migrations = new SchemaManager($pdo);
$migrations->migrate();
$migrations->migrate();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$columns = $pdo->query('SHOW COLUMNS FROM admins')->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('email', $columns, true), 'email migration');
$assert(in_array('session_version', $columns, true), 'session version migration');
$assert($pdo->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'UTC database session');
$assert((bool)$pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn(), 'settings migration');
$legacy = $pdo->query("SELECT * FROM admins WHERE username = 'admin'")->fetch();
$assert($legacy && $legacy['account_status'] === 'active', 'legacy account active');
$assert(password_verify('legacy-password-123', (string)$legacy['password_hash']), 'legacy password preserved');

$property = $pdo->prepare(
    'INSERT INTO search_properties (site_url, site_hash, is_active) VALUES (:url, :hash, 1)'
);
$property->execute([
    'url' => 'sc-domain:example.com',
    'hash' => hash('sha256', 'sc-domain:example.com', true),
]);
$propertyId = (int)$pdo->lastInsertId();
$locks = new ImportLockService($pdo, 300);
$owner = $locks->acquire($propertyId, 'web');
$storedOwnerHash = $pdo->query(
    'SELECT owner_hash FROM import_locks WHERE property_id = ' . $propertyId
)->fetchColumn();
$assert(is_string($storedOwnerHash) && strlen($storedOwnerHash) === 64, 'lock owner hash is SHA-256 hex');
$assert($storedOwnerHash === hash('sha256', $owner), 'raw lock owner is not stored');
$noOp = $pdo->prepare(
    'UPDATE import_locks SET heartbeat_at = heartbeat_at, expires_at = expires_at
     WHERE property_id = :id AND owner_hash = :owner'
);
$noOp->execute(['id' => $propertyId, 'owner' => $storedOwnerHash]);
$assert($noOp->rowCount() === 0, 'MySQL no-op update reports zero changed rows');
$locks->heartbeat($propertyId, $owner);
$locks->heartbeat($propertyId, $owner);
try {
    $locks->heartbeat($propertyId, 'different-owner');
    $assert(false, 'different owner heartbeat rejected');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), '所有権'), 'different owner heartbeat rejected');
}
$assert(!$locks->release($propertyId, 'different-owner'), 'different owner release rejected');
$pdo->exec(
    'UPDATE import_locks SET heartbeat_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),
     expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
     WHERE property_id = ' . $propertyId
);
try {
    $locks->heartbeat($propertyId, $owner);
    $assert(false, 'expired owner heartbeat rejected');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), '所有権'), 'expired owner heartbeat rejected');
}
$replacementOwner = $locks->acquire($propertyId, 'cron');
try {
    $locks->heartbeat($propertyId, $owner);
    $assert(false, 'stale owner heartbeat rejected after takeover');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), '所有権'), 'stale owner heartbeat rejected after takeover');
}
$assert(!$locks->release($propertyId, $owner), 'stale owner release rejected after takeover');
$locks->heartbeat($propertyId, $replacementOwner);
$assert($locks->release($propertyId, $replacementOwner), 'active owner release succeeds');
$assert(!$locks->release($propertyId, $replacementOwner), 'missing lock release is idempotent');

$_SESSION = [];
$auth = new Auth($pdo);
$assert($auth->attempt('admin', 'legacy-password-123'), 'legacy login');
$assert(isset($_SESSION['session_version']), 'login stores session version');
$viewerHash = password_hash('viewer-password-123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    "INSERT INTO admins (username, password_hash, role, account_status) VALUES ('viewer', :hash, 'viewer', 'active')"
);
$stmt->execute(['hash' => $viewerHash]);
$viewerId = (int)$pdo->lastInsertId();

$tokens = new UserActionTokenRepository($pdo);
$first = $tokens->issue((int)$legacy['id'], UserActionTokenRepository::PASSWORD_RESET);
$stored = $pdo->query('SELECT token_hash FROM user_action_tokens ORDER BY id DESC LIMIT 1')->fetchColumn();
$assert($stored === hash('sha256', $first['token']), 'token hash stored');
$assert($stored !== $first['token'], 'raw token not stored');
$second = $tokens->issue((int)$legacy['id'], UserActionTokenRepository::PASSWORD_RESET);
$assert($tokens->findValid($first['token'], UserActionTokenRepository::PASSWORD_RESET) === null, 'old token invalidated');
$uses = 0;
$assert($tokens->consume($second['token'], UserActionTokenRepository::PASSWORD_RESET, function () use (&$uses): void {
    $uses++;
}), 'valid token consumed');
$assert(!$tokens->consume($second['token'], UserActionTokenRepository::PASSWORD_RESET, function () use (&$uses): void {
    $uses++;
}), 'used token rejected');
$assert($uses === 1, 'single token use');

$limiter = new AuthRateLimiter($pdo, 'integration-secret');
$assert($limiter->consume('test', 'ip', '192.0.2.1', 2, 900), 'rate attempt 1');
$assert($limiter->consume('test', 'ip', '192.0.2.1', 2, 900), 'rate attempt 2');
$assert(!$limiter->consume('test', 'ip', '192.0.2.1', 2, 900), 'rate limited');
$key = $pdo->query("SELECT key_hash FROM auth_rate_limits WHERE action_name = 'test'")->fetchColumn();
$assert($key !== '192.0.2.1', 'rate key pseudonymized');

$audit = new AuthenticationAuditLogger($pdo, 'integration-secret');
$recovery = new AccountRecoveryService($pdo, $tokens, new DisabledMailer(), $audit, 'https://example.com/seo-watch');
$issued = $recovery->issueResetForUser($viewerId, (int)$legacy['id']);
$assert(str_starts_with($issued['url'], 'https://example.com/seo-watch/'), 'trusted reset base URL');

$adminId = (int)$legacy['id'];
try {
    $recovery->requestEmailChange($adminId, 'wrong-password', 'admin@example.com');
    $assert(false, 'wrong current password rejected');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === '現在のパスワードが違います。', 'wrong current password message');
}
$assert($pdo->query("SELECT pending_email FROM admins WHERE id = {$adminId}")->fetchColumn() === null, 'wrong password does not save pending email');

$recovery->requestEmailChange($adminId, 'legacy-password-123', ' Admin@Example.COM ');
$assert($pdo->query("SELECT pending_email FROM admins WHERE id = {$adminId}")->fetchColumn() === 'admin@example.com', 'disabled delivery saves normalized pending email');
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM user_action_tokens WHERE user_id = {$adminId} AND purpose = 'email_verification' AND used_at IS NULL"
)->fetchColumn() === 0, 'disabled delivery does not issue unusable verification token');
$recovery->requestEmailChange($adminId, 'legacy-password-123', 'ADMIN@example.com');

$otherVerified = $pdo->prepare(
    "INSERT INTO admins (username, password_hash, role, email, email_verified_at)
     VALUES ('verified-user', :hash, 'viewer', 'taken@example.com', CURRENT_TIMESTAMP)"
);
$otherVerified->execute(['hash' => password_hash('verified-password-123', PASSWORD_DEFAULT)]);
$otherPending = $pdo->prepare(
    "INSERT INTO admins (username, password_hash, role, pending_email)
     VALUES ('pending-user', :hash, 'viewer', 'waiting@example.com')"
);
$otherPending->execute(['hash' => password_hash('pending-password-123', PASSWORD_DEFAULT)]);
foreach ([' TAKEN@EXAMPLE.COM ', ' Waiting@Example.com '] as $duplicateEmail) {
    try {
        $recovery->requestEmailChange($adminId, 'legacy-password-123', $duplicateEmail);
        $assert(false, 'other user duplicate email rejected');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'すでに使用'), 'duplicate email message');
    }
}

$users = new UserRepository($pdo);
try {
    $users->createInvitation('duplicate-invite', ' TAKEN@EXAMPLE.COM ');
    $assert(false, 'invitation rejects verified email duplicate');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'すでに使用'), 'invitation verified duplicate message');
}
try {
    $users->createInvitation('duplicate-pending-invite', ' WAITING@example.com ');
    $assert(false, 'invitation rejects pending email duplicate');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'すでに使用'), 'invitation pending duplicate message');
}

$fakeMailer = new FakeMailer();
$mailRecovery = new AccountRecoveryService($pdo, $tokens, $fakeMailer, $audit, 'https://example.com/seo-watch');
$mailRecovery->requestEmailChange($adminId, 'legacy-password-123', 'deliver@example.com');
$assert(count($fakeMailer->messages) === 1, 'enabled fake transport sends verification mail');
$firstVerificationHash = $pdo->query(
    "SELECT token_hash FROM user_action_tokens
     WHERE user_id = {$adminId} AND purpose = 'email_verification' AND used_at IS NULL"
)->fetchColumn();
$assert(is_string($firstVerificationHash) && strlen($firstVerificationHash) === 64, 'verification token stored as hash');
$assert($mailRecovery->sendEmailVerification($adminId), 'verification resend succeeds');
$assert(count($fakeMailer->messages) === 2, 'verification resend uses fake transport');
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM user_action_tokens
     WHERE user_id = {$adminId} AND purpose = 'email_verification'
       AND token_hash = " . $pdo->quote((string)$firstVerificationHash) . " AND used_at IS NOT NULL"
)->fetchColumn() === 1, 'verification resend invalidates old token');
$mailRecovery->cancelPendingEmail($adminId);
$assert($pdo->query("SELECT pending_email FROM admins WHERE id = {$adminId}")->fetchColumn() === null, 'pending email cancellation clears value');
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM user_action_tokens
     WHERE user_id = {$adminId} AND purpose = 'email_verification' AND used_at IS NULL"
)->fetchColumn() === 0, 'pending email cancellation invalidates token');

$fakeMailer->sendResult = false;
$mailRecovery->requestEmailChange($adminId, 'legacy-password-123', 'send-failed@example.com');
$assert($pdo->query("SELECT pending_email FROM admins WHERE id = {$adminId}")->fetchColumn() === 'send-failed@example.com', 'send failure preserves pending email');
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM user_action_tokens
     WHERE user_id = {$adminId} AND purpose = 'email_verification' AND used_at IS NULL"
)->fetchColumn() === 1, 'send failure preserves verification token for retry');
$mailRecovery->cancelPendingEmail($adminId);

$fakeMailer->sendResult = true;
$mailRecovery->requestEmailChange($adminId, 'legacy-password-123', 'confirmed@example.com');
$verificationMessage = $fakeMailer->messages[array_key_last($fakeMailer->messages)];
preg_match('/[?&]token=([a-f0-9]{64})/', $verificationMessage['body'], $tokenMatch);
$assert(isset($tokenMatch[1]), 'fake verification mail contains token URL');
$assert($mailRecovery->verifyEmail($tokenMatch[1]), 'email verification completes');
$verifiedAccount = $pdo->query("SELECT email, pending_email, email_verified_at FROM admins WHERE id = {$adminId}")->fetch();
$assert($verifiedAccount['email'] === 'confirmed@example.com', 'verified email promoted');
$assert($verifiedAccount['pending_email'] === null, 'verified pending email cleared');
$assert($verifiedAccount['email_verified_at'] !== null, 'verified timestamp stored');

fwrite(STDOUT, "PASS integration migrations, login, tokens, email hotfix, rate limiting\n");
