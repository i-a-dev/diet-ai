-- 既存データは user_id = 0（未割当）。初回登録ユーザーに引き継ぐ。

-- user_profile: id=1 固定を廃止し user_id を主キーにする
CREATE TABLE user_profile_new (
  user_id INTEGER PRIMARY KEY,
  gender TEXT CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
  birth_date TEXT,
  height_cm REAL CHECK (height_cm IS NULL OR (height_cm > 0 AND height_cm <= 300)),
  current_weight_kg REAL CHECK (current_weight_kg IS NULL OR (current_weight_kg > 0 AND current_weight_kg <= 300)),
  target_weight_kg REAL CHECK (target_weight_kg IS NULL OR (target_weight_kg > 0 AND target_weight_kg <= 300)),
  activity_level TEXT CHECK (activity_level IS NULL OR activity_level IN ('sedentary', 'light', 'moderate', 'active', 'very_active')),
  target_pace_kg_per_month REAL CHECK (target_pace_kg_per_month IS NULL OR (target_pace_kg_per_month >= 0 AND target_pace_kg_per_month <= 20)),
  diet_goal TEXT CHECK (diet_goal IS NULL OR diet_goal IN ('weight_loss', 'maintenance', 'muscle_gain', 'health')),
  dietary_restrictions TEXT,
  allergies_dislikes TEXT,
  past_diet_experience TEXT,
  desired_diet_method TEXT,
  coach_notes TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO user_profile_new (
  user_id, gender, birth_date, height_cm, current_weight_kg, target_weight_kg,
  activity_level, target_pace_kg_per_month, diet_goal, dietary_restrictions,
  allergies_dislikes, past_diet_experience, desired_diet_method, coach_notes, updated_at
)
SELECT
  0, gender, birth_date, height_cm, current_weight_kg, target_weight_kg,
  activity_level, target_pace_kg_per_month, diet_goal, dietary_restrictions,
  allergies_dislikes, past_diet_experience, desired_diet_method, coach_notes, updated_at
FROM user_profile
WHERE id = 1;

DROP TABLE user_profile;
ALTER TABLE user_profile_new RENAME TO user_profile;

-- weight_entries: ユーザーごとに1日1件
CREATE TABLE weight_entries_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 0,
  recorded_on TEXT NOT NULL,
  weight_kg REAL NOT NULL CHECK (weight_kg > 0 AND weight_kg <= 300),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, recorded_on)
);

INSERT INTO weight_entries_new (id, user_id, recorded_on, weight_kg, created_at, updated_at)
SELECT id, 0, recorded_on, weight_kg, created_at, updated_at FROM weight_entries;

DROP TABLE weight_entries;
ALTER TABLE weight_entries_new RENAME TO weight_entries;
CREATE INDEX IF NOT EXISTS idx_weight_entries_user_recorded ON weight_entries (user_id, recorded_on);

-- step_entries: ユーザーごとに1日1件
CREATE TABLE step_entries_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 0,
  recorded_on TEXT NOT NULL,
  step_count INTEGER NOT NULL CHECK (step_count >= 0 AND step_count <= 100000),
  burned_calories_kcal INTEGER NOT NULL CHECK (burned_calories_kcal >= 0 AND burned_calories_kcal <= 5000),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, recorded_on)
);

INSERT INTO step_entries_new (id, user_id, recorded_on, step_count, burned_calories_kcal, created_at, updated_at)
SELECT id, 0, recorded_on, step_count, burned_calories_kcal, created_at, updated_at FROM step_entries;

DROP TABLE step_entries;
ALTER TABLE step_entries_new RENAME TO step_entries;
CREATE INDEX IF NOT EXISTS idx_step_entries_user_recorded ON step_entries (user_id, recorded_on);

-- meal_entries / exercise_entries / chat_messages
ALTER TABLE meal_entries ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_meal_entries_user_recorded ON meal_entries (user_id, recorded_on);

ALTER TABLE exercise_entries ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_exercise_entries_user_recorded ON exercise_entries (user_id, recorded_on);

ALTER TABLE chat_messages ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_chat_messages_user_created ON chat_messages (user_id, created_at);
