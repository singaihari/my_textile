-- 002: product and variant images (relative paths under public/, e.g. uploads/products/abc.webp)
ALTER TABLE products ADD COLUMN image_path TEXT;
ALTER TABLE product_variants ADD COLUMN image_path TEXT;
