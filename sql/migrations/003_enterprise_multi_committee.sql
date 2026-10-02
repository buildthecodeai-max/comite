-- Enterprise multi-committee upgrade (additive and safe to re-run on MySQL 8+).
-- IMPORTANT: select the target database before importing. This file intentionally
-- contains no USE statement so prefixed cPanel database names continue to work.

CREATE TABLE IF NOT EXISTS committees (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS committee_settings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE settings
  ADD COLUMN IF NOT EXISTS default_committee_id INT NULL DEFAULT NULL AFTER committee_rules;

-- The one legacy settings row becomes committee 1. No credentials are copied or
-- changed; settings.admin_user/admin_pass_hash remain the authentication source.
INSERT INTO committees (
  id, name, description, status, start_date, committee_amount,
  installment_amount, draw_frequency, version, created_at, updated_at
)
SELECT
  1,
  s.committee_name,
  s.committee_subtitle,
  'running',
  CASE WHEN s.start_month REGEXP '^[0-9]{4}-[0-9]{2}$'
       THEN STR_TO_DATE(CONCAT(s.start_month, '-01'), '%Y-%m-%d') ELSE NULL END,
  s.prize_per_share,
  s.amt_per_share,
  'monthly',
  1,
  s.updated_at,
  s.updated_at
FROM settings s
WHERE s.id = 1
ON DUPLICATE KEY UPDATE id = VALUES(id);

UPDATE settings
SET default_committee_id = COALESCE(default_committee_id, 1)
WHERE id = 1;

INSERT INTO committee_settings (
  committee_id, subtitle, total_months, current_month, prize_per_share,
  draw_method, installment_frequency, winner_rules, penalty_rules,
  payment_grace_period, notification_settings, certificate_template,
  currency, theme_color, updated_at
)
SELECT
  1,
  s.committee_subtitle,
  s.total_months,
  s.current_month,
  s.prize_per_share,
  'manual',
  'monthly',
  CASE
    WHEN JSON_VALID(s.committee_rules) THEN JSON_EXTRACT(s.committee_rules, '$.rules')
    ELSE JSON_OBJECT()
  END,
  JSON_OBJECT(),
  0,
  JSON_OBJECT('email', false, 'sms', false, 'whatsapp', false, 'inApp', true),
  JSON_OBJECT(),
  'PKR',
  '#6366F1',
  s.updated_at
FROM settings s
WHERE s.id = 1
ON DUPLICATE KEY UPDATE committee_id = VALUES(committee_id);

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS committee_id INT NULL DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS photo VARCHAR(500) NOT NULL DEFAULT '' AFTER email,
  ADD COLUMN IF NOT EXISTS documents LONGTEXT NULL AFTER photo,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER documents,
  ADD COLUMN IF NOT EXISTS created_at BIGINT NOT NULL DEFAULT 0 AFTER pref_month,
  ADD COLUMN IF NOT EXISTS updated_at BIGINT NOT NULL DEFAULT 0 AFTER created_at;

UPDATE members
SET committee_id = (SELECT default_committee_id FROM settings WHERE id = 1)
WHERE committee_id IS NULL;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS committee_id INT NULL DEFAULT NULL AFTER member_id,
  ADD COLUMN IF NOT EXISTS amount_due DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER month_num,
  ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER amount_due,
  ADD COLUMN IF NOT EXISTS status VARCHAR(24) NOT NULL DEFAULT 'paid' AFTER amount_paid,
  ADD COLUMN IF NOT EXISTS due_at BIGINT NULL DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS paid_at BIGINT NULL DEFAULT NULL AFTER due_at,
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NOT NULL DEFAULT '' AFTER paid_at,
  ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_method,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER reference_no,
  ADD COLUMN IF NOT EXISTS created_at BIGINT NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN IF NOT EXISTS updated_at BIGINT NOT NULL DEFAULT 0 AFTER created_at;

UPDATE payments p
JOIN members m ON m.id = p.member_id
SET p.committee_id = m.committee_id,
    p.amount_due = CASE WHEN p.amount_due = 0 THEN m.shares *
      (SELECT installment_amount FROM committees c WHERE c.id = m.committee_id) ELSE p.amount_due END,
    p.amount_paid = CASE WHEN p.amount_paid = 0 THEN m.shares *
      (SELECT installment_amount FROM committees c WHERE c.id = m.committee_id) ELSE p.amount_paid END,
    p.status = CASE WHEN p.status = '' THEN 'paid' ELSE p.status END
WHERE p.committee_id IS NULL OR p.amount_due = 0 OR p.amount_paid = 0 OR p.status = '';

ALTER TABLE winners
  ADD COLUMN IF NOT EXISTS committee_id INT NULL DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS draw_number INT NOT NULL DEFAULT 0 AFTER month_num,
  ADD COLUMN IF NOT EXISTS winner_date DATE NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS status VARCHAR(24) NOT NULL DEFAULT 'declared' AFTER winner_date,
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN IF NOT EXISTS certificate_number VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_status,
  ADD COLUMN IF NOT EXISTS certificate_data LONGTEXT NULL AFTER certificate_number,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER certificate_data,
  ADD COLUMN IF NOT EXISTS declared_at BIGINT NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN IF NOT EXISTS updated_at BIGINT NOT NULL DEFAULT 0 AFTER declared_at,
  ADD COLUMN IF NOT EXISTS deleted_at BIGINT NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS deleted_by VARCHAR(255) NULL DEFAULT NULL AFTER deleted_at;

UPDATE winners w
JOIN members m ON m.id = w.member_id
SET w.committee_id = m.committee_id,
    w.draw_number = CASE WHEN w.draw_number = 0 THEN w.month_num ELSE w.draw_number END,
    w.status = CASE WHEN w.status = '' THEN 'declared' ELSE w.status END
WHERE w.committee_id IS NULL OR w.draw_number = 0 OR w.status = '';

CREATE TABLE IF NOT EXISTS committee_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes/foreign keys on altered legacy tables and the final NOT NULL conversion
-- are applied by scripts/migrate.php after it verifies every backfill. That avoids
-- non-idempotent ADD CONSTRAINT statements in phpMyAdmin imports.
