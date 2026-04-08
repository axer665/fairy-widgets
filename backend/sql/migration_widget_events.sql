-- Выполнить на существующей БД (после init.sql), если таблицы ещё нет.
CREATE TABLE IF NOT EXISTS widget_events (
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
