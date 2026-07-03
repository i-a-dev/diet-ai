-- 歩数記録テーブル（1日1件）
CREATE TABLE IF NOT EXISTS step_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recorded_on DATE NOT NULL,
  step_count INT NOT NULL CHECK (step_count >= 0 AND step_count <= 100000),
  burned_calories_kcal INT NOT NULL CHECK (burned_calories_kcal >= 0 AND burned_calories_kcal <= 5000),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_step_entries_recorded_on (recorded_on)
);

CREATE INDEX idx_step_entries_recorded_on
ON step_entries (recorded_on);

-- 運動記録テーブル（1日に複数件登録可能）
CREATE TABLE IF NOT EXISTS exercise_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recorded_on DATE NOT NULL,
  exercise_name VARCHAR(255) NOT NULL,
  amount INT NOT NULL CHECK (amount > 0 AND amount <= 10000),
  unit VARCHAR(10) NOT NULL CHECK (unit IN ('min', 'rep')),
  burned_calories_kcal INT NOT NULL CHECK (burned_calories_kcal > 0 AND burned_calories_kcal <= 5000),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE INDEX idx_exercise_entries_recorded_on
ON exercise_entries (recorded_on);
