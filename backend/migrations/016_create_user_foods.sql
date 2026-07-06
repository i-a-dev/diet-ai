-- Web 検索などで登録した食品マスタ（017 で全ユーザー共有に変更）
CREATE TABLE IF NOT EXISTS user_foods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  amount DECIMAL(10, 2) NOT NULL DEFAULT 1,
  unit VARCHAR(20) NOT NULL DEFAULT '食',
  calories_kcal INT NOT NULL CHECK (calories_kcal > 0 AND calories_kcal <= 5000),
  source VARCHAR(50) NOT NULL DEFAULT 'ai_web_search',
  raw_input VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_foods_display_name (user_id, display_name),
  INDEX idx_user_foods_user_id (user_id),
  INDEX idx_user_foods_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
