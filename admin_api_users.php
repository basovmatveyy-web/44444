<?php
ob_start();
require_once __DIR__ . '/api_common.php';
require_admin();
require_once __DIR__ . '/admin_api_util.php';

try {
  $db = pdo();

  // гарантируем поле users.role, чтобы работали роли admin/buh
  ensure_users_role($db);

  $action = $_GET['action'] ?? '';

  if ($action === 'list') {
    $hasRole = has_column($db, 'users', 'role');
    $hasIsAdmin = has_column($db, 'users', 'is_admin');

    if ($hasRole) {
      $sql = "SELECT id, username, surname, name, role FROM users ORDER BY id ASC";
    } elseif ($hasIsAdmin) {
      $sql = "SELECT id, username, surname, name, IF(is_admin=1,'admin','user') AS role FROM users ORDER BY id ASC";
    } else {
      $sql = "SELECT id, username, surname, name, 'user' AS role FROM users ORDER BY id ASC";
    }

    $items = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    reply_ok(['items' => $items]);
  }

  if ($action === 'set_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $role = (string)($_POST['role'] ?? 'user');

    if ($id <= 0) reply_err('Неверный id');
    if (!in_array($role, ['user', 'admin', 'buh'], true)) reply_err('Недопустимая роль');

    $meId = (int)($_SESSION['user']['id'] ?? 0);
    // безопасность: не даём снять права у собственной учётки
    if ($meId > 0 && $id === $meId && $role !== 'admin') {
      reply_err('Нельзя изменить роль собственной учётной записи');
    }

    $hasRole = has_column($db, 'users', 'role');
    $hasIsAdmin = has_column($db, 'users', 'is_admin');

    if ($hasRole) {
      // нельзя демотировать последнего администратора
      $cntAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
      $curRole = (string)$db->query("SELECT role FROM users WHERE id={$id} LIMIT 1")->fetchColumn();
      if ($curRole === 'admin' && $role !== 'admin' && $cntAdmins <= 1) {
        reply_err('Нельзя снять права: это последний администратор');
      }

      $st = $db->prepare("UPDATE users SET role=? WHERE id=?");
      $st->execute([$role, $id]);

      // если есть старое поле is_admin — синхронизируем
      if ($hasIsAdmin) {
        $isAdmin = ($role === 'admin') ? 1 : 0;
        $st2 = $db->prepare("UPDATE users SET is_admin=? WHERE id=?");
        $st2->execute([$isAdmin, $id]);
      }

    } elseif ($hasIsAdmin) {
      // без users.role роль "buh" невозможна
      if ($role === 'buh') reply_err('Роль "Бухгалтер" требует обновления БД (users.role)');

      $new = ($role === 'admin') ? 1 : 0;
      if ($new === 0) {
        $cntAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn();
        $isTargetAdmin = (int)$db->query("SELECT COUNT(*) FROM users WHERE id={$id} AND is_admin=1")->fetchColumn();
        if ($isTargetAdmin && $cntAdmins <= 1) reply_err('Нельзя снять права: это последний администратор');
      }

      $st = $db->prepare("UPDATE users SET is_admin=? WHERE id=?");
      $st->execute([$new, $id]);

    } else {
      reply_err('В таблице users нет поля role или is_admin.');
    }

    log_event_dynamic($db, 'set_role', 'user_id=' . $id . '; role=' . $role, $_SESSION['user']['id'] ?? null);
    reply_ok();
  }

  reply_err('Неизвестное действие', 400);

} catch (Throwable $e) {
  reply_err('Ошибка запроса: ' . $e->getMessage(), 500);
}
