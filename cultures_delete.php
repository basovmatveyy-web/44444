<?php
require_once __DIR__ . '/api_common.php';
if (($_SESSION['user']['role'] ?? 'user') !== 'admin') json_err('Нет прав',403);
need_csrf();
$id = (int)($_POST['id'] ?? 0);
if ($id<=0) json_err('Неверный id');
$pdo = pdo();
$pdo->beginTransaction();
try {
  // Отвязываем поля от удаляемой культуры, чтобы не упасть на FK
  $st1 = $pdo->prepare("UPDATE fields SET culture_id=NULL WHERE culture_id=:id");
  $st1->execute([':id'=>$id]);
  $st2 = $pdo->prepare("DELETE FROM cultures WHERE id=:id");
  $st2->execute([':id'=>$id]);
  $pdo->commit();
  json_ok();
} catch(Throwable $e){
  $pdo->rollBack();
  json_err('Не удалось удалить культуру: ' . $e->getMessage());
}
