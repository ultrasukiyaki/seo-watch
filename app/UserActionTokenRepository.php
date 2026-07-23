<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class UserActionTokenRepository
{
    public const PASSWORD_RESET = 'password_reset';
    public const INVITATION = 'invitation';
    public const EMAIL_VERIFICATION = 'email_verification';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock = new SystemClock()
    )
    {
    }

    /** @return array{token:string,expires_at:string} */
    public function issue(
        int $userId,
        string $purpose,
        int $ttlSeconds = 1800,
        ?string $pendingValue = null,
        ?int $createdBy = null
    ): array {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = $this->clock->nowUtc()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE user_action_tokens SET used_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
            )->execute(['user_id' => $userId, 'purpose' => $purpose]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_action_tokens
                 (user_id, purpose, token_hash, pending_value, expires_at, created_by_user_id)
                 VALUES (:user_id, :purpose, :token_hash, :pending_value, :expires_at, :created_by)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'purpose' => $purpose,
                'token_hash' => $hash,
                'pending_value' => $pendingValue,
                'expires_at' => $expires,
                'created_by' => $createdBy,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['token' => $token, 'expires_at' => $expires];
    }

    public function findValid(string $token, string $purpose): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.*, a.username, a.email, a.password_hash, a.account_status
             FROM user_action_tokens t JOIN admins a ON a.id = t.user_id
             WHERE t.token_hash = :hash AND t.purpose = :purpose
               AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token), 'purpose' => $purpose]);
        return $stmt->fetch() ?: null;
    }

    public function consume(string $token, string $purpose, callable $operation): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM user_action_tokens
                 WHERE token_hash = :hash AND purpose = :purpose
                   AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $stmt->execute(['hash' => hash('sha256', $token), 'purpose' => $purpose]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->rollBack();
                return false;
            }
            $operation($row, $this->pdo);
            $this->pdo->prepare('UPDATE user_action_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => (int)$row['id']]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function invalidateForUser(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE user_action_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :id AND used_at IS NULL'
        )->execute(['id' => $userId]);
    }
}
