-- 0004_progress_social.sql — puzzles, attempts, friends, matchmaking queue,
-- WebRTC signaling relay (docs/API.md §3 signaling, §7, §8).

CREATE TABLE IF NOT EXISTS puzzles (
    id INT UNSIGNED NOT NULL,
    fen VARCHAR(128) NOT NULL,
    solution_moves TEXT NOT NULL,
    difficulty TINYINT UNSIGNED NOT NULL,
    rating INT NULL DEFAULT NULL,
    themes VARCHAR(255) NULL DEFAULT NULL,
    source VARCHAR(64) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_puzzles_difficulty (difficulty, rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puzzle_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    puzzle_id INT UNSIGNED NOT NULL,
    solved TINYINT(1) NOT NULL DEFAULT 0,
    moves INT UNSIGNED NULL DEFAULT NULL,
    ms INT UNSIGNED NULL DEFAULT NULL,
    xp_granted INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_puzzle_attempts_user_puzzle (user_id, puzzle_id),
    KEY idx_puzzle_attempts_user (user_id, id),
    CONSTRAINT fk_puzzle_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_puzzle_attempts_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friendships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id BIGINT UNSIGNED NOT NULL,
    addressee_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friendships_pair (requester_id, addressee_id),
    KEY idx_friendships_addressee_status (addressee_id, status),
    CONSTRAINT fk_friendships_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_friendships_addressee FOREIGN KEY (addressee_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_friendships_self CHECK (requester_id <> addressee_id),
    CONSTRAINT chk_friendships_status CHECK (status IN ('pending', 'accepted', 'declined', 'blocked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One active queue entry per user (docs/API.md §3 matchmaking).
CREATE TABLE IF NOT EXISTS matchmaking_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    rated TINYINT(1) NOT NULL DEFAULT 1,
    initial_time INT UNSIGNED NOT NULL,
    increment INT UNSIGNED NOT NULL,
    rating_at_queue INT UNSIGNED NOT NULL DEFAULT 1200,
    state VARCHAR(16) NOT NULL DEFAULT 'queued',
    match_id BIGINT UNSIGNED NULL DEFAULT NULL,
    queued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    matched_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_matchmaking_queue_user (user_id),
    KEY idx_matchmaking_lookup (state, mode, initial_time, increment, queued_at),
    KEY idx_matchmaking_match (match_id),
    CONSTRAINT fk_matchmaking_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_matchmaking_queue_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE SET NULL,
    CONSTRAINT chk_matchmaking_state CHECK (state IN ('queued', 'matched', 'cancelled', 'expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WebRTC signaling over HTTPS long-poll (docs/API.md §3).
CREATE TABLE IF NOT EXISTS signal_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    from_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    to_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    type VARCHAR(16) NOT NULL,
    payload TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_signal_messages_match_seq (match_id, seq),
    KEY idx_signal_messages_inbox (match_id, to_user_id, seq),
    CONSTRAINT fk_signal_messages_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_signal_messages_from FOREIGN KEY (from_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_signal_messages_to FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_signal_messages_type CHECK (type IN ('offer', 'answer', 'candidate', 'bye'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
