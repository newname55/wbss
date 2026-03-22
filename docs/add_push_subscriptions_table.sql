CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
  p256dh_key VARCHAR(255) NOT NULL,
  auth_key VARCHAR(128) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_subscriptions_endpoint_hash (endpoint_hash),
  KEY idx_push_subscriptions_user_store_active (user_id, store_id, is_active),
  KEY idx_push_subscriptions_store_active (store_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
