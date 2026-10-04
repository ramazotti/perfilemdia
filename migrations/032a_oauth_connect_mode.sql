ALTER TABLE oauth_states
    ADD COLUMN connect_mode VARCHAR(16) NOT NULL DEFAULT 'connect' AFTER user_id;
