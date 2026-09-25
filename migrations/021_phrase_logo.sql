ALTER TABLE users
    ADD COLUMN phrase_style VARCHAR(16) NOT NULL DEFAULT 'classica' AFTER idea_text,
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER phrase_style;
