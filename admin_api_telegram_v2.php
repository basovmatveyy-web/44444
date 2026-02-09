<?php
// admin_api_telegram_v2.php — safe minimal endpoint (v2). No stray output.
declare(strict_types=1);
@ini_set('display_errors','0');
@ini_set('log_errors','1');

session_start();
header('Content-Type: application/json; charset=utf-8');

function out(array $a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function need_admin(){ if (empty($_SESSION['user'])) return; }

// Load config variants
@require_once __DIR__.'/config.php';
if (file_exists(__DIR__.'/api_common.php')) { @require_once __DIR__.'/api_common.php'; }

// Try to reuse connections first
$mysqli = isset($mysqli) && $mysqli instanceof mysqli ? $mysqli : null;
$pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
if (!$mysqli && function_exists('db')) { $db = @db(); if ($db instanceof mysqli) $mysqli=$db; elseif ($db instanceof PDO) $pdo=$db; }
if (!$mysqli && function_exists('get_db')) { $db = @get_db(); if ($db instanceof mysqli) $mysqli=$db; elseif ($db instanceof PDO) $pdo=$db; }

// Discover credentials
$host='localhost'; $user='bereg_user'; $pass='bereg_user'; $name='bereg_db';
foreach (['db_host','DB_HOST','MYSQL_HOST'] as $k){ if (isset($$k)) { $host=$$k; break; } if (defined($k)) { $host=constant($k); break; } }
foreach (['db_user','DB_USER','MYSQL_USER'] as $k){ if (isset($$k)) { $user=$$k; break; } if (defined($k)) { $user=constant($k); break; } }
foreach (['db_pass','DB_PASS','MYSQL_PASS'] as $k){ if (isset($$k)) { $pass=$$k; break; } if (defined($k)) { $pass=constant($k); break; } }
foreach (['db_name','DB_NAME','MYSQL_DB'] as $k){ if (isset($$k)) { $name=$$k; break; } if (defined($k)) { $name=constant($k); break; } }
if (!$mysqli and !$pdo){
  if (class_exists('mysqli')){
    $m = @new mysqli($host,$user,$pass,$name);
    if ($m && !$m->connect_errno){ @$m->set_charset('utf8mb4'); $mysqli=$m; }
  }
  if (!$mysqli && class_exists('PDO')){
    try { $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    catch(Exception $e){ /* ignore */ }
  }
}
if (!$mysqli && !$pdo) out(['ok'=>false,'error'=>'DB connect error']);

// helpers
function q(string $sql, array $p=[]){
  global $mysqli,$pdo;
  if ($mysqli){
    $st=$mysqli->prepare($sql); if(!$st) return false;
    if ($p){ $types=''; $bind=[]; foreach($p as $v){ $types.=is_int($v)?'i':(is_float($v)?'d':'s'); $bind[]=$v; } $st->bind_param($types, ...$bind); }
    if(!$st->execute()) return false; return $st;
  } else {
    $st=$pdo->prepare($sql); if(!$st->execute(array_values($p))) return false; return $st;
  }
}
function all(string $sql, array $p=[]){ $st=q($sql,$p); if(!$st) return []; if ($st instanceof mysqli_stmt){ $r=$st->get_result(); return $r? $r->fetch_all(MYSQLI_ASSOC):[]; } return $st->fetchAll(); }
function one(string $sql, array $p=[]){ $a=all($sql,$p); return $a? $a[0]:null; }

// ensure tables
q("CREATE TABLE IF NOT EXISTS telegram_settings (
  id TINYINT PRIMARY KEY DEFAULT 1,
  token VARCHAR(128) NOT NULL DEFAULT '',
  admin_chat VARCHAR(64) NOT NULL DEFAULT '',
  secret VARCHAR(64) NOT NULL DEFAULT '',
  notify_save TINYINT(1) NOT NULL DEFAULT 1,
  notify_logins TINYINT(1) NOT NULL DEFAULT 1,
  notify_users TINYINT(1) NOT NULL DEFAULT 1,
  notify_errors TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

q("CREATE TABLE IF NOT EXISTS tg_subscribers (
  chat_id BIGINT PRIMARY KEY,
  type VARCHAR(20) NULL,
  username VARCHAR(64) NULL,
  first_name VARCHAR(64) NULL,
  last_name VARCHAR(64) NULL,
  title VARCHAR(128) NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function get_settings(){ $row=one("SELECT token, admin_chat, secret, notify_save, notify_logins, notify_users, notify_errors FROM telegram_settings WHERE id=1 LIMIT 1"); if(!$row){ $sec=bin2hex(function_exists('random_bytes')?random_bytes(12):openssl_random_pseudo_bytes(12)); q("INSERT IGNORE INTO telegram_settings (id, secret) VALUES (1, ?)",[$sec]); $row=['token'=>'','admin_chat'=>'','secret'=>$sec,'notify_save'=>1,'notify_logins'=>1,'notify_users'=>1,'notify_errors'=>1]; } $has=!empty($row['token']); $tail=$has?substr($row['token'],-6):''; return [$row, ['has_token'=>$has,'token_tail'=>$tail]]; }
function tg_api($method,$data=[],$token=null){ list($s,)=get_settings(); $token=$token?:$s['token']; if(!$token) return ['ok'=>false,'error'=>'No token']; $url="https://api.telegram.org/bot".$token."/".$method; $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($data),'timeout'=>10]]); $resp=@file_get_contents($url,false,$ctx); if($resp===false) return ['ok'=>false,'error'=>'TG request failed']; $j=json_decode($resp,true); return $j?$j:['ok'=>false,'error'=>'Bad TG response']; }

$action=isset($_GET['action'])?$_GET['action']:'';

if ($action==='get'){
  list($s,$meta)=get_settings();
  $bot=$s['token']? tg_api('getMe',[], $s['token']): ['ok'=>false];
  $hook=$s['token']? tg_api('getWebhookInfo',[], $s['token']): ['ok'=>false];
  $scheme = (!empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https');
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  out(['ok'=>true,'settings'=>$s,'data'=>array_merge($s,$meta),'bot'=>$bot,'webhook'=>$hook,'base'=>$scheme.'://'.$host]);
}
elseif($action==='subscribers'){
  $items=all("SELECT chat_id, type, username, first_name, last_name, title, is_admin, last_seen_at FROM tg_subscribers ORDER BY last_seen_at DESC");
  out(['ok'=>true,'count'=>count($items),'items'=>$items]);
}
elseif($action==='broadcast'){
  list($s,)=get_settings();
  $text=isset($_POST['text'])?trim($_POST['text']):''; if($text==='') out(['ok'=>false,'error'=>'empty text']);
  $rows=all("SELECT chat_id FROM tg_subscribers"); $ok=0;$fail=0;$total=0;
  foreach($rows as $r){ $total++; $resp=tg_api('sendMessage',['chat_id'=>$r['chat_id'],'text'=>$text],$s['token']); if(!empty($resp['ok'])) $ok++; else $fail++; }
  out(['ok'=>true,'result'=>['ok_count'=>$ok,'fail_count'=>$fail,'total'=>$total]]);
}
else {
  out(['ok'=>false,'error'=>'Unknown action']);
}
?>
