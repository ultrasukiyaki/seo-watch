CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'viewer',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_admins_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_tokens (
    provider VARCHAR(50) PRIMARY KEY,
    encrypted_token LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_properties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_url VARCHAR(2048) NOT NULL,
    site_hash BINARY(32) NOT NULL,
    permission_level VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_property_hash (site_hash),
    KEY idx_property_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_performance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    data_date DATE NOT NULL,
    query_text TEXT NOT NULL,
    query_hash BINARY(32) NOT NULL,
    page_url TEXT NOT NULL,
    page_hash BINARY(32) NOT NULL,
    normalized_page_url TEXT NULL,
    normalized_page_hash BINARY(32) NULL,
    country VARCHAR(8) NOT NULL DEFAULT '',
    device VARCHAR(16) NOT NULL DEFAULT '',
    search_type VARCHAR(20) NOT NULL DEFAULT 'web',
    clicks DOUBLE NOT NULL DEFAULT 0,
    impressions DOUBLE NOT NULL DEFAULT 0,
    ctr DOUBLE NOT NULL DEFAULT 0,
    position DOUBLE NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_performance_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    UNIQUE KEY uq_performance_row (property_id, data_date, query_hash, page_hash, country, device, search_type),
    KEY idx_perf_property_date (property_id, data_date),
    KEY idx_perf_query_hash (query_hash),
    KEY idx_perf_page_hash (page_hash),
    KEY idx_perf_normalized_page_hash (normalized_page_hash),
    KEY idx_perf_position (position),
    KEY idx_perf_impressions (impressions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    rows_imported BIGINT UNSIGNED NOT NULL DEFAULT 0,
    message TEXT NULL,
    CONSTRAINT fk_import_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    KEY idx_import_property_started (property_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
