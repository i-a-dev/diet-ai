-- 食事記録でカロリーを手入力・編集して登録したかどうか
ALTER TABLE meal_entries ADD COLUMN calories_edited INTEGER NOT NULL DEFAULT 0 CHECK (calories_edited IN (0, 1));
