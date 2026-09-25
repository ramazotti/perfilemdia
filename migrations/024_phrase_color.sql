ALTER TABLE users
    ADD COLUMN phrase_color VARCHAR(16) NOT NULL DEFAULT 'branco' AFTER phrase_style;
