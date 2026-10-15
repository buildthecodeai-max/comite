-- Login rate-limiting table.
-- Tracks failed attempts by IP; rows older than 1 hour are cleaned up
-- probabilistically by RateLimiter::record().
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)     NOT NULL,
  identifier   VARCHAR(255)    NOT NULL DEFAULT '',
  attempted_at BIGINT          NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
