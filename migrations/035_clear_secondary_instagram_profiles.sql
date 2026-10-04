-- Migração 034 preencheu perfis vazios a partir de users; em multicontas a 2ª @ ficou igual à 1ª.
UPDATE instagram_profiles ip
INNER JOIN instagram_accounts ia ON ia.id = ip.instagram_account_id
INNER JOIN users u ON u.id = ia.user_id
INNER JOIN (
    SELECT user_id, MIN(id) AS keep_id
    FROM instagram_accounts
    GROUP BY user_id
    HAVING COUNT(*) > 1
) multi ON multi.user_id = ia.user_id
SET
    ip.display_name = NULL,
    ip.profession = NULL,
    ip.city = NULL,
    ip.tone = NULL,
    ip.contact_cta = NULL,
    ip.fixed_hashtags = NULL,
    ip.about = NULL,
    ip.brand_style = NULL,
    ip.phrase_color = NULL,
    ip.phrase_place = NULL,
    ip.phrase_size = NULL,
    ip.logo_path = NULL,
    ip.phrase_style = 'classica'
WHERE ia.id <> multi.keep_id
  AND ip.about <=> u.about;
