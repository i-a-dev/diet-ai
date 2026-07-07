-- 検索語と user_foods の紐づけ（学習用エイリアス）
CREATE TABLE IF NOT EXISTS food_search_aliases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  query_normalized VARCHAR(255) NOT NULL,
  raw_query_sample VARCHAR(255) NOT NULL,
  food_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  selection_count INT UNSIGNED NOT NULL DEFAULT 1,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  confidence_score DECIMAL(5, 4) NOT NULL DEFAULT 0.5000,
  source VARCHAR(50) NOT NULL DEFAULT 'user_selected',
  last_selected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alias_query_food (query_normalized, food_id),
  INDEX idx_alias_query (query_normalized),
  INDEX idx_alias_food_id (food_id),
  INDEX idx_alias_selection (query_normalized, selection_count),
  CONSTRAINT fk_alias_food FOREIGN KEY (food_id) REFERENCES user_foods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
