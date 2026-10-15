<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MS    = 15 * 60 * 1000; // block window: 15 minutes
    private const CLEANUP_MS   = 60 * 60 * 1000; // delete rows older than 1 hour

    /**
     * Check rate limit for $ip. Calls Http::json() with 429 and exits if over
     * the limit. Fails open (does nothing) if the DB is unavailable.
     */
    public static function check(string $ip, string $identifier = ''): void
    {
        try {
            $pdo  = Database::connection();
            self::ensureTable($pdo);
            $now  = self::now();
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?'
            );
            $stmt->execute([$ip, $now - self::WINDOW_MS]);
            if ((int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS) {
                Http::json([
                    'ok'    => false,
                    'error' => 'Too many failed login attempts. Please try again in 15 minutes.',
                ], 429);
            }
        } catch (\PDOException $e) {
            error_log('[CommitteeManager] RateLimiter::check error: ' . $e->getMessage());
        }
    }

    /**
     * Record a failed login attempt for $ip.
     */
    public static function record(string $ip, string $identifier = ''): void
    {
        try {
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $now = self::now();
            $pdo->prepare(
                'INSERT INTO login_attempts (ip, identifier, attempted_at) VALUES (?, ?, ?)'
            )->execute([$ip, $identifier, $now]);
            // Probabilistic cleanup to keep the table small
            if (random_int(1, 10) === 1) {
                $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
                    ->execute([$now - self::CLEANUP_MS]);
            }
        } catch (\PDOException $e) {
            error_log('[CommitteeManager] RateLimiter::record error: ' . $e->getMessage());
        }
    }

    /**
     * Clear all attempts for $ip on successful login.
     */
    public static function clear(string $ip): void
    {
        try {
            Database::connection()
                ->prepare('DELETE FROM login_attempts WHERE ip = ?')
                ->execute([$ip]);
        } catch (\PDOException $e) {
            error_log('[CommitteeManager] RateLimiter::clear error: ' . $e->getMessage());
        }
    }

    /**
     * Return the best available client IP. Prefers REMOTE_ADDR; falls back to
     * the first entry in X-Forwarded-For (e.g. behind Cloudflare or a proxy).
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '' || $ip === '127.0.0.1') {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }
        return $ip;
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip           VARCHAR(45)     NOT NULL,
                identifier   VARCHAR(255)    NOT NULL DEFAULT '',
                attempted_at BIGINT          NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_la_ip_time (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
