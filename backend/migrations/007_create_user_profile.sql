-- ユーザープロフィール（単一ユーザー想定・1行のみ）
CREATE TABLE IF NOT EXISTS user_profile (
  id INT PRIMARY KEY CHECK (id = 1),
  target_weight_kg DECIMAL(5,1) CHECK (target_weight_kg IS NULL OR (target_weight_kg > 0 AND target_weight_kg <= 300)),
  height_cm DECIMAL(5,1) CHECK (height_cm IS NULL OR (height_cm > 0 AND height_cm <= 300)),
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO user_profile (id, target_weight_kg, height_cm, updated_at)
VALUES (1, 57.0, NULL, NOW());
