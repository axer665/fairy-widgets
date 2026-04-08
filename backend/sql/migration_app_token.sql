-- Один токен доступа на заявку (URL сайта); феи без собственного токена.
-- Выполните после migration_fairies.sql, если у фей ещё были widget_token.

ALTER TABLE widget_applications
  ADD COLUMN widget_token VARCHAR(64) NULL UNIQUE AFTER status;

UPDATE widget_applications a
SET widget_token = (
  SELECT f.widget_token FROM widget_fairies f
  WHERE f.application_id = a.id
  ORDER BY f.id ASC LIMIT 1
)
WHERE a.status = 'approved'
  AND a.widget_token IS NULL
  AND EXISTS (SELECT 1 FROM widget_fairies f2 WHERE f2.application_id = a.id);

ALTER TABLE widget_fairies DROP COLUMN widget_token;
