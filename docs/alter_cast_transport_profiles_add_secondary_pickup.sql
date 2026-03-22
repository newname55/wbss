ALTER TABLE cast_transport_profiles
  ADD COLUMN pickup_sub_zip VARCHAR(16) DEFAULT NULL AFTER pickup_geocoded_at,
  ADD COLUMN pickup_sub_prefecture VARCHAR(64) DEFAULT NULL AFTER pickup_sub_zip,
  ADD COLUMN pickup_sub_city VARCHAR(128) DEFAULT NULL AFTER pickup_sub_prefecture,
  ADD COLUMN pickup_sub_address1 VARCHAR(255) DEFAULT NULL AFTER pickup_sub_city,
  ADD COLUMN pickup_sub_address2 VARCHAR(255) DEFAULT NULL AFTER pickup_sub_address1,
  ADD COLUMN pickup_sub_building VARCHAR(255) DEFAULT NULL AFTER pickup_sub_address2,
  ADD COLUMN pickup_sub_note VARCHAR(255) DEFAULT NULL AFTER pickup_sub_building,
  ADD COLUMN pickup_sub_lat DECIMAL(10,7) DEFAULT NULL AFTER pickup_sub_note,
  ADD COLUMN pickup_sub_lng DECIMAL(10,7) DEFAULT NULL AFTER pickup_sub_lat,
  ADD COLUMN pickup_sub_geocoded_at DATETIME DEFAULT NULL AFTER pickup_sub_lng;
