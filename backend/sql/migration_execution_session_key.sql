-- Учёт показа по сессии браузера: разные посетители не блокируют друг друга на одном событии.
-- После бэкапа.

ALTER TABLE widget_event_executions
  ADD COLUMN session_key VARCHAR(64) NOT NULL DEFAULT 'legacy' AFTER kind,
  ADD INDEX idx_event_session (widget_event_id, session_key);

UPDATE widget_event_executions
SET session_key = CONCAT('mig_', id)
WHERE session_key = 'legacy' AND completed_at IS NULL;
