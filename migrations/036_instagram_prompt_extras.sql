CREATE TABLE instagram_prompt_extras (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instagram_account_id BIGINT UNSIGNED NOT NULL,
    trigger_word VARCHAR(64) NOT NULL,
    prompt_text TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prompt_extra_trigger (instagram_account_id, trigger_word),
    KEY idx_prompt_extra_account (instagram_account_id),
    CONSTRAINT fk_prompt_extra_account
        FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
