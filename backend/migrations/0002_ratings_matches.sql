-- 0002_ratings_matches.sql — ratings, matches, players, moves, rating updates.
-- Schema for docs/API.md §3 + §5 + §6 (endpoints come in a later phase).

CREATE TABLE IF NOT EXISTS player_ratings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    rating INT NOT NULL DEFAULT 1200,
    highest_rating INT NOT NULL DEFAULT 1200,
    wins INT UNSIGNED NOT NULL DEFAULT 0,
    losses INT UNSIGNED NOT NULL DEFAULT 0,
    draws INT UNSIGNED NOT NULL DEFAULT 0,
    games INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_player_ratings_user_mode (user_id, mode),
    KEY idx_player_ratings_mode_rank (mode, rating, highest_rating, wins),
    CONSTRAINT fk_player_ratings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_player_ratings_mode CHECK (mode IN ('bullet', 'blitz', 'rapid', 'classical'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mode VARCHAR(16) NOT NULL,
    rated TINYINT(1) NOT NULL DEFAULT 1,
    initial_time INT UNSIGNED NOT NULL,
    increment INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'waiting',
    invite_code VARCHAR(8) NULL DEFAULT NULL,
    invite_expires_at DATETIME NULL DEFAULT NULL,
    start_fen VARCHAR(128) NOT NULL DEFAULT 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
    current_fen VARCHAR(128) NULL DEFAULT NULL,
    last_seq INT UNSIGNED NOT NULL DEFAULT 0,
    result_winner VARCHAR(8) NULL DEFAULT NULL,
    result_reason VARCHAR(32) NULL DEFAULT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    started_at DATETIME NULL DEFAULT NULL,
    finished_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_matches_invite_code (invite_code),
    KEY idx_matches_status_created (status, created_at),
    KEY idx_matches_created_by (created_by),
    CONSTRAINT fk_matches_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_matches_mode CHECK (mode IN ('bullet', 'blitz', 'rapid', 'classical')),
    CONSTRAINT chk_matches_status CHECK (status IN ('waiting', 'signaling', 'live', 'finished', 'aborted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account deletion orphans the match (user_id -> NULL) instead of deleting it,
-- per docs/API.md §1 DELETE /account.
CREATE TABLE IF NOT EXISTS match_players (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    color VARCHAR(8) NOT NULL,
    rating_at_start INT UNSIGNED NOT NULL DEFAULT 1200,
    result VARCHAR(8) NULL DEFAULT NULL,
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_match_players_match_color (match_id, color),
    KEY idx_match_players_user (user_id),
    CONSTRAINT fk_match_players_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_match_players_color CHECK (color IN ('white', 'black')),
    CONSTRAINT chk_match_players_result CHECK (result IS NULL OR result IN ('win', 'loss', 'draw'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS match_moves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    uci VARCHAR(10) NOT NULL,
    san VARCHAR(24) NOT NULL,
    fen VARCHAR(128) NOT NULL,
    white_ms INT UNSIGNED NULL DEFAULT NULL,
    black_ms INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_match_moves_match_seq (match_id, seq),
    CONSTRAINT fk_match_moves_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency marker for rating application: exactly one row per verified
-- match (docs/API.md §3 rule 6). The payload carries both players' deltas.
CREATE TABLE IF NOT EXISTS rating_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    delta_payload TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rating_updates_match (match_id),
    CONSTRAINT fk_rating_updates_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user audit trail (feeds /stats/summary history).
CREATE TABLE IF NOT EXISTS rating_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    rating INT NOT NULL,
    delta INT NOT NULL,
    match_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rating_history_user_mode (user_id, mode, id),
    KEY idx_rating_history_match (match_id),
    CONSTRAINT fk_rating_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_history_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
