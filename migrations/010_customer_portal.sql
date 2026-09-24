CREATE TABLE customer_access_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_access_hash (token_hash),
    KEY idx_customer_access_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subscriptions
    ADD COLUMN cancel_at DATETIME NULL AFTER current_period_end,
    ADD COLUMN next_plan_id INT UNSIGNED NULL AFTER cancel_at,
    ADD COLUMN next_cycle ENUM('mensal', 'anual') NULL AFTER next_plan_id;

ALTER TABLE subscriptions
    MODIFY renew_token VARCHAR(255) NULL;
