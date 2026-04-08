ALTER TABLE widget_applications
  ADD COLUMN standard_behavior TINYINT(1) NOT NULL DEFAULT 0
  AFTER moderator_note;
