-- Empty schema only (no member/sample data).
-- For cPanel: create + select your database in phpMyAdmin first, then import this file.
-- Do not rely on CREATE/USE -- cPanel DB names are usually prefixed.

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

-- This remains the single global authentication/recovery row. Committee-specific
-- settings live in committees and committee_settings.
CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY DEFAULT 1,
  committee_name VARCHAR(255) NOT NULL DEFAULT 'Hashmat Commite',
  committee_subtitle VARCHAR(255) NOT NULL DEFAULT 'Committee Management System',
  admin_user VARCHAR(100) NOT NULL DEFAULT 'admin',
  admin_pass_hash VARCHAR(255) NOT NULL,
  admin_email VARCHAR(255) NOT NULL DEFAULT '',
  recovery_contact VARCHAR(255) NOT NULL DEFAULT '',
  recovery_note TEXT NOT NULL,
  amt_per_share INT NOT NULL DEFAULT 2000,
  total_months INT NOT NULL DEFAULT 25,
  prize_per_share INT NOT NULL DEFAULT 50000,
  start_month CHAR(7) NOT NULL DEFAULT '',
  current_month INT NOT NULL DEFAULT 1,
  next_id INT NOT NULL DEFAULT 1,
  updated_at BIGINT NOT NULL DEFAULT 0,
  committee_rules TEXT NULL DEFAULT NULL,
  default_committee_id INT NULL DEFAULT NULL,
  CONSTRAINT settings_single_row CHECK (id = 1),
  CONSTRAINT fk_settings_default_committee
    FOREIGN KEY (default_committee_id) REFERENCES committees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id INT PRIMARY KEY,
  committee_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  shares INT NOT NULL DEFAULT 1,
  phone VARCHAR(50) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  photo VARCHAR(500) NOT NULL DEFAULT '',
  documents LONGTEXT NULL,
  notes TEXT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  pref_month INT NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL DEFAULT 0,
  deleted_at BIGINT NULL DEFAULT NULL,
  deleted_by VARCHAR(255) NULL DEFAULT NULL,
  UNIQUE KEY uq_members_committee_id (committee_id, id),
  INDEX idx_members_committee_active (committee_id, deleted_at),
  INDEX idx_members_active_name (deleted_at, name),
  CONSTRAINT fk_members_committee
    FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  member_id INT NOT NULL,
  committee_id INT NOT NULL,
  month_num INT NOT NULL,
  amount_due DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'paid',
  due_at BIGINT NULL DEFAULT NULL,
  paid_at BIGINT NULL DEFAULT NULL,
  payment_method VARCHAR(50) NOT NULL DEFAULT '',
  reference_no VARCHAR(100) NOT NULL DEFAULT '',
  notes TEXT NULL,
  created_at BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id, month_num),
  UNIQUE KEY uq_payments_committee_member_month (committee_id, member_id, month_num),
  INDEX idx_payments_committee_status (committee_id, status, month_num),
  CONSTRAINT fk_payments_member
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_committee_member
    FOREIGN KEY (committee_id, member_id) REFERENCES members(committee_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS winners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  committee_id INT NOT NULL,
  member_id INT NULL,
  name VARCHAR(255) NOT NULL,
  month_num INT NOT NULL,
  draw_number INT NOT NULL DEFAULT 0,
  shares_won INT NOT NULL DEFAULT 1,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  winner_date DATE NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'declared',
  payment_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  certificate_number VARCHAR(100) NOT NULL DEFAULT '',
  certificate_data LONGTEXT NULL,
  notes TEXT NULL,
  declared_at BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL DEFAULT 0,
  deleted_at BIGINT NULL DEFAULT NULL,
  deleted_by VARCHAR(255) NULL DEFAULT NULL,
  CONSTRAINT fk_winners_committee
    FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_winners_member
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  INDEX idx_winners_member (member_id),
  INDEX idx_winners_committee_date (committee_id, winner_date, id),
  INDEX idx_winners_committee_status (committee_id, status, payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS committee_month_closures (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  INDEX idx_password_reset_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
