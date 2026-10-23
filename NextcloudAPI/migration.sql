CREATE TABLE IF NOT EXISTS nextcloud_note_meta (
 message_id BIGINT UNSIGNED PRIMARY KEY,
 title VARCHAR(255) NOT NULL,
 favorite BOOLEAN NOT NULL DEFAULT FALSE,
 modified BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (message_id) REFERENCES note_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nextcloud_login_flows (
 poll_hash CHAR(64) PRIMARY KEY,
 grant_hash CHAR(64) NOT NULL UNIQUE,
 device_name VARCHAR(120) NOT NULL,
 session_id BIGINT UNSIGNED NULL,
 sealed_password TEXT NULL,
 expires_at DATETIME(6) NOT NULL,
 KEY flow_expiry (expires_at),
 FOREIGN KEY (session_id) REFERENCES auth_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
