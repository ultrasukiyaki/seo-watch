<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class DataMaintenance
{
    public function __construct(private readonly PDO $pdo, private readonly UrlNormalizer $normalizer)
    {
    }

    public function normalizeExisting(int $propertyId, int $batchSize = 1000): int
    {
        $batchSize = max(100, min(5000, $batchSize));
        $lastId = 0;
        $updated = 0;

        $select = $this->pdo->prepare(
            'SELECT id, page_url
             FROM search_performance
             WHERE property_id = :property_id
               AND id > :last_id
               AND (normalized_page_url IS NULL OR normalized_page_hash IS NULL)
             ORDER BY id ASC
             LIMIT ' . $batchSize
        );
        $update = $this->pdo->prepare(
            'UPDATE search_performance
             SET normalized_page_url = :normalized_page_url,
                 normalized_page_hash = :normalized_page_hash
             WHERE id = :id'
        );

        while (true) {
            $select->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
            $select->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();
            if (!$rows) {
                break;
            }

            $this->pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $lastId = (int)$row['id'];
                    $normalized = $this->normalizer->normalize((string)$row['page_url']);
                    $update->bindValue(':normalized_page_url', $normalized);
                    $update->bindValue(':normalized_page_hash', hash('sha256', $normalized, true), PDO::PARAM_LOB);
                    $update->bindValue(':id', $lastId, PDO::PARAM_INT);
                    $update->execute();
                    $updated++;
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        return $updated;
    }
}
