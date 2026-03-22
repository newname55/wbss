CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id INT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  kind ENUM('normal', 'thanks') NOT NULL DEFAULT 'normal',
  title VARCHAR(120) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  visibility_scope VARCHAR(32) NOT NULL DEFAULT 'direct',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_store_kind_created (store_id, kind, created_at),
  KEY idx_messages_sender_store_created (sender_user_id, store_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  recipient_user_id INT UNSIGNED NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_recipients_message_user (message_id, recipient_user_id),
  KEY idx_message_recipients_user_read_created (recipient_user_id, is_read, created_at),
  KEY idx_message_recipients_user_created (recipient_user_id, created_at),
  KEY idx_message_recipients_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
