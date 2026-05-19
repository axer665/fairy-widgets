-- MySQL 8+. Длительность видео, таймеры ухода феи для видео и опросов.
-- docker compose exec -T mysql mysql -uwidget -pwidget_secret widget_app < backend/sql/migration_widget_timing.sql

ALTER TABLE widget_media_assets
  ADD COLUMN duration_ms INT UNSIGNED NULL AFTER size_bytes;

ALTER TABLE widget_survey_widgets
  ADD COLUMN dismiss_after_ms INT UNSIGNED NULL AFTER description;

ALTER TABLE widget_video_widgets
  ADD COLUMN leave_mode ENUM('video_end', 'timer') NOT NULL DEFAULT 'video_end' AFTER link_url,
  ADD COLUMN leave_timer_ms INT UNSIGNED NULL AFTER leave_mode;
