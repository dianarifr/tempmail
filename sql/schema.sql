-- Skema database TempMail
-- MySQL 5.7+ / MariaDB 10.3+
-- Import lewat phpMyAdmin atau: mysql -u USER -p DBNAME < sql/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS inboxes (
    address        VARCHAR(190)  NOT NULL,
    local_part     VARCHAR(64)   NOT NULL,
    created_ip     VARBINARY(16) NULL,
    created_at     DATETIME      NOT NULL,
    last_opened_at DATETIME      NULL,
    PRIMARY KEY (address),
    KEY idx_local_part (local_part),
    KEY idx_last_opened (last_opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    address     VARCHAR(190)    NOT NULL,
    message_key CHAR(64)        NOT NULL,
    from_name   VARCHAR(190)    NULL,
    from_addr   VARCHAR(190)    NULL,
    to_addr     VARCHAR(190)    NULL,
    subject     VARCHAR(500)    NULL,
    body_text   MEDIUMTEXT      NULL,
    body_html   MEDIUMTEXT      NULL,
    preview     VARCHAR(255)    NULL,
    size_bytes  INT UNSIGNED    NOT NULL DEFAULT 0,
    is_read     TINYINT(1)      NOT NULL DEFAULT 0,
    received_at DATETIME        NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_key (message_key),
    KEY idx_address_received (address, received_at),
    KEY idx_received (received_at),
    CONSTRAINT fk_messages_inbox FOREIGN KEY (address)
        REFERENCES inboxes (address) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id  BIGINT UNSIGNED NOT NULL,
    filename    VARCHAR(255)    NOT NULL,
    mime_type   VARCHAR(120)    NOT NULL,
    size_bytes  INT UNSIGNED    NOT NULL DEFAULT 0,
    stored_name VARCHAR(64)     NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stored_name (stored_name),
    KEY idx_message (message_id),
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id)
        REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    ip         VARBINARY(16) NOT NULL,
    bucket     VARCHAR(40)   NOT NULL,
    window_key VARCHAR(20)   NOT NULL,
    counter    INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at DATETIME      NOT NULL,
    PRIMARY KEY (ip, bucket, window_key),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menyimpan UID terakhir yang sudah diproses dari mailbox catch-all,
-- supaya polling hanya mengambil pesan baru.
CREATE TABLE IF NOT EXISTS sync_state (
    id             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_uid       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    last_polled_at DATETIME         NULL,
    last_error     VARCHAR(500)     NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sync_state (id, last_uid) VALUES (1, 0)
    ON DUPLICATE KEY UPDATE id = id;
