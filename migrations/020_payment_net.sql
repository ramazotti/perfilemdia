ALTER TABLE payments
    ADD COLUMN net_cents INT UNSIGNED NULL AFTER amount_cents;
