<?php
/**
 * field_save.php
 * Создание/обновление записи участка в таблице `fields`.
 *
 * Требования:
 *   - Пользователь должен быть администратором (require_admin()).
 *   - Метод: POST.
 *   - Должен присутствовать валидный CSRF-токен (поле `csrf`).
 *
 * Входные поля (имена строго соответствуют форме в dashboard.php):
 *   - field_code        (строка, 3–4 цифры)
 *   - area_ha           (число, допускается пусто)
 *   - plow_date         (YYYY-MM-DD или пусто)
 *   - sow_date          (YYYY-MM-DD или пусто)
 *   - culture_id        (целое или пусто)
 *   - treatment_date    (YYYY-MM-DD или пусто)
 *   - treatment_desc    (строка или пусто)
 *   - last_water_date   (YYYY-MM-DD или пусто)
 *   - harvest_date      (YYYY-MM-DD или пусто)
 *   - gross_yield       (строка/число, хранится в VARCHAR(64), допускается пусто)
 *   - avg_yield         (строка/число, хранится в VARCHAR(64), допускается пусто)
 *   - notes             (TEXT, допускается пусто)
 *   - csrf              (CSRF-токен)
 *
 * Схема таблицы `fields` (по твоему дампу):
 *   id INT PK AI,
 *   field_code CHAR(4) UNIQUE,
 *   area_ha DECIMAL(10,2) NULL,
 *   plow_date DATE NULL,
 *   sow_date DATE NULL,
 *   culture_id INT NULL,
 *   treatment_date DATE NULL,
 *   treatment_desc VARCHAR(255) NULL,
 *   last_water_date DATE NULL,
 *   harvest_date DATE NULL,
 *   gross_yield VARCHAR(64) NULL,
 *   avg_yield VARCHAR(64) NULL,
 *   notes TEXT NULL
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guard.php';       // гарантирует, что пользователь авторизован для страниц
require_once __DIR__ . '/api_common.php';  // JSON-хелперы, CSRF, права, pdo()

// Разрешаем только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Метод не поддерживается', 405);
}

// Для записи нужны права администратора
require_admin();

// CSRF
check_csrf_or_die($_POST['csrf'] ?? '');

// -------- Валидация входных данных --------
$code = post_str('field_code');
if (!preg_match('/^\d{3,4}$/', $code)) {
    json_err('Кадастровый номер должен состоять из 3–4 цифр');
}

// Числа/даты/строки
$area_ha        = (($_POST['area_ha'] ?? '') !== '') ? (float)$_POST['area_ha'] : null;

$plow_date      = null_if_empty($_POST['plow_date']      ?? null);
$sow_date       = null_if_empty($_POST['sow_date']       ?? null);
$culture_id_raw = null_if_empty($_POST['culture_id']     ?? null);
$treatment_date = null_if_empty($_POST['treatment_date'] ?? null);
$treatment_desc = null_if_empty($_POST['treatment_desc'] ?? null);
$last_water     = null_if_empty($_POST['last_water_date']?? null);
$harvest_date   = null_if_empty($_POST['harvest_date']   ?? null);
$gross_yield    = null_if_empty($_POST['gross_yield']    ?? null); // VARCHAR(64) — оставляем как строку
$avg_yield      = null_if_empty($_POST['avg_yield']      ?? null); // VARCHAR(64) — оставляем как строку
$notes          = null_if_empty($_POST['notes']          ?? null);

// culture_id: либо NULL, либо целое >=1
$culture_id = null;
if ($culture_id_raw !== null) {
    if (!ctype_digit($culture_id_raw)) {
        json_err('Неверный идентификатор культуры');
    }
    $culture_id = (int)$culture_id_raw;
    if ($culture_id <= 0) {
        $culture_id = null;
    }
}

// -------- Сохранение в БД (UPSERT) --------
try {
    $db = pdo();
    $db->beginTransaction();

    $userId = (int)($_SESSION['user']['id'] ?? 0);

    // Проверим, есть ли такой участок
    // Берём строку целиком, чтобы при UPDATE можно было записать историю изменений
    $st = $db->prepare("SELECT * FROM fields WHERE field_code=? LIMIT 1 FOR UPDATE");
    $st->execute([$code]);
    $beforeRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $exists = (bool)$beforeRow;

    if ($exists) {
        // Обновление
        $sql = "UPDATE fields SET
                    area_ha = :area_ha,
                    plow_date = :plow_date,
                    sow_date = :sow_date,
                    culture_id = :culture_id,
                    treatment_date = :treatment_date,
                    treatment_desc = :treatment_desc,
                    last_water_date = :last_water_date,
                    harvest_date = :harvest_date,
                    gross_yield = :gross_yield,
                    avg_yield = :avg_yield,
                    notes = :notes
                WHERE field_code = :field_code";
    } else {
        // Вставка
        $sql = "INSERT INTO fields
                (field_code, area_ha, plow_date, sow_date, culture_id,
                 treatment_date, treatment_desc, last_water_date,
                 harvest_date, gross_yield, avg_yield, notes)
                VALUES
                (:field_code, :area_ha, :plow_date, :sow_date, :culture_id,
                 :treatment_date, :treatment_desc, :last_water_date,
                 :harvest_date, :gross_yield, :avg_yield, :notes)";
    }

    $params = [
        ':field_code'      => $code,
        ':area_ha'         => $area_ha,
        ':plow_date'       => $plow_date,
        ':sow_date'        => $sow_date,
        ':culture_id'      => $culture_id,
        ':treatment_date'  => $treatment_date,
        ':treatment_desc'  => $treatment_desc,
        ':last_water_date' => $last_water,
        ':harvest_date'    => $harvest_date,
        ':gross_yield'     => $gross_yield,
        ':avg_yield'       => $avg_yield,
        ':notes'           => $notes,
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // --- История изменений участка (audit_log) ---
    // Пишем только для UPDATE, и только если реально что-то поменялось.
    if ($exists && $beforeRow) {
        $fieldId = (int)($beforeRow['id'] ?? 0);
        $st2 = $db->prepare("SELECT * FROM fields WHERE id=? LIMIT 1");
        $st2->execute([$fieldId]);
        $afterRow = $st2->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($afterRow) {
            // Определим, изменилось ли что-то из значимых полей
            $watch = [
                'field_code','area_ha','plow_date','sow_date','culture_id',
                'treatment_date','treatment_desc','last_water_date','harvest_date',
                'gross_yield','avg_yield','notes'
            ];
            $changed = false;
            foreach ($watch as $k) {
                $bv = $beforeRow[$k] ?? null;
                $av = $afterRow[$k] ?? null;
                if ((string)($bv ?? '') !== (string)($av ?? '')) { $changed = true; break; }
            }

            if ($changed) {
                try {
                    // Проверим, что audit_log «современный» (с JSON до/после)
                    $cols = [];
                    foreach ($db->query("SHOW COLUMNS FROM `audit_log`") as $r) {
                        $cols[strtolower($r['Field'])] = true;
                    }

                    if (isset($cols['action'], $cols['subject'], $cols['before_json'], $cols['after_json'], $cols['created_at'])) {
                        $sqlIns = "INSERT INTO audit_log (user_id, action, subject, subject_id, before_json, after_json, created_at)
                                  VALUES (:uid, :act, :subj, :sid, :b, :a, NOW())";
                        $ins = $db->prepare($sqlIns);
                        $ins->execute([
                            ':uid'  => $userId ?: null,
                            ':act'  => 'field_update',
                            ':subj' => 'fields',
                            ':sid'  => $fieldId ?: null,
                            ':b'    => json_encode($beforeRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ':a'    => json_encode($afterRow,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    } else {
                        // Фолбэк: если таблица старая, пишем кратко (event/details)
                        if (isset($cols['event']) && isset($cols['details'])) {
                            $ins = $db->prepare("INSERT INTO audit_log (event, details, user_id, created_at) VALUES (?,?,?,NOW())");
                            $ins->execute(['field_update', $code, $userId ?: null]);
                        }
                    }
                } catch (Throwable $e) {
                    // История не должна ломать сохранение
                    error_log('field_save audit error: '.$e->getMessage());
                }
            }
        }
    }

    $db->commit();
    json_ok(); // => { ok: true }
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    // Можно раскомментировать для отладки (лог ошибок):
    error_log('field_save error: ' . $e->getMessage());

    json_err('Ошибка сохранения', 500);
}
