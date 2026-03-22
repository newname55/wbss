CREATE TABLE IF NOT EXISTS store_external_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(30) NOT NULL,
  source_id VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  venue_name VARCHAR(200) NULL,
  venue_addr VARCHAR(255) NULL,
  organizer_name VARCHAR(200) NULL,
  organizer_contact TEXT NULL,
  source_url VARCHAR(255) NULL,
  notes TEXT NULL,
  fetched_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_external_events_source (store_id, source, source_id),
  KEY idx_store_external_events_store_start (store_id, starts_at),
  KEY idx_store_external_events_store_end (store_id, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_event_instances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  budget_yen INT UNSIGNED NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  memo TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_event_instances_store_start (store_id, starts_at),
  KEY idx_store_event_instances_store_status (store_id, status),
  KEY idx_store_event_instances_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_event_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(30) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  summary VARCHAR(255) NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_event_audit_logs_store_created (store_id, created_at),
  KEY idx_store_event_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 必要なら最初の1件をこの形式で追加してください。
-- INSERT INTO store_event_instances
--   (store_id, title, status, starts_at, ends_at, budget_yen, memo, created_by, updated_by)
-- VALUES
--   (1, 'ホワイトデーイベント', 'scheduled', '2026-03-14 21:00:00', '2026-03-14 23:30:00', 30000, '店舗イベント初期登録', 1, 1);

-- 外部イベント手入力の例
-- INSERT INTO store_external_events
--   (store_id, source, source_id, title, starts_at, ends_at, all_day, venue_name, venue_addr, source_url, fetched_at)
-- VALUES
--   (1, 'manual', 'manual-20260314-01', '商店街ホワイトデー催事', '2026-03-14 10:00:00', '2026-03-14 18:00:00', 0, '岡山駅前', '岡山県岡山市北区駅前町', 'https://example.com/events/1', NOW());
