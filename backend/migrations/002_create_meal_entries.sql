-- 食事記録テーブル（1日に複数件登録可能）
CREATE TABLE IF NOT EXISTS meal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recorded_on DATE NOT NULL,
  meal_type VARCHAR(20) NOT NULL CHECK (meal_type IN ('breakfast', 'lunch', 'dinner', 'snack')),
  food_name VARCHAR(255) NOT NULL,
  calories_kcal INT NOT NULL CHECK (calories_kcal > 0 AND calories_kcal <= 5000),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE INDEX idx_meal_entries_recorded_on_meal_type
ON meal_entries (recorded_on, meal_type);
