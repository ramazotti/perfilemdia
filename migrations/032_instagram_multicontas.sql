ALTER TABLE instagram_accounts
    DROP FOREIGN KEY instagram_accounts_ibfk_1;

ALTER TABLE instagram_accounts
    DROP INDEX user_id;

ALTER TABLE instagram_accounts
    ADD KEY idx_instagram_user (user_id),
    ADD UNIQUE KEY uq_instagram_ig_user (ig_user_id),
    ADD UNIQUE KEY uq_instagram_user_ig (user_id, ig_user_id),
    ADD CONSTRAINT fk_instagram_accounts_user FOREIGN KEY (user_id) REFERENCES users (id);

ALTER TABLE users
    ADD COLUMN active_instagram_account_id BIGINT UNSIGNED NULL AFTER pending_action;

ALTER TABLE posts
    ADD COLUMN instagram_account_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD KEY idx_posts_user_ig (user_id, instagram_account_id, status);

UPDATE posts p
INNER JOIN instagram_accounts ia ON ia.user_id = p.user_id
SET p.instagram_account_id = ia.id
WHERE p.instagram_account_id IS NULL;

UPDATE users u
INNER JOIN instagram_accounts ia ON ia.user_id = u.id
SET u.active_instagram_account_id = ia.id
WHERE u.active_instagram_account_id IS NULL;

ALTER TABLE users
    ADD CONSTRAINT fk_users_active_ig
        FOREIGN KEY (active_instagram_account_id) REFERENCES instagram_accounts (id) ON DELETE SET NULL;

CREATE TABLE instagram_profiles (
    instagram_account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tone ENUM('profissional', 'descontraido', 'tecnico', 'acolhedor') NULL,
    contact_cta VARCHAR(255) NULL,
    fixed_hashtags VARCHAR(255) NULL,
    about VARCHAR(500) NULL,
    profession VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_instagram_profiles_account
        FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO instagram_profiles (instagram_account_id, tone, contact_cta, fixed_hashtags, about, profession, city)
SELECT ia.id, u.tone, u.contact_cta, u.fixed_hashtags, u.about, u.profession, u.city
FROM instagram_accounts ia
INNER JOIN users u ON u.id = ia.user_id;
