<?php

declare(strict_types=1);

/**
 * Reset admin password to admin123.
 * Usage (SSH / cPanel Terminal):
 *   php scripts/reset_admin.php
 *   php scripts/reset_admin.php myNewPassword
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Database;

$newPassword = $argv[1] ?? 'admin123';
if (strlen($newPassword) < 4) {
    fwrite(STDERR, "Password must be at least 4 characters.\n");
    exit(1);
}

try {
    $pdo = Database::connection();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'UPDATE settings SET admin_pass_hash = :hash, admin_user = COALESCE(NULLIF(admin_user, ""), "admin"), updated_at = :updated_at WHERE id = 1'
    );
    $stmt->execute([
        'hash' => $hash,
        'updated_at' => (int) (microtime(true) * 1000),
    ]);

    if ($stmt->rowCount() === 0) {
        fwrite(STDERR, "No settings row found. Import sql/committee_manager.sql first.\n");
        exit(1);
    }

    $user = $pdo->query('SELECT admin_user FROM settings WHERE id = 1')->fetchColumn();
    echo "OK — admin password reset.\n";
    echo "Username: {$user}\n";
    echo "Password: {$newPassword}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
