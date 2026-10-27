<?php
// ONE-TIME fix — ALTER users table to add missing columns. DELETE after use.
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
use CommitteeManager\Auth;
use CommitteeManager\Database;

Auth::requireAdmin();

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$results = [];
function runSql(PDO $pdo, string $label, string $sql): void {
    global $results;
    try { $pdo->exec($sql); $results[] = "OK  $label"; }
    catch (Throwable $e) { $results[] = "ERR $label: " . $e->getMessage(); }
}

runSql($pdo, 'ALTER users role ENUM add app_user',
    "ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','owner','app_user') NOT NULL DEFAULT 'owner'");

runSql($pdo, 'ALTER users add mobile',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS mobile VARCHAR(50) NOT NULL DEFAULT '' AFTER email");

runSql($pdo, 'ALTER users add subscription_plan',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS subscription_plan VARCHAR(32) NOT NULL DEFAULT 'free' AFTER role");

runSql($pdo, 'ALTER users add status',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(24) NOT NULL DEFAULT 'active' AFTER subscription_plan");

runSql($pdo, 'ALTER users add google_id',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL DEFAULT NULL AFTER status");

runSql($pdo, 'ALTER users add facebook_id',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS facebook_id VARCHAR(255) NULL DEFAULT NULL AFTER google_id");

$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n\nColumns now in users: " . implode(', ', $cols) . "\n\nDONE. Delete api/migrate_users_v2.php!\n";
