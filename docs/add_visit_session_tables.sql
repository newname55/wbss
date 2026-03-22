CREATE TABLE IF NOT EXISTS visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  store_event_instance_id BIGINT UNSIGNED NULL,
  entry_id BIGINT UNSIGNED NULL,
  primary_ticket_id BIGINT UNSIGNED NULL,
  visit_status VARCHAR(30) NOT NULL DEFAULT 'arrived',
  visit_type VARCHAR(30) NOT NULL DEFAULT 'unknown',
  arrived_at DATETIME NOT NULL,
  left_at DATETIME NULL,
  guest_count INT UNSIGNED NOT NULL DEFAULT 1,
  charge_people_snapshot INT UNSIGNED NOT NULL DEFAULT 0,
  first_free_stage VARCHAR(20) NOT NULL DEFAULT 'first',
  close_reason VARCHAR(30) NULL,
  next_action_status VARCHAR(30) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_visits_store_date (store_id, business_date),
  KEY idx_visits_store_customer (store_id, customer_id),
  KEY idx_visits_store_event (store_id, store_event_instance_id),
  KEY idx_visits_primary_ticket (primary_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_ticket_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NOT NULL,
  ticket_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  link_type VARCHAR(30) NOT NULL DEFAULT 'primary',
  payer_group VARCHAR(60) NULL,
  allocated_sales_yen INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visit_ticket_links_ticket (ticket_id),
  KEY idx_visit_ticket_links_visit (visit_id),
  KEY idx_visit_ticket_links_store_customer (store_id, customer_id),
  KEY idx_visit_ticket_links_store_ticket (store_id, ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  store_event_instance_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  entry_type VARCHAR(30) NOT NULL DEFAULT 'reservation',
  status VARCHAR(30) NOT NULL DEFAULT 'planned',
  source_detail VARCHAR(255) NULL,
  referrer_customer_id BIGINT UNSIGNED NULL,
  reserved_at DATETIME NULL,
  arrived_at DATETIME NULL,
  note TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_entries_store_event (store_id, store_event_instance_id),
  KEY idx_event_entries_store_customer (store_id, customer_id),
  KEY idx_event_entries_visit (visit_id),
  KEY idx_event_entries_reserved (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_cast_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  cast_user_id BIGINT UNSIGNED NOT NULL,
  role_type VARCHAR(30) NOT NULL DEFAULT 'table',
  free_stage VARCHAR(20) NOT NULL DEFAULT 'none',
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_visit_cast_assignments_visit (visit_id),
  KEY idx_visit_cast_assignments_cast (cast_user_id),
  KEY idx_visit_cast_assignments_store_date (store_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_nomination_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  cast_user_id BIGINT UNSIGNED NOT NULL,
  nomination_type VARCHAR(30) NOT NULL DEFAULT 'hon',
  set_no INT UNSIGNED NOT NULL DEFAULT 1,
  fee_ex_tax INT UNSIGNED NOT NULL DEFAULT 0,
  cast_back_yen INT UNSIGNED NOT NULL DEFAULT 0,
  count_unit DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_visit_nomination_events_visit (visit_id),
  KEY idx_visit_nomination_events_cast (cast_user_id),
  KEY idx_visit_nomination_events_store_date (store_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_metrics (
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  first_visit_at DATETIME NULL,
  last_visit_at DATETIME NULL,
  visit_count INT UNSIGNED NOT NULL DEFAULT 0,
  paid_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
  nomination_count INT UNSIGNED NOT NULL DEFAULT 0,
  same_cast_repeat_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_sales_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  avg_sales_yen INT UNSIGNED NOT NULL DEFAULT 0,
  favorite_event_id BIGINT UNSIGNED NULL,
  favorite_lead_source VARCHAR(30) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_id, customer_id),
  KEY idx_customer_metrics_last_visit (store_id, last_visit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_metrics_daily (
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  store_event_instance_id BIGINT UNSIGNED NOT NULL,
  entry_count INT UNSIGNED NOT NULL DEFAULT 0,
  visit_count INT UNSIGNED NOT NULL DEFAULT 0,
  new_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
  repeat_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
  nomination_count INT UNSIGNED NOT NULL DEFAULT 0,
  sales_total_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  paid_total_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gross_profit_yen BIGINT NOT NULL DEFAULT 0,
  roi DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  revisit_30d_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_id, business_date, store_event_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_daily_metrics (
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  visit_count INT UNSIGNED NOT NULL DEFAULT 0,
  customer_count INT UNSIGNED NOT NULL DEFAULT 0,
  ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
  sales_total_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  paid_total_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  balance_total_yen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  avg_sales_per_visit INT UNSIGNED NOT NULL DEFAULT 0,
  nomination_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  repeat_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_id, business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
