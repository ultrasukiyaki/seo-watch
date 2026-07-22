<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class RuntimeEnvironment
{
    /** @return array<string,bool> */
    public static function requirements(string $configPath, bool $requireConfig = false): array
    {
        $checks = [
            'PHP 8.1以上' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'cURL' => extension_loaded('curl'),
            'OpenSSL' => extension_loaded('openssl'),
            'JSON' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
        ];

        if ($requireConfig) {
            $checks['config/local.php'] = is_file($configPath);
        } else {
            $checks['configディレクトリ書き込み可'] = is_writable(dirname($configPath));
        }

        return $checks;
    }

    /** @param array<string,mixed>|null $server */
    public static function requestIsHttps(?array $server = null): bool
    {
        $server ??= $_SERVER;

        if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') {
            return true;
        }
        if ((string)($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @param array<string,mixed>|null $server */
    public static function detectedBaseUrl(?array $server = null): string
    {
        $server ??= $_SERVER;
        $scheme = self::requestIsHttps($server) ? 'https' : 'http';
        $host = trim((string)($server['HTTP_HOST'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }

        $requestPath = parse_url((string)($server['REQUEST_URI'] ?? '/install.php'), PHP_URL_PATH);
        if (!is_string($requestPath) || $requestPath === '') {
            $requestPath = '/install.php';
        }
        $dir = rtrim(str_replace('\\', '/', dirname($requestPath)), '/');

        return $scheme . '://' . $host . ($dir === '' || $dir === '.' ? '' : $dir);
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.');
        }

        return false;
    }

    public static function validateBaseUrl(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'ベースURLが不正です。';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'ベースURLを解析できません。';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return 'ベースURLにホスト名がありません。';
        }
        if ($scheme !== 'https' && !($scheme === 'http' && self::isLocalHost($host))) {
            return '公開環境のベースURLはHTTPSで指定してください。HTTPはlocalhostでの開発時だけ利用できます。';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'ベースURLにユーザー情報を含めないでください。';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'ベースURLにクエリ文字列やフラグメントを含めないでください。';
        }

        return null;
    }
}
