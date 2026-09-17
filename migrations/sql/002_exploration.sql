-- Additive world saves. Existing accounts, collections and inventories are retained.
START TRANSACTION;
CREATE TABLE IF NOT EXISTS exploration_saves (
  user_id INT NOT NULL PRIMARY KEY,
  map_id VARCHAR(32) NOT NULL DEFAULT 'laboratory',
  x SMALLINT NOT NULL DEFAULT 7,
  y SMALLINT NOT NULL DEFAULT 8,
  facing VARCHAR(5) NOT NULL DEFAULT 'down',
  revision INT UNSIGNED NOT NULL DEFAULT 0,
  state LONGTEXT NOT NULL CHECK (JSON_VALID(state)),
  last_seen DATETIME(3) NULL,
  moved_at DOUBLE NOT NULL DEFAULT 0,
  INDEX presence (map_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
COMMIT;
