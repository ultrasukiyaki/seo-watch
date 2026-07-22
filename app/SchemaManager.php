<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class SchemaManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->migrateUsers();

        $this->ensureColumn(
            'search_performance',
            'normalized_page_url',
            'ALTER TABLE search_performance ADD COLUMN normalized_page_url TEXT NULL AFTER page_hash'
        );
        $this->ensureColumn(
            'search_performance',
            'normalized_page_hash',
            'ALTER TABLE search_performance ADD COLUMN normalized_page_hash BINARY(32) NULL AFTER normalized_page_url'
        );
        $this->ensureIndex(
            'search_performance',
            'idx_perf_normalized_page_hash',
            'ALTER TABLE search_performance ADD KEY idx_perf_normalized_page_hash (normalized_page_hash)'
        );

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS page_metadata (
    property_id BIGINT UNSIGNED NOT NULL,
    normalized_page_hash BINARY(32) NOT NULL,
    normalized_page_url TEXT NOT NULL,
    page_title TEXT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'wordpress',
    fetch_status VARCHAR(20) NOT NULL DEFAULT 'success',
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    page_modified_at DATETIME NULL,
    headings_json LONGTEXT NULL,
    content_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    content_fetched_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (property_id, normalized_page_hash),
    CONSTRAINT fk_page_metadata_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    KEY idx_page_metadata_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->ensureColumn(
            'page_metadata',
            'page_modified_at',
            'ALTER TABLE page_metadata ADD COLUMN page_modified_at DATETIME NULL AFTER fetched_at'
        );
        $this->ensureColumn(
            'page_metadata',
            'headings_json',
            'ALTER TABLE page_metadata ADD COLUMN headings_json LONGTEXT NULL AFTER page_modified_at'
        );
        $this->ensureColumn(
            'page_metadata',
            'content_status',
            "ALTER TABLE page_metadata ADD COLUMN content_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER headings_json"
        );
        $this->ensureColumn(
            'page_metadata',
            'content_fetched_at',
            'ALTER TABLE page_metadata ADD COLUMN content_fetched_at DATETIME NULL AFTER content_status'
        );
    }


    private function migrateUsers(): void
    {
        $this->ensureColumn(
            'admins',
            'role',
            "ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'viewer' AFTER password_hash"
        );
        $this->ensureColumn(
            'admins',
            'last_login_at',
            'ALTER TABLE admins ADD COLUMN last_login_at DATETIME NULL AFTER role'
        );
        $this->ensureIndex(
            'admins',
            'idx_admins_role',
            'ALTER TABLE admins ADD KEY idx_admins_role (role)'
        );

        $this->pdo->exec("UPDATE admins SET role = 'viewer' WHERE role NOT IN ('superuser', 'viewer')");

        $superuserId = $this->pdo->query(
            "SELECT MIN(id) FROM admins WHERE role = 'superuser'"
        )->fetchColumn();
        if ($superuserId === false || $superuserId === null) {
            $superuserId = $this->pdo->query('SELECT MIN(id) FROM admins')->fetchColumn();
            if ($superuserId !== false && $superuserId !== null) {
                $stmt = $this->pdo->prepare("UPDATE admins SET role = 'superuser' WHERE id = :id");
                $stmt->execute(['id' => (int)$superuserId]);
            }
        }

        if ($superuserId !== false && $superuserId !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE admins SET role = 'viewer' WHERE role = 'superuser' AND id <> :id"
            );
            $stmt->execute(['id' => (int)$superuserId]);
        }
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->pdo->quote($column));
        if (!$stmt->fetch()) {
            $this->pdo->exec($alterSql);
        }
    }

    private function ensureIndex(string $table, string $index, string $alterSql): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $this->pdo->quote($index));
        if (!$stmt->fetch()) {
            $this->pdo->exec($alterSql);
        }
    }
}
