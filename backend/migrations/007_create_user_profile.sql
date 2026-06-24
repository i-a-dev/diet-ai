-- ユーザープロフィール（単一ユーザー想定・1行のみ）
CREATE TABLE IF NOT EXISTS user_profile (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  target_weight_kg REAL CHECK (target_weight_kg IS NULL OR (target_weight_kg > 0 AND target_weight_kg <= 300)),
  height_cm REAL CHECK (height_cm IS NULL OR (height_cm > 0 AND height_cm <= 300)),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO user_profile (id, target_weight_kg, height_cm, updated_at)
VALUES (1, 57.0, NULL, datetime('now'));
