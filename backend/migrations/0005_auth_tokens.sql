-- 0005: opaque access+refresh token store (docs/API.md §1) for the HTTP kernel
-- in src/Http + services in src/Auth.
--
-- WHY A SEPARATE TABLE: a concurrent agent had already shipped
-- 0001_core_auth.sql with a `sessions` table implementing the same contract.
-- Rather than fight over table shape mid-flight, this store coexists: every
-- token is random 43-char base64url, only its SHA-256 hash is stored here,
-- refresh rotation marks the row used and inserts a new row in the same
-- `family_id`; reuse of a used row revokes the family (theft detection).
-- Access TTL 15 min / refresh TTL 30 days are enforced by the application.

CREATE TABLE IF NOT EXISTS auth_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    family_id      CHAR(36)        NOT NULL,
    kind           ENUM('access', 'refresh') NOT NULL,
    token_hash     CHAR(64)        NOT NULL,
    parent_id      BIGINT UNSIGNED NULL DEFAULT NULL,
    used_at        DATETIME        NULL DEFAULT NULL,
    revoked_at     DATETIME        NULL DEFAULT NULL,
    revoked_reason VARCHAR(32)     NULL DEFAULT NULL,
    expires_at     DATETIME        NOT NULL,
    created_at     DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user_kind (user_id, kind),
    KEY idx_auth_tokens_family (family_id),
    KEY idx_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
