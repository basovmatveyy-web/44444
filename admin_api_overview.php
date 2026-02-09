<?php
require_once __DIR__ . '/api_common.php';
require_admin();

try {
    $db = pdo();
    $users = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $cult  = (int)$db->query("SELECT COUNT(*) FROM cultures")->fetchColumn();

    @$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(120) NOT NULL,
        details TEXT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $logs  = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $lastLogs = $db->query("SELECT created_at,event,(SELECT username FROM users u WHERE u.id=a.user_id) AS username
                            FROM audit_log a ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $lastUsers = $db->query("SELECT id,username,role FROM users ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

    json_ok(['users'=>$users,'cultures'=>$cult,'logs'=>$logs,'last_logs'=>$lastLogs,'last_users'=>$lastUsers]);
} catch (Throwable $e) {
    json_err('Ошибка запроса', 500);
}
