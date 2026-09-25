UPDATE plans
SET trial_price_cents = 99, updated_at = NOW()
WHERE trial_days > 0;
