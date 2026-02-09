<?php
declare(strict_types=1);

// Режим отладки: на проде установи APP_DEBUG в false (например, в отдельном файле-конфиге перед подключением config.php)
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);
}

if (APP_DEBUG) {
    ini_set('display_errors','1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors','0');
    // Можно скорректировать набор уровней под себя
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

if (session_status() === PHP_SESSION_NONE) session_start();


$DB_HOST = 'localhost';
$DB_NAME = 'bereg_db1';
$DB_USER = 'bereg_db1';
$DB_PASS = 'bereg_db1';
$DB_DSN  = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

try {
  $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB connect error: ".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  exit;
}

function safe($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function db_has_column(PDO $db, string $table, string $col): bool {
  try {
    $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

// Миграция: обеспечиваем поле users.role (нужно для ролей admin/buh)
try {
  if (db_has_column($pdo, 'users', 'id') && !db_has_column($pdo, 'users', 'role')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
    if (db_has_column($pdo, 'users', 'is_admin')) {
      $pdo->exec("UPDATE users SET role = IF(is_admin=1,'admin','user')");
    }
  }
} catch (Throwable $e) { /* ignore */ }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// refresh user info if logged in
if (!empty($_SESSION['user'])) {
  try {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid > 0) {
      if (db_has_column($pdo, 'users', 'role')) {
        $st = $pdo->prepare("SELECT id,username,surname,name,role FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        if ($row = $st->fetch()) $_SESSION['user'] = $row;
      } elseif (db_has_column($pdo, 'users', 'is_admin')) {
        $st = $pdo->prepare("SELECT id,username,surname,name, IF(is_admin=1,'admin','user') AS role FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        if ($row = $st->fetch()) $_SESSION['user'] = $row;
      }
    }
  } catch(Throwable $e){}
}

// ... остальной config.php выше

