ALTER TABLE stock_products
  ADD COLUMN purchase_price_yen INT NULL DEFAULT NULL AFTER unit,
  ADD COLUMN selling_price_yen INT NULL DEFAULT NULL AFTER purchase_price_yen;

CREATE TABLE IF NOT EXISTS stock_product_price_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  price_type ENUM('purchase', 'selling') NOT NULL,
  old_price_yen INT NULL DEFAULT NULL,
  new_price_yen INT NULL DEFAULT NULL,
  note VARCHAR(255) NULL DEFAULT NULL,
  changed_by BIGINT UNSIGNED NULL DEFAULT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_product_price_history_store_changed (store_id, changed_at),
  KEY idx_stock_product_price_history_product_type (product_id, price_type, changed_at),
  CONSTRAINT fk_stock_product_price_history_product
    FOREIGN KEY (product_id) REFERENCES stock_products (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
