<?php

declare(strict_types=1);

namespace CommitteeManager;

final class Http
{
    public static function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
        }

        return $data;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function action(): string
    {
        return (string) ($_GET['action'] ?? '');
    }

    /**
     * Returns a client-safe error string for an uncaught exception and logs
     * the real details server-side via error_log().
     *
     * Rules:
     *  - PHP engine errors (TypeError, ParseError, …) → generic message
     *  - PDOException or SQLSTATE in message → DB error message
     *  - Message contains a filesystem path → generic message
     *  - RuntimeException thrown intentionally by app code → pass through
     *  - Anything else → generic message
     */
    public static function sanitizeError(\Throwable $e): string
    {
        error_log('[CommitteeManager] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        if ($e instanceof \Error) {
            return 'Internal server error.';
        }

        if ($e instanceof \PDOException) {
            return 'A database error occurred.';
        }

        $msg = $e->getMessage();

        if (stripos($msg, 'SQLSTATE') !== false) {
            return 'A database error occurred.';
        }

        if (stripos($msg, 'Database connection failed') !== false) {
            return 'Database connection failed. Check server configuration.';
        }

        if (preg_match('#[/\\\\](var|home|usr|tmp|www|public_html|htdocs)[/\\\\]#i', $msg)) {
            return 'Internal server error.';
        }

        if ($e instanceof \RuntimeException) {
            return $msg;
        }

        return 'Internal server error.';
    }
}
