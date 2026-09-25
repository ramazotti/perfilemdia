ALTER TABLE users
    MODIFY idea_daily TINYINT(1) NOT NULL DEFAULT 1;

UPDATE users
SET idea_daily = 1
WHERE status = 'active' AND onboarding_step = 'done' AND idea_daily = 0;
