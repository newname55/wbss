ALTER TABLE stock_products
  ADD COLUMN bottle_back_rate_pct DECIMAL(5,2) NULL DEFAULT NULL AFTER selling_price_yen;
