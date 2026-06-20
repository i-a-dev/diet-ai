-- 食事記録テーブル（1日に複数件登録可能）
CREATE TABLE IF NOT EXISTS meal_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT, -- 主キー
  recorded_on TEXT NOT NULL, -- 記録日（YYYY-MM-DD）
  meal_type TEXT NOT NULL CHECK (meal_type IN ('breakfast', 'lunch', 'dinner', 'snack')), -- 食事区分
  food_name TEXT NOT NULL, -- 食品名
  calories_kcal INTEGER NOT NULL CHECK (calories_kcal > 0 AND calories_kcal <= 5000), -- カロリー(kcal)
  created_at TEXT NOT NULL DEFAULT (datetime('now')), -- 登録日時
  updated_at TEXT NOT NULL DEFAULT (datetime('now')) -- 更新日時
);

-- 日付と食事区分での絞り込みを高速化
CREATE INDEX IF NOT EXISTS idx_meal_entries_recorded_on_meal_type
ON meal_entries (recorded_on, meal_type);
