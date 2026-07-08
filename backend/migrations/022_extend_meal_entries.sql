-- meal_entries に食品参照・入力内容・分量・PFC を追加
ALTER TABLE meal_entries
  ADD COLUMN food_id INT UNSIGNED NULL AFTER confidence,
  ADD COLUMN raw_input VARCHAR(255) NULL AFTER food_id,
  ADD COLUMN amount DECIMAL(10, 2) NULL AFTER raw_input,
  ADD COLUMN unit VARCHAR(20) NULL AFTER amount,
  ADD COLUMN serving_label VARCHAR(100) NULL AFTER unit,
  ADD COLUMN serving_weight_g DECIMAL(10, 2) NULL AFTER serving_label,
  ADD COLUMN protein_g DECIMAL(8, 2) NULL AFTER serving_weight_g,
  ADD COLUMN fat_g DECIMAL(8, 2) NULL AFTER protein_g,
  ADD COLUMN carbs_g DECIMAL(8, 2) NULL AFTER fat_g,
  ADD COLUMN fiber_g DECIMAL(8, 2) NULL AFTER carbs_g,
  ADD COLUMN sodium_mg DECIMAL(10, 2) NULL AFTER fiber_g,
  ADD INDEX idx_meal_entries_food_id (food_id);
