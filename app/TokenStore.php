<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class TokenStore
{
    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    public function get(string $provider = 'google'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT encrypted_token FROM oauth_tokens WHERE provider = :provider');
        $stmt->execute(['provider' => $provider]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($this->crypto->decrypt($value), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function put(array $token, string $provider = 'google'): void
    {
        $payload = $this->crypto->encrypt(json_encode($token, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $stmt = $this->pdo->prepare(
            'INSERT INTO oauth_tokens (provider, encrypted_token) VALUES (:provider, :token)
             ON DUPLICATE KEY UPDATE encrypted_token = VALUES(encrypted_token), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['provider' => $provider, 'token' => $payload]);
    }

    public function delete(string $provider = 'google'): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM oauth_tokens WHERE provider = :provider');
        $stmt->execute(['provider' => $provider]);
    }
}
