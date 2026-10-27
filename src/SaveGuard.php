<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

/**
 * Safety net for admin writes: snapshots the data tables before every write
 * and refuses saves that would wipe most of the existing records.
 */
final class SaveGuard
{
    private const KEEP = 150;

    public static function snapshot(array $user, string $action, array $body): void
    {
        $dir = dirname(__DIR__) . '/tmp/save-backups';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $pdo = Database::connection();
        $dump = [];
        foreach (['settings', 'members', 'payments', 'winners'] as $table) {
            try {
                $dump[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $dump[$table] = [];
            }
        }
        $json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return;
        }
        $hash = substr(md5($json), 0, 8);

        $existing = glob($dir . '/*.json') ?: [];
        sort($existing);
        $last = $existing ? basename((string) end($existing)) : '';
        if ($last === '' || strpos($last, $hash) === false) {
            $file = sprintf('%s/%s-%s.json', $dir, date('Ymd-His'), $hash);
            if (@file_put_contents($file, $json, LOCK_EX) !== false) {
                @chmod($file, 0600);
                $existing[] = $file;
            }
        }
        while (count($existing) > self::KEEP) {
            @unlink((string) array_shift($existing));
        }

        $members = is_array($body['members'] ?? null) ? count($body['members']) : null;
        $winners = is_array($body['winners'] ?? null) ? count($body['winners']) : null;
        $line = json_encode([
            'at' => date('c'),
            'user' => $user['username'] ?? ($user['email'] ?? ($user['role'] ?? '')),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'action' => $action,
            'incomingMembers' => $members,
            'incomingWinners' => $winners,
            'snapshot' => $hash,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/save-log.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Reject a full-state save that would delete most existing data. */
    public static function assertSafe(array $body): void
    {
        if (!empty($body['confirmOverwrite'])) {
            return;
        }
        try {
            $pdo = Database::connection();
            $scope = (int) ($body['committeeId'] ?? 0);
            $winnersExisting = self::count($pdo, 'winners', true, $scope);
            $membersExisting = self::count($pdo, 'members', false, $scope);
            $paymentsExisting = self::count($pdo, 'payments', false, $scope);
        } catch (\Throwable $e) {
            return;
        }

        $problems = [];

        if (array_key_exists('winners', $body) && is_array($body['winners'])) {
            $kept = 0;
            foreach ($body['winners'] as $w) {
                if (is_array($w) && empty($w['archivedAt']) && empty($w['deletedAt']) && ($w['status'] ?? '') !== 'archived') {
                    $kept++;
                }
            }
            if ($winnersExisting - $kept > 1) {
                $problems[] = "winners ({$winnersExisting} stored, {$kept} in this save)";
            }
        }

        $incomingMembers = (is_array($body['members'] ?? null) ? count($body['members']) : 0)
            + (is_array($body['trashedMembers'] ?? null) ? count($body['trashedMembers']) : 0);
        if ($membersExisting >= 4 && $incomingMembers < $membersExisting / 2) {
            $problems[] = "members ({$membersExisting} stored, {$incomingMembers} in this save)";
        }

        $incomingPayments = 0;
        foreach (array_merge(
            is_array($body['members'] ?? null) ? $body['members'] : [],
            is_array($body['trashedMembers'] ?? null) ? $body['trashedMembers'] : []
        ) as $m) {
            if (is_array($m) && is_array($m['payments'] ?? null)) {
                $incomingPayments += count(array_filter($m['payments']));
            }
        }
        if ($paymentsExisting >= 10 && $incomingPayments === 0) {
            $problems[] = "payments ({$paymentsExisting} stored, 0 in this save)";
        }

        if ($problems) {
            Http::json([
                'ok' => false,
                'error' => 'Save blocked to protect your data — it would delete: ' . implode('; ', $problems)
                    . '. Reload the page and try again.',
            ], 409);
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function count(PDO $pdo, string $table, bool $skipDeleted, int $committeeId): int
    {
        $conds = [];
        if ($skipDeleted && self::hasColumn($pdo, $table, 'deleted_at')) {
            $conds[] = 'deleted_at IS NULL';
        }
        if ($committeeId > 0 && self::hasColumn($pdo, $table, 'committee_id')) {
            $conds[] = 'committee_id = ' . $committeeId;
        }
        $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

        return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`{$where}")->fetchColumn();
    }
}
