-- Стандартное приветствие = событие _standard (как остальные ключи).
-- После бэкапа. Создаёт строку события и переносит fairy.standard_behavior → fairy_events.

INSERT INTO widget_events (application_id, event_key, phrase)
SELECT a.id, '_standard', 'Привет! Я фея виджета.'
FROM widget_applications a
WHERE a.status = 'approved'
  AND NOT EXISTS (
    SELECT 1 FROM widget_events e
    WHERE e.application_id = a.id AND e.event_key = '_standard'
  );

INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id)
SELECT f.id, e.id
FROM widget_fairies f
JOIN widget_events e ON e.application_id = f.application_id AND e.event_key = '_standard'
WHERE f.standard_behavior = 1;
