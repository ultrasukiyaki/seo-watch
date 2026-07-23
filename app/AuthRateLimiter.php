<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class AuthRateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly string $key)
    {
    }

    public function consume(string $action, string $dimension, string $value, int $limit, int $windowSeconds): bool
    {
        $keyHash = hash_hmac('sha256', $action . "\0" . $dimension . "\0" . strtolower($value), $this->key);
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, attempts, window_started_at FROM auth_rate_limits
                 WHERE action_name = :action AND key_hash = :key_hash FOR UPDATE'
            );
            $stmt->execute(['action' => $action, 'key_hash' => $keyHash]);
            $row = $stmt->fetch();
            if (!$row || (string)$row['window_started_at'] < $cutoff) {
                $upsert = $this->pdo->prepare(
                    'INSERT INTO auth_rate_limits (action_name, key_hash, attempts, window_started_at, updated_at)
                     VALUES (:action, :key_hash, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP'
                );
                $upsert->execute(['action' => $action, 'key_hash' => $keyHash]);
                $allowed = true;
            } else {
                $allowed = (int)$row['attempts'] < $limit;
                $this->pdo->prepare(
                    'UPDATE auth_rate_limits SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute(['id' => (int)$row['id']]);
            }
            $this->pdo->commit();
            return $allowed;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
