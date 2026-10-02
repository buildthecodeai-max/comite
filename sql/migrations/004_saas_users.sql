-- SaaS multi-tenant user accounts.
-- Run once: php scripts/migrate.php

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL DEFAULT '',
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('superadmin','owner') NOT NULL DEFAULT 'owner',
  created_at    BIGINT NOT NULL DEFAULT 0,
  updated_at    BIGINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps committee owners/managers to their committees.
CREATE TABLE IF NOT EXISTS committee_users (
  user_id      INT NOT NULL,
  committee_id INT NOT NULL,
  role         ENUM('owner','manager','viewer') NOT NULL DEFAULT 'owner',
  created_at   BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, committee_id),
  INDEX idx_committee_users_committee (committee_id),
  CONSTRAINT fk_cu_user
    FOREIGN KEY (user_id)      REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_cu_committee
    FOREIGN KEY (committee_id) REFERENCES committees(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
