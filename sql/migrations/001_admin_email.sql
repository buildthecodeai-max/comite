USE committee_manager;

ALTER TABLE settings
  ADD COLUMN IF NOT EXISTS admin_email VARCHAR(255) NOT NULL DEFAULT '' AFTER admin_pass_hash;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL
);
