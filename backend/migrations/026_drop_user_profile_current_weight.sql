-- プロフィールの現在体重は体重記録（weight_entries）に一本化するため削除する
ALTER TABLE user_profile DROP COLUMN current_weight_kg;
