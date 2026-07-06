-- 食事記録の推定信頼度を保存する
ALTER TABLE meal_entries
  ADD COLUMN confidence VARCHAR(10) NULL AFTER source_url;
