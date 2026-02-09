-- SQL: базовая таблица settings + ключи для Telegram
CREATE TABLE IF NOT EXISTS `settings` (
  `key`   VARCHAR(64) PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Базовые ключи (если их ещё нет)
INSERT IGNORE INTO `settings` (`key`,`value`) VALUES
 ('tg_bot_token',''),
 ('tg_admin_chat',''),
 ('tg_notify_save','1'),
 ('tg_notify_users','1'),
 ('tg_notify_errors','1');

-- tg_secret добавляем только если ещё не существует
INSERT INTO `settings` (`key`,`value`)
SELECT 'tg_secret', SUBSTRING(MD5(RAND()),1,32)
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key`='tg_secret');
