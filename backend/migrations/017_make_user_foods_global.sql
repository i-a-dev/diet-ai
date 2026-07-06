-- user_foods をユーザー単位から全ユーザー共有に変更
DELETE t1
FROM user_foods t1
INNER JOIN user_foods t2
  ON t1.display_name = t2.display_name
 AND t1.id < t2.id;

ALTER TABLE user_foods DROP INDEX uq_user_foods_display_name;
ALTER TABLE user_foods DROP INDEX idx_user_foods_user_id;
ALTER TABLE user_foods DROP INDEX idx_user_foods_name;
ALTER TABLE user_foods DROP COLUMN user_id;
ALTER TABLE user_foods ADD UNIQUE KEY uq_user_foods_display_name (display_name);
CREATE INDEX idx_user_foods_name ON user_foods (name);
