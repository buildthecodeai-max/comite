<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Database;

$pdo = Database::connection();

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExists($pdo, 'settings', 'admin_email')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN admin_email VARCHAR(255) NOT NULL DEFAULT '' AFTER admin_pass_hash");
    echo "Added settings.admin_email\n";
}

if (!columnExists($pdo, 'members', 'deleted_at')) {
    $pdo->exec('ALTER TABLE members ADD COLUMN deleted_at BIGINT NULL DEFAULT NULL');
    echo "Added members.deleted_at\n";
}

if (!columnExists($pdo, 'members', 'deleted_by')) {
    $pdo->exec('ALTER TABLE members ADD COLUMN deleted_by VARCHAR(255) NULL DEFAULT NULL');
    echo "Added members.deleted_by\n";
}

if (!columnExists($pdo, 'settings', 'committee_rules')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN committee_rules TEXT NULL DEFAULT NULL AFTER updated_at');
    echo "Added settings.committee_rules\n";
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at BIGINT NOT NULL,
        created_at BIGINT NOT NULL
    )'
);
echo "Ensured password_reset_tokens table exists\n";
