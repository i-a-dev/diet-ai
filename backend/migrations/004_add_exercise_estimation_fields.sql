-- 運動METsローカル辞書テーブル（運動名の完全一致に利用）
CREATE TABLE IF NOT EXISTS exercise_mets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exercise_name TEXT NOT NULL UNIQUE,
  mets REAL NOT NULL CHECK (mets > 0 AND mets <= 25),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_exercise_mets_name
ON exercise_mets (exercise_name);

INSERT OR IGNORE INTO exercise_mets (exercise_name, mets) VALUES
  ('ウォーキング', 3.5),
  ('ランニング', 8.3),
  ('ジョギング', 7.0),
  ('ストレッチ', 2.3),
  ('ヨガ', 2.8),
  ('スクワット', 5.0),
  ('腹筋', 3.8),
  ('縄跳び', 11.0),
  ('サイクリング', 6.8),
  ('階段上り', 8.8);

-- 既存exercise_entriesへの推定関連列を追加（存在しない場合のみ）
ALTER TABLE exercise_entries ADD COLUMN minutes INTEGER NOT NULL DEFAULT 0;
ALTER TABLE exercise_entries ADD COLUMN mets REAL NOT NULL DEFAULT 4.0;
ALTER TABLE exercise_entries ADD COLUMN source TEXT NOT NULL DEFAULT 'local_db';
ALTER TABLE exercise_entries ADD COLUMN confidence TEXT NOT NULL DEFAULT 'high';
ALTER TABLE exercise_entries ADD COLUMN is_estimated INTEGER NOT NULL DEFAULT 0;
ALTER TABLE exercise_entries ADD COLUMN estimate_note TEXT;

-- 既存データを初期補完
UPDATE exercise_entries
SET minutes = CASE
  WHEN unit = 'min' THEN amount
  ELSE 5
END
WHERE minutes <= 0;
