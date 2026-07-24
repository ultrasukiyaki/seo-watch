#!/usr/bin/env php
<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\EmailAddress;
use Tenyendama\SeoWatch\PasswordPolicy;
use Tenyendama\SeoWatch\PhpMailMailer;
use Tenyendama\SeoWatch\RoutePolicy;
use Tenyendama\SeoWatch\DateTimeFormatter;
use Tenyendama\SeoWatch\FrozenClock;
use Tenyendama\SeoWatch\SearchConsoleDate;
use Tenyendama\SeoWatch\TimezoneService;
use Tenyendama\SeoWatch\MailFormatter;
use Tenyendama\SeoWatch\MailMessage;

require_once dirname(__DIR__) . '/app/autoload.php';

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    try {
        $callback();
        $tests[] = [$name, true, ''];
    } catch (Throwable $e) {
        $tests[] = [$name, false, $e->getMessage()];
    }
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('email normalization', function () use ($assert): void {
    $assert(EmailAddress::normalize(' Admin@Example.COM ') === 'admin@example.com');
});
$test('email rejects header injection', function () use ($assert): void {
    try {
        EmailAddress::normalize("a@example.com\r\nBcc:x@example.com");
        $assert(false);
    } catch (RuntimeException) {
        $assert(true);
    }
});
$test('password length and identity policy', function () use ($assert): void {
    $assert(PasswordPolicy::validate('short') !== null);
    $assert(PasswordPolicy::validate(str_repeat('a', 12)) === null);
    $assert(PasswordPolicy::validate('same-as-user', 'same-as-user') !== null);
    $assert(PasswordPolicy::validate(str_repeat('a', 129)) !== null);
    $assert(PasswordPolicy::validate("12345678901\0") !== null);
});
$test('mailer rejects CRLF from name', function () use ($assert): void {
    try {
        new PhpMailMailer('noreply@example.com', "SEO Watch\r\nBcc: bad@example.com");
        $assert(false);
    } catch (InvalidArgumentException) {
        $assert(true);
    }
});
$test('mail message and headers are safe UTF-8 MIME', function () use ($assert): void {
    $message = new MailMessage('User@Example.COM', '日本語の件名', ".first\n.second");
    $raw = MailFormatter::format($message, [
        'from_address' => 'noreply@example.com',
        'from_name' => 'SEO ウォッチ',
        'reply_to' => 'support@example.com',
    ]);
    $assert($message->to === 'user@example.com');
    foreach (['Date:', 'Message-ID:', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'] as $header) {
        $assert(str_contains($raw, $header), $header);
    }
    $assert(str_contains($raw, '=?UTF-8?'));
    try {
        new MailMessage('user@example.com', "subject\r\nBcc: bad@example.com", 'body');
        $assert(false);
    } catch (InvalidArgumentException) {
        $assert(true);
    }
});
$test('mail settings routes require superuser', function () use ($assert): void {
    foreach (['mail/settings', 'mail/connection-test', 'mail/test'] as $route) {
        $assert(RoutePolicy::requiresSuperuser($route), $route);
    }
});
$test('smtp password uses application AES-256-GCM crypto', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/MailSettingsRepository.php');
    $crypto = file_get_contents(dirname(__DIR__) . '/app/Crypto.php');
    $assert(is_string($source) && str_contains($source, '$this->crypto->encrypt'));
    $assert(is_string($source) && !str_contains($source, 'smtp_password_plaintext'));
    $assert(is_string($crypto) && str_contains($crypto, "'aes-256-gcm'"));
});
$test('viewer cannot access audit route', function () use ($assert): void {
    $assert(RoutePolicy::requiresSuperuser('audit'));
    $assert(!RoutePolicy::requiresSuperuser('account'));
});
$test('token source stores hash only', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/UserActionTokenRepository.php');
    $assert(is_string($source) && str_contains($source, "hash('sha256', \$token)"));
    $assert(is_string($source) && !str_contains($source, "'token' => \$token,\n                'purpose'"));
});
$test('trusted base URL is used', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/AccountRecoveryService.php');
    $assert(is_string($source) && str_contains($source, '$this->baseUrl'));
    $assert(is_string($source) && !str_contains($source, 'HTTP_HOST'));
});
$test('password reset only uses verified email', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/AccountRecoveryService.php');
    $assert(is_string($source) && substr_count($source, 'email_verified_at IS NOT NULL') >= 2);
});
$test('email duplicate queries use unique native placeholders', function () use ($assert): void {
    foreach (['AccountRecoveryService.php', 'UserRepository.php'] as $file) {
        $source = file_get_contents(dirname(__DIR__) . '/app/' . $file);
        $assert(is_string($source), $file);
        $assert(!str_contains($source, 'email = :email OR pending_email = :email'), $file);
    }
    $service = file_get_contents(dirname(__DIR__) . '/app/AccountRecoveryService.php');
    $assert(is_string($service) && str_contains($service, 'id <> :current_user_id'));
    $assert(is_string($service) && str_contains($service, 'LOWER(email) = :verified_email'));
    $assert(is_string($service) && str_contains($service, 'LOWER(pending_email) = :pending_email'));
    $assert(is_string($service) && str_contains($service, "'current_user_id' => \$userId"));
    $assert(is_string($service) && str_contains($service, "'verified_email' => \$newEmail"));
    $assert(is_string($service) && str_contains($service, "'pending_email' => \$newEmail"));
});
$test('email PDO failures use a safe user message', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/AccountRecoveryService.php');
    $assert(is_string($source) && str_contains($source, "'operation' => 'account_email_change'"));
    $assert(is_string($source) && str_contains($source, 'メールアドレスを保存できませんでした。'));
});
$test('reset pages use protective headers', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/index.php');
    foreach (['Cache-Control: no-store', 'Referrer-Policy: no-referrer', 'X-Robots-Tag: noindex, nofollow'] as $header) {
        $assert(is_string($source) && str_contains($source, $header), $header);
    }
});
$test('UTC display conversions and formats', function () use ($assert): void {
    $clock = new FrozenClock('2026-07-23T08:42:10Z');
    $tokyo = new DateTimeFormatter($clock, 'Asia/Tokyo');
    $london = new DateTimeFormatter($clock, 'Europe/London');
    $losAngeles = new DateTimeFormatter($clock, 'America/Los_Angeles');
    $value = '2026-07-23 08:42:10';
    $assert($tokyo->short($value) === '2026-07-23 17:42');
    $assert($tokyo->detail($value) === '2026-07-23 17:42:10 JST');
    $assert($tokyo->mail($value) === '2026-07-23 17:42:10 JST (Asia/Tokyo)');
    $assert($london->detail($value) === '2026-07-23 09:42:10 BST');
    $assert($losAngeles->detail($value) === '2026-07-23 01:42:10 PDT');
    $assert($tokyo->isoUtc($value) === '2026-07-23T08:42:10Z');
    $assert(str_contains($tokyo->time($value), 'datetime="2026-07-23T08:42:10Z"'));
});
$test('DST boundaries are timezone aware', function () use ($assert): void {
    $spring = new DateTimeFormatter(new FrozenClock('2026-03-08T09:59:59Z'), 'America/Los_Angeles');
    $assert($spring->detail($spring->nowUtc()) === '2026-03-08 01:59:59 PST');
    $springAfter = new DateTimeFormatter(new FrozenClock('2026-03-08T10:00:00Z'), 'America/Los_Angeles');
    $assert($springAfter->detail($springAfter->nowUtc()) === '2026-03-08 03:00:00 PDT');
    $fall = new DateTimeFormatter(new FrozenClock('2026-11-01T08:59:59Z'), 'America/Los_Angeles');
    $assert($fall->detail($fall->nowUtc()) === '2026-11-01 01:59:59 PDT');
    $fallAfter = new DateTimeFormatter(new FrozenClock('2026-11-01T09:00:00Z'), 'America/Los_Angeles');
    $assert($fallAfter->detail($fallAfter->nowUtc()) === '2026-11-01 01:00:00 PST');
});
$test('strict database datetime and safe fallback', function () use ($assert): void {
    $formatter = new DateTimeFormatter(new FrozenClock('2026-01-01T00:00:00Z'), 'UTC');
    $assert($formatter->parseDatabase('2026-02-29 00:00:00') === null);
    $assert($formatter->short(null) === '—');
    $assert($formatter->detail('not-a-date') === '—');
});
$test('IANA timezone validation and fallback', function () use ($assert): void {
    $assert(TimezoneService::isValid('Asia/Tokyo'));
    $assert(!TimezoneService::isValid('UTC+09:00'));
    $formatter = new DateTimeFormatter(new FrozenClock('2026-01-01T00:00:00Z'), 'invalid/timezone');
    $assert($formatter->timezoneName() === 'UTC');
});
$test('Search Console dates remain PT DATE values', function () use ($assert): void {
    $before = new SearchConsoleDate(new FrozenClock('2026-03-08T07:30:00Z'));
    $after = new SearchConsoleDate(new FrozenClock('2026-03-08T10:30:00Z'));
    $assert($before->today() === '2026-03-07');
    $assert($after->today() === '2026-03-08');
    $range = $after->importRange(3, 1);
    $assert($range === ['start' => '2026-03-05', 'end' => '2026-03-07']);
});
$test('viewer cannot change timezone', function () use ($assert): void {
    $assert(RoutePolicy::requiresSuperuser('settings/timezone'));
});
$test('database connection fixes session timezone', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/Database.php');
    $assert(is_string($source) && str_contains($source, "SET time_zone = '+00:00'"));
});
$test('migration does not offset existing datetimes', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/SchemaManager.php');
    $assert(is_string($source) && !preg_match('/DATE_ADD\\s*\\([^)]*INTERVAL\\s+9\\s+HOUR/i', $source));
});
$test('sync lease verifies ownership after a no-op heartbeat', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/ImportLockService.php');
    $assert(is_string($source) && str_contains($source, "hash_equals(\$lock['owner_hash'], \$ownerHash)"));
    $assert(is_string($source) && str_contains($source, 'expires_at >= UTC_TIMESTAMP() AS is_active'));
    $assert(is_string($source) && str_contains($source, 'AND expires_at >= UTC_TIMESTAMP()'));
    $assert(is_string($source) && !str_contains($source, 'MYSQL_ATTR_FOUND_ROWS'));
});

$failed = 0;
foreach ($tests as [$name, $ok, $message]) {
    fwrite($ok ? STDOUT : STDERR, ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($message ? ": {$message}" : '') . "\n");
    if (!$ok) {
        $failed++;
    }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failed));
exit($failed === 0 ? 0 : 1);
