ALTER TABLE exercise_mets ADD COLUMN activity_code TEXT;
ALTER TABLE exercise_mets ADD COLUMN source TEXT NOT NULL DEFAULT 'manual';
ALTER TABLE exercise_mets ADD COLUMN source_url TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_exercise_mets_activity_code
ON exercise_mets (activity_code);
