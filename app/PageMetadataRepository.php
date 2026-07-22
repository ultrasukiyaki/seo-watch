<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class PageMetadataRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $urls @return array<string,array<string,mixed>> */
    public function findMany(int $propertyId, array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn(string $url): bool => $url !== '')));
        if ($urls === []) {
            return [];
        }

        $placeholders = [];
        $hashes = [];
        foreach ($urls as $i => $url) {
            $placeholder = ':hash' . $i;
            $placeholders[] = $placeholder;
            $hashes[$placeholder] = hash('sha256', $url, true);
        }

        $stmt = $this->pdo->prepare(
            'SELECT normalized_page_url, page_title, source, fetch_status, fetched_at,
                    page_modified_at, headings_json, content_status, content_fetched_at
             FROM page_metadata
             WHERE property_id = :property_id
               AND normalized_page_hash IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
        foreach ($hashes as $placeholder => $hash) {
            $stmt->bindValue($placeholder, $hash, PDO::PARAM_LOB);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string)$row['normalized_page_url']] = $row;
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function findOne(int $propertyId, string $url): ?array
    {
        $rows = $this->findMany($propertyId, [$url]);
        return $rows[$url] ?? null;
    }

    public function put(
        int $propertyId,
        string $url,
        ?string $title,
        string $status = 'success',
        string $source = 'wordpress'
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO page_metadata
                (property_id, normalized_page_hash, normalized_page_url, page_title, source, fetch_status, fetched_at)
             VALUES
                (:property_id, :page_hash, :page_url, :page_title, :source, :fetch_status, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                normalized_page_url = VALUES(normalized_page_url),
                page_title = VALUES(page_title),
                source = VALUES(source),
                fetch_status = VALUES(fetch_status),
                fetched_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':page_hash', hash('sha256', $url, true), PDO::PARAM_LOB);
        $stmt->bindValue(':page_url', $url);
        $stmt->bindValue(':page_title', $title);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':fetch_status', $status);
        $stmt->execute();
    }

    /** @param list<array{level:int,text:string}> $headings */
    public function putInspection(
        int $propertyId,
        string $url,
        ?string $title,
        ?string $modifiedAt,
        array $headings,
        string $status = 'success'
    ): void {
        $headingsJson = json_encode($headings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($headingsJson === false) {
            $headingsJson = '[]';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO page_metadata
                (property_id, normalized_page_hash, normalized_page_url, page_title, source, fetch_status, fetched_at,
                 page_modified_at, headings_json, content_status, content_fetched_at)
             VALUES
                (:property_id, :page_hash, :page_url, :page_title, :source, :fetch_status, CURRENT_TIMESTAMP,
                 :page_modified_at, :headings_json, :content_status, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                normalized_page_url = VALUES(normalized_page_url),
                page_title = COALESCE(VALUES(page_title), page_title),
                source = VALUES(source),
                fetch_status = CASE WHEN VALUES(page_title) IS NOT NULL THEN VALUES(fetch_status) ELSE fetch_status END,
                fetched_at = CASE WHEN VALUES(page_title) IS NOT NULL THEN CURRENT_TIMESTAMP ELSE fetched_at END,
                page_modified_at = VALUES(page_modified_at),
                headings_json = VALUES(headings_json),
                content_status = VALUES(content_status),
                content_fetched_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':page_hash', hash('sha256', $url, true), PDO::PARAM_LOB);
        $stmt->bindValue(':page_url', $url);
        $stmt->bindValue(':page_title', $title);
        $stmt->bindValue(':source', 'wordpress');
        $stmt->bindValue(':fetch_status', $title !== null && $title !== '' ? 'success' : 'not_found');
        $stmt->bindValue(':page_modified_at', $modifiedAt);
        $stmt->bindValue(':headings_json', $headingsJson);
        $stmt->bindValue(':content_status', $status);
        $stmt->execute();
    }
}
