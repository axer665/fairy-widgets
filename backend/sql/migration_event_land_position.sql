-- MySQL 8+ only. docker compose exec -T mysql mysql -uwidget -pwidget_secret widget_app < backend/sql/migration_event_land_position.sql
-- Позиция приземления феи для показа текста (на событие).
ALTER TABLE widget_events
  ADD COLUMN pos_h_edge ENUM('left', 'right') NOT NULL DEFAULT 'right' AFTER phrase,
  ADD COLUMN pos_v_edge ENUM('top', 'bottom') NOT NULL DEFAULT 'bottom' AFTER pos_h_edge,
  ADD COLUMN pos_unit ENUM('px', 'percent') NOT NULL DEFAULT 'px' AFTER pos_v_edge,
  ADD COLUMN pos_x DECIMAL(8, 2) NOT NULL DEFAULT 150.00 AFTER pos_unit,
  ADD COLUMN pos_y DECIMAL(8, 2) NOT NULL DEFAULT 130.00 AFTER pos_x;
