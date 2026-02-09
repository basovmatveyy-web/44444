<?php
/**
 * api_field_events.php
 * История операций по участку (полив/удобрения/посев/обработки/уборка).
 *
 * GET:
 *   ?action=list&type=water|fert|sow|treat|harvest&code=1234
 * POST:
 *   action=add&type=...&code=1234 + поля формы + csrf
 *
 * Важно:
 * - Чтение доступно любому авторизованному пользователю.
 * - Добавление/изменение — только admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

require_user();

function is_admin(): bool {
    return (($_SESSION['user']['role'] ?? 'user') === 'admin');
}

function clean_code(string $code): string {
    $code = preg_replace('/\D+/', '', $code);
    // допускаем 3–4 цифры; не дополняем нулями
    return substr($code, 0, 4);
}
function is_iso_date(?string $s): bool {
    $s = trim((string)$s);
    if ($s === '') return false;
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function table_exists(PDO $db, string $name): bool {
    $st = $db->prepare("SHOW TABLES LIKE ?");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}

/**
 * Пытаемся создать таблицы истории (если их ещё нет).
 * В большинстве хостингов у пользователя БД достаточно прав на CREATE.
 */
function ensure_tables(PDO $db): void {
    // Полив
    $db->exec("CREATE TABLE IF NOT EXISTS field_waterings (
        id INT NOT NULL AUTO_INCREMENT,
        field_id INT NOT NULL,
        water_date DATE NOT NULL,
        amount VARCHAR(64) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_field_date (field_id, water_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    // Удобрения
    $db->exec("CREATE TABLE IF NOT EXISTS field_fertilizations (
        id INT NOT NULL AUTO_INCREMENT,
        field_id INT NOT NULL,
        fert_date DATE NOT NULL,
        fertilizer VARCHAR(140) DEFAULT NULL,
        dose VARCHAR(80) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_field_date (field_id, fert_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    // Посев
    $db->exec("CREATE TABLE IF NOT EXISTS field_sowings (
        id INT NOT NULL AUTO_INCREMENT,
        field_id INT NOT NULL,
        sow_date DATE NOT NULL,
        culture_id INT DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_field_date (field_id, sow_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    // Уборка
    $db->exec("CREATE TABLE IF NOT EXISTS field_harvests (
        id INT NOT NULL AUTO_INCREMENT,
        field_id INT NOT NULL,
        harvest_date DATE NOT NULL,
        gross_yield VARCHAR(64) DEFAULT NULL,
        avg_yield VARCHAR(64) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_field_date (field_id, harvest_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
}

/**
 * Fallback: достаём историю изменения конкретных ключей из audit_log.
 * Нужно, чтобы «история» работала даже если новые таблицы пока не поставили.
 */
function history_from_audit(PDO $db, int $fieldId, string $fieldCode, array $keys, array $cultureMap): array {
    $st = $db->prepare("SELECT id, created_at, user_id, before_json, after_json
                        FROM audit_log
                        WHERE action='field_update' AND subject='fields' AND subject_id = ?
                        ORDER BY id DESC
                        LIMIT 300");
    $st->execute([$fieldId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // Users map (id -> display)
    $userMap = [];
    try {
        foreach ($db->query("SELECT id, username, surname, name FROM users") as $u) {
            $name = trim((string)($u['surname'] ?? '').' '.(string)($u['name'] ?? ''));
            if ($name === '') $name = (string)($u['username'] ?? ('#'.$u['id']));
            $userMap[(int)$u['id']] = $name;
        }
    } catch (Throwable $e) {}

    $items = [];
    foreach ($rows as $r) {
        $before = json_decode((string)($r['before_json'] ?? ''), true);
        $after  = json_decode((string)($r['after_json'] ?? ''), true);
        if (!is_array($before)) $before = [];
        if (!is_array($after))  $after  = [];

        $changed = false;
        $payload = [];
        foreach ($keys as $k) {
            $bv = $before[$k] ?? null;
            $av = $after[$k] ?? null;
            if ((string)($bv ?? '') === (string)($av ?? '')) continue;
            $changed = true;
            $payload[$k] = $av;
        }
        if (!$changed) continue;

        // попытка выбрать «основную» дату
        $date = null;
        foreach (['last_water_date','sow_date','harvest_date'] as $dk) {
            if (in_array($dk, $keys, true) && is_iso_date((string)($payload[$dk] ?? ''))) { $date = (string)$payload[$dk]; break; }
        }
        if (!$date) {
            $date = substr((string)$r['created_at'], 0, 10);
        }

        // humanize culture
        if (isset($payload['culture_id'])) {
            $cid = (int)$payload['culture_id'];
            $payload['culture'] = $cid ? ($cultureMap[$cid] ?? ('ID '.$cid)) : null;
        }

        $items[] = [
            'id' => (int)$r['id'],
            'date' => $date,
            'payload' => $payload,
            'source' => 'audit',
            'created_at' => (string)$r['created_at'],
            'user' => $userMap[(int)($r['user_id'] ?? 0)] ?? null,
            'field_code' => $fieldCode,
        ];
    }
    return $items;
}

try {
    $db = pdo();

    $action = $_REQUEST['action'] ?? 'list';
    $type   = strtolower(trim((string)($_REQUEST['type'] ?? '')));
    $code   = clean_code((string)($_REQUEST['code'] ?? ''));

    if (!preg_match('/^\d{3,4}$/', $code)) {
        json_err('Неверный код участка');
    }
    if (!in_array($type, ['water','fert','sow','treat','harvest'], true)) {
        json_err('Неверный тип истории');
    }

    // поле
    $stF = $db->prepare("SELECT id, field_code, culture_id,
                                DATE_FORMAT(last_water_date,'%Y-%m-%d') AS last_water_date,
                                DATE_FORMAT(sow_date,'%Y-%m-%d') AS sow_date,
                                DATE_FORMAT(harvest_date,'%Y-%m-%d') AS harvest_date,
                                DATE_FORMAT(treatment_date,'%Y-%m-%d') AS treatment_date,
                                treatment_desc
                         FROM fields WHERE field_code=? LIMIT 1");
    $stF->execute([$code]);
    $field = $stF->fetch(PDO::FETCH_ASSOC);
    if (!$field) {
        json_err('Участок не найден', 404);
    }
    $fieldId = (int)$field['id'];

    // cultures map
    $cultureMap = [];
    try {
        foreach ($db->query("SELECT id, title FROM cultures") as $c) {
            $cultureMap[(int)$c['id']] = (string)$c['title'];
        }
    } catch (Throwable $e) {}

    if ($action === 'list') {
        $items = [];
        $supportsAdd = false;
        $source = 'table';

        if (in_array($type, ['water','fert','sow','harvest'], true)) {
            $supportsAdd = true; // можно добавлять (создадим таблицы при add)
        }

        if ($type === 'water' && table_exists($db, 'field_waterings')) {
            $st = $db->prepare("SELECT w.id,
                                       DATE_FORMAT(w.water_date,'%Y-%m-%d') AS date,
                                       w.amount,
                                       w.notes,
                                       w.created_at,
                                       u.username, u.surname, u.name
                                FROM field_waterings w
                                LEFT JOIN users u ON u.id = w.created_by
                                WHERE w.field_id=?
                                ORDER BY w.water_date DESC, w.id DESC");
            $st->execute([$fieldId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif ($type === 'fert' && table_exists($db, 'field_fertilizations')) {
            $st = $db->prepare("SELECT f.id,
                                       DATE_FORMAT(f.fert_date,'%Y-%m-%d') AS date,
                                       f.fertilizer,
                                       f.dose,
                                       f.notes,
                                       f.created_at,
                                       u.username, u.surname, u.name
                                FROM field_fertilizations f
                                LEFT JOIN users u ON u.id = f.created_by
                                WHERE f.field_id=?
                                ORDER BY f.fert_date DESC, f.id DESC");
            $st->execute([$fieldId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif ($type === 'sow' && table_exists($db, 'field_sowings')) {
            $st = $db->prepare("SELECT s.id,
                                       DATE_FORMAT(s.sow_date,'%Y-%m-%d') AS date,
                                       s.culture_id,
                                       c.title AS culture,
                                       s.notes,
                                       s.created_at,
                                       u.username, u.surname, u.name
                                FROM field_sowings s
                                LEFT JOIN cultures c ON c.id = s.culture_id
                                LEFT JOIN users u ON u.id = s.created_by
                                WHERE s.field_id=?
                                ORDER BY s.sow_date DESC, s.id DESC");
            $st->execute([$fieldId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif ($type === 'harvest' && table_exists($db, 'field_harvests')) {
            $st = $db->prepare("SELECT h.id,
                                       DATE_FORMAT(h.harvest_date,'%Y-%m-%d') AS date,
                                       h.gross_yield,
                                       h.avg_yield,
                                       h.notes,
                                       h.created_at,
                                       u.username, u.surname, u.name
                                FROM field_harvests h
                                LEFT JOIN users u ON u.id = h.created_by
                                WHERE h.field_id=?
                                ORDER BY h.harvest_date DESC, h.id DESC");
            $st->execute([$fieldId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif ($type === 'treat') {
            // field_treatments есть в схеме, используем её.
            $supportsAdd = true;
            $st = $db->prepare("SELECT t.id,
                                       DATE_FORMAT(t.treat_date,'%Y-%m-%d') AS date,
                                       t.chemical,
                                       t.notes,
                                       t.created_at
                                FROM field_treatments t
                                WHERE t.field_id=?
                                ORDER BY t.treat_date DESC, t.id DESC");
            $st->execute([$fieldId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);

            // fallback: если пусто, покажем текущую «Обработку» из fields
            if (!$items && !empty($field['treatment_date'])) {
                $items[] = [
                    'id' => 0,
                    'date' => (string)$field['treatment_date'],
                    'chemical' => (string)($field['treatment_desc'] ?? ''),
                    'notes' => null,
                    'created_at' => null,
                    'source' => 'fields'
                ];
            }
        }
        else {
            // fallback на audit_log
            $source = 'audit';
            if ($type === 'water') {
                $items = history_from_audit($db, $fieldId, $code, ['last_water_date'], $cultureMap);
            } elseif ($type === 'sow') {
                $items = history_from_audit($db, $fieldId, $code, ['sow_date','culture_id'], $cultureMap);
            } elseif ($type === 'harvest') {
                $items = history_from_audit($db, $fieldId, $code, ['harvest_date','gross_yield','avg_yield'], $cultureMap);
            } else {
                // fert без таблицы и без ключа в fields — пусто
                $items = [];
            }
        }

        // нормализуем имя
        foreach ($items as &$it) {
            if (isset($it['surname']) || isset($it['name']) || isset($it['username'])) {
                $nm = trim((string)($it['surname'] ?? '').' '.(string)($it['name'] ?? ''));
                if ($nm === '') $nm = (string)($it['username'] ?? '');
                $it['user'] = $nm ?: null;
                unset($it['surname'],$it['name'],$it['username']);
            }
        }
        unset($it);

        json_ok([
            'field' => [
                'id' => $fieldId,
                'code' => $code,
            ],
            'type' => $type,
            'source' => $source,
            'supports_add' => $supportsAdd,
            'is_admin' => is_admin(),
            'items' => $items,
        ]);
    }

    if ($action === 'add') {
        if (!is_admin()) {
            json_err('Недостаточно прав', 403);
        }
        check_csrf_or_die($_POST['csrf'] ?? '');

        // Для новых таблиц — создадим, если нужно.
        if (in_array($type, ['water','fert','sow','harvest'], true)) {
            ensure_tables($db);
        }

        $uid = (int)($_SESSION['user']['id'] ?? 0);

        if ($type === 'water') {
            $date = trim((string)($_POST['date'] ?? ''));
            if (!is_iso_date($date)) json_err('Укажите корректную дату');
            $amount = null_if_empty((string)($_POST['amount'] ?? ''));
            $notes  = null_if_empty((string)($_POST['notes'] ?? ''));
            $st = $db->prepare("INSERT INTO field_waterings (field_id, water_date, amount, notes, created_by)
                                VALUES (?,?,?,?,?)");
            $st->execute([$fieldId, $date, $amount, $notes, $uid ?: null]);

            // обновим «последний полив» на всякий
            $db->prepare("UPDATE fields
                          SET last_water_date = CASE
                            WHEN last_water_date IS NULL OR last_water_date < ? THEN ?
                            ELSE last_water_date
                          END
                          WHERE id=?")
               ->execute([$date, $date, $fieldId]);
        }
        elseif ($type === 'fert') {
            $date = trim((string)($_POST['date'] ?? ''));
            if (!is_iso_date($date)) json_err('Укажите корректную дату');
            $fert = null_if_empty((string)($_POST['fertilizer'] ?? ''));
            $dose = null_if_empty((string)($_POST['dose'] ?? ''));
            $notes= null_if_empty((string)($_POST['notes'] ?? ''));
            $st = $db->prepare("INSERT INTO field_fertilizations (field_id, fert_date, fertilizer, dose, notes, created_by)
                                VALUES (?,?,?,?,?,?)");
            $st->execute([$fieldId, $date, $fert, $dose, $notes, $uid ?: null]);
        }
        elseif ($type === 'sow') {
            $date = trim((string)($_POST['date'] ?? ''));
            if (!is_iso_date($date)) json_err('Укажите корректную дату');
            $cid = (int)($_POST['culture_id'] ?? 0);
            if ($cid <= 0) $cid = 0;
            $notes= null_if_empty((string)($_POST['notes'] ?? ''));
            $st = $db->prepare("INSERT INTO field_sowings (field_id, sow_date, culture_id, notes, created_by)
                                VALUES (?,?,?,?,?)");
            $st->execute([$fieldId, $date, $cid ?: null, $notes, $uid ?: null]);

            // обновим основную карточку поля (удобно и логично)
            $db->prepare("UPDATE fields SET sow_date = ?, culture_id = ? WHERE id=?")
               ->execute([$date, $cid ?: null, $fieldId]);
        }
        elseif ($type === 'harvest') {
            $date = trim((string)($_POST['date'] ?? ''));
            if (!is_iso_date($date)) json_err('Укажите корректную дату');
            $gy = null_if_empty((string)($_POST['gross_yield'] ?? ''));
            $ay = null_if_empty((string)($_POST['avg_yield'] ?? ''));
            $notes= null_if_empty((string)($_POST['notes'] ?? ''));
            $st = $db->prepare("INSERT INTO field_harvests (field_id, harvest_date, gross_yield, avg_yield, notes, created_by)
                                VALUES (?,?,?,?,?,?)");
            $st->execute([$fieldId, $date, $gy, $ay, $notes, $uid ?: null]);

            $db->prepare("UPDATE fields SET harvest_date = ?, gross_yield = COALESCE(?, gross_yield), avg_yield = COALESCE(?, avg_yield) WHERE id=?")
               ->execute([$date, $gy, $ay, $fieldId]);
        }
        elseif ($type === 'treat') {
            $date = trim((string)($_POST['date'] ?? ''));
            if (!is_iso_date($date)) json_err('Укажите корректную дату');
            $chem = null_if_empty((string)($_POST['chemical'] ?? ''));
            $notes= null_if_empty((string)($_POST['notes'] ?? ''));
            if (!$chem) $chem = '—';
            // На некоторых установках field_treatments мог быть без AUTO_INCREMENT.
            // Пытаемся вставить без id, а если не получится — вставим с вычисленным id.
            try {
                $st = $db->prepare("INSERT INTO field_treatments (field_id, treat_date, chemical, notes)
                                    VALUES (?,?,?,?)");
                $st->execute([$fieldId, $date, $chem, $notes]);
            } catch (Throwable $e) {
                $nextId = 1;
                try {
                    $nextId = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM field_treatments")->fetchColumn();
                } catch (Throwable $e2) {}
                $st = $db->prepare("INSERT INTO field_treatments (id, field_id, treat_date, chemical, notes)
                                    VALUES (?,?,?,?,?)");
                $st->execute([$nextId, $fieldId, $date, $chem, $notes]);
            }

            // как подсказка в карточке — обновим «последнюю обработку»
            $db->prepare("UPDATE fields SET treatment_date = ?, treatment_desc = ? WHERE id=?")
               ->execute([$date, $chem, $fieldId]);
        }
        else {
            json_err('Нельзя добавить в этот тип');
        }

        json_ok(['saved' => true]);
    }

    json_err('Неизвестное действие');

} catch (Throwable $e) {
    // Не показываем детали пользователю, но лог/отладка можно добавить в будущем.
    json_err('Ошибка сервера', 500);
}
