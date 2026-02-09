<?php
ob_start();

require_once __DIR__ . '/api_common.php';
require_admin();

/**
 * Минимальные утилиты для JSON-ответа (на случай, если нет в инклуде)
 */
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
    function reply_ok($data = []) {
        $data = is_array($data) ? $data : [];
        reply_json(array_merge(['ok' => true], $data), 200);
    }
    function reply_err($msg, $code = 400) { reply_json(['ok' => false, 'error' => $msg], $code); }
}

try {
    $db     = pdo();
    $action = $_GET['action'] ?? '';
    if ($action !== 'list') reply_err('Неизвестное действие', 400);

    // Считываем структуру audit_log
    $cols = [];
    try {
        $rs = $db->query("SHOW COLUMNS FROM `audit_log`");
        foreach ($rs as $row) {
            $cols[strtolower($row['Field'])] = $row['Field'];
        }
    } catch (Throwable $e) {
        // Если таблицы нет — создаём «стандартную»
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
              id INT AUTO_INCREMENT PRIMARY KEY,
              event VARCHAR(120) NOT NULL,
              details TEXT NULL,
              user_id INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci;
        ");
        $cols = [
            'id'         => 'id',
            'event'      => 'event',
            'details'    => 'details',
            'user_id'    => 'user_id',
            'created_at' => 'created_at',
        ];
    }

    // Функция подбора колонки по списку синонимов
    $pick = function(array $cands) use ($cols) {
        foreach ($cands as $c) {
            $lc = strtolower($c);
            if (isset($cols[$lc])) return $cols[$lc];
        }
        return null;
    };

    $colId     = $pick(['id','log_id']);
    $colTime   = $pick(['created_at','created','timestamp','ts','time','datetime','date']);
    $colEvent  = $pick(['event','action','event_name','type','operation']);
    $colDetail = $pick(['details','data','payload','info','message','msg','extra','meta']);
    $colUserId = $pick(['user_id','uid','user']);

    // Если ни event, ни action — добавим столбец event (для совместимости будущих логов)
    if (!$colEvent) {
        try {
            $db->exec("ALTER TABLE `audit_log` ADD COLUMN `event` VARCHAR(120) NOT NULL DEFAULT ''");
            $cols['event'] = 'event';
            $colEvent = 'event';
        } catch (Throwable $e) { /* игнор */ }
    }

    // Строим SELECT
    $select = [];
    $select[] = $colTime   ? "`a`.`{$colTime}`   AS `created_at`" : "'—' AS `created_at`";
    $select[] = $colEvent  ? "`a`.`{$colEvent}`  AS `event`"      : "'—' AS `event`";
    $select[] = $colDetail ? "`a`.`{$colDetail}` AS `details`"    : "'—' AS `details`";

    // Подготовим join c users, если возможно
    $usernameExpr = "'—' AS `username`";
    $join = "";

    try {
        $rsU = $db->query("SHOW COLUMNS FROM `users`");
        $uCols = [];
        foreach ($rsU as $r) {
            $uCols[strtolower($r['Field'])] = $r['Field'];
        }

        if ($colUserId && isset($uCols['id']) && isset($uCols['username'])) {
            $usernameExpr = "`u`.`{$uCols['username']}` AS `username`";
            $join = "LEFT JOIN `users` AS `u` ON `u`.`{$uCols['id']}` = `a`.`{$colUserId}`";
        }
    } catch (Throwable $e) {
        // нет таблицы users — просто оставим username = '—'
    }
    $select[] = $usernameExpr;

    $selectSql = implode(",\n                ", $select);

    $from = "FROM `audit_log` AS `a`";

    // LIMIT и ORDER
    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit < 1 || $limit > 10000) $limit = 200;

    $orderBy = $colId
        ? "`a`.`{$colId}` DESC"
        : ($colTime ? "`a`.`{$colTime}` DESC" : "1");

    $sql = "SELECT
                {$selectSql}
            {$from}
            {$join}
            ORDER BY {$orderBy}
            LIMIT {$limit}";

    $items = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    reply_ok(['items' => $items]);

} catch (Throwable $e) {
    reply_err('Ошибка запроса: '.$e->getMessage(), 500);
}
