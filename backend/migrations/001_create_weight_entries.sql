CREATE TABLE IF NOT EXISTS weight_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recorded_on TEXT NOT NULL UNIQUE,
  weight_kg REAL NOT NULL CHECK (weight_kg > 0 AND weight_kg <= 300),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_weight_entries_recorded_on ON weight_entries (recorded_on);
