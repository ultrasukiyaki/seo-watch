CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'viewer',
    email VARCHAR(254) NULL,
    pending_email VARCHAR(254) NULL,
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    password_changed_at DATETIME NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    account_status VARCHAR(20) NOT NULL DEFAULT 'active',
    invited_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email),
    UNIQUE KEY uq_admins_pending_email (pending_email),
    KEY idx_admins_role (role),
    KEY idx_admins_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_name VARCHAR(64) NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auth_rate_key (action_name, key_hash),
    KEY idx_auth_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(190) NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME NULL,
    applied_at DATETIME NULL,
    status VARCHAR(20) NOT NULL,
    error_summary VARCHAR(500) NULL,
    app_version VARCHAR(32) NOT NULL
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
    source VARCHAR(16) NOT NULL DEFAULT 'web',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    rows_imported BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rows_fetched BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error_category VARCHAR(20) NULL,
    correlation_id CHAR(32) NULL,
    user_id BIGINT UNSIGNED NULL,
    message TEXT NULL,
    CONSTRAINT fk_import_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    KEY idx_import_property_started (property_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS improvement_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    normalized_page_hash BINARY(32) NOT NULL,
    normalized_page_url TEXT NOT NULL,
    task_type VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    source_query TEXT NULL,
    source_score DECIMAL(12,2) NULL,
    suggestion_hash BINARY(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    note TEXT NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    revision_date DATE NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_improvement_suggestion (property_id, normalized_page_hash, task_type, suggestion_hash),
    KEY idx_improvement_property_status (property_id, status, updated_at),
    KEY idx_improvement_assignee (assigned_user_id),
    CONSTRAINT fk_improvement_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    CONSTRAINT fk_improvement_assignee FOREIGN KEY (assigned_user_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_improvement_creator FOREIGN KEY (created_by_user_id) REFERENCES admins(id) ON DELETE RESTRICT,
    CONSTRAINT fk_improvement_updater FOREIGN KEY (updated_by_user_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS improvement_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_improvement_history_task (task_id, created_at),
    CONSTRAINT fk_improvement_history_task FOREIGN KEY (task_id) REFERENCES improvement_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_improvement_history_actor FOREIGN KEY (actor_user_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_locks (
    property_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    owner_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(16) NOT NULL,
    acquired_at DATETIME NOT NULL,
    heartbeat_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_import_lock_property FOREIGN KEY (property_id) REFERENCES search_properties(id) ON DELETE CASCADE,
    KEY idx_import_lock_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
