<?php
/**
 * admin_api_field_history.php
 * История изменений по полям (участкам).
 *
 * Таблица источника: audit_log (action='field_update', subject='fields', before_json/after_json).
 * Доступ: только админ.
 */

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/api_common.php';
require_admin();

function reply_json(array $data, int $code = 200): void {
    while (ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reply_ok(array $data = []): void {
    reply_json(['ok' => true] + $data, 200);
}

function reply_err(string $msg, int $code = 400): void {
    reply_json(['ok' => false, 'error' => $msg], $code);
}

function safe_date(?string $s): ?string {
    $s = trim((string)($s ?? ''));
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

try {
    $db = pdo();

    // Быстрый check структуры audit_log
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM `audit_log`") as $r) {
        $cols[strtolower($r['Field'])] = true;
    }

    if (!isset($cols['action'], $cols['subject'], $cols['before_json'], $cols['after_json'], $cols['created_at'])) {
        reply_err('audit_log не поддерживает историю (не хватает колонок before_json/after_json)', 500);
    }

    $action = $_GET['action'] ?? 'list';

    // Карта культур для красивого вывода
    $cultMap = [];
    try {
        foreach ($db->query("SELECT id, title FROM cultures") as $c) {
            $cultMap[(int)$c['id']] = (string)$c['title'];
        }
    } catch (Throwable $e) {
        // если cultures нет — не падаем
    }

    $labels = [
        'field_code'        => 'Участок',
        'area_ha'           => 'Площадь (га)',
        'culture_id'        => 'Культура',
        'plow_date'         => 'Вспашка',
        'sow_date'          => 'Сев',
        'treatment_date'    => 'Обработка (дата)',
        'treatment_desc'    => 'Обработка (описание)',
        'last_water_date'   => 'Последний полив',
        'harvest_date'      => 'Уборка',
        'gross_yield'       => 'Валовый сбор',
        'avg_yield'         => 'Средняя урожайность',
        'notes'             => 'Заметки',
        // запасные (в БД могут присутствовать)
        'last_treatment_date' => 'Последняя обработка',
        'last_water_date'     => 'Последний полив',
        'harvest_date'        => 'Уборка',
    ];

    $watchKeys = array_keys($labels);

    if ($action === 'meta') {
        // Пользователи (админы и/или все)
        $users = [];
        try {
            $st = $db->query("SELECT id, username, surname, name, role FROM users ORDER BY role='admin' DESC, id ASC");
            $users = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        // Список полей (коды)
        $fields = [];
        try {
            $st = $db->query("SELECT id, field_code FROM fields ORDER BY field_code ASC");
            $fields = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $total = 0;
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE action='field_update' AND subject='fields'");
            $st->execute();
            $total = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        reply_ok([
            'users'  => $users,
            'fields' => $fields,
            'total'  => $total,
        ]);
    }

    if ($action !== 'list') {
        reply_err('Неизвестное действие', 400);
    }

    $fieldCode = trim((string)($_GET['field_code'] ?? ''));
    $fieldCode = preg_replace('/\D+/', '', $fieldCode);
    $fieldCode = substr($fieldCode, 0, 4);

    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId < 0) $userId = 0;

    $dateFrom = safe_date($_GET['date_from'] ?? null);
    $dateTo   = safe_date($_GET['date_to']   ?? null);

    $limit  = (int)($_GET['limit'] ?? 40);
    $offset = (int)($_GET['offset'] ?? 0);
    if ($limit < 1) $limit = 40;
    if ($limit > 200) $limit = 200;
    if ($offset < 0) $offset = 0;

    $where = ["a.action='field_update'", "a.subject='fields'"];
    $params = [];

    if ($userId > 0) {
        $where[] = "a.user_id = :uid";
        $params[':uid'] = $userId;
    }

    if ($dateFrom) {
        $where[] = "DATE(a.created_at) >= :dfrom";
        $params[':dfrom'] = $dateFrom;
    }
    if ($dateTo) {
        $where[] = "DATE(a.created_at) <= :dto";
        $params[':dto'] = $dateTo;
    }

    // Фильтр по коду поля: через JSON и через join на fields (на случай разных сохранений)
    if ($fieldCode !== '') {
        $where[] = "(
            JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.field_code')) = :fcode
            OR JSON_UNQUOTE(JSON_EXTRACT(a.before_json,'$.field_code')) = :fcode
            OR f.field_code = :fcode
        )";
        $params[':fcode'] = $fieldCode;
    }

    $whereSql = implode(' AND ', $where);

    // Total для плашки
    $stCnt = $db->prepare("SELECT COUNT(*)
                          FROM audit_log a
                          LEFT JOIN fields f ON f.id = a.subject_id
                          WHERE {$whereSql}");
    foreach ($params as $k=>$v) $stCnt->bindValue($k, $v);
    $stCnt->execute();
    $total = (int)$stCnt->fetchColumn();

    $sql = "SELECT
              a.id,
              a.created_at,
              a.user_id,
              a.subject_id,
              a.before_json,
              a.after_json,
              u.username,
              u.surname,
              u.name,
              COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.field_code')),
                JSON_UNQUOTE(JSON_EXTRACT(a.before_json,'$.field_code')),
                f.field_code
              ) AS field_code
            FROM audit_log a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN fields f ON f.id = a.subject_id
            WHERE {$whereSql}
            ORDER BY a.id DESC
            LIMIT :lim OFFSET :off";

    $st = $db->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = [];

    foreach ($rows as $r) {
        $before = json_decode((string)($r['before_json'] ?? ''), true);
        $after  = json_decode((string)($r['after_json']  ?? ''), true);
        if (!is_array($before)) $before = [];
        if (!is_array($after))  $after  = [];

        $changes = [];

        foreach ($watchKeys as $k) {
            $bv = $before[$k] ?? null;
            $av = $after[$k]  ?? null;

            // Нормализация
            if ($k === 'culture_id') {
                $bvNorm = ($bv === null || $bv === '' ? null : (int)$bv);
                $avNorm = ($av === null || $av === '' ? null : (int)$av);
                if ($bvNorm === $avNorm) continue;

                $beforeText = $bvNorm ? ($cultMap[$bvNorm] ?? ('ID '.$bvNorm)) : '—';
                $afterText  = $avNorm ? ($cultMap[$avNorm] ?? ('ID '.$avNorm)) : '—';

                $changes[] = [
                    'key'   => $k,
                    'label' => $labels[$k] ?? $k,
                    'before'=> $beforeText,
                    'after' => $afterText,
                ];
                continue;
            }

            $bvStr = (string)($bv ?? '');
            $avStr = (string)($av ?? '');
            if ($bvStr === $avStr) continue;

            $beforeText = ($bv === null || $bvStr === '') ? '—' : $bvStr;
            $afterText  = ($av === null || $avStr === '') ? '—' : $avStr;

            // Короткий превью для очень длинных заметок
            if ($k === 'notes') {
                $beforeText = (mb_strlen($beforeText) > 120) ? (mb_substr($beforeText, 0, 120).'…') : $beforeText;
                $afterText  = (mb_strlen($afterText)  > 120) ? (mb_substr($afterText,  0, 120).'…') : $afterText;
            }

            $changes[] = [
                'key'   => $k,
                'label' => $labels[$k] ?? $k,
                'before'=> $beforeText,
                'after' => $afterText,
            ];
        }

        // Если по каким-то причинам «watch» ничего не поймал — покажем хотя бы факт редактирования
        if (!$changes) {
            $changes[] = [
                'key'   => '—',
                'label' => 'Изменение',
                'before'=> '—',
                'after' => 'Обновлено',
            ];
        }

        $u = [
            'username' => (string)($r['username'] ?? ''),
            'surname'  => (string)($r['surname']  ?? ''),
            'name'     => (string)($r['name']     ?? ''),
        ];
        $fio = trim(($u['surname'] ? $u['surname'].' ' : '').($u['name'] ?? ''));
        $userDisplay = $fio !== ''
            ? ($u['username'] !== '' ? ($fio.' ('.$u['username'].')') : $fio)
            : ($u['username'] !== '' ? $u['username'] : '—');

        // Короткое резюме: "Площадь, Культура, Сев (+2)"
        $names = [];
        foreach ($changes as $ch) {
            if (!empty($ch['label']) && $ch['label'] !== 'Изменение') $names[] = $ch['label'];
        }
        $names = array_values(array_unique($names));
        $summary = '';
        if ($names) {
            $head = array_slice($names, 0, 3);
            $summary = implode(', ', $head);
            if (count($names) > 3) {
                $summary .= ' (+' . (count($names) - 3) . ')';
            }
        } else {
            $summary = 'Обновлено';
        }

        $items[] = [
            'id'          => (int)$r['id'],
            'created_at'  => (string)($r['created_at'] ?? ''),
            'field_code'  => (string)($r['field_code'] ?? ''),
            'user_id'     => (int)($r['user_id'] ?? 0),
            'user_display'=> $userDisplay,
            'summary'     => $summary,
            'changes'     => $changes,
        ];
    }

    $hasMore = ($offset + $limit) < $total;

    reply_ok([
        'items'    => $items,
        'total'    => $total,
        'has_more' => $hasMore,
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

} catch (Throwable $e) {
    reply_err('Ошибка: '.$e->getMessage(), 500);
}
