ALTER TABLE exercise_mets ADD COLUMN activity_code VARCHAR(50);
ALTER TABLE exercise_mets ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT 'manual';
ALTER TABLE exercise_mets ADD COLUMN source_url TEXT;

CREATE UNIQUE INDEX idx_exercise_mets_activity_code
ON exercise_mets (activity_code);
