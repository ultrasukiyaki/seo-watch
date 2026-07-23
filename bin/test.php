#!/usr/bin/env php
<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\EmailAddress;
use Tenyendama\SeoWatch\PasswordPolicy;
use Tenyendama\SeoWatch\PhpMailMailer;
use Tenyendama\SeoWatch\RoutePolicy;

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
$test('reset pages use protective headers', function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/index.php');
    foreach (['Cache-Control: no-store', 'Referrer-Policy: no-referrer', 'X-Robots-Tag: noindex, nofollow'] as $header) {
        $assert(is_string($source) && str_contains($source, $header), $header);
    }
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
