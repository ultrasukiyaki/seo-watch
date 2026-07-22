<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

/**
 * Issues and verifies a short-lived OAuth state token bound to the
 * current authenticated PHP session.
 *
 * The token is self-contained and HMAC-signed, so shared-hosting session
 * write delays or a second OAuth tab cannot overwrite the only valid state.
 */
final class OAuthState
{
    private const TTL_SECONDS = 900;

    public function __construct(private readonly Config $config)
    {
    }

    public function issue(): string
    {
        $payload = json_encode([
            'nonce' => self::base64UrlEncode(random_bytes(24)),
            'issued_at' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $encodedPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $this->signedMessage($encodedPayload), $this->key(), true);

        return $encodedPayload . '.' . self::base64UrlEncode($signature);
    }

    public function verify(string $state): bool
    {
        if ($state === '' || substr_count($state, '.') !== 1) {
            return false;
        }

        [$encodedPayload, $encodedSignature] = explode('.', $state, 2);
        $signature = self::base64UrlDecode($encodedSignature);
        if ($encodedPayload === '' || $signature === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $this->signedMessage($encodedPayload), $this->key(), true);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return false;
        }

        try {
            $payload = json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($payload)) {
            return false;
        }

        $nonce = $payload['nonce'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        if (!is_string($nonce) || $nonce === '' || !is_int($issuedAt)) {
            return false;
        }

        $age = time() - $issuedAt;
        return $age >= 0 && $age <= self::TTL_SECONDS;
    }

    private function signedMessage(string $encodedPayload): string
    {
        $sessionId = session_id();
        $adminId = (string)($_SESSION['admin_id'] ?? '');

        return $encodedPayload . "\0" . $sessionId . "\0" . $adminId;
    }

    private function key(): string
    {
        $configured = (string)$this->config->get('app.key', '');
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return $decoded;
            }
        }

        if (strlen($configured) >= 32) {
            return $configured;
        }

        throw new \RuntimeException('OAuth state署名用のアプリキーが不正です。');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
