<?php
/**
 * api_fields_overview.php
 * Сводка по полям для "режима директора": KPI, культуры, список рисков.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_common.php';
require_user();

try {
    $db = pdo();

    // Быстрые проверки, чтобы вернуть понятную ошибку
    $hasFields = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fields'")->fetchColumn();
    if ($hasFields !== 1) {
        json_err('Таблица fields не найдена в базе данных', 500);
    }

    // Пороговые значения (можно будет вынести в настройки)
    $WATER_DAYS = 10;      // полив старше N дней -> риск
    $TREAT_DAYS = 30;      // обработка старше N дней -> риск
    $HARVEST_AFTER_SOW_DAYS = 150; // если после сева прошло N дней и уборка не отмечена

    $rows = $db->query(
        "SELECT f.field_code, f.area_ha, f.sow_date, f.last_water_date, f.treatment_date, f.harvest_date,
                f.culture_id, c.title AS culture
         FROM fields f
         LEFT JOIN cultures c ON c.id = f.culture_id
         ORDER BY f.field_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    $fieldsTotal = 0;
    $areaTotal = 0.0;

    $missingCulture = 0;
    $missingSow = 0;

    $opsAlerts = 0; // суммарно: полив/обработка/уборка
    $attentionTotal = 0;

    $cultStats = []; // title => ['title'=>..., 'count'=>..., 'area'=>...]
    $risks = [];

    $today = new DateTimeImmutable('today');

    $daysSince = function (?string $iso) use ($today): ?int {
        if (!$iso) return null;
        $iso = trim($iso);
        if ($iso === '' || $iso === '0000-00-00') return null;
        try {
            $d = new DateTimeImmutable($iso);
        } catch (Throwable $e) {
            return null;
        }
        $diff = $today->diff($d);
        // если дата в будущем — вернём 0
        $days = (int)$diff->format('%r%a');
        return $days < 0 ? 0 : $days;
    };

    foreach ($rows as $r) {
        $fieldsTotal++;

        $area = $r['area_ha'];
        if ($area !== null && $area !== '') {
            $areaTotal += (float)$area;
        }

        $cultureTitle = (string)($r['culture'] ?? '');
        if ($cultureTitle === '') $cultureTitle = 'Не указана';
        if (!isset($cultStats[$cultureTitle])) {
            $cultStats[$cultureTitle] = ['title' => $cultureTitle, 'count' => 0, 'area' => 0.0];
        }
        $cultStats[$cultureTitle]['count']++;
        if ($area !== null && $area !== '') {
            $cultStats[$cultureTitle]['area'] += (float)$area;
        }

        $reasons = [];

        if (empty($r['culture_id'])) {
            $missingCulture++;
            $reasons[] = 'Не указана культура';
        }
        if (empty($r['sow_date']) || $r['sow_date'] === '0000-00-00') {
            $missingSow++;
            $reasons[] = 'Не указана дата сева';
        }

        // Полив
        $waterDays = $daysSince($r['last_water_date'] ?? null);
        if ($waterDays === null) {
            // если был сев — отсутствие полива хотя бы подсветим как "проверить"
            if (!empty($r['sow_date']) && $r['sow_date'] !== '0000-00-00') {
                $opsAlerts++;
                $reasons[] = 'Нет данных по поливу';
            }
        } elseif ($waterDays >= $WATER_DAYS) {
            $opsAlerts++;
            $reasons[] = 'Полив давно (' . $waterDays . ' дн.)';
        }

        // Обработка
        $tDays = $daysSince($r['treatment_date'] ?? null);
        if ($tDays !== null && $tDays >= $TREAT_DAYS) {
            $opsAlerts++;
            $reasons[] = 'Обработка давно (' . $tDays . ' дн.)';
        }

        // Уборка (если посев давно и уборка не отмечена)
        $sowDays = $daysSince($r['sow_date'] ?? null);
        $hasHarvest = !empty($r['harvest_date']) && $r['harvest_date'] !== '0000-00-00';
        if ($sowDays !== null && !$hasHarvest && $sowDays >= $HARVEST_AFTER_SOW_DAYS) {
            $opsAlerts++;
            $reasons[] = 'Уборка не отмечена (' . $sowDays . ' дн. после сева)';
        }

        if (!empty($reasons)) {
            $attentionTotal++;
            $risks[] = [
                'field_code' => (string)$r['field_code'],
                'reasons' => $reasons,
            ];
        }
    }

    // Сортируем культуры по площади, затем по количеству
    $cultures = array_values($cultStats);
    usort($cultures, function ($a, $b) {
        $da = (float)($a['area'] ?? 0);
        $db = (float)($b['area'] ?? 0);
        if ($da === $db) return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
        return $db <=> $da;
    });

    // Сортируем риски по количеству причин (больше причин -> выше)
    usort($risks, function ($a, $b) {
        return count($b['reasons'] ?? []) <=> count($a['reasons'] ?? []);
    });

    $now = new DateTimeImmutable('now');

    json_ok([
        'generated_at' => $now->format(DATE_ATOM),
        'generated_at_human' => $now->format('d.m.Y H:i'),
        'kpi' => [
            'fields_total' => $fieldsTotal,
            'area_total' => round($areaTotal, 2),
            'missing_culture' => $missingCulture,
            'missing_sow_date' => $missingSow,
            'ops_alerts' => $opsAlerts,
            'attention_total' => $attentionTotal,
            'thresholds' => [
                'water_days' => $WATER_DAYS,
                'treatment_days' => $TREAT_DAYS,
                'harvest_after_sow_days' => $HARVEST_AFTER_SOW_DAYS,
            ],
        ],
        'cultures' => $cultures,
        'risks' => array_slice($risks, 0, 50),
    ]);

} catch (Throwable $e) {
    json_err('Ошибка запроса сводки', 500);
}
