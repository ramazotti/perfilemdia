CREATE TABLE contact_updates (
  update_id    BIGINT PRIMARY KEY,
  payload      JSON NOT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  attempts     INT NOT NULL DEFAULT 0,
  last_error   TEXT NULL,
  INDEX (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_routes (
  admin_chat_id BIGINT NOT NULL,
  admin_message_id BIGINT NOT NULL,
  customer_chat_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_chat_id, admin_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
