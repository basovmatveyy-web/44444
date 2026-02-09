<?php
if (!function_exists('reply_json')){
  function reply_json($arr, $code=200){
    while (ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()){ header('Content-Type: application/json; charset=utf-8'); http_response_code($code); }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  function reply_ok($data = []){ $data = is_array($data)? $data: []; $data = array_merge(['ok'=>true], $data); reply_json($data, 200); }
  function reply_err($msg, $code=400){ reply_json(['ok'=>false, 'error'=>$msg], $code); }
}
if (!function_exists('has_column')){
  function has_column(PDO $db, $table, $col){
    try{
      $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $st->execute([$col]);
      return (bool)$st->fetchColumn();
    } catch(Throwable $e){ return false; }
  }
}
if (!function_exists('ensure_table_audit')){
  function ensure_table_audit(PDO $db){
    try{
      $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(120) NOT NULL,
        details TEXT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }catch(Throwable $e){}
  }
}
if (!function_exists('log_event_dynamic')){
  function log_event_dynamic(PDO $db, $event, $details, $user_id=null){
    $cols = [];
    try{
      $rs = $db->query("SHOW COLUMNS FROM `audit_log`");
      foreach ($rs as $row){ $cols[strtolower($row['Field'])] = $row['Field']; }
    }catch(Throwable $e){
      ensure_table_audit($db);
      $cols = ['id'=>'id','event'=>'event','details'=>'details','user_id'=>'user_id','created_at'=>'created_at'];
    }
    if (!isset($cols['event']) && !isset($cols['action'])){
      try{
        $db->exec("ALTER TABLE `audit_log` ADD COLUMN `event` VARCHAR(120) NOT NULL AFTER `id`");
        $cols['event'] = 'event';
      }catch(Throwable $e){}
    }
    $fields=[];$values=[];$params=[];
    if (isset($cols['event'])){ $fields[]="`{$cols['event']}`"; $values[]='?'; $params[]=$event; }
    elseif (isset($cols['action'])){ $fields[]="`{$cols['action']}`"; $values[]='?'; $params[]=$event; }
    if (isset($cols['details'])){ $fields[]="`{$cols['details']}`"; $values[]='?'; $params[]=$details; }
    if (isset($cols['user_id'])){ $fields[]="`{$cols['user_id']}`"; $values[]='?'; $params[]=$user_id; }
    if (!$fields) return;
    $sql = "INSERT INTO `audit_log` (".implode(",", $fields).") VALUES (".implode(",", $values).")";
    try{ $st=$db->prepare($sql); $st->execute($params); }catch(Throwable $e){}
  }
}


if (!function_exists('ensure_users_role')){
  function ensure_users_role(PDO $db): void {
    try{
      $hasRole = has_column($db, 'users', 'role');
      if ($hasRole) return;
      // добавляем users.role (для ролей admin/buh)
      $db->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
      if (has_column($db, 'users', 'is_admin')){
        $db->exec("UPDATE users SET role = IF(is_admin=1,'admin','user')");
      }
    }catch(Throwable $e){ /* ignore */ }
  }
}
