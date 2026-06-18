-- 体重記録テーブル（1日1件）
CREATE TABLE IF NOT EXISTS weight_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,           -- 主キー
  recorded_on TEXT NOT NULL UNIQUE,               -- 記録日（YYYY-MM-DD）
  weight_kg REAL NOT NULL CHECK (weight_kg > 0 AND weight_kg <= 300),  -- 体重（kg）
  created_at TEXT NOT NULL DEFAULT (datetime('now')),  -- 登録日時
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))   -- 更新日時
);

-- 日付での検索を高速化するインデックス
CREATE INDEX IF NOT EXISTS idx_weight_entries_recorded_on ON weight_entries (recorded_on);
