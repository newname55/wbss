ALTER TABLE stores
  ADD COLUMN late_notice_auto_enabled TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '遅刻LINE自動送信の店舗別ON/OFF';
