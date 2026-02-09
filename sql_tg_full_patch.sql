-- SQL‑патч Telegram v2 (settings + tg_subscribers + новые ключи логинов)
CREATE TABLE IF NOT EXISTS `settings` (
  `key`   VARCHAR(64) PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key`,`value`) VALUES
 ('tg_bot_token',''),
 ('tg_admin_chat',''),
 ('tg_notify_save','1'),
 ('tg_notify_logins','1'),
 ('tg_notify_users','1'),
 ('tg_notify_errors','1');

INSERT INTO `settings` (`key`,`value`)
SELECT 'tg_secret', SUBSTRING(MD5(RAND()),1,32)
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key`='tg_secret');

CREATE TABLE IF NOT EXISTS `tg_subscribers` (
    `chat_id` BIGINT PRIMARY KEY,
    `type` VARCHAR(20) NULL,
    `username` VARCHAR(64) NULL,
    `first_name` VARCHAR(64) NULL,
    `last_name` VARCHAR(64) NULL,
    `title` VARCHAR(128) NULL,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
