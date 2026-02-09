<?php
/**
 * tg_lib.php — ядро Telegram‑бота для СХПК «Береговой» (v2)
 * Полная интеграция: настройки, webhook, подписчики, рассылка, уведомления.
 * Зависимости: api_common.php (pdo(), require_admin(), json helpers).
 * Файл кладём в КОРЕНЬ.
 */
require_once __DIR__ . '/api_common.php';

/* ---------------- JSON хелперы (если не определены) ---------------- */
if (!function_exists('reply_json')) {
    function reply_json($arr, $code = 200) {
        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
        }
        echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    function reply_ok($data = []) { reply_json(array_merge(['ok'=>true], (array)$data), 200); }
    function reply_err($msg, $code = 400) { reply_json(['ok'=>false,'error'=>$msg], $code); }
}

/* ---------------- SETTINGS ---------------- */
function settings_ensure_table() {
    $db = pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key`   VARCHAR(64) PRIMARY KEY,
        `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}
function setting_get($key, $default='') {
    settings_ensure_table();
    $st = pdo()->prepare("SELECT `value` FROM `settings` WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v===false || $v===null) ? $default : $v;
}
function setting_set($key, $val) {
    settings_ensure_table();
    $st = pdo()->prepare("INSERT INTO `settings`(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$key, (string)$val]);
}
function setting_delete($key) {
    settings_ensure_table();
    $st = pdo()->prepare("DELETE FROM `settings` WHERE `key`=?");
    $st->execute([$key]);
}
function tg_settings() : array {
    $token  = setting_get('tg_bot_token', '');
    $chatId = setting_get('tg_admin_chat', '');
    $secret = setting_get('tg_secret', '');
    if ($secret==='') {
        try { $secret = bin2hex(random_bytes(16)); } catch (Throwable $e) { $secret = substr(sha1(uniqid('tg',true)),0,32); }
        setting_set('tg_secret', $secret);
    }
    return [
        'token'            => $token,
        'admin_chat'       => $chatId,
        'secret'           => $secret,
        'notify_save'      => setting_get('tg_notify_save', '1')==='1',
        'notify_logins'    => setting_get('tg_notify_logins','1')==='1',   // <— добавили логины
        'notify_users'     => setting_get('tg_notify_users','1')==='1',
        'notify_errors'    => setting_get('tg_notify_errors','1')==='1',
    ];
}
function tg_regenerate_secret() {
    try { $secret = bin2hex(random_bytes(16)); } catch (Throwable $e) { $secret = substr(sha1(uniqid('tg',true)),0,32); }
    setting_set('tg_secret', $secret);
    return $secret;
}


/* ---------------- Журнал аудита ---------------- */
if (!function_exists('tg_ensure_audit_log')) {
    function tg_ensure_audit_log() : void {
        $db = pdo();
        $db->exec("CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `user_id` INT NULL,
            `event` VARCHAR(255) NULL,
            `action` VARCHAR(255) NULL,
            `details` TEXT NULL,
            PRIMARY KEY (`id`),
            INDEX `created_at` (`created_at`),
            INDEX `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/* ---------------- Подписчики ---------------- */
function tg_ensure_subscribers() {
    $db = pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS `tg_subscribers` (
        `chat_id` BIGINT PRIMARY KEY,
        `type` VARCHAR(20) NULL,
        `username` VARCHAR(64) NULL,
        `first_name` VARCHAR(64) NULL,
        `last_name` VARCHAR(64) NULL,
        `title` VARCHAR(128) NULL,
        `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
        `last_seen_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}
function tg_subscriber_upsert(array $message) {
    tg_ensure_subscribers();
    $chat = $message['chat'] ?? [];
    $cid  = (int)($chat['id'] ?? 0);
    if (!$cid) return;
    $type = (string)($chat['type'] ?? '');
    $username = (string)($chat['username'] ?? '');
    $first = (string)($chat['first_name'] ?? '');
    $last  = (string)($chat['last_name'] ?? '');
    $title = (string)($chat['title'] ?? '');
    $isAdmin = ((string)$cid === (string)setting_get('tg_admin_chat','')) ? 1 : 0;
    $st = pdo()->prepare("INSERT INTO `tg_subscribers`(chat_id,type,username,first_name,last_name,title,is_admin,last_seen_at)
                          VALUES(?,?,?,?,?,?,?,NOW())
                          ON DUPLICATE KEY UPDATE type=VALUES(type),username=VALUES(username),first_name=VALUES(first_name),
                              last_name=VALUES(last_name),title=VALUES(title),is_admin=VALUES(is_admin),last_seen_at=NOW()");
    $st->execute([$cid,$type,$username,$first,$last,$title,$isAdmin]);
}
function tg_broadcast(string $text) : array {
    $s = tg_settings();
    tg_ensure_subscribers();
    $rows = pdo()->query("SELECT chat_id FROM tg_subscribers ORDER BY last_seen_at DESC")->fetchAll(PDO::FETCH_COLUMN);
    if ($s['admin_chat'] && !in_array((int)$s['admin_chat'], array_map('intval',$rows), true)) $rows[] = (int)$s['admin_chat'];
    $ok=0; $err=0; $fails=[];
    foreach ($rows as $cid) {
        $res = tg_send_message($text, (int)$cid);
        if ($res['ok'] ?? false) $ok++; else { $err++; $fails[] = ['chat_id'=>$cid,'error'=>$res['description'] ?? 'unknown']; }
    }
    return ['ok'=>true,'sent'=>$ok,'failed'=>$err,'fails'=>$fails];
}

/* ---------------- HTTP и Telegram API ---------------- */
function http_post_json($url, array $payload, array $extraHeaders = []) {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) return ['ok'=>false,'error'=>'curl: '.$err];
        $decoded = json_decode($response, true);
        return $decoded ?: ['ok'=>false,'error'=>'bad json'];
    } else {
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>15
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        $decoded = json_decode($response ?: 'null', true);
        return $decoded ?: ['ok'=>false,'error'=>'http stream error'];
    }
}
function tg_api($method, array $params = [], ?string $token = null) {
    $s = tg_settings();
    $token = $token ?: $s['token'];
    if (!$token) return ['ok'=>false,'error'=>'no-token'];
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    return http_post_json($url, $params);
}
function tg_get_me(?string $token=null) { return tg_api('getMe', [], $token); }

function tg_send_message(string $text, ?int $chatId=null, array $opts=[]) {
    $s = tg_settings();
    $chatId = $chatId ?: (int)$s['admin_chat'];
    if (!$s['token'] || !$chatId) return ['ok'=>false,'error'=>'no-token-or-chat'];
    $params = array_merge([
        'chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true
    ], $opts);
    return tg_api('sendMessage', $params, $s['token']);
}

function tg_send_document_file(int $chatId, string $filePath, string $filename, ?string $caption = null) {
    $s = tg_settings();
    $token = $s['token'] ?? '';
    if (!$token || !$chatId) return ['ok'=>false,'error'=>'no-token-or-chat'];
    if (!is_file($filePath)) return ['ok'=>false,'error'=>'file-not-found'];
    if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'no-curl'];

    $url = "https://api.telegram.org/bot{$token}/sendDocument";
    $post = [
        'chat_id' => $chatId,
        'document' => curl_file_create($filePath, 'application/pdf', $filename),
    ];
    if ($caption !== null && $caption !== '') {
        $post['caption'] = $caption;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok'=>false,'error'=>'curl: '.$err];
    }
    $decoded = json_decode($response, true);
    return $decoded ?: ['ok'=>false,'error'=>'bad-json'];
}

function tg_set_webhook(string $baseUrl) {
    $s = tg_settings();
    if (!$s['token']) return ['ok'=>false,'error'=>'no-token'];
    $baseUrl = trim((string)$baseUrl);
    // Telegram требует HTTPS webhook. На некоторых хостингах PHP не видит HTTPS за прокси,
    // поэтому дополнительно «поджимаем» схему к https.
    if (stripos($baseUrl, 'https://') !== 0) {
        $baseUrl = preg_replace('~^http://~i', 'https://', $baseUrl);
        if (stripos($baseUrl, 'https://') !== 0) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }
    }
    $url = rtrim($baseUrl,'/').'/tg_webhook.php';
    return tg_api('setWebhook',[
        'url'=>$url,'secret_token'=>$s['secret'],'drop_pending_updates'=>true
    ], $s['token']);
}
function tg_delete_webhook() {
    $s = tg_settings();
    if (!$s['token']) return ['ok'=>false,'error'=>'no-token'];
    return tg_api('deleteWebhook',['drop_pending_updates'=>false], $s['token']);
}

/* ---------------- Уведомления по событиям ---------------- */
function tg_notify_event(string $event, $details=null, ?int $userId=null) {
    $s = tg_settings();
    if (!$s['token'] || !$s['admin_chat']) return false;
    $ok = true;
    if (in_array($event,['create_user','role_change','delete_user'],true) && !$s['notify_users']) $ok=false;
    if (in_array($event,['create_culture','rename_culture','delete_culture','save_field'],true) && !$s['notify_save']) $ok=false;
    if (in_array($event,['login'],true) && !$s['notify_logins']) $ok=false; // <— логины
    if (in_array($event,['error','php_error'],true) && !$s['notify_errors']) $ok=false;
    if (!$ok) return false;

    $db = pdo(); $username='—';
    if ($userId) { $u=$db->prepare("SELECT username FROM users WHERE id=?"); $u->execute([$userId]); $username=$u->fetchColumn() ?: '—'; }
    $dt = date('Y-m-d H:i:s');
    if (is_array($details)) {
        $pairs=[]; foreach($details as $k=>$v){ $pairs[] = "<b>{$k}:</b> ".htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
        $details = implode("\n",$pairs);
    } else {
        $details = htmlspecialchars((string)$details, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    $map=[
        'create_user'=>"➕ <b>Добавлен пользователь</b>",
        'delete_user'=>"➖ <b>Удалён пользователь</b>",
        'role_change'=>"🛡 <b>Изменение роли</b>",
        'create_culture'=>"🌾 <b>Добавлена культура</b>",
        'rename_culture'=>"✏️ <b>Переименована культура</b>",
        'delete_culture'=>"🗑 <b>Удалена культура</b>",
        'save_field'=>"📍 <b>Сохранены данные участка</b>",
        'login'=>"🔑 <b>Вход в систему</b>",
        'error'=>"⚠️ <b>Ошибка</b>",
        'php_error'=>"🔥 <b>Сбой PHP</b>",
        'info'=>"ℹ️ <b>Информация</b>",
    ];
    $title = $map[$event] ?? ("📌 <b>".htmlspecialchars($event, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</b>");
    $text = "{$title}\n{$details}\n\n👤 <b>Пользователь:</b> ".htmlspecialchars($username, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n🕒 {$dt}";
    tg_send_message($text);
    return true;
}

/* ---------------- Сессии бота (tg_sessions) ---------------- */
if (!function_exists('tg_ensure_sessions')) {
    function tg_ensure_sessions() {
        $db = pdo();
        $db->exec("CREATE TABLE IF NOT EXISTS `tg_sessions` (
            `chat_id` BIGINT NOT NULL,
            `state` VARCHAR(64) NOT NULL,
            `payload` TEXT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`chat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
}

if (!function_exists('tg_session_get')) {
    function tg_session_get(int $chatId) : array {
        tg_ensure_sessions();
        $db = pdo();
        $st = $db->prepare("SELECT `state`, `payload` FROM `tg_sessions` WHERE `chat_id` = ? LIMIT 1");
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['state' => null, 'data' => []];
        $state = $row['state'] ?? null;
        $payload = $row['payload'] ?? null;
        $data = [];
        if ($payload !== null && $payload !== '') {
            $tmp = json_decode($payload, true);
            if (is_array($tmp)) $data = $tmp;
        }
        return ['state' => $state, 'data' => $data];
    }
}

if (!function_exists('tg_session_set')) {
    function tg_session_set(int $chatId, ?string $state, array $data = []) : void {
        tg_ensure_sessions();
        $db = pdo();
        if ($state === null || $state === '') {
            $st = $db->prepare("DELETE FROM `tg_sessions` WHERE `chat_id` = ?");
            $st->execute([$chatId]);
            return;
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("INSERT INTO `tg_sessions` (`chat_id`, `state`, `payload`, `updated_at`)
                            VALUES (?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE `state` = VALUES(`state`), `payload` = VALUES(`payload`), `updated_at` = NOW()");
        $st->execute([$chatId, $state, $payload]);
    }
}

/* ---------------- Избранные участки (tg_favorites) ---------------- */
if (!function_exists('tg_ensure_favorites')) {
    function tg_ensure_favorites() {
        $db = pdo();
        $db->exec("CREATE TABLE IF NOT EXISTS `tg_favorites` (
            `chat_id` BIGINT NOT NULL,
            `field_code` CHAR(4) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`chat_id`, `field_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
}

if (!function_exists('tg_favorites_add')) {
    function tg_favorites_add(int $chatId, string $fieldCode) : void {
        $code = preg_replace('~\D~','', $fieldCode);
        if (strlen($code) < 3 || strlen($code) > 4) return;
        tg_ensure_favorites();
        $db = pdo();
        $st = $db->prepare("INSERT IGNORE INTO `tg_favorites` (`chat_id`, `field_code`) VALUES (?, ?)");
        $st->execute([$chatId, $code]);
    }
}

if (!function_exists('tg_favorites_remove')) {
    function tg_favorites_remove(int $chatId, string $fieldCode) : void {
        $code = preg_replace('~\D~','', $fieldCode);
        if (strlen($code) < 3 || strlen($code) > 4) return;
        tg_ensure_favorites();
        $db = pdo();
        $st = $db->prepare("DELETE FROM `tg_favorites` WHERE `chat_id` = ? AND `field_code` = ?");
        $st->execute([$chatId, $code]);
    }
}

if (!function_exists('tg_favorites_is')) {
    function tg_favorites_is(int $chatId, string $fieldCode) : bool {
        $code = preg_replace('~\D~','', $fieldCode);
        if (strlen($code) < 3 || strlen($code) > 4) return false;
        tg_ensure_favorites();
        $db = pdo();
        $st = $db->prepare("SELECT 1 FROM `tg_favorites` WHERE `chat_id` = ? AND `field_code` = ? LIMIT 1");
        $st->execute([$chatId, $code]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('tg_favorites_list')) {
    function tg_favorites_list(int $chatId) : array {
        tg_ensure_favorites();
        $db = pdo();
        $st = $db->prepare("SELECT f.field_code, f.area_ha, c.title AS culture
                            FROM `tg_favorites` tf
                            LEFT JOIN `fields` f ON f.field_code = tf.field_code
                            LEFT JOIN `cultures` c ON c.id = f.culture_id
                            WHERE tf.chat_id = ?
                            ORDER BY tf.created_at DESC, tf.field_code ASC");
        $st->execute([$chatId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}


/* ---------------- Напоминания по полям (tg_reminders) ---------------- */
if (!function_exists('tg_ensure_reminders')) {
    function tg_ensure_reminders() : void {
        $db = pdo();
        $db->exec("CREATE TABLE IF NOT EXISTS `tg_reminders` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `chat_id` BIGINT NOT NULL,
            `field_code` CHAR(4) NOT NULL,
            `remind_at` DATETIME NOT NULL,
            `text` VARCHAR(512) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `done_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `chat_remind_at` (`chat_id`, `remind_at`),
            KEY `remind_at` (`remind_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
}

if (!function_exists('tg_reminder_add')) {
    function tg_reminder_add(int $chatId, string $fieldCode, string $remindAt, string $text) : int {
        $code = preg_replace('~\D~','', $fieldCode);
        if (strlen($code) < 3 || strlen($code) > 4) return 0;
        $text = trim($text);
        if ($text === '') return 0;
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("INSERT INTO `tg_reminders` (`chat_id`,`field_code`,`remind_at`,`text`,`created_at`) VALUES (?,?,?,?,NOW())");
        $st->execute([$chatId, $code, $remindAt, $text]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('tg_reminders_due')) {
    function tg_reminders_due(int $limit = 50) : array {
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("SELECT * FROM `tg_reminders` WHERE `done_at` IS NULL AND `remind_at` <= NOW() ORDER BY `remind_at` ASC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }
}

if (!function_exists('tg_reminder_mark_done')) {
    function tg_reminder_mark_done(int $id) : void {
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("UPDATE `tg_reminders` SET `done_at` = NOW() WHERE `id` = ?");
        $st->execute([$id]);
    }
}


if (!function_exists('tg_reminders_list')) {
    function tg_reminders_list(int $chatId, int $limit = 20, int $offset = 0) : array {
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("SELECT * FROM `tg_reminders` WHERE `chat_id` = ? AND `done_at` IS NULL ORDER BY `remind_at` ASC, `id` ASC LIMIT ? OFFSET ?");
        $st->bindValue(1, $chatId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }
}

if (!function_exists('tg_reminder_cancel')) {
    function tg_reminder_cancel(int $id, int $chatId) : void {
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("UPDATE `tg_reminders` SET `done_at` = NOW() WHERE `id` = ? AND `chat_id` = ? AND `done_at` IS NULL");
        $st->execute([$id, $chatId]);
    }
}

if (!function_exists('tg_reminder_reschedule')) {
    /**
     * Сдвинуть напоминание на указанное количество дней.
     * Возвращает новое время remind_at (Y-m-d H:i:s) или null при ошибке.
     */
    function tg_reminder_reschedule(int $id, int $chatId, int $days) : ?string {
        if ($days === 0) return null;
        tg_ensure_reminders();
        $db = pdo();
        $st = $db->prepare("SELECT `remind_at` FROM `tg_reminders` WHERE `id` = ? AND `chat_id` = ? AND `done_at` IS NULL LIMIT 1");
        $st->execute([$id, $chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        try {
            $dt = new DateTimeImmutable((string)$row['remind_at']);
        } catch (Exception $e) {
            return null;
        }
        $dt = $dt->modify(($days > 0 ? '+' : '').$days.' days');
        $newRem = $dt->format('Y-m-d H:i:s');
        $st = $db->prepare("UPDATE `tg_reminders` SET `remind_at` = ? WHERE `id` = ? AND `chat_id` = ? AND `done_at` IS NULL");
        $st->execute([$newRem, $id, $chatId]);
        return $newRem;
    }
}

// ========================= ЗАДАЧИ ПО УЧАСТКАМ =========================

if (!function_exists('tg_ensure_tasks')) {
    function tg_ensure_tasks() : void {
        $db = pdo();
        $db->exec("CREATE TABLE IF NOT EXISTS `tg_tasks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `chat_id` BIGINT NOT NULL,
            `field_code` CHAR(4) NOT NULL,
            `text` VARCHAR(512) NOT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `due_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `done_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            INDEX `chat_status` (`chat_id`,`status`),
            INDEX `chat_due` (`chat_id`,`due_at`),
            INDEX `field_code` (`field_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('tg_task_add')) {
    function tg_task_add(int $chatId, string $fieldCode, string $text, ?string $dueAt = null) : int {
        tg_ensure_tasks();
        $fieldCode = preg_replace('~\D~', '', $fieldCode);
        if (strlen($fieldCode) !== 4) {
            return 0;
        }
        $text = trim($text);
        if ($text === '') return 0;

        $db = pdo();
        $st = $db->prepare("INSERT INTO `tg_tasks` (`chat_id`,`field_code`,`text`,`status`,`due_at`) VALUES (?,?,?,?,?)");
        $st->execute([
            $chatId,
            $fieldCode,
            $text,
            0, // 0 = открыта
            $dueAt,
        ]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('tg_tasks_list')) {
    function tg_tasks_list(int $chatId, int $status = 0, int $limit = 20, int $offset = 0) : array {
        tg_ensure_tasks();
        $db = pdo();
        $st = $db->prepare("SELECT * FROM `tg_tasks` WHERE `chat_id` = ? AND `status` = ? ORDER BY 
            CASE WHEN `due_at` IS NULL THEN 1 ELSE 0 END,
            `due_at` ASC,
            `created_at` ASC,
            `id` ASC
            LIMIT ? OFFSET ?");
        $st->bindValue(1, $chatId, PDO::PARAM_INT);
        $st->bindValue(2, $status, PDO::PARAM_INT);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->bindValue(4, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }
}

if (!function_exists('tg_task_mark_done')) {
    function tg_task_mark_done(int $id, int $chatId) : void {
        tg_ensure_tasks();
        $db = pdo();
        $st = $db->prepare("UPDATE `tg_tasks` SET `status` = 1, `done_at` = NOW() WHERE `id` = ? AND `chat_id` = ? AND `status` = 0");
        $st->execute([$id, $chatId]);
    }
}

if (!function_exists('tg_task_delete')) {
    function tg_task_delete(int $id, int $chatId) : void {
        tg_ensure_tasks();
        $db = pdo();
        $st = $db->prepare("DELETE FROM `tg_tasks` WHERE `id` = ? AND `chat_id` = ?");
        $st->execute([$id, $chatId]);
    }
}

if (!function_exists('tg_task_reschedule')) {
    /**
     * Перенести задачу на указанное количество дней относительно текущей даты или due_at.
     * Возвращает новое due_at (Y-m-d H:i:s) или null.
     */
    function tg_task_reschedule(int $id, int $chatId, int $days) : ?string {
        if ($days === 0) return null;
        tg_ensure_tasks();
        $db = pdo();
        $st = $db->prepare("SELECT `due_at` FROM `tg_tasks` WHERE `id` = ? AND `chat_id` = ? AND `status` = 0 LIMIT 1");
        $st->execute([$id, $chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['due_at']) {
            try {
                $dt = new DateTimeImmutable((string)$row['due_at']);
            } catch (Exception $e) {
                $dt = new DateTimeImmutable('now');
            }
        } else {
            $dt = new DateTimeImmutable('now');
        }
        $dt = $dt->modify(($days > 0 ? '+' : '').$days.' days');
        $newDue = $dt->format('Y-m-d H:i:s');
        $st = $db->prepare("UPDATE `tg_tasks` SET `due_at` = ? WHERE `id` = ? AND `chat_id` = ? AND `status` = 0");
        $st->execute([$newDue, $id, $chatId]);
        return $newDue;
    }
}
