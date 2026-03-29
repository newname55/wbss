CREATE TABLE IF NOT EXISTS cast_growth_manager_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  manager_user_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cast_growth_manager_notes_store_cast (store_id, cast_id),
  KEY idx_cast_growth_manager_notes_store (store_id),
  KEY idx_cast_growth_manager_notes_cast (cast_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
