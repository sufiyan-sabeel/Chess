-- 0001_core_auth.sql — accounts, sessions (refresh families), token tables, rate limits.
-- All timestamps are written explicitly in UTC by the application (Support\Clock).

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(20) NOT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    avatar_seed VARCHAR(64) NOT NULL,
    country CHAR(2) NULL DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_display_name (display_name),
    CONSTRAINT chk_users_status CHECK (status IN ('active', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refresh-token sessions. One row per issued refresh token; rotation inserts
-- a new row into the same family and marks the old row used_at. Reuse of a
-- used row revokes the whole family (see TokenService::rotate).
CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    family_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    access_token_hash CHAR(64) NOT NULL,
    access_expires_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    revoked_reason VARCHAR(32) NULL DEFAULT NULL,
    created_ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_token_hash (token_hash),
    UNIQUE KEY uq_sessions_access_hash (access_token_hash),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_family (family_id),
    KEY idx_sessions_access_lookup (access_token_hash, access_expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    KEY idx_email_verification_user (user_id),
    KEY idx_email_verification_expiry (expires_at),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_user (user_id),
    KEY idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiting (per IP + route), see Services\RateLimiter.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(160) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
