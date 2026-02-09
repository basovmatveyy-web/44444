<?php
require_once __DIR__ . '/api_common.php';
if (($_SESSION['user']['role'] ?? 'user') !== 'admin') json_err('Нет прав',403);
$pdo = pdo();
$sql = "SELECT id, login, last_name, first_name, role FROM users ORDER BY id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
json_ok(['users'=>$rows]);
