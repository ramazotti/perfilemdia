ALTER TABLE users
    ADD COLUMN phrase_place VARCHAR(16) NOT NULL DEFAULT 'rodape' AFTER phrase_color;
