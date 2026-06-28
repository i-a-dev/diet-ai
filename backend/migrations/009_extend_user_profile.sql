-- ユーザープロフィールの拡張（性別・生年月日・活動レベルなど）
ALTER TABLE user_profile ADD COLUMN gender TEXT CHECK (gender IS NULL OR gender IN ('male', 'female', 'other'));
ALTER TABLE user_profile ADD COLUMN birth_date TEXT;
ALTER TABLE user_profile ADD COLUMN current_weight_kg REAL CHECK (current_weight_kg IS NULL OR (current_weight_kg > 0 AND current_weight_kg <= 300));
ALTER TABLE user_profile ADD COLUMN activity_level TEXT CHECK (activity_level IS NULL OR activity_level IN ('sedentary', 'light', 'moderate', 'active', 'very_active'));
ALTER TABLE user_profile ADD COLUMN target_pace_kg_per_month REAL CHECK (target_pace_kg_per_month IS NULL OR (target_pace_kg_per_month >= 0 AND target_pace_kg_per_month <= 20));
ALTER TABLE user_profile ADD COLUMN diet_goal TEXT CHECK (diet_goal IS NULL OR diet_goal IN ('weight_loss', 'maintenance', 'muscle_gain', 'health'));
ALTER TABLE user_profile ADD COLUMN dietary_restrictions TEXT;
ALTER TABLE user_profile ADD COLUMN allergies_dislikes TEXT;
ALTER TABLE user_profile ADD COLUMN past_diet_experience TEXT;
