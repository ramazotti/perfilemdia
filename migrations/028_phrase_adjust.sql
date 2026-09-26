ALTER TABLE users
    ADD COLUMN phrase_size VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER phrase_place;

ALTER TABLE posts
    ADD COLUMN photo_phrase VARCHAR(80) NULL AFTER theme_text;
