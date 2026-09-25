ALTER TABLE customers
    ADD COLUMN ai_video TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE posts
    ADD COLUMN video_job_id VARCHAR(191) NULL AFTER creative,
    ADD COLUMN video_seconds TINYINT UNSIGNED NULL AFTER video_job_id;
