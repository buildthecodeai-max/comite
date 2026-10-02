-- Empty schema only (no data).
-- For cPanel: create + select your database in phpMyAdmin first, then import this file.
-- Do not rely on CREATE/USE — cPanel DB names are usually prefixed (e.g. youruser_committee).

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
  CONSTRAINT settings_single_row CHECK (id = 1)
);

CREATE TABLE IF NOT EXISTS members (
  id INT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  shares INT NOT NULL DEFAULT 1,
  phone VARCHAR(50) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  pin_hash VARCHAR(255) NOT NULL,
  pref_month INT NOT NULL DEFAULT 0,
  deleted_at BIGINT NULL DEFAULT NULL,
  deleted_by VARCHAR(255) NULL DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS payments (
  member_id INT NOT NULL,
  month_num INT NOT NULL,
  PRIMARY KEY (member_id, month_num),
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS winners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  month_num INT NOT NULL,
  shares_won INT NOT NULL DEFAULT 1,
  amount INT NOT NULL DEFAULT 0,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_winners_member (member_id),
  INDEX idx_winners_month (month_num)
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL
);
