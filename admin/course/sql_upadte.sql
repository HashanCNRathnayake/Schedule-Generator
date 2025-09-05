-- Master list (you already have something similar)
CREATE TABLE IF NOT EXISTS courses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id VARCHAR(64) NOT NULL,
  course_code VARCHAR(64) NOT NULL,
  course_title_external VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_course_code (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A “template/header” per uploaded CSV (one per Course+Module+Mode+User)
CREATE TABLE IF NOT EXISTS session_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id VARCHAR(64) NOT NULL,
  course_code VARCHAR(64) NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  learning_mode VARCHAR(128) NOT NULL,        -- e.g., "Full Time" or your selected mode text
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lookup (course_id, module_code, learning_mode, user_id),
  UNIQUE KEY uniq_latest (
    course_id, module_code, learning_mode, user_id, created_at
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The CSV rows (only first 4 columns as requested)
CREATE TABLE IF NOT EXISTS session_template_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  session_no VARCHAR(32) NOT NULL,
  session_type VARCHAR(64) NOT NULL,          -- e.g., MS-Sync / MS-ASync (you can normalize in PHP)
  session_details TEXT NOT NULL,
  duration_hr VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row (template_id, session_no),  -- avoids dupes on same template + row
  CONSTRAINT fk_rows_template
    FOREIGN KEY (template_id) REFERENCES session_templates(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- CREATE TABLE IF NOT EXISTS session_templates (
--   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--   course_id VARCHAR(64) NOT NULL,
--   course_code VARCHAR(64) NOT NULL,
--   module_code VARCHAR(64) NOT NULL,
--   learning_mode VARCHAR(128) NOT NULL,        -- e.g., "Full Time" or your selected mode text
--   cohort_code VARCHAR(128) NOT NULL,          -- ModuleCode + '-' + suffix (from UI)
--   cohort_suffix VARCHAR(64) NOT NULL,
--   user_id BIGINT UNSIGNED NOT NULL,
--   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--   PRIMARY KEY (id),
--   KEY idx_lookup (course_id, module_code, learning_mode, cohort_code, user_id),
--   UNIQUE KEY uniq_latest (
--     course_id, module_code, learning_mode, cohort_code, user_id, created_at
--   )
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -- The CSV rows (only first 4 columns as requested)
-- CREATE TABLE IF NOT EXISTS session_template_rows (
--   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--   template_id BIGINT UNSIGNED NOT NULL,
--   session_no VARCHAR(32) NOT NULL,
--   session_type VARCHAR(64) NOT NULL,          -- e.g., MS-Sync / MS-ASync (you can normalize in PHP)
--   session_details TEXT NOT NULL,
--   duration_hr VARCHAR(32) NOT NULL,
--   PRIMARY KEY (id),
--   UNIQUE KEY uniq_row (template_id, session_no),  -- avoids dupes on same template + row
--   CONSTRAINT fk_rows_template
--     FOREIGN KEY (template_id) REFERENCES session_templates(id)
--     ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


