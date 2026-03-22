SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wbss_applicant_persons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_source VARCHAR(40) NULL,
  legacy_record_no VARCHAR(100) NULL,
  person_code VARCHAR(40) NULL,
  last_name VARCHAR(60) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  last_name_kana VARCHAR(60) NULL,
  first_name_kana VARCHAR(60) NULL,
  birth_date DATE NULL,
  age_cached TINYINT UNSIGNED NULL,
  phone VARCHAR(30) NULL,
  postal_code VARCHAR(20) NULL,
  current_address VARCHAR(255) NULL,
  previous_address VARCHAR(255) NULL,
  blood_type VARCHAR(10) NULL,
  zodiac_sign VARCHAR(16) NULL,
  body_height_cm SMALLINT UNSIGNED NULL,
  body_weight_kg DECIMAL(5,1) NULL,
  bust_cm SMALLINT UNSIGNED NULL,
  waist_cm SMALLINT UNSIGNED NULL,
  hip_cm SMALLINT UNSIGNED NULL,
  cup_size VARCHAR(10) NULL,
  shoe_size DECIMAL(4,1) NULL,
  clothing_top_size VARCHAR(20) NULL,
  clothing_bottom_size VARCHAR(20) NULL,
  previous_job VARCHAR(120) NULL,
  desired_hourly_wage DECIMAL(10,2) NULL,
  desired_daily_wage DECIMAL(10,2) NULL,
  notes TEXT NULL,
  current_status ENUM('interviewing','trial','active','left','hold') NOT NULL DEFAULT 'interviewing',
  is_currently_employed TINYINT(1) NOT NULL DEFAULT 0,
  current_store_id INT UNSIGNED NULL,
  current_assignment_id INT UNSIGNED NULL,
  latest_interview_id INT UNSIGNED NULL,
  latest_interviewed_at DATE NULL,
  latest_interview_result ENUM('pending','pass','hold','reject','joined') NULL,
  primary_photo_id INT NULL,
  current_stage_name VARCHAR(80) NULL,
  created_by_user_id INT UNSIGNED NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applicant_persons_name (last_name, first_name, id),
  KEY idx_applicant_persons_name_kana (last_name_kana, first_name_kana, id),
  KEY idx_applicant_persons_phone (phone),
  KEY idx_applicant_persons_current (is_currently_employed, current_store_id, current_status),
  KEY idx_applicant_persons_latest_interview (latest_interviewed_at, latest_interview_result),
  KEY idx_applicant_persons_updated_at (updated_at),
  KEY idx_applicant_persons_legacy (legacy_source, legacy_record_no),
  CONSTRAINT fk_applicant_persons_current_store
    FOREIGN KEY (current_store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_persons_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_persons_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wbss_applicant_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  thumb_path VARCHAR(255) NULL,
  mime_type VARCHAR(80) NULL,
  file_size INT UNSIGNED NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applicant_photos_person (person_id, is_primary, created_at),
  CONSTRAINT fk_applicant_photos_person
    FOREIGN KEY (person_id) REFERENCES wbss_applicant_persons(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_applicant_photos_uploaded_by
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wbss_applicant_interviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id INT UNSIGNED NOT NULL,
  interview_date DATE NOT NULL,
  interview_time TIME NULL,
  interview_store_id INT UNSIGNED NOT NULL,
  interviewer_user_id INT UNSIGNED NULL,
  interview_result ENUM('pending','pass','hold','reject','joined') NOT NULL DEFAULT 'pending',
  application_route VARCHAR(100) NULL,
  recruitment_slot_note VARCHAR(255) NULL,
  interview_notes TEXT NULL,
  previous_job VARCHAR(120) NULL,
  desired_hourly_wage DECIMAL(10,2) NULL,
  desired_daily_wage DECIMAL(10,2) NULL,
  preferred_store_id INT UNSIGNED NULL,
  trial_status ENUM('not_set','scheduled','completed','passed','failed','cancelled') NOT NULL DEFAULT 'not_set',
  trial_date DATE NULL,
  trial_feedback TEXT NULL,
  join_decision ENUM('undecided','approved','rejected','deferred') NOT NULL DEFAULT 'undecided',
  join_date DATE NULL,
  next_action_note VARCHAR(255) NULL,
  created_by_user_id INT UNSIGNED NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applicant_interviews_person (person_id, interview_date DESC, id DESC),
  KEY idx_applicant_interviews_store (interview_store_id, interview_date DESC),
  KEY idx_applicant_interviews_user (interviewer_user_id, interview_date DESC),
  KEY idx_applicant_interviews_result (interview_result, trial_status),
  CONSTRAINT fk_applicant_interviews_person
    FOREIGN KEY (person_id) REFERENCES wbss_applicant_persons(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_applicant_interviews_store
    FOREIGN KEY (interview_store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_applicant_interviews_preferred_store
    FOREIGN KEY (preferred_store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_interviews_user
    FOREIGN KEY (interviewer_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_interviews_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_interviews_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wbss_applicant_interview_scores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  interview_id INT UNSIGNED NOT NULL,
  appearance_score TINYINT UNSIGNED NULL,
  communication_score TINYINT UNSIGNED NULL,
  motivation_score TINYINT UNSIGNED NULL,
  cleanliness_score TINYINT UNSIGNED NULL,
  sales_potential_score TINYINT UNSIGNED NULL,
  retention_potential_score TINYINT UNSIGNED NULL,
  total_score SMALLINT UNSIGNED NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_applicant_scores_interview (interview_id),
  CONSTRAINT fk_applicant_scores_interview
    FOREIGN KEY (interview_id) REFERENCES wbss_applicant_interviews(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wbss_applicant_store_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NOT NULL,
  source_interview_id INT UNSIGNED NULL,
  assignment_status ENUM('active','left') NOT NULL DEFAULT 'active',
  transition_type ENUM('join','move','rejoin') NOT NULL DEFAULT 'join',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  genji_name VARCHAR(80) NULL,
  move_reason VARCHAR(255) NULL,
  leave_reason VARCHAR(255) NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT UNSIGNED NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applicant_assignments_person (person_id, is_current, start_date DESC),
  KEY idx_applicant_assignments_store (store_id, is_current, assignment_status),
  KEY idx_applicant_assignments_period (start_date, end_date),
  CONSTRAINT fk_applicant_assignments_person
    FOREIGN KEY (person_id) REFERENCES wbss_applicant_persons(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_applicant_assignments_store
    FOREIGN KEY (store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_applicant_assignments_interview
    FOREIGN KEY (source_interview_id) REFERENCES wbss_applicant_interviews(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_assignments_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_assignments_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wbss_applicant_status_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id INT UNSIGNED NOT NULL,
  interview_id INT UNSIGNED NULL,
  assignment_id INT UNSIGNED NULL,
  action_type ENUM(
    'person_created',
    'person_updated',
    'photo_uploaded',
    'interview_created',
    'interview_updated',
    'trial_updated',
    'status_changed',
    'joined',
    'moved',
    'left'
  ) NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NULL,
  store_id INT UNSIGNED NULL,
  target_store_id INT UNSIGNED NULL,
  actor_user_id INT UNSIGNED NULL,
  action_note TEXT NULL,
  payload_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applicant_logs_person (person_id, created_at DESC),
  KEY idx_applicant_logs_action (action_type, created_at DESC),
  CONSTRAINT fk_applicant_logs_person
    FOREIGN KEY (person_id) REFERENCES wbss_applicant_persons(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_applicant_logs_interview
    FOREIGN KEY (interview_id) REFERENCES wbss_applicant_interviews(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_logs_assignment
    FOREIGN KEY (assignment_id) REFERENCES wbss_applicant_store_assignments(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_logs_store
    FOREIGN KEY (store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_logs_target_store
    FOREIGN KEY (target_store_id) REFERENCES stores(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_applicant_logs_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE wbss_applicant_persons
  ADD CONSTRAINT fk_applicant_persons_current_assignment
    FOREIGN KEY (current_assignment_id) REFERENCES wbss_applicant_store_assignments(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_applicant_persons_latest_interview
    FOREIGN KEY (latest_interview_id) REFERENCES wbss_applicant_interviews(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_applicant_persons_primary_photo
    FOREIGN KEY (primary_photo_id) REFERENCES wbss_applicant_photos(id)
    ON UPDATE CASCADE ON DELETE SET NULL;
