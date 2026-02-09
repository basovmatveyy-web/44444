<?php
// admin_api_import_unmatched.php — manage unmatched rows after Excel import

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/admin_api_util.php';
require_once __DIR__ . '/year_lib.php';

require_user();
require_admin();

$db = pdo();
ensure_field_passport_tables($db);
ensure_field_accounting_rows_tables($db);
ensure_excel_import_queue_tables($db);

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));

function nstr($v): string {
    if ($v === null) return '';
    return trim((string)$v);
}

/** @return string[] */
function parse_efis_list_local($v): array {
    $s = trim((string)($v ?? ''));
    if ($s === '') return [];
    preg_match_all('/(\d{6,8})/', $s, $m);
    $out = [];
    foreach (($m[1] ?? []) as $num) {
        $num = trim($num);
        if ($num === '') continue;
        $out[] = $num;
    }
    return array_values(array_unique($out));
}

function get_max_pos(PDO $db, string $table, int $fieldId, int $year): int {
    try {
        $st = $db->prepare("SELECT COALESCE(MAX(pos), -1) AS m FROM `$table` WHERE field_id=? AND `year`=?");
        $st->execute([$fieldId, $year]);
        $m = (int)($st->fetchColumn() ?? -1);
        return $m + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

if ($action === 'batches') {
    $year = normalize_season_year($_GET['year'] ?? '', 2026);
    $limit = 30;
    $st = $db->prepare('SELECT id, `year`, file_name, user_id, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i") AS created_at FROM excel_import_batch WHERE `year`=? ORDER BY id DESC LIMIT '.$limit);
    $st->execute([$year]);
    json_ok(['items' => $st->fetchAll() ?: []]);
}

if ($action === 'list') {
    $year = normalize_season_year($_GET['year'] ?? '', 2026);
    $status = nstr($_GET['status'] ?? 'open');
    if (!in_array($status, ['open','resolved','ignored'], true)) $status = 'open';
    $batchId = (int)($_GET['batch_id'] ?? 0);
    $q = nstr($_GET['q'] ?? '');

    $where = ['u.`year` = :year', 'u.status = :status'];
    $params = [':year' => $year, ':status' => $status];
    if ($batchId > 0) {
        $where[] = 'u.batch_id = :bid';
        $params[':bid'] = $batchId;
    }
    if ($q !== '') {
        $where[] = '(u.local_name LIKE :q OR u.efis_raw LIKE :q OR u.crop_text LIKE :q)';
        $params[':q'] = '%'.$q.'%';
    }

    $sql = 'SELECT u.id, u.batch_id, u.`year`, u.kind, u.local_name, u.efis_raw, u.efis_json, u.area_ha, u.crop_text, u.gross_yield, u.avg_yield,
                   u.fertilized, u.purchased, u.elite, u.note, u.status,
                   DATE_FORMAT(u.created_at, "%Y-%m-%d %H:%i") AS created_at,
                   u.resolved_field_id,
                   TRIM(f.field_code) AS resolved_code,
                   b.file_name,
                   DATE_FORMAT(b.created_at, "%Y-%m-%d %H:%i") AS batch_created_at
            FROM excel_import_unmatched u
            LEFT JOIN fields f ON f.id = u.resolved_field_id
            LEFT JOIN excel_import_batch b ON b.id = u.batch_id
            WHERE '.implode(' AND ', $where).'
            ORDER BY u.id DESC
            LIMIT 400';

    $st = $db->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll() ?: [];

    // counts
    $stC = $db->prepare('SELECT status, COUNT(*) AS c FROM excel_import_unmatched WHERE `year`=? GROUP BY status');
    $stC->execute([$year]);
    $counts = ['open'=>0,'resolved'=>0,'ignored'=>0];
    foreach (($stC->fetchAll() ?: []) as $r) {
        $s = $r['status'] ?? '';
        if (isset($counts[$s])) $counts[$s] = (int)$r['c'];
    }

    json_ok(['items' => $items, 'counts' => $counts]);
}

if ($action === 'resolve') {
    check_csrf_or_die(nstr($_POST['csrf'] ?? ''));

    $id = (int)($_POST['id'] ?? 0);
    $fieldCode = preg_replace('/\D+/', '', nstr($_POST['field_code'] ?? ''));
    if ($id <= 0) json_err('Некорректный ID');
    if (!preg_match('/^(\d{3,4})$/', $fieldCode)) json_err('Код поля должен быть 3–4 цифры');

    $st = $db->prepare('SELECT * FROM excel_import_unmatched WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('Строка не найдена');
    if (($row['status'] ?? '') !== 'open') json_err('Строка уже обработана');

    $year = (int)$row['year'];
    $kind = (string)$row['kind'];

    $db->beginTransaction();
    try {
        // Find or create field
        $stF = $db->prepare('SELECT id FROM fields WHERE field_code=? LIMIT 1');
        $stF->execute([$fieldCode]);
        $fid = $stF->fetchColumn();
        if (!$fid) {
            $stI = $db->prepare('INSERT INTO fields (field_code, area_ha) VALUES (?, NULL)');
            $stI->execute([$fieldCode]);
            $fid = (int)$db->lastInsertId();
        } else {
            $fid = (int)$fid;
        }

        // best-effort: set local_name if empty
        $lname = trim((string)($row['local_name'] ?? ''));
        if ($lname !== '') {
            $stP = $db->prepare('INSERT INTO field_passport (field_id, local_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE local_name=IF(local_name IS NULL OR local_name="", VALUES(local_name), local_name)');
            $stP->execute([$fid, $lname]);
        }

        // EFIS links (if present)
        $efisList = [];
        if (!empty($row['efis_json'])) {
            $tmp = json_decode((string)$row['efis_json'], true);
            if (is_array($tmp)) {
                foreach ($tmp as $x) {
                    $x = trim((string)$x);
                    if ($x !== '') $efisList[] = $x;
                }
            }
        }
        if (!$efisList) {
            $efisList = parse_efis_list_local($row['efis_raw'] ?? '');
        }
        $efisList = array_values(array_unique($efisList));
        if ($efisList) {
            $stE = $db->prepare('INSERT IGNORE INTO field_efis (field_id, efis_code, area_ha, note) VALUES (?, ?, NULL, NULL)');
            foreach ($efisList as $ef) {
                $stE->execute([$fid, $ef]);
            }
        }
        $efisFirst = $efisList[0] ?? null;

        if ($kind === 'plan') {
            $pos = get_max_pos($db, 'field_plan_rows', $fid, $year);
            $stI = $db->prepare('INSERT INTO field_plan_rows (field_id, year, pos, efis_code, area_ha, crop_text, fertilized, purchased, elite, note)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stI->execute([
                $fid,
                $year,
                $pos,
                $efisFirst,
                $row['area_ha'] !== null ? (float)$row['area_ha'] : null,
                $row['crop_text'] !== null ? (string)$row['crop_text'] : null,
                $row['fertilized'],
                $row['purchased'],
                $row['elite'],
                $row['note'],
            ]);
        } elseif ($kind === 'harvest') {
            $pos = get_max_pos($db, 'field_harvest_rows', $fid, $year);
            $stI = $db->prepare('INSERT INTO field_harvest_rows (field_id, year, pos, efis_code, area_ha, crop_text, gross_yield, avg_yield, note)
                                 VALUES (?,?,?,?,?,?,?,?,?)');
            $stI->execute([
                $fid,
                $year,
                $pos,
                $efisFirst,
                $row['area_ha'] !== null ? (float)$row['area_ha'] : null,
                $row['crop_text'],
                $row['gross_yield'],
                $row['avg_yield'],
                $row['note'],
            ]);
        } else {
            throw new RuntimeException('Неизвестный тип строки');
        }

        // Mark resolved
        $stU = $db->prepare('UPDATE excel_import_unmatched SET status="resolved", resolved_field_id=?, resolved_by=?, resolved_at=NOW() WHERE id=?');
        $stU->execute([$fid, (int)($_SESSION['user']['id'] ?? 0), $id]);

        try {
            log_event_dynamic($db, 'excel_unmatched_resolve', 'Ручная привязка строки импорта #'.$id.' к полю '.$fieldCode.' (сезон '.$year.')', (int)($_SESSION['user']['id'] ?? 0));
        } catch (Throwable $e) {}

        $db->commit();
        json_ok(['id' => $id, 'field_id' => $fid, 'field_code' => $fieldCode]);
    } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $e2) {}
        json_err('Ошибка привязки: '.$e->getMessage());
    }
}

if ($action === 'ignore') {
    check_csrf_or_die(nstr($_POST['csrf'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_err('Некорректный ID');

    $st = $db->prepare('UPDATE excel_import_unmatched SET status="ignored", resolved_by=?, resolved_at=NOW() WHERE id=? AND status="open"');
    $st->execute([(int)($_SESSION['user']['id'] ?? 0), $id]);
    if ($st->rowCount() <= 0) json_err('Не удалось пометить (возможно, уже обработано)');

    try {
        log_event_dynamic($db, 'excel_unmatched_ignore', 'Пометил строку импорта #'.$id.' как «игнор»', (int)($_SESSION['user']['id'] ?? 0));
    } catch (Throwable $e) {}

    json_ok(['id' => $id]);
}

json_err('Неизвестное действие');
