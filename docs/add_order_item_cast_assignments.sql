CREATE TABLE IF NOT EXISTS order_item_cast_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL DEFAULT NULL,
  menu_id INT UNSIGNED NOT NULL,
  cast_user_id INT UNSIGNED NOT NULL,
  consumed_qty DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  amount_yen INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_oica_ticket_cast (ticket_id, cast_user_id, created_at),
  KEY idx_oica_order_item (order_item_id),
  KEY idx_oica_order (order_id),
  KEY idx_oica_store_ticket (store_id, ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
