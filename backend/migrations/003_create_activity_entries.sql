-- 歩数記録テーブル（1日1件）
CREATE TABLE IF NOT EXISTS step_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recorded_on TEXT NOT NULL UNIQUE, -- 記録日（YYYY-MM-DD）
  step_count INTEGER NOT NULL CHECK (step_count >= 0 AND step_count <= 100000),
  burned_calories_kcal INTEGER NOT NULL CHECK (burned_calories_kcal >= 0 AND burned_calories_kcal <= 5000),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_step_entries_recorded_on
ON step_entries (recorded_on);

-- 運動記録テーブル（1日に複数件登録可能）
CREATE TABLE IF NOT EXISTS exercise_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recorded_on TEXT NOT NULL, -- 記録日（YYYY-MM-DD）
  exercise_name TEXT NOT NULL,
  amount INTEGER NOT NULL CHECK (amount > 0 AND amount <= 10000),
  unit TEXT NOT NULL CHECK (unit IN ('min', 'rep')),
  burned_calories_kcal INTEGER NOT NULL CHECK (burned_calories_kcal > 0 AND burned_calories_kcal <= 5000),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_exercise_entries_recorded_on
ON exercise_entries (recorded_on);
