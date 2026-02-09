<?php
require_once __DIR__ . '/api_common.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') json_err('Метод не поддерживается',405);
if (empty($_POST['csrf']) || $_POST['csrf']!==($_SESSION['csrf']??'')) json_err('CSRF token');
$username = trim((string)($_POST['username']??''));
$password = trim((string)($_POST['password']??''));
if ($username==='' || $password==='') json_err('Заполните логин и пароль');
$sql = "SELECT id,username,surname,name,password";
if (function_exists('db_has_column') && db_has_column($pdo, 'users', 'role')) {
  $sql .= ", role";
} elseif (function_exists('db_has_column') && db_has_column($pdo, 'users', 'is_admin')) {
  $sql .= ", IF(is_admin=1,'admin','user') AS role";
} else {
  $sql .= ", 'user' AS role";
}
$sql .= " FROM users WHERE username=? LIMIT 1";
$st=$pdo->prepare($sql);
$st->execute([$username]);
$u=$st->fetch();
if(!$u || $u['password']!==$password) json_err('Неверный логин или пароль');
$_SESSION['user']=['id'=>$u['id'],'username'=>$u['username'],'surname'=>$u['surname'],'name'=>$u['name'],'role'=>$u['role']];
setcookie('last_user',$u['username'],time()+31536000,'/');
json_ok();
