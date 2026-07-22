<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class PropertyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sync(array $sites): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_properties (site_url, site_hash, permission_level)
             VALUES (:site_url, :site_hash, :permission_level)
             ON DUPLICATE KEY UPDATE site_url = VALUES(site_url), permission_level = VALUES(permission_level), updated_at = CURRENT_TIMESTAMP'
        );
        $count = 0;
        foreach ($sites as $site) {
            $url = (string)($site['siteUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            $stmt->bindValue(':site_url', $url);
            $stmt->bindValue(':site_hash', hash('sha256', $url, true), PDO::PARAM_LOB);
            $stmt->bindValue(':permission_level', (string)($site['permissionLevel'] ?? ''));
            $stmt->execute();
            $count++;
        }
        return $count;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM search_properties ORDER BY is_active DESC, site_url ASC')->fetchAll();
    }

    public function active(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM search_properties WHERE is_active = 1 LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function activate(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE search_properties SET is_active = 0');
            $stmt = $this->pdo->prepare('UPDATE search_properties SET is_active = 1 WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('指定されたプロパティが見つかりません。');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
