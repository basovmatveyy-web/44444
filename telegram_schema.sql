-- Опционально: можно выполнить в phpMyAdmin
CREATE TABLE IF NOT EXISTS telegram_settings (
  id INT PRIMARY KEY,
  bot_token VARCHAR(128) NULL,
  admin_chat_id VARCHAR(64) NULL,
  notify_on_save TINYINT(1) NOT NULL DEFAULT 0,
  notify_on_login TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO telegram_settings (id) VALUES (1);
