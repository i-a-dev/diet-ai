ALTER TABLE exercise_entries ADD COLUMN weight_kg DECIMAL(5,1) NOT NULL DEFAULT 60.0;
ALTER TABLE exercise_entries ADD COLUMN weight_source VARCHAR(50) NOT NULL DEFAULT 'default';
