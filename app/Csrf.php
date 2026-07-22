<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }

    public static function verify(?string $token): void
    {
        $stored = $_SESSION['_csrf'] ?? '';
        if (!is_string($token) || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
            throw new \RuntimeException('CSRFトークンが不正です。画面を再読み込みしてください。');
        }
    }
}
