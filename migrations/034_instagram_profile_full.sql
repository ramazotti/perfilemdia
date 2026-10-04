ALTER TABLE instagram_profiles
    ADD COLUMN display_name VARCHAR(120) NULL AFTER instagram_account_id,
    ADD COLUMN brand_style VARCHAR(160) NULL AFTER city,
    ADD COLUMN phrase_style VARCHAR(16) NOT NULL DEFAULT 'classica' AFTER brand_style,
    ADD COLUMN phrase_color VARCHAR(16) NULL AFTER phrase_style,
    ADD COLUMN phrase_place VARCHAR(16) NULL AFTER phrase_color,
    ADD COLUMN phrase_size VARCHAR(16) NULL AFTER phrase_place,
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER phrase_size;

UPDATE instagram_profiles ip
INNER JOIN instagram_accounts ia ON ia.id = ip.instagram_account_id
INNER JOIN users u ON u.id = ia.user_id
SET
    ip.display_name = COALESCE(ip.display_name, u.display_name),
    ip.brand_style = COALESCE(ip.brand_style, u.brand_style),
    ip.phrase_style = CASE WHEN ip.phrase_style = 'classica' THEN COALESCE(NULLIF(u.phrase_style, ''), 'classica') ELSE ip.phrase_style END,
    ip.phrase_color = COALESCE(ip.phrase_color, u.phrase_color),
    ip.phrase_place = COALESCE(ip.phrase_place, u.phrase_place),
    ip.phrase_size = COALESCE(ip.phrase_size, u.phrase_size),
    ip.logo_path = COALESCE(ip.logo_path, u.logo_path);
