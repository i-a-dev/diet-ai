-- 食事記録のカロリー取得元を保存する
ALTER TABLE meal_entries
  ADD COLUMN calorie_source VARCHAR(50) NULL AFTER calories_edited,
  ADD COLUMN source_url VARCHAR(2048) NULL AFTER calorie_source;
