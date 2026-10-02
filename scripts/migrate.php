<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Database;

$pdo = Database::connection();

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
    );
    $stmt->execute(['table' => $table, 'index_name' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function foreignKeyExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table
           AND CONSTRAINT_NAME = :constraint_name AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute(['table' => $table, 'constraint_name' => $constraint]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (columnExists($pdo, $table, $column)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "Added {$table}.{$column}\n";
}

function ensureIndex(PDO $pdo, string $table, string $index, string $definition): void
{
    if (indexExists($pdo, $table, $index)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    echo "Added index {$table}.{$index}\n";
}

function ensureForeignKey(PDO $pdo, string $table, string $constraint, string $definition): void
{
    if (foreignKeyExists($pdo, $table, $constraint)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    echo "Added foreign key {$constraint}\n";
}

function dropCascadingWinnerMemberKeys(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT DISTINCT rc.CONSTRAINT_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
          AND kcu.TABLE_NAME = rc.TABLE_NAME
          AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.TABLE_NAME = 'winners'
           AND rc.REFERENCED_TABLE_NAME = 'members'
           AND rc.DELETE_RULE = 'CASCADE'
           AND kcu.COLUMN_NAME = 'member_id'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $constraint)) {
            throw new RuntimeException('Unsafe foreign-key name returned by database.');
        }
        $pdo->exec("ALTER TABLE winners DROP FOREIGN KEY `{$constraint}`");
        echo "Replaced cascading winner foreign key {$constraint}\n";
    }
}

function jsonObject(mixed $value): array
{
    return is_array($value) ? $value : [];
}

// Existing incremental upgrades.
ensureColumn($pdo, 'settings', 'admin_email', "VARCHAR(255) NOT NULL DEFAULT '' AFTER admin_pass_hash");
ensureColumn($pdo, 'members', 'deleted_at', 'BIGINT NULL DEFAULT NULL');
ensureColumn($pdo, 'members', 'deleted_by', 'VARCHAR(255) NULL DEFAULT NULL');
ensureColumn($pdo, 'settings', 'committee_rules', 'TEXT NULL DEFAULT NULL AFTER updated_at');

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at BIGINT NOT NULL,
        created_at BIGINT NOT NULL,
        INDEX idx_password_reset_email (email, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

echo "Ensured password_reset_tokens table exists\n";

// Enterprise multi-committee tables. DDL is additive; the legacy settings row is
// retained as the global authentication source and compatibility mirror.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS committees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        logo VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'running',
        start_date DATE NULL,
        end_date DATE NULL,
        committee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        installment_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        draw_frequency VARCHAR(32) NOT NULL DEFAULT 'monthly',
        notes TEXT NULL,
        version BIGINT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        archived_at BIGINT NULL DEFAULT NULL,
        INDEX idx_committees_status (status),
        INDEX idx_committees_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS committee_settings (
        committee_id INT PRIMARY KEY,
        subtitle VARCHAR(255) NOT NULL DEFAULT 'Committee Management System',
        total_months INT NOT NULL DEFAULT 25,
        current_month INT NOT NULL DEFAULT 1,
        prize_per_share DECIMAL(15,2) NOT NULL DEFAULT 0,
        draw_method VARCHAR(32) NOT NULL DEFAULT 'manual',
        installment_frequency VARCHAR(32) NOT NULL DEFAULT 'monthly',
        winner_rules LONGTEXT NULL,
        penalty_rules LONGTEXT NULL,
        payment_grace_period INT NOT NULL DEFAULT 0,
        notification_settings LONGTEXT NULL,
        certificate_template LONGTEXT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'PKR',
        theme_color VARCHAR(20) NOT NULL DEFAULT '#6366F1',
        updated_at BIGINT NOT NULL DEFAULT 0,
        CONSTRAINT fk_committee_settings_committee
          FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

ensureColumn($pdo, 'settings', 'default_committee_id', 'INT NULL DEFAULT NULL AFTER committee_rules');

$legacySettings = $pdo->query('SELECT * FROM settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$legacySettings) {
    throw new RuntimeException('Settings row missing. Seed or restore the existing application before migrating.');
}

$defaultCommitteeId = (int) ($legacySettings['default_committee_id'] ?? 0);
if ($defaultCommitteeId <= 0) {
    $existingCommitteeId = (int) ($pdo->query('SELECT id FROM committees ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($existingCommitteeId > 0) {
        $defaultCommitteeId = $existingCommitteeId;
    } else {
        $startDate = null;
        $startMonth = (string) ($legacySettings['start_month'] ?? '');
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $startMonth)) {
            $startDate = $startMonth . '-01';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO committees (
                name, description, status, start_date, committee_amount,
                installment_amount, draw_frequency, version, created_at, updated_at
             ) VALUES (
                :name, :description, :status, :start_date, :committee_amount,
                :installment_amount, :draw_frequency, 1, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'name' => (string) $legacySettings['committee_name'],
            'description' => (string) $legacySettings['committee_subtitle'],
            'status' => 'running',
            'start_date' => $startDate,
            'committee_amount' => (int) $legacySettings['prize_per_share'],
            'installment_amount' => (int) $legacySettings['amt_per_share'],
            'draw_frequency' => 'monthly',
            'created_at' => (int) $legacySettings['updated_at'],
            'updated_at' => (int) $legacySettings['updated_at'],
        ]);
        $defaultCommitteeId = (int) $pdo->lastInsertId();
        echo "Created legacy default committee {$defaultCommitteeId}\n";
    }

    $stmt = $pdo->prepare('UPDATE settings SET default_committee_id = :committee_id WHERE id = 1');
    $stmt->execute(['committee_id' => $defaultCommitteeId]);
}

$legacyRuleEnvelope = json_decode((string) ($legacySettings['committee_rules'] ?? ''), true);
$legacyRules = jsonObject($legacyRuleEnvelope['rules'] ?? null);
$now = (int) (microtime(true) * 1000);
$stmt = $pdo->prepare(
    'INSERT IGNORE INTO committee_settings (
        committee_id, subtitle, total_months, current_month, prize_per_share,
        draw_method, installment_frequency, winner_rules, penalty_rules,
        payment_grace_period, notification_settings, certificate_template,
        currency, theme_color, updated_at
     ) VALUES (
        :committee_id, :subtitle, :total_months, :current_month, :prize_per_share,
        :draw_method, :installment_frequency, :winner_rules, :penalty_rules,
        :payment_grace_period, :notification_settings, :certificate_template,
        :currency, :theme_color, :updated_at
     )'
);
$stmt->execute([
    'committee_id' => $defaultCommitteeId,
    'subtitle' => (string) $legacySettings['committee_subtitle'],
    'total_months' => max(1, (int) $legacySettings['total_months']),
    'current_month' => max(1, (int) $legacySettings['current_month']),
    'prize_per_share' => (int) $legacySettings['prize_per_share'],
    'draw_method' => 'manual',
    'installment_frequency' => 'monthly',
    'winner_rules' => json_encode($legacyRules, JSON_UNESCAPED_UNICODE),
    'penalty_rules' => '{}',
    'payment_grace_period' => 0,
    'notification_settings' => json_encode([
        'email' => false,
        'sms' => false,
        'whatsapp' => false,
        'inApp' => true,
    ]),
    'certificate_template' => '{}',
    'currency' => 'PKR',
    'theme_color' => '#6366F1',
    'updated_at' => (int) $legacySettings['updated_at'],
]);

// Scope existing rows without changing primary IDs, password hashes or PIN hashes.
ensureColumn($pdo, 'members', 'committee_id', 'INT NULL DEFAULT NULL AFTER id');
ensureColumn($pdo, 'members', 'photo', "VARCHAR(500) NOT NULL DEFAULT '' AFTER email");
ensureColumn($pdo, 'members', 'documents', 'LONGTEXT NULL AFTER photo');
ensureColumn($pdo, 'members', 'notes', 'TEXT NULL AFTER documents');
ensureColumn($pdo, 'members', 'created_at', 'BIGINT NOT NULL DEFAULT 0 AFTER pref_month');
ensureColumn($pdo, 'members', 'updated_at', 'BIGINT NOT NULL DEFAULT 0 AFTER created_at');

$stmt = $pdo->prepare('UPDATE members SET committee_id = :committee_id WHERE committee_id IS NULL');
$stmt->execute(['committee_id' => $defaultCommitteeId]);

ensureColumn($pdo, 'payments', 'committee_id', 'INT NULL DEFAULT NULL AFTER member_id');
ensureColumn($pdo, 'payments', 'amount_due', 'DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER month_num');
ensureColumn($pdo, 'payments', 'amount_paid', 'DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER amount_due');
ensureColumn($pdo, 'payments', 'status', "VARCHAR(24) NOT NULL DEFAULT 'paid' AFTER amount_paid");
ensureColumn($pdo, 'payments', 'due_at', 'BIGINT NULL DEFAULT NULL AFTER status');
ensureColumn($pdo, 'payments', 'paid_at', 'BIGINT NULL DEFAULT NULL AFTER due_at');
ensureColumn($pdo, 'payments', 'payment_method', "VARCHAR(50) NOT NULL DEFAULT '' AFTER paid_at");
ensureColumn($pdo, 'payments', 'reference_no', "VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_method");
ensureColumn($pdo, 'payments', 'notes', 'TEXT NULL AFTER reference_no');
ensureColumn($pdo, 'payments', 'created_at', 'BIGINT NOT NULL DEFAULT 0 AFTER notes');
ensureColumn($pdo, 'payments', 'updated_at', 'BIGINT NOT NULL DEFAULT 0 AFTER created_at');

$pdo->exec(
    "UPDATE payments p
     JOIN members m ON m.id = p.member_id
     JOIN committees c ON c.id = m.committee_id
     SET p.committee_id = m.committee_id,
         p.amount_due = IF(p.amount_due = 0, m.shares * c.installment_amount, p.amount_due),
         p.amount_paid = IF(p.amount_paid = 0, m.shares * c.installment_amount, p.amount_paid),
         p.status = IF(p.status = '', 'paid', p.status)
     WHERE p.committee_id IS NULL OR p.amount_due = 0 OR p.amount_paid = 0 OR p.status = ''"
);

ensureColumn($pdo, 'winners', 'committee_id', 'INT NULL DEFAULT NULL AFTER id');
ensureColumn($pdo, 'winners', 'draw_number', 'INT NOT NULL DEFAULT 0 AFTER month_num');
ensureColumn($pdo, 'winners', 'winner_date', 'DATE NULL AFTER amount');
ensureColumn($pdo, 'winners', 'status', "VARCHAR(24) NOT NULL DEFAULT 'declared' AFTER winner_date");
ensureColumn($pdo, 'winners', 'payment_status', "VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER status");
ensureColumn($pdo, 'winners', 'certificate_number', "VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_status");
ensureColumn($pdo, 'winners', 'certificate_data', 'LONGTEXT NULL AFTER certificate_number');
ensureColumn($pdo, 'winners', 'notes', 'TEXT NULL AFTER certificate_data');
ensureColumn($pdo, 'winners', 'declared_at', 'BIGINT NOT NULL DEFAULT 0 AFTER notes');
ensureColumn($pdo, 'winners', 'updated_at', 'BIGINT NOT NULL DEFAULT 0 AFTER declared_at');
ensureColumn($pdo, 'winners', 'deleted_at', 'BIGINT NULL DEFAULT NULL AFTER updated_at');
ensureColumn($pdo, 'winners', 'deleted_by', 'VARCHAR(255) NULL DEFAULT NULL AFTER deleted_at');

$pdo->exec(
    "UPDATE winners w
     JOIN members m ON m.id = w.member_id
     SET w.committee_id = m.committee_id,
         w.draw_number = IF(w.draw_number = 0, w.month_num, w.draw_number),
         w.status = IF(w.status = '', 'declared', w.status)
     WHERE w.committee_id IS NULL OR w.draw_number = 0 OR w.status = ''"
);

// Historical dumps did not store a winner date. Use the committee's month
// schedule and the product default declaration day (the 20th).
$pdo->exec(
    "UPDATE winners w
     JOIN committees c ON c.id = w.committee_id
     SET w.winner_date = DATE_ADD(
         DATE_ADD(DATE_FORMAT(COALESCE(c.start_date, CURRENT_DATE), '%Y-%m-01'),
                  INTERVAL (w.month_num - 1) MONTH),
         INTERVAL 19 DAY)
     WHERE w.winner_date IS NULL"
);

// Legacy winners used month_num as a draw label. Normalize to a stable sequence
// within each committee/month so history remains unambiguous after migration.
$drawRows = $pdo->query('SELECT id, committee_id, month_num FROM winners ORDER BY committee_id, month_num, id')->fetchAll();
$drawCounters = [];
$drawUpdate = $pdo->prepare('UPDATE winners SET draw_number = :draw_number WHERE id = :id');
foreach ($drawRows as $drawRow) {
    $key = (int) $drawRow['committee_id'] . ':' . (int) $drawRow['month_num'];
    $drawCounters[$key] = ($drawCounters[$key] ?? 0) + 1;
    $drawUpdate->execute(['draw_number' => $drawCounters[$key], 'id' => (int) $drawRow['id']]);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS committee_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        committee_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        start_at BIGINT NOT NULL,
        end_at BIGINT NULL DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'scheduled',
        created_by VARCHAR(255) NOT NULL DEFAULT '',
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        deleted_at BIGINT NULL DEFAULT NULL,
        INDEX idx_events_committee_start (committee_id, start_at, type),
        CONSTRAINT fk_events_committee
          FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notification_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        committee_id INT NOT NULL,
        member_id INT NULL DEFAULT NULL,
        channel VARCHAR(24) NOT NULL DEFAULT 'in_app',
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        recipient VARCHAR(255) NOT NULL DEFAULT '',
        sent_at BIGINT NULL DEFAULT NULL,
        read_at BIGINT NULL DEFAULT NULL,
        error_message TEXT NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        INDEX idx_notifications_committee_member (committee_id, member_id, read_at, created_at),
        INDEX idx_notifications_status (status, channel, created_at),
        CONSTRAINT fk_notifications_committee
          FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT,
        CONSTRAINT fk_notifications_member
          FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        committee_id INT NULL DEFAULT NULL,
        actor_role VARCHAR(24) NOT NULL,
        actor_member_id INT NULL DEFAULT NULL,
        actor_name VARCHAR(255) NOT NULL DEFAULT '',
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NOT NULL DEFAULT '',
        entity_id VARCHAR(100) NOT NULL DEFAULT '',
        before_data LONGTEXT NULL,
        after_data LONGTEXT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        source_key VARCHAR(64) NULL DEFAULT NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_audit_source_key (source_key),
        INDEX idx_audit_committee_created (committee_id, created_at, id),
        INDEX idx_audit_action (action, created_at),
        CONSTRAINT fk_audit_committee
          FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT,
        CONSTRAINT fk_audit_actor_member
          FOREIGN KEY (actor_member_id) REFERENCES members(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Month close records lock completed operational periods without deleting history.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS committee_month_closures (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        committee_id INT NOT NULL,
        month_num INT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'closed',
        notes TEXT NULL,
        closed_by VARCHAR(255) NOT NULL DEFAULT '',
        closed_at BIGINT NOT NULL DEFAULT 0,
        reopened_by VARCHAR(255) NULL DEFAULT NULL,
        reopened_at BIGINT NULL DEFAULT NULL,
        UNIQUE KEY uq_committee_month_closure (committee_id, month_num),
        INDEX idx_month_closure_status (committee_id, status, month_num),
        CONSTRAINT fk_month_closure_committee
          FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Import the existing client-side rule audit exactly once. The deterministic
// source_key makes retries safe.
$legacyAudit = jsonObject($legacyRuleEnvelope['auditLog'] ?? null);
$insertAudit = $pdo->prepare(
    'INSERT IGNORE INTO audit_logs (
        committee_id, actor_role, actor_name, action, entity_type, entity_id,
        before_data, after_data, ip, source_key, created_at
     ) VALUES (
        :committee_id, :actor_role, :actor_name, :action, :entity_type, :entity_id,
        :before_data, :after_data, :ip, :source_key, :created_at
     )'
);
foreach ($legacyAudit as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $sourcePayload = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $insertAudit->execute([
        'committee_id' => $defaultCommitteeId,
        'actor_role' => 'admin',
        'actor_name' => (string) ($entry['admin'] ?? $legacySettings['admin_user']),
        'action' => (string) ($entry['action'] ?? 'Settings Changed'),
        'entity_type' => 'committee_settings',
        'entity_id' => (string) $defaultCommitteeId,
        'before_data' => json_encode(['value' => $entry['oldValue'] ?? null], JSON_UNESCAPED_UNICODE),
        'after_data' => json_encode(['value' => $entry['newValue'] ?? null], JSON_UNESCAPED_UNICODE),
        'ip' => (string) ($entry['ip'] ?? ''),
        'source_key' => hash('sha256', 'legacy-rule-audit:' . $sourcePayload),
        'created_at' => max(0, (int) ($entry['at'] ?? $now)),
    ]);
}

// Only tighten constraints after every legacy row has been assigned.
$nullMembers = (int) $pdo->query('SELECT COUNT(*) FROM members WHERE committee_id IS NULL')->fetchColumn();
$nullPayments = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE committee_id IS NULL')->fetchColumn();
$nullWinners = (int) $pdo->query('SELECT COUNT(*) FROM winners WHERE committee_id IS NULL')->fetchColumn();
if ($nullMembers !== 0 || $nullPayments !== 0 || $nullWinners !== 0) {
    throw new RuntimeException('Committee backfill incomplete; refusing to add NOT NULL/foreign-key constraints.');
}

$pdo->exec('ALTER TABLE members MODIFY committee_id INT NOT NULL');
$pdo->exec('ALTER TABLE payments MODIFY committee_id INT NOT NULL');
$pdo->exec('ALTER TABLE winners MODIFY committee_id INT NOT NULL, MODIFY member_id INT NULL');

ensureIndex($pdo, 'members', 'uq_members_committee_id', 'UNIQUE KEY uq_members_committee_id (committee_id, id)');
ensureIndex($pdo, 'members', 'idx_members_committee_active', 'INDEX idx_members_committee_active (committee_id, deleted_at)');
ensureIndex($pdo, 'members', 'idx_members_active_name', 'INDEX idx_members_active_name (deleted_at, name)');
ensureIndex($pdo, 'payments', 'uq_payments_committee_member_month', 'UNIQUE KEY uq_payments_committee_member_month (committee_id, member_id, month_num)');
ensureIndex($pdo, 'payments', 'idx_payments_committee_status', 'INDEX idx_payments_committee_status (committee_id, status, month_num)');
ensureIndex($pdo, 'winners', 'idx_winners_committee_date', 'INDEX idx_winners_committee_date (committee_id, winner_date, id)');
ensureIndex($pdo, 'winners', 'idx_winners_committee_status', 'INDEX idx_winners_committee_status (committee_id, status, payment_status)');

dropCascadingWinnerMemberKeys($pdo);
ensureForeignKey($pdo, 'members', 'fk_members_committee', 'FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT');
ensureForeignKey($pdo, 'payments', 'fk_payments_committee_member', 'FOREIGN KEY (committee_id, member_id) REFERENCES members(committee_id, id) ON DELETE CASCADE');
ensureForeignKey($pdo, 'winners', 'fk_winners_committee', 'FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT');
ensureForeignKey($pdo, 'winners', 'fk_winners_member', 'FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL');
ensureForeignKey($pdo, 'settings', 'fk_settings_default_committee', 'FOREIGN KEY (default_committee_id) REFERENCES committees(id) ON DELETE RESTRICT');

echo "Enterprise multi-committee migration complete. Default committee: {$defaultCommitteeId}\n";
echo "Preserved rows -- members: " . $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn()
    . '; payments: ' . $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()
    . '; winners: ' . $pdo->query('SELECT COUNT(*) FROM winners')->fetchColumn() . "\n";
