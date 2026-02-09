-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Окт 31 2025 г., 09:02
-- Версия сервера: 8.0.43-0ubuntu0.22.04.2
-- Версия PHP: 8.1.2-1ubuntu2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `bereg_db`
--

DELIMITER $$
--
-- Процедуры
--
CREATE DEFINER=`bereg_user`@`localhost` PROCEDURE `add_col_if_not_exists` (IN `db_name` VARCHAR(64), IN `tbl_name` VARCHAR(64), IN `col_name` VARCHAR(64), IN `ddl` TEXT)  BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name
      AND TABLE_NAME   = tbl_name
      AND COLUMN_NAME  = col_name
  ) THEN
    SET @q = ddl;
    PREPARE stmt FROM @q;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `subject` varchar(50) NOT NULL,
  `subject_id` int DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `subject`, `subject_id`, `before_json`, `after_json`, `created_at`) VALUES
(1, 1, 'create_culture', '', NULL, NULL, NULL, '2025-10-23 08:20:24'),
(2, 1, 'create_culture', '', NULL, NULL, NULL, '2025-10-23 08:22:14'),
(3, 1, 'create_culture', '', NULL, NULL, NULL, '2025-10-23 10:07:34'),
(4, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-23 10:07:39'),
(5, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-23 10:07:42'),
(6, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-23 10:07:43'),
(7, 1, 'create_culture', '', NULL, NULL, NULL, '2025-10-23 10:09:49'),
(8, 1, 'save_tg_settings', '', NULL, NULL, NULL, '2025-10-23 10:16:29'),
(9, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-23 11:55:22'),
(10, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-23 11:55:27'),
(11, 1, 'delete_culture', '', NULL, NULL, NULL, '2025-10-23 11:55:33'),
(12, 1, 'delete_culture', '', NULL, NULL, NULL, '2025-10-24 06:34:22'),
(13, 1, 'delete_culture', '', NULL, NULL, NULL, '2025-10-24 06:34:26'),
(14, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-26 06:37:22'),
(15, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-28 07:09:44'),
(16, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-28 07:09:45'),
(17, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-28 08:20:35'),
(18, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-28 08:20:35'),
(19, 1, 'create_culture', '', NULL, NULL, NULL, '2025-10-28 08:21:10'),
(20, 1, 'delete_culture', '', NULL, NULL, NULL, '2025-10-28 08:21:12'),
(21, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-29 11:22:19'),
(22, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-29 11:22:20'),
(23, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-29 12:31:35'),
(24, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-29 12:31:35'),
(25, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-30 06:02:24'),
(26, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-30 06:02:25'),
(27, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-31 05:42:33'),
(28, 1, 'set_role', '', NULL, NULL, NULL, '2025-10-31 05:42:34'),
(29, 1, 'update_culture', '', NULL, NULL, NULL, '2025-10-31 05:42:37'),
(30, 1, 'update_culture', '', NULL, NULL, NULL, '2025-10-31 05:42:38'),
(31, 1, 'delete_culture', '', NULL, NULL, NULL, '2025-10-31 05:42:41');

-- --------------------------------------------------------

--
-- Структура таблицы `cultures`
--

CREATE TABLE `cultures` (
  `id` int NOT NULL,
  `title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `cultures`
--

INSERT INTO `cultures` (`id`, `title`) VALUES
(6, 'Овёс'),
(5, 'Рапс'),
(2, 'Ячмень');

-- --------------------------------------------------------

--
-- Структура таблицы `fields`
--

CREATE TABLE `fields` (
  `id` int NOT NULL,
  `field_code` char(4) NOT NULL,
  `area_ha` decimal(10,2) DEFAULT NULL,
  `plow_date` date DEFAULT NULL,
  `sow_date` date DEFAULT NULL,
  `culture_id` int DEFAULT NULL,
  `last_treatment_date` date DEFAULT NULL,
  `last_water_date` date DEFAULT NULL,
  `harvest_date` date DEFAULT NULL,
  `gross_yield` decimal(12,2) DEFAULT NULL,
  `avg_yield` decimal(12,2) DEFAULT NULL,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `treatment_date` date DEFAULT NULL,
  `treatment_desc` varchar(255) DEFAULT NULL
) ;

--
-- Дамп данных таблицы `fields`
--

INSERT INTO `fields` (`id`, `field_code`, `area_ha`, `plow_date`, `sow_date`, `culture_id`, `last_treatment_date`, `last_water_date`, `harvest_date`, `gross_yield`, `avg_yield`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`, `treatment_date`, `treatment_desc`) VALUES
(1, '2222', '2222.00', '1111-11-11', NULL, 5, NULL, NULL, NULL, '0.00', '0.00', NULL, NULL, NULL, '2025-10-21 12:29:03', '2025-10-28 08:20:15', '2025-10-23', 'егором'),
(2, '1222', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-22 11:40:45', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `field_treatments`
--

CREATE TABLE `field_treatments` (
  `id` int NOT NULL,
  `field_id` int NOT NULL,
  `treat_date` date NOT NULL,
  `chemical` varchar(200) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `settings`
--

CREATE TABLE `settings` (
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `settings`
--

INSERT INTO `settings` (`key`, `value`) VALUES
('tg_admin_chat', ''),
('tg_bot_token', '8261667202:AAE-cIrCnTIgchvr4yNyLy8HcDws0kcBVys'),
('tg_notify_errors', '1'),
('tg_notify_logins', '1'),
('tg_notify_save', '0'),
('tg_notify_users', '1'),
('tg_secret', 'ff21207139d684dc63b1a3be0163f86b');

-- --------------------------------------------------------

--
-- Структура таблицы `telegram_logs`
--

CREATE TABLE `telegram_logs` (
  `id` bigint NOT NULL,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `event` varchar(64) NOT NULL,
  `details` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `telegram_logs`
--

INSERT INTO `telegram_logs` (`id`, `ts`, `event`, `details`) VALUES
(1, '2025-10-30 07:05:50', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(2, '2025-10-30 07:05:50', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(3, '2025-10-30 07:05:51', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(4, '2025-10-30 07:05:51', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(5, '2025-10-30 07:05:51', 'webhook_delete', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(6, '2025-10-30 07:05:51', 'rotate_secret', '24889d14b889aa53cb14840db9124673'),
(7, '2025-10-30 07:05:52', 'rotate_secret', '6f6cacc4538ae2ef0320516102e511e3'),
(8, '2025-10-30 07:05:52', 'rotate_secret', '58f74de9ee092c2fbe932030ae4f87af'),
(9, '2025-10-30 07:05:52', 'rotate_secret', 'd8b9b5afe1f71ec6f067d961ed0d074a'),
(10, '2025-10-30 07:05:52', 'rotate_secret', '3d49aae664032393dd0b589f59819b2f'),
(11, '2025-10-30 07:05:52', 'rotate_secret', '7b57d8310e796294e4f453f014743641'),
(12, '2025-10-30 07:05:53', 'rotate_secret', '54fd9d365c6c2e8daeca04ccd5bf65d0'),
(13, '2025-10-30 07:05:54', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(14, '2025-10-30 07:05:54', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(15, '2025-10-30 07:05:55', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(16, '2025-10-30 07:05:55', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(17, '2025-10-30 07:05:55', 'webhook_delete', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(18, '2025-10-30 07:05:56', 'webhook_delete', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(19, '2025-10-30 07:05:56', 'send_test', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(20, '2025-10-30 07:05:56', 'send_test', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(21, '2025-10-30 07:05:57', 'send_test', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(22, '2025-10-30 07:05:57', 'send_test', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(23, '2025-10-30 07:06:24', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}'),
(24, '2025-10-30 07:06:24', 'webhook_set', '{\"ok\":false,\"error\":\"no_token\",\"description\":\"Токен не задан\"}');

-- --------------------------------------------------------

--
-- Структура таблицы `telegram_settings`
--

CREATE TABLE `telegram_settings` (
  `id` int NOT NULL,
  `bot_token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_chat_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notify_on_save` tinyint(1) NOT NULL DEFAULT '0',
  `notify_on_login` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notify_logins` tinyint(1) NOT NULL DEFAULT '1',
  `notify_save` tinyint(1) NOT NULL DEFAULT '1',
  `admin_chat` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `secret` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `token` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notify_users` tinyint(1) NOT NULL DEFAULT '1',
  `notify_errors` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `telegram_settings`
--

INSERT INTO `telegram_settings` (`id`, `bot_token`, `admin_chat_id`, `notify_on_save`, `notify_on_login`, `updated_at`, `created_at`, `notify_logins`, `notify_save`, `admin_chat`, `secret`, `token`, `notify_users`, `notify_errors`) VALUES
(1, '', '', 1, 1, '2025-10-30 10:05:53', '2025-10-23 10:09:37', 1, 1, '', '54fd9d365c6c2e8daeca04ccd5bf65d0', '', 1, 1);

-- --------------------------------------------------------

--
-- Структура таблицы `tg_subscribers`
--

CREATE TABLE `tg_subscribers` (
  `chat_id` bigint NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tg_subscribers`
--

INSERT INTO `tg_subscribers` (`chat_id`, `type`, `username`, `first_name`, `last_name`, `title`, `is_admin`, `last_seen_at`) VALUES
(1224507343, 'private', 'aglaya_movess', 'глаша', '', '', 0, '2025-10-29 18:10:58'),
(5033763412, 'private', 'propavshiy_GR', 'Матвей', '', '', 0, '2025-10-29 18:11:11'),
(8139254007, 'private', 'opalalaxuia', 'Настя', '', '', 0, '2025-10-29 16:36:56');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `surname` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `tg_user_id` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `surname`, `name`, `username`, `password`, `role`, `tg_user_id`, `created_at`) VALUES
(1, 'Администратор', 'Главный', 'admin', 'admin1234', 'admin', NULL, '2025-10-20 13:36:53'),
(4, 'Басов', 'Матвей', 'basovmatveyy@gmail.com', 'voKmym-zobwez-1hokba', 'admin', NULL, '2025-10-22 15:39:07'),
(5, 'Кудинова', 'Аглая', 'akdnv', 'Aglaya123', 'user', NULL, '2025-10-22 15:42:46');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `cultures`
--
ALTER TABLE `cultures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `title` (`title`);

--
-- Индексы таблицы `fields`
--
ALTER TABLE `fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `field_code` (`field_code`),
  ADD UNIQUE KEY `uq_fields_code` (`field_code`),
  ADD KEY `idx_fields_culture` (`culture_id`),
  ADD KEY `idx_fields_plow` (`plow_date`),
  ADD KEY `idx_fields_sow` (`sow_date`),
  ADD KEY `idx_fields_harvest` (`harvest_date`);

--
-- Индексы таблицы `field_treatments`
--
ALTER TABLE `field_treatments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_treat_field_date` (`field_id`,`treat_date`);

--
-- Индексы таблицы `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Индексы таблицы `telegram_logs`
--
ALTER TABLE `telegram_logs`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `telegram_settings`
--
ALTER TABLE `telegram_settings`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `tg_subscribers`
--
ALTER TABLE `tg_subscribers`
  ADD PRIMARY KEY (`chat_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT для таблицы `cultures`
--
ALTER TABLE `cultures`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT для таблицы `fields`
--
ALTER TABLE `fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `field_treatments`
--
ALTER TABLE `field_treatments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `telegram_logs`
--
ALTER TABLE `telegram_logs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `fields`
--
ALTER TABLE `fields`
  ADD CONSTRAINT `fk_fields_culture` FOREIGN KEY (`culture_id`) REFERENCES `cultures` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `field_treatments`
--
ALTER TABLE `field_treatments`
  ADD CONSTRAINT `fk_treat_field` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
