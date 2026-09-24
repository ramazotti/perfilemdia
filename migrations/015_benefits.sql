ALTER TABLE users
    ADD COLUMN brand_style VARCHAR(160) NULL AFTER about,
    ADD COLUMN idea_daily TINYINT(1) NOT NULL DEFAULT 0 AFTER brand_style,
    ADD COLUMN idea_sent_on DATE NULL AFTER idea_daily,
    ADD COLUMN idea_text VARCHAR(240) NULL AFTER idea_sent_on;

ALTER TABLE posts
    ADD COLUMN destination VARCHAR(12) NOT NULL DEFAULT 'feed' AFTER creative;
