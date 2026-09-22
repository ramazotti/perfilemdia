ALTER TABLE subscriptions
    ADD COLUMN renew_method ENUM('pix', 'cartao', 'cupom') NULL AFTER period_started_at,
    ADD COLUMN renew_token VARCHAR(120) NULL AFTER renew_method,
    ADD COLUMN renew_brand VARCHAR(20) NULL AFTER renew_token,
    ADD COLUMN renew_last4 CHAR(4) NULL AFTER renew_brand;
