<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class ImportLockService
{
    public function __construct(private readonly PDO $pdo, private readonly int $leaseSeconds = 300)
    {
    }

    public function acquire(int $propertyId, string $source): string
    {
        if ($propertyId < 1 || !in_array($source, ['web', 'cli', 'cron'], true)) {
            throw new RuntimeException('同期ロックの指定が不正です。');
        }
        $owner = bin2hex(random_bytes(32));
        $ownerHash = hash('sha256', $owner);
        $lease = max(30, min(3600, $this->leaseSeconds));
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM import_locks WHERE property_id = :id AND expires_at < UTC_TIMESTAMP()');
            $delete->execute(['id' => $propertyId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO import_locks (property_id, owner_hash, source, acquired_at, heartbeat_at, expires_at)
                 VALUES (:id, :owner, :source, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND))'
            );
            $insert->execute(['id' => $propertyId, 'owner' => $ownerHash, 'source' => $source]);
            $this->pdo->commit();
            return $owner;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('データ取得はすでに実行中です。', 409, $e);
            }
            throw $e;
        }
    }

    public function heartbeat(int $propertyId, string $owner): void
    {
        $lease = max(30, min(3600, $this->leaseSeconds));
        $stmt = $this->pdo->prepare(
            'UPDATE import_locks SET heartbeat_at = UTC_TIMESTAMP(),
             expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lease . ' SECOND)
             WHERE property_id = :id AND owner_hash = :owner'
        );
        $stmt->execute(['id' => $propertyId, 'owner' => hash('sha256', $owner)]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('同期ロックの所有権を確認できません。');
        }
    }

    public function release(int $propertyId, string $owner): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM import_locks WHERE property_id = :id AND owner_hash = :owner');
        $stmt->execute(['id' => $propertyId, 'owner' => hash('sha256', $owner)]);
        return $stmt->rowCount() === 1;
    }

    public function purgeStale(): int
    {
        return $this->pdo->exec('DELETE FROM import_locks WHERE expires_at < UTC_TIMESTAMP()');
    }
}
