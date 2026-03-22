ALTER TABLE stores
  ADD COLUMN late_notice_delay_minutes INT NOT NULL DEFAULT 10
  COMMENT '遅刻LINE自動送信までの遅延分数';
