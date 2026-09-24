ALTER TABLE posts
    ADD COLUMN scheduled_at DATETIME NULL AFTER published_at,
    ADD INDEX idx_posts_scheduled (status, scheduled_at);
