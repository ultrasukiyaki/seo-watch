<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class UserAccountPolicy
{
    public const ROLE_SUPERUSER = 'superuser';
    public const ROLE_VIEWER = 'viewer';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_SUPERUSER, self::ROLE_VIEWER];
    }

    public static function normalizeRole(string $role): string
    {
        return in_array($role, self::roles(), true) ? $role : self::ROLE_VIEWER;
    }

    public static function isSuperuser(string $role): bool
    {
        return self::normalizeRole($role) === self::ROLE_SUPERUSER;
    }

    public static function roleLabel(string $role): string
    {
        return self::isSuperuser($role) ? 'スーパーユーザー' : '閲覧ユーザー';
    }

    public static function validateUsername(string $username): ?string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
        if ($length < 3 || $length > 64) {
            return 'ユーザー名は3〜64文字で入力してください。';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $username) === 1 || preg_match('/\s/u', $username) === 1) {
            return 'ユーザー名に空白や制御文字は使用できません。';
        }
        return null;
    }

    public static function validatePassword(string $password): ?string
    {
        $length = strlen($password);
        if ($length < 10) {
            return 'パスワードは10文字以上にしてください。';
        }
        if ($length > 4096) {
            return 'パスワードが長すぎます。';
        }
        return null;
    }
}
