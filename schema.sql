-- === Инициализация БД (выполняйте в phpMyAdmin) ===
-- Создать БД (если ещё нет)
CREATE DATABASE IF NOT EXISTS beregovoy_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE beregovoy_db;

-- Создать пользователя и выдать права (если это допускается политикой хостинга)
-- ЗАМЕЧАНИЕ: если пользователь уже создан через ISPmanager, эти строки пропустите
CREATE USER IF NOT EXISTS 'beregovoy_user'@'localhost' IDENTIFIED BY 'beregovoy_user';
GRANT ALL PRIVILEGES ON beregovoy_db.* TO 'beregovoy_user'@'localhost';
FLUSH PRIVILEGES;

-- Полная схема БД для beregovoy_db
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  surname  VARCHAR(100) NOT NULL,
  name     VARCHAR(100) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cultures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_code CHAR(4) NOT NULL UNIQUE,
  area_ha DECIMAL(8,2),
  plow_date DATE,
  sow_date DATE,
  culture_id INT NULL,
  treatment_date DATE,
  treatment_desc VARCHAR(255),
  last_water_date DATE,
  harvest_date DATE,
  gross_yield VARCHAR(64),
  avg_yield VARCHAR(64),
  notes TEXT,
  CONSTRAINT fk_fields_culture FOREIGN KEY (culture_id) REFERENCES cultures(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO users (username,password,surname,name,role) VALUES ('admin','admin','Администратор','Главный','admin');
INSERT IGNORE INTO cultures (title) VALUES ('Пшеница'),('Ячмень'),('Овес'),('Подсолнух'),('Кукуруза');
