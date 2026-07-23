<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use RuntimeException;

final class EmailAddress
{
    public static function normalize(string $email): string
    {
        $email = trim($email);
        if ($email === '' || preg_match('/[\r\n\x00]/', $email) === 1) {
            throw new RuntimeException('有効なメールアドレスを入力してください。');
        }
        $email = strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('有効なメールアドレスを入力してください。');
        }
        return $email;
    }

    public static function mask(?string $email): string
    {
        if (!$email || !str_contains($email, '@')) {
            return '未登録';
        }
        [$local, $domain] = explode('@', $email, 2);
        return substr($local, 0, 1) . '***@' . $domain;
    }
}
