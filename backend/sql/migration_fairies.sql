-- Миграция: феи, назначение событий, выполнения, журнал сбоев.
-- После бэкапа. Если токен был у каждой феи — затем выполните migration_app_token.sql (один токен на заявку, fairy_id в URL).

CREATE TABLE IF NOT EXISTS widget_fairies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL DEFAULT 'Фея',
  widget_token VARCHAR(64) NOT NULL,
  standard_behavior TINYINT(1) NOT NULL DEFAULT 0,
  current_execution_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  UNIQUE KEY uq_widget_token (widget_token),
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fairy_events (
  fairy_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (fairy_id, widget_event_id),
  FOREIGN KEY (fairy_id) REFERENCES widget_fairies(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widget_event_executions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fairy_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NULL,
  kind ENUM('event', 'standard') NOT NULL,
  session_key VARCHAR(64) NOT NULL DEFAULT 'legacy',
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (fairy_id) REFERENCES widget_fairies(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE SET NULL,
  INDEX idx_fairy_open (fairy_id, completed_at),
  INDEX idx_event_open (widget_event_id, completed_at),
  INDEX idx_event_session (widget_event_id, session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widget_event_failures (
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

-- Одна фея на заявку из старых колонок (только если колонки ещё есть).
INSERT INTO widget_fairies (application_id, name, widget_token, standard_behavior)
SELECT a.id, 'Фея', a.widget_token, 0
FROM widget_applications a
WHERE a.status = 'approved'
  AND a.widget_token IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM widget_fairies f WHERE f.application_id = a.id);

-- Назначить все события заявки каждой её фее
INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id)
SELECT f.id, e.id
FROM widget_fairies f
INNER JOIN widget_events e ON e.application_id = f.application_id;

-- После выката кода, при желании убрать устаревшие колонки с заявки:
-- ALTER TABLE widget_applications DROP COLUMN widget_token;
-- ALTER TABLE widget_applications DROP COLUMN standard_behavior;
