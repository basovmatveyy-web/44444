-- Добавление сезонных данных полей (по годам)
-- Можно выполнить в phpMyAdmin (в вашей БД beregovoy_db).

CREATE TABLE IF NOT EXISTS `field_year_data` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `field_id` INT NOT NULL,
  `year` SMALLINT NOT NULL,
  `plow_date` DATE NULL,
  `sow_date` DATE NULL,
  `culture_id` INT NULL,
  `treatment_date` DATE NULL,
  `treatment_desc` VARCHAR(255) NULL,
  `last_water_date` DATE NULL,
  `harvest_date` DATE NULL,
  `gross_yield` VARCHAR(64) NULL,
  `avg_yield` VARCHAR(64) NULL,
  `notes` TEXT NULL,
  UNIQUE KEY `uniq_field_year` (`field_id`,`year`),
  CONSTRAINT `fk_fyd_field` FOREIGN KEY (`field_id`) REFERENCES `fields`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fyd_culture` FOREIGN KEY (`culture_id`) REFERENCES `cultures`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
