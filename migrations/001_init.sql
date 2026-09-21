CREATE TABLE users (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telegram_user_id  BIGINT NOT NULL UNIQUE,
  telegram_chat_id  BIGINT NOT NULL,
  telegram_username VARCHAR(64) NULL,
  display_name      VARCHAR(120) NULL,
  profession        VARCHAR(120) NULL,
  city              VARCHAR(120) NULL,
  tone              ENUM('profissional','descontraido','tecnico','acolhedor') NULL,
  contact_cta       VARCHAR(255) NULL,
  fixed_hashtags    VARCHAR(255) NULL,
  about             VARCHAR(500) NULL,
  onboarding_step   VARCHAR(40) NOT NULL DEFAULT 'start',
  pending_action    VARCHAR(40) NULL,
  status            ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instagram_accounts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL UNIQUE,
  ig_user_id         VARCHAR(64) NOT NULL,
  username           VARCHAR(64) NOT NULL,
  account_type       VARCHAR(32) NULL,
  access_token_enc   TEXT NOT NULL,
  token_expires_at   DATETIME NOT NULL,
  token_refreshed_at DATETIME NULL,
  status             ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active',
  connected_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_states (
  state       CHAR(64) PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                BIGINT UNSIGNED NOT NULL,
  status                 VARCHAR(32) NOT NULL,
  theme_text             VARCHAR(1000) NULL,
  caption                TEXT NULL,
  alt_text               VARCHAR(500) NULL,
  caption_version        INT NOT NULL DEFAULT 0,
  regen_count            INT NOT NULL DEFAULT 0,
  last_feedback          VARCHAR(1000) NULL,
  media_group_id         VARCHAR(64) NULL,
  preview_message_id     BIGINT NULL,
  ig_container_id        VARCHAR(64) NULL,
  ig_media_id            VARCHAR(64) NULL,
  ig_permalink           VARCHAR(255) NULL,
  error_code             VARCHAR(64) NULL,
  error_message          TEXT NULL,
  attempts               INT NOT NULL DEFAULT 0,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at           DATETIME NULL,
  INDEX (user_id, status),
  INDEX (media_group_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_media (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id          BIGINT UNSIGNED NOT NULL,
  position         TINYINT UNSIGNED NOT NULL,
  telegram_file_id VARCHAR(255) NOT NULL,
  telegram_msg_id  BIGINT NOT NULL,
  original_path    VARCHAR(255) NULL,
  public_name      CHAR(40) NULL,
  width            INT NULL,
  height           INT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (post_id) REFERENCES posts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE telegram_updates (
  update_id    BIGINT PRIMARY KEY,
  payload      JSON NOT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  attempts     INT NOT NULL DEFAULT 0,
  last_error   TEXT NULL,
  INDEX (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_usage (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  post_id       BIGINT UNSIGNED NULL,
  model         VARCHAR(64) NOT NULL,
  input_tokens  INT NOT NULL,
  output_tokens INT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NULL,
  post_id    BIGINT UNSIGNED NULL,
  type       VARCHAR(64) NOT NULL,
  data       JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
