<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class PasswordPolicy
{
    public static function validate(
        string $password,
        string $username = '',
        ?string $email = null,
        ?string $currentHash = null
    ): ?string {
        if (str_contains($password, "\0")) {
            return 'パスワードに使用できない文字が含まれています。';
        }
        $length = strlen($password);
        if ($length < 12 || $length > 128) {
            return 'パスワードは12文字以上128文字以下にしてください。';
        }
        if ($username !== '' && hash_equals($username, $password)) {
            return 'ユーザー名と同じパスワードは使用できません。';
        }
        if ($email !== null && $email !== '' && hash_equals(strtolower($email), strtolower($password))) {
            return 'メールアドレスと同じパスワードは使用できません。';
        }
        if ($currentHash !== null && $currentHash !== '' && password_verify($password, $currentHash)) {
            return '現在と同じパスワードは使用できません。';
        }
        return null;
    }
}
