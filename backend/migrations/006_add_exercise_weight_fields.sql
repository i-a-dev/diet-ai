ALTER TABLE exercise_entries ADD COLUMN weight_kg REAL NOT NULL DEFAULT 60.0;
ALTER TABLE exercise_entries ADD COLUMN weight_source TEXT NOT NULL DEFAULT 'default';
