ALTER TABLE store_transport_bases
  ADD COLUMN is_dispatch_origin TINYINT(1) NOT NULL DEFAULT 0
  AFTER is_default;
