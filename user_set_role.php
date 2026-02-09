<?php
require_once __DIR__ . '/api_common.php';
if (($_SESSION['user']['role'] ?? 'user') !== 'admin') json_err('Нет прав',403);
need_csrf();
$uid = (int)($_POST['user_id'] ?? 0);
$role = (string)($_POST['role'] ?? 'user');
if ($uid<=0 || !in_array($role, ['admin','buh','user'], true)) json_err('Неверные данные');

$pdo = pdo();
// гарантируем, что users.role существует
if (function_exists('db_has_column') && !db_has_column($pdo, 'users', 'role')) {
  try {
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
    if (db_has_column($pdo, 'users', 'is_admin')) {
      $pdo->exec("UPDATE users SET role = IF(is_admin=1,'admin','user')");
    }
  } catch (Throwable $e) {}
}

if (function_exists('db_has_column') && db_has_column($pdo, 'users', 'role')) {
  // защита: нельзя снять права у последнего админа
  if ($role !== 'admin') {
    try {
      $cntAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
      $curRole = (string)$pdo->query("SELECT role FROM users WHERE id={$uid} LIMIT 1")->fetchColumn();
      if ($curRole==='admin' && $cntAdmins<=1) json_err('Нельзя снять права: это последний администратор');
    } catch (Throwable $e) {}
  }
  $st = $pdo->prepare("UPDATE users SET role=:r WHERE id=:id");
  $st->execute([':r'=>$role, ':id'=>$uid]);
  // синхронизируем is_admin если есть
  if (db_has_column($pdo, 'users', 'is_admin')) {
    $st2 = $pdo->prepare("UPDATE users SET is_admin=:a WHERE id=:id");
    $st2->execute([':a'=>($role==='admin'?1:0), ':id'=>$uid]);
  }
  json_ok();
}

// fallback для старой схемы
if (function_exists('db_has_column') && db_has_column($pdo, 'users', 'is_admin')) {
  if ($role==='buh') json_err('Роль "Бухгалтер" требует обновления БД');
  $st = $pdo->prepare("UPDATE users SET is_admin=:a WHERE id=:id");
  $st->execute([':a'=>($role==='admin'?1:0), ':id'=>$uid]);
  json_ok();
}

json_err('В таблице users нет поля role или is_admin');
