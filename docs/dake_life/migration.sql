CREATE DATABASE IF NOT EXISTS dake_life
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE dake_life;

CREATE TABLE IF NOT EXISTS dl_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_user_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  timezone_name VARCHAR(64) NOT NULL DEFAULT 'Asia/Tokyo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_users_external_user_id (external_user_id),
  KEY idx_dl_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_action_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dl_user_id BIGINT UNSIGNED NOT NULL,
  action_date DATE NOT NULL,
  action_at DATETIME NULL,
  action_type VARCHAR(64) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  meta_json LONGTEXT NULL,
  source_code VARCHAR(32) NOT NULL DEFAULT 'api',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dl_action_logs_user_date (dl_user_id, action_date),
  KEY idx_dl_action_logs_type_date (action_type, action_date),
  CONSTRAINT fk_dl_action_logs_user
    FOREIGN KEY (dl_user_id) REFERENCES dl_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_fortune_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_type VARCHAR(64) NOT NULL,
  effect_json LONGTEXT NOT NULL,
  note_text VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_fortune_rules_action_type (action_type),
  KEY idx_dl_fortune_rules_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_fortune_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dl_user_id BIGINT UNSIGNED NOT NULL,
  encounter_score DECIMAL(5,1) NOT NULL DEFAULT 50.0,
  comfort_score DECIMAL(5,1) NOT NULL DEFAULT 50.0,
  challenge_score DECIMAL(5,1) NOT NULL DEFAULT 50.0,
  flow_score DECIMAL(5,1) NOT NULL DEFAULT 50.0,
  total_score DECIMAL(5,1) NOT NULL DEFAULT 50.0,
  phase_code VARCHAR(32) NOT NULL DEFAULT 'rest',
  bias_level DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  recent_action_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_text TEXT NULL,
  based_on_date DATE NOT NULL,
  last_calculated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_fortune_states_user (dl_user_id),
  KEY idx_dl_fortune_states_phase_code (phase_code),
  CONSTRAINT fk_dl_fortune_states_user
    FOREIGN KEY (dl_user_id) REFERENCES dl_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_daily_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dl_user_id BIGINT UNSIGNED NOT NULL,
  snapshot_date DATE NOT NULL,
  encounter_score DECIMAL(5,1) NOT NULL,
  comfort_score DECIMAL(5,1) NOT NULL,
  challenge_score DECIMAL(5,1) NOT NULL,
  flow_score DECIMAL(5,1) NOT NULL,
  total_score DECIMAL(5,1) NOT NULL,
  phase_code VARCHAR(32) NOT NULL,
  message_text TEXT NULL,
  source_state_updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_daily_snapshots_user_date (dl_user_id, snapshot_date),
  KEY idx_dl_daily_snapshots_date (snapshot_date),
  CONSTRAINT fk_dl_daily_snapshots_user
    FOREIGN KEY (dl_user_id) REFERENCES dl_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_message_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phase_code VARCHAR(32) NOT NULL,
  template_code VARCHAR(64) NOT NULL,
  min_total_score DECIMAL(5,1) NULL,
  max_total_score DECIMAL(5,1) NULL,
  template_text TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_message_templates_code (template_code),
  KEY idx_dl_message_templates_phase (phase_code, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
