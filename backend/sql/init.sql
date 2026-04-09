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

CREATE TABLE widget_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(64) NOT NULL,
  phrase VARCHAR(2000) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
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

INSERT INTO users (login, email, password_hash, role)
VALUES (
  'admin',
  'admin@local.test',
  '$2y$10$2yJnNdEDp1rEh7YzoHULNeNqJahak3KWdhy9MbpCcGXRMHapKHW.C',
  'moderator'
);
