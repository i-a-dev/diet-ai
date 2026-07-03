-- 既存データは user_id = 0（未割当）。初回登録ユーザーに引き継ぐ。

CREATE TABLE user_profile_new (
  user_id INT PRIMARY KEY,
  gender VARCHAR(20) CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
  birth_date DATE,
  height_cm DECIMAL(5,1) CHECK (height_cm IS NULL OR (height_cm > 0 AND height_cm <= 300)),
  current_weight_kg DECIMAL(5,1) CHECK (current_weight_kg IS NULL OR (current_weight_kg > 0 AND current_weight_kg <= 300)),
  target_weight_kg DECIMAL(5,1) CHECK (target_weight_kg IS NULL OR (target_weight_kg > 0 AND target_weight_kg <= 300)),
  activity_level VARCHAR(20) CHECK (activity_level IS NULL OR activity_level IN ('sedentary', 'light', 'moderate', 'active', 'very_active')),
  target_pace_kg_per_month DECIMAL(4,1) CHECK (target_pace_kg_per_month IS NULL OR (target_pace_kg_per_month >= 0 AND target_pace_kg_per_month <= 20)),
  diet_goal VARCHAR(20) CHECK (diet_goal IS NULL OR diet_goal IN ('weight_loss', 'maintenance', 'muscle_gain', 'health')),
  dietary_restrictions TEXT,
  allergies_dislikes TEXT,
  past_diet_experience TEXT,
  desired_diet_method TEXT,
  coach_notes TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
RENAME TABLE user_profile_new TO user_profile;

CREATE TABLE weight_entries_new (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL DEFAULT 0,
  recorded_on DATE NOT NULL,
  weight_kg DECIMAL(5,1) NOT NULL CHECK (weight_kg > 0 AND weight_kg <= 300),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weight_entries_user_recorded (user_id, recorded_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO weight_entries_new (id, user_id, recorded_on, weight_kg, created_at, updated_at)
SELECT id, 0, recorded_on, weight_kg, created_at, updated_at FROM weight_entries;

DROP TABLE weight_entries;
RENAME TABLE weight_entries_new TO weight_entries;
CREATE INDEX idx_weight_entries_user_recorded ON weight_entries (user_id, recorded_on);

CREATE TABLE step_entries_new (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL DEFAULT 0,
  recorded_on DATE NOT NULL,
  step_count INT NOT NULL CHECK (step_count >= 0 AND step_count <= 100000),
  burned_calories_kcal INT NOT NULL CHECK (burned_calories_kcal >= 0 AND burned_calories_kcal <= 5000),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_step_entries_user_recorded (user_id, recorded_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO step_entries_new (id, user_id, recorded_on, step_count, burned_calories_kcal, created_at, updated_at)
SELECT id, 0, recorded_on, step_count, burned_calories_kcal, created_at, updated_at FROM step_entries;

DROP TABLE step_entries;
RENAME TABLE step_entries_new TO step_entries;
CREATE INDEX idx_step_entries_user_recorded ON step_entries (user_id, recorded_on);

ALTER TABLE meal_entries ADD COLUMN user_id INT NOT NULL DEFAULT 0;
CREATE INDEX idx_meal_entries_user_recorded ON meal_entries (user_id, recorded_on);

ALTER TABLE exercise_entries ADD COLUMN user_id INT NOT NULL DEFAULT 0;
CREATE INDEX idx_exercise_entries_user_recorded ON exercise_entries (user_id, recorded_on);

ALTER TABLE chat_messages ADD COLUMN user_id INT NOT NULL DEFAULT 0;
CREATE INDEX idx_chat_messages_user_created ON chat_messages (user_id, created_at);
