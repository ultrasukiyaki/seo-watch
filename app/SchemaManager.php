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
        $this->migrateAuthentication();

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
        $columns = [
            ['email', 'ALTER TABLE admins ADD COLUMN email VARCHAR(254) NULL AFTER role'],
            ['pending_email', 'ALTER TABLE admins ADD COLUMN pending_email VARCHAR(254) NULL AFTER email'],
            ['email_verified_at', 'ALTER TABLE admins ADD COLUMN email_verified_at DATETIME NULL AFTER pending_email'],
            ['password_changed_at', 'ALTER TABLE admins ADD COLUMN password_changed_at DATETIME NULL AFTER last_login_at'],
            ['session_version', 'ALTER TABLE admins ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_changed_at'],
            ['account_status', "ALTER TABLE admins ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER session_version"],
            ['invited_at', 'ALTER TABLE admins ADD COLUMN invited_at DATETIME NULL AFTER account_status'],
        ];
        foreach ($columns as [$column, $sql]) {
            $this->ensureColumn('admins', $column, $sql);
        }
        $this->ensureIndex(
            'admins',
            'idx_admins_role',
            'ALTER TABLE admins ADD KEY idx_admins_role (role)'
        );
        $this->ensureIndex('admins', 'uq_admins_email', 'ALTER TABLE admins ADD UNIQUE KEY uq_admins_email (email)');
        $this->ensureIndex('admins', 'uq_admins_pending_email', 'ALTER TABLE admins ADD UNIQUE KEY uq_admins_pending_email (pending_email)');
        $this->ensureIndex('admins', 'idx_admins_status', 'ALTER TABLE admins ADD KEY idx_admins_status (account_status)');

        $this->pdo->exec("UPDATE admins SET role = 'viewer' WHERE role NOT IN ('superuser', 'viewer')");
        $this->pdo->exec("UPDATE admins SET account_status = 'active' WHERE account_status NOT IN ('active', 'disabled', 'invited')");

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

    private function migrateAuthentication(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_action_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pending_value VARCHAR(254) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_user_action_token_hash (token_hash),
    KEY idx_user_action_lookup (user_id, purpose, used_at),
    KEY idx_user_action_expiry (expires_at),
    CONSTRAINT fk_user_action_user FOREIGN KEY (user_id) REFERENCES admins(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_action_actor FOREIGN KEY (created_by_user_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_name VARCHAR(64) NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auth_rate_key (action_name, key_hash),
    KEY idx_auth_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS authentication_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    outcome VARCHAR(20) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    pseudonymized_ip CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auth_audit_created (created_at),
    KEY idx_auth_audit_event (event_type, outcome),
    CONSTRAINT fk_auth_audit_actor FOREIGN KEY (actor_user_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_auth_audit_subject FOREIGN KEY (subject_user_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
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
