<?php
ob_start();
require_once __DIR__ . '/api_common.php';
require_admin();
require_once __DIR__ . '/admin_api_util.php';
try {
  $db = pdo(); $action = $_GET['action'] ?? '';
  $db->exec("CREATE TABLE IF NOT EXISTS cultures (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(120) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
  if ($action==='list') {
      $items=$db->query("SELECT id,title FROM cultures ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
      reply_ok(['items'=>$items]);
  }
  if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
      $title=trim($_POST['title'] ?? ''); if($title==='') reply_err('Укажите название культуры');
      $st=$db->prepare("SELECT id FROM cultures WHERE title=?"); $st->execute([$title]); if($st->fetchColumn()) reply_err('Такая культура уже существует');
      $st=$db->prepare("INSERT INTO cultures (title) VALUES (?)"); $st->execute([$title]);
      log_event_dynamic($db,'create_culture',$title,$_SESSION['user']['id'] ?? null); reply_ok();
  }
  if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
      $id=(int)($_POST['id'] ?? 0); $title=trim($_POST['title'] ?? ''); if($id<=0 || $title==='') reply_err('Неверные данные');
      $st=$db->prepare("UPDATE cultures SET title=? WHERE id=?"); $st->execute([$title,$id]);
      log_event_dynamic($db,'update_culture','id='.$id.'; '.$title,$_SESSION['user']['id'] ?? null); reply_ok();
  }
  if ($action==='delete' && $_SERVER['REQUEST_METHOD']==='POST') {
      $id=(int)($_POST['id'] ?? 0); if($id<=0) reply_err('Неверный id');
      $st=$db->prepare("DELETE FROM cultures WHERE id=?"); $st->execute([$id]);
      log_event_dynamic($db,'delete_culture','id='.$id,$_SESSION['user']['id'] ?? null); reply_ok();
  }
  reply_err('Неизвестное действие',400);
} catch (Throwable $e){ reply_err('Ошибка запроса: '.$e->getMessage(),500); }
