-- MySQL 8+ only (не PostgreSQL). Применить:
--   docker compose exec -T mysql mysql -uwidget -pwidget_secret widget_app < backend/sql/migration_action_types_and_media.sql
-- Типы действий, медиа-контент, опросы.

CREATE TABLE IF NOT EXISTS widget_action_types (
  id TINYINT UNSIGNED PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  label VARCHAR(128) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO widget_action_types (id, code, label, sort_order) VALUES
  (1, 'text', 'Текст', 1),
  (2, 'survey', 'Опрос удовлетворённости', 2),
  (3, 'video', 'Видео', 3)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS widget_media_assets (
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

ALTER TABLE widget_events
  ADD COLUMN action_type_id TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER phrase,
  ADD COLUMN survey_title VARCHAR(512) NULL AFTER action_type_id,
  ADD COLUMN video_media_id INT UNSIGNED NULL AFTER survey_title,
  ADD COLUMN video_link_url VARCHAR(2048) NULL AFTER video_media_id,
  ADD CONSTRAINT fk_events_action_type FOREIGN KEY (action_type_id) REFERENCES widget_action_types(id),
  ADD CONSTRAINT fk_events_video_media FOREIGN KEY (video_media_id) REFERENCES widget_media_assets(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS widget_survey_ratings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  widget_event_id INT UNSIGNED NOT NULL,
  execution_id BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(64) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  page_url VARCHAR(2048) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (widget_event_id) REFERENCES widget_events(id) ON DELETE CASCADE,
  FOREIGN KEY (execution_id) REFERENCES widget_event_executions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_execution_rating (execution_id),
  INDEX idx_app_event_created (application_id, widget_event_id, created_at),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
