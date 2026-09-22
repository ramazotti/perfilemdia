ALTER TABLE plans
    ADD COLUMN trial_days TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER posts_limit,
    ADD COLUMN trial_price_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER trial_days;

ALTER TABLE subscriptions
    ADD COLUMN period_kind ENUM('teste', 'cheio') NOT NULL DEFAULT 'cheio' AFTER cycle,
    ADD COLUMN posts_limit INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents,
    ADD COLUMN period_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER posts_limit,
    ADD COLUMN period_started_at DATETIME NULL AFTER current_period_end;

UPDATE plans SET trial_days = 3, trial_price_cents = 299 WHERE slug = 'essencial';
UPDATE plans SET trial_days = 3, trial_price_cents = 499 WHERE slug = 'profissional';
