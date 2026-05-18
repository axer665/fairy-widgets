CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('client', 'moderator') NOT NULL DEFAULT 'client',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  site_url VARCHAR(2048) NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  widget_token VARCHAR(64) NULL UNIQUE,
  moderator_note VARCHAR(512) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_fairies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL DEFAULT 'Фея',
  standard_behavior TINYINT(1) NOT NULL DEFAULT 0,
  current_execution_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  page_url VARCHAR(2048) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  INDEX idx_app_created (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_action_types (
  id TINYINT UNSIGNED PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  label VARCHAR(128) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO widget_action_types (id, code, label, sort_order) VALUES
  (1, 'text', 'Текст', 1),
  (2, 'survey', 'Опрос удовлетворённости', 2),
  (3, 'video', 'Видео', 3);

CREATE TABLE widget_media_assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(128) NOT NULL,
  mime_type VARCHAR(64) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  UNIQUE KEY uq_app_stored (application_id, stored_filename),
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_text_widgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_survey_widgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL,
  title VARCHAR(512) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_video_widgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL,
  media_id INT UNSIGNED NOT NULL,
  link_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES widget_media_assets(id) ON DELETE RESTRICT,
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(64) NOT NULL,
  action_type_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  text_widget_id INT UNSIGNED NULL,
  survey_widget_id INT UNSIGNED NULL,
  video_widget_id INT UNSIGNED NULL,
  pos_h_edge ENUM('left', 'right') NOT NULL DEFAULT 'right',
  pos_v_edge ENUM('top', 'bottom') NOT NULL DEFAULT 'bottom',
  pos_unit ENUM('px', 'percent') NOT NULL DEFAULT 'px',
  pos_x DECIMAL(8, 2) NOT NULL DEFAULT 150.00,
  pos_y DECIMAL(8, 2) NOT NULL DEFAULT 130.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (action_type_id) REFERENCES widget_action_types(id),
  FOREIGN KEY (text_widget_id) REFERENCES widget_text_widgets(id) ON DELETE SET NULL,
  FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE SET NULL,
  FOREIGN KEY (video_widget_id) REFERENCES widget_video_widgets(id) ON DELETE SET NULL,
  UNIQUE KEY uq_app_event_key (application_id, event_key),
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fairy_events (
  fairy_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (fairy_id, widget_event_id),
  FOREIGN KEY (fairy_id) REFERENCES widget_fairies(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_event_executions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fairy_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  kind ENUM('event', 'standard') NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (fairy_id) REFERENCES widget_fairies(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  INDEX idx_fairy_open (fairy_id, completed_at),
  INDEX idx_event_open (widget_event_id, completed_at),
  INDEX idx_event_session (widget_event_id, session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_event_failures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  fairy_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  event_key VARCHAR(64) NOT NULL,
  reason_code VARCHAR(32) NOT NULL,
  detail VARCHAR(512) NULL,
  blocker_execution_id BIGINT UNSIGNED NULL,
  blocker_fairy_id INT UNSIGNED NULL,
  blocker_widget_event_id INT UNSIGNED NULL,
  blocker_event_key VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (fairy_id) REFERENCES widget_fairies(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  INDEX idx_app_created (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_survey_ratings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NOT NULL,
  survey_widget_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  page_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE CASCADE,
  FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution_rating (execution_id),
  INDEX idx_app_event_created (application_id, widget_event_id, created_at),
  INDEX idx_survey_widget (survey_widget_id, created_at),
  CONSTRAINT chk_survey_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_text_impressions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  text_widget_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  page_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (text_widget_id) REFERENCES widget_text_widgets(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution (execution_id),
  INDEX idx_widget_created (text_widget_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_survey_impressions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  survey_widget_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  page_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution (execution_id),
  INDEX idx_widget_created (survey_widget_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_survey_cancellations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  survey_widget_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution (execution_id),
  INDEX idx_widget_created (survey_widget_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_video_impressions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  video_widget_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  page_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (video_widget_id) REFERENCES widget_video_widgets(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution (execution_id),
  INDEX idx_widget_created (video_widget_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE widget_video_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  video_widget_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  watch_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  completed_full TINYINT(1) NOT NULL DEFAULT 0,
  link_clicked TINYINT(1) NOT NULL DEFAULT 0,
  dismissed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (video_widget_id) REFERENCES widget_video_widgets(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution (execution_id),
  INDEX idx_widget_updated (video_widget_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (login, email, password_hash, role)
VALUES (
  'admin',
  'admin@local.test',
  '$2y$10$2yJnNdEDp1rEh7YzoHULNeNqJahak3KWdhy9MbpCcGXRMHapKHW.C',
  'moderator'
);
