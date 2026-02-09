<?php
require_once __DIR__ . '/api_common.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') json_err('Метод не поддерживается',405);
if (empty($_POST['csrf']) || $_POST['csrf']!==($_SESSION['csrf']??'')) json_err('CSRF token');
$surname=trim((string)($_POST['surname']??''));
$name=trim((string)($_POST['name']??''));
$username=trim((string)($_POST['username']??''));
$password=trim((string)($_POST['password']??''));
if ($surname===''||$name===''||$username===''||$password==='') json_err('Заполните все поля');
$st=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1"); $st->execute([$username]);
if($st->fetch()) json_err('Такой логин уже существует');
$st=$pdo->prepare("INSERT INTO users (username,password,surname,name,role,created_at) VALUES (?,?,?,?,'user',NOW())");
$st->execute([$username,$password,$surname,$name]);
json_ok();
