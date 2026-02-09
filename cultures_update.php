<?php
require_once __DIR__ . '/api_common.php';
if (($_SESSION['user']['role'] ?? 'user') !== 'admin') json_err('Нет прав',403);
need_csrf();
$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
if ($id<=0 || $name==='') json_err('Неверные данные');
$pdo = pdo();
$st = $pdo->prepare("UPDATE cultures SET name=:n WHERE id=:id");
$st->execute([':n'=>$name, ':id'=>$id]);
json_ok();
