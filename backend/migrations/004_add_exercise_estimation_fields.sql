-- 運動METsローカル辞書テーブル（運動名の完全一致に利用）
CREATE TABLE IF NOT EXISTS exercise_mets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exercise_name VARCHAR(255) NOT NULL,
  mets DECIMAL(4,1) NOT NULL CHECK (mets > 0 AND mets <= 25),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exercise_mets_name (exercise_name)
);

CREATE INDEX idx_exercise_mets_name
ON exercise_mets (exercise_name);

INSERT IGNORE INTO exercise_mets (exercise_name, mets) VALUES
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

ALTER TABLE exercise_entries ADD COLUMN minutes INT NOT NULL DEFAULT 0;
ALTER TABLE exercise_entries ADD COLUMN mets DECIMAL(4,1) NOT NULL DEFAULT 4.0;
ALTER TABLE exercise_entries ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT 'local_db';
ALTER TABLE exercise_entries ADD COLUMN confidence VARCHAR(20) NOT NULL DEFAULT 'high';
ALTER TABLE exercise_entries ADD COLUMN is_estimated TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE exercise_entries ADD COLUMN estimate_note TEXT;

UPDATE exercise_entries
SET minutes = CASE
  WHEN unit = 'min' THEN amount
  ELSE 5
END
WHERE minutes <= 0;
