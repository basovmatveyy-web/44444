<?php
require_once __DIR__ . '/api_common.php';
if (($_SESSION['user']['role'] ?? 'user') !== 'admin') json_err('Нет прав',403);
need_csrf();
$name = trim((string)($_POST['name'] ?? ''));
if ($name === '') json_err('Название обязательно');
$pdo = pdo();
$st = $pdo->prepare("INSERT INTO cultures(name) VALUES(:n)");
$st->execute([':n'=>$name]);
json_ok(['id'=>$pdo->lastInsertId()]);
