-- MySQL 8+. Раздельные виджеты (текст / опрос / видео), привязка к событиям, статистика.
-- docker compose exec -T mysql mysql -uwidget -pwidget_secret widget_app < backend/sql/migration_widget_content_entities.sql

CREATE TABLE IF NOT EXISTS widget_text_widgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES widget_applications(id) ON DELETE CASCADE,
  INDEX idx_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widget_survey_widgets (
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

CREATE TABLE IF NOT EXISTS widget_video_widgets (
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

ALTER TABLE widget_events
  ADD COLUMN text_widget_id INT UNSIGNED NULL AFTER action_type_id,
  ADD COLUMN survey_widget_id INT UNSIGNED NULL AFTER text_widget_id,
  ADD COLUMN video_widget_id INT UNSIGNED NULL AFTER survey_widget_id;

ALTER TABLE widget_events
  ADD CONSTRAINT fk_events_text_widget FOREIGN KEY (text_widget_id) REFERENCES widget_text_widgets(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_events_survey_widget FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_events_video_widget FOREIGN KEY (video_widget_id) REFERENCES widget_video_widgets(id) ON DELETE SET NULL;

INSERT INTO widget_text_widgets (application_id, name, body)
SELECT we.application_id, CONCAT('Событие ', we.event_key), we.phrase
FROM widget_events we
WHERE we.action_type_id = 1
  AND NOT EXISTS (
    SELECT 1 FROM widget_text_widgets tw
    WHERE tw.application_id = we.application_id AND tw.name = CONCAT('Событие ', we.event_key)
  );

UPDATE widget_events we
INNER JOIN widget_text_widgets tw
  ON tw.application_id = we.application_id AND tw.name = CONCAT('Событие ', we.event_key)
SET we.text_widget_id = tw.id
WHERE we.action_type_id = 1 AND we.text_widget_id IS NULL;

INSERT INTO widget_survey_widgets (application_id, name, title, description)
SELECT we.application_id, CONCAT('Событие ', we.event_key), we.survey_title,
  CASE WHEN we.phrase IS NOT NULL AND we.phrase <> '' AND we.phrase <> we.survey_title THEN we.phrase ELSE NULL END
FROM widget_events we
WHERE we.action_type_id = 2 AND we.survey_title IS NOT NULL AND we.survey_title <> ''
  AND NOT EXISTS (
    SELECT 1 FROM widget_survey_widgets sw
    WHERE sw.application_id = we.application_id AND sw.name = CONCAT('Событие ', we.event_key)
  );

UPDATE widget_events we
INNER JOIN widget_survey_widgets sw
  ON sw.application_id = we.application_id AND sw.name = CONCAT('Событие ', we.event_key)
SET we.survey_widget_id = sw.id
WHERE we.action_type_id = 2 AND we.survey_widget_id IS NULL;

INSERT INTO widget_video_widgets (application_id, name, media_id, link_url)
SELECT we.application_id, CONCAT('Событие ', we.event_key), we.video_media_id, we.video_link_url
FROM widget_events we
WHERE we.action_type_id = 3 AND we.video_media_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM widget_video_widgets vw
    WHERE vw.application_id = we.application_id AND vw.name = CONCAT('Событие ', we.event_key)
  );

UPDATE widget_events we
INNER JOIN widget_video_widgets vw
  ON vw.application_id = we.application_id AND vw.name = CONCAT('Событие ', we.event_key)
SET we.video_widget_id = vw.id
WHERE we.action_type_id = 3 AND we.video_widget_id IS NULL;

CREATE TABLE IF NOT EXISTS widget_text_impressions (
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

CREATE TABLE IF NOT EXISTS widget_survey_impressions (
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

CREATE TABLE IF NOT EXISTS widget_survey_cancellations (
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

CREATE TABLE IF NOT EXISTS widget_video_impressions (
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

CREATE TABLE IF NOT EXISTS widget_video_sessions (
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

ALTER TABLE widget_survey_ratings
  ADD COLUMN survey_widget_id INT UNSIGNED NULL AFTER widget_event_id;

UPDATE widget_survey_ratings r
INNER JOIN widget_events we ON we.id = r.widget_event_id
SET r.survey_widget_id = we.survey_widget_id
WHERE we.survey_widget_id IS NOT NULL AND r.survey_widget_id IS NULL;

ALTER TABLE widget_survey_ratings
  ADD CONSTRAINT fk_rating_survey_widget FOREIGN KEY (survey_widget_id) REFERENCES widget_survey_widgets(id) ON DELETE SET NULL;

ALTER TABLE widget_events
  DROP FOREIGN KEY fk_events_video_media;

ALTER TABLE widget_events
  DROP COLUMN phrase,
  DROP COLUMN survey_title,
  DROP COLUMN video_media_id,
  DROP COLUMN video_link_url;
