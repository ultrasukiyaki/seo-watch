<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class AlertLockService
{
    public function __construct(private readonly PDO $pdo, private readonly int $leaseSeconds = 600)
    {
    }

    public function acquire(int $propertyId, string $source): string
    {
        if ($propertyId < 1 || !in_array($source, ['web', 'cli', 'cron'], true)) {
            throw new RuntimeException('検知ロックの指定が不正です。');
        }
        $owner = bin2hex(random_bytes(32));
        $ownerHash = hash('sha256', $owner);
        $lease = max(30, min(3600, $this->leaseSeconds));
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM alert_locks WHERE property_id = :property_id AND expires_at < UTC_TIMESTAMP()');
            $delete->execute(['property_id' => $propertyId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO alert_locks (property_id, owner_hash, source, acquired_at, heartbeat_at, expires_at)
                 VALUES (:property_id, :owner_hash, :source, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND))'
            );
            $insert->execute(['property_id' => $propertyId, 'owner_hash' => $ownerHash, 'source' => $source]);
            $this->pdo->commit();
            return $owner;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('変動検知はすでに実行中です。', 409, $e);
            }
            throw $e;
        }
    }

    public function release(int $propertyId, string $owner): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM alert_locks WHERE property_id = :property_id AND owner_hash = :owner_hash');
        $stmt->execute(['property_id' => $propertyId, 'owner_hash' => hash('sha256', $owner)]);
        return $stmt->rowCount() === 1;
    }
}
