-- 体重記録テーブル（1日1件）
CREATE TABLE IF NOT EXISTS weight_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recorded_on DATE NOT NULL,
  weight_kg DECIMAL(5,1) NOT NULL CHECK (weight_kg > 0 AND weight_kg <= 300),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weight_entries_recorded_on (recorded_on)
);

CREATE INDEX idx_weight_entries_recorded_on ON weight_entries (recorded_on);
