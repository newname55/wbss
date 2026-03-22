ALTER TABLE cast_transport_profiles
  ADD COLUMN pickup_target VARCHAR(32) NOT NULL DEFAULT 'primary'
  AFTER privacy_level;
