-- Доп. миграции, если нужно
-- 1) Создаём audit_log, если отсутствует
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event` VARCHAR(120) NOT NULL,
  `details` TEXT NULL,
  `user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Создаём cultures, если отсутствует
CREATE TABLE IF NOT EXISTS `cultures` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Если хотите перейти на колонку role (ENUM), раскомментируйте:
-- ALTER TABLE `users` ADD COLUMN `role` ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER `name`;
-- UPDATE `users` SET `role` = CASE WHEN IFNULL(`is_admin`,0)=1 THEN 'admin' ELSE 'user' END;
