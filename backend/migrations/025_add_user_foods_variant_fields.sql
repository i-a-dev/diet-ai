-- user_foods にサイズ・容量などのバリアント情報を追加
ALTER TABLE user_foods
  ADD COLUMN brand_name VARCHAR(255) NULL AFTER source_url,
  ADD COLUMN base_product_name VARCHAR(255) NULL AFTER brand_name,
  ADD COLUMN variant_label VARCHAR(100) NULL AFTER base_product_name,
  ADD COLUMN package_size VARCHAR(100) NULL AFTER variant_label,
  ADD COLUMN serving_weight_g DECIMAL(10,2) NULL AFTER package_size;
