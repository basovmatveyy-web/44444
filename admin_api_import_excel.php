<?php
// admin_api_import_excel.php — Import accounting data from Excel (.xlsx)

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/admin_api_util.php';
require_once __DIR__ . '/year_lib.php';
require_once __DIR__ . '/lib/xlsx_reader.php';

require_user();
require_admin();

$mode = trim((string)($_POST['mode'] ?? 'preview'));
if ($mode !== 'preview' && $mode !== 'import') {
    $mode = 'preview';
}

$year = normalize_season_year($_POST['year'] ?? '', 2026);

if (empty($_FILES['file']) || !is_array($_FILES['file']) || empty($_FILES['file']['tmp_name'])) {
    json_err('Файл не загружен');
}

$tmp = (string)$_FILES['file']['tmp_name'];
$name = (string)($_FILES['file']['name'] ?? 'upload.xlsx');
if (!preg_match('/\.xlsx$/i', $name)) {
    json_err('Нужен файл .xlsx');
}

$db = pdo();

// Ensure schema
ensure_field_passport_tables($db);
ensure_field_accounting_rows_tables($db);

// --- helpers ---
function nstr($v): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    return $s;
}

function is_summary_row(string $s): bool {
    $t = mb_strtolower(trim($s));
    if ($t === '') return false;
    return (strpos($t, 'итого') === 0);
}

function cadastral_to_code(string $cad): ?string {
    $cad = trim($cad);
    if ($cad === '') return null;
    if (preg_match('/:(\d{3,4})\s*$/', $cad, $m)) {
        return $m[1];
    }
    // sometimes plain 3/4 digits
    if (preg_match('/^(\d{3,4})$/', $cad, $m)) {
        return $m[1];
    }
    return null;
}

/** @return string[] */
function parse_efis_list($v): array {
    $s = trim((string)($v ?? ''));
    if ($s === '') return [];

    // Examples:
    //  - 50604-511814
    //  - 511822, и 511823
    //  - 511884 и 511887
    //  - 50604-8977003
    preg_match_all('/(\d{6,8})/', $s, $m);
    $out = [];
    foreach (($m[1] ?? []) as $num) {
        $num = trim($num);
        if ($num === '') continue;
        $out[] = $num;
    }
    // de-dup
    $out = array_values(array_unique($out));
    return $out;
}

function parse_flags_from_crop(string $crop): array {
    $t = mb_strtolower($crop);
    return [
        'fertilized' => (strpos($t, 'удобр') !== false) ? 1 : null,
        'purchased'  => (strpos($t, 'покуп') !== false) ? 1 : null,
        'elite'      => (strpos($t, 'элит') !== false) ? 1 : null,
    ];
}

function find_sheet_by_hint(array $names, array $hints): ?string {
    $low = [];
    foreach ($names as $n) $low[mb_strtolower($n)] = $n;
    foreach ($hints as $h) {
        $h = mb_strtolower($h);
        foreach ($low as $ln => $orig) {
            if (strpos($ln, $h) !== false) return $orig;
        }
    }
    return null;
}

// --- parse file ---
try {
    $reader = XlsxReader::open($tmp);
    $sheetNames = $reader->sheetNames();

    $sheetMap = find_sheet_by_hint($sheetNames, ['кадастр', 'ефис']);
    $sheetPlan = find_sheet_by_hint($sheetNames, ['размещ']);
    $sheetHarvest = find_sheet_by_hint($sheetNames, ['урож']);

    if (!$sheetMap || !$sheetPlan || !$sheetHarvest) {
        $reader->close();
        json_err('Не нашёл нужные листы в файле. Ожидаю: «совмещение кадастра и ЕФИС», «размещение», «урожай».');
    }

    $rowsMap = $reader->readSheet($sheetMap);
    $rowsPlan = $reader->readSheet($sheetPlan);
    $rowsHarv = $reader->readSheet($sheetHarvest);
    $reader->close();

    // 1) Cadastral ↔ EFIS map
    $map = []; // code => ['cadastral_full'=>..., 'efis'=>[]]
    $warnings = [];
    $curCad = '';
    foreach ($rowsMap as $i => $row) {
        if ($i === 0) continue; // header
        $cad = nstr($row[0] ?? '');
        $efisCell = $row[1] ?? '';
        if ($cad !== '') $curCad = $cad;
        if (trim($curCad) === '') continue;
        $efis = parse_efis_list($efisCell);
        if (!$efis) continue;

        $code = cadastral_to_code($curCad);
        if (!$code) {
            $warnings[] = 'Кадастр: не понял номер «'.$curCad.'» (строка '.($i+1).')';
            continue;
        }

        if (!isset($map[$code])) {
            $map[$code] = ['cadastral_full' => $curCad, 'efis' => []];
        }
        // If cadastral appeared earlier and now continues, keep first non-empty
        if ($map[$code]['cadastral_full'] === '' && $curCad !== '') {
            $map[$code]['cadastral_full'] = $curCad;
        }
        $map[$code]['efis'] = array_values(array_unique(array_merge($map[$code]['efis'], $efis)));
    }

    // 2) Placement (plan) rows
    $planRows = []; // field_id => list of rows
    $planUnmatched = 0;
    $planUnmatchedRows = [];
    $localNames = []; // field_id => local_name (best-effort)
    $curName = '';
    foreach ($rowsPlan as $i => $row) {
        if ($i === 0) continue; // header
        $nameCell = nstr($row[0] ?? '');
        $area = $row[1] ?? null;
        $crop = nstr($row[2] ?? '');
        $efisCell = $row[3] ?? null;

        if ($nameCell !== '') {
            if (is_summary_row($nameCell)) continue;
            $curName = trim($nameCell);
        }
        if ($curName === '' || is_summary_row($curName)) continue;

        // skip empty lines
        if ($crop === '' && ($efisCell === null || trim((string)$efisCell) === '') && ($area === null || $area === '')) {
            continue;
        }

        $efisList = parse_efis_list($efisCell);

        $flags = parse_flags_from_crop($crop);
        $areaNum = null;
        if ($area !== null && $area !== '') {
            $areaNum = is_numeric($area) ? (float)$area : null;
        }

        $fieldId = null;
        if ($efisList) {
            // Try match by any efis
            foreach ($efisList as $ef) {
                $st = $db->prepare('SELECT field_id FROM field_efis WHERE efis_code=? LIMIT 1');
                $st->execute([$ef]);
                $fid = $st->fetchColumn();
                if ($fid) { $fieldId = (int)$fid; break; }
            }
        } else {
            // Fallback match by passport local_name
            $st = $db->prepare('SELECT field_id FROM field_passport WHERE local_name=? LIMIT 1');
            $st->execute([$curName]);
            $fid = $st->fetchColumn();
            if ($fid) $fieldId = (int)$fid;
        }

        if (!$fieldId) {
            $planUnmatched++;
            $planUnmatchedRows[] = [
                'local_name' => $curName,
                'efis_raw' => nstr($efisCell),
                'efis_list' => $efisList,
                'area_ha' => $areaNum,
                'crop_text' => $crop,
                'fertilized' => $flags['fertilized'],
                'purchased' => $flags['purchased'],
                'elite' => $flags['elite'],
                'note' => null,
            ];
            if ($planUnmatched <= 40) {
                $warnings[] = 'Размещение: не смог привязать строку «'.$curName.'» (ЕФИС: '.nstr($efisCell).')';
            }
            continue;
        }

        if (!isset($localNames[$fieldId]) || $localNames[$fieldId] === '') {
            $localNames[$fieldId] = $curName;
        }


        $rowsToAdd = [];
        if (!$efisList) {
            $rowsToAdd[] = [
                'efis_code' => null,
                'area_ha' => $areaNum,
                'crop_text' => $crop,
                'fertilized' => $flags['fertilized'],
                'purchased' => $flags['purchased'],
                'elite' => $flags['elite'],
                'note' => null,
            ];
        } elseif (count($efisList) === 1) {
            $rowsToAdd[] = [
                'efis_code' => $efisList[0],
                'area_ha' => $areaNum,
                'crop_text' => $crop,
                'fertilized' => $flags['fertilized'],
                'purchased' => $flags['purchased'],
                'elite' => $flags['elite'],
                'note' => null,
            ];
        } else {
            // Multiple EFIS in one Excel row: keep area only on the first row to avoid double counting.
            $listText = implode(', ', $efisList);
            foreach ($efisList as $k => $ef) {
                $rowsToAdd[] = [
                    'efis_code' => $ef,
                    'area_ha' => ($k === 0) ? $areaNum : null,
                    'crop_text' => $crop,
                    'fertilized' => $flags['fertilized'],
                    'purchased' => $flags['purchased'],
                    'elite' => $flags['elite'],
                    'note' => ($k === 0) ? ('ЕФИС: '.$listText) : 'Продолжение строки (см. выше)'
                ];
            }
        }

        if (!isset($planRows[$fieldId])) $planRows[$fieldId] = [];
        foreach ($rowsToAdd as $r0) $planRows[$fieldId][] = $r0;
    }

    // 3) Harvest rows
    $harvRows = []; // field_id => list
    $harvUnmatched = 0;
    $harvUnmatchedRows = [];
    $curName2 = '';
    foreach ($rowsHarv as $i => $row) {
        if ($i === 0) continue; // header
        $nameCell = nstr($row[0] ?? '');
        $crop = nstr($row[1] ?? '');
        $area = $row[2] ?? null;
        $gross = $row[3] ?? null;
        $avg = $row[4] ?? null;
        $efisCell = $row[5] ?? null;

        if ($nameCell !== '') {
            if (is_summary_row($nameCell)) continue;
            $curName2 = trim($nameCell);
        }
        if ($curName2 === '' || is_summary_row($curName2)) continue;

        if (is_summary_row($crop)) continue;

        $efisList = parse_efis_list($efisCell);
        $efis = $efisList[0] ?? null;

        if (!$efis) continue;

        $areaNum = (is_numeric($area)) ? (float)$area : null;
        $grossText = ($gross === null || $gross === '') ? null : (is_numeric($gross) ? (string)$gross : nstr($gross));
        $avgText = ($avg === null || $avg === '') ? null : (is_numeric($avg) ? (string)$avg : nstr($avg));

        $st = $db->prepare('SELECT field_id FROM field_efis WHERE efis_code=? LIMIT 1');
        $st->execute([$efis]);
        $fid = $st->fetchColumn();
        if (!$fid) {
            $harvUnmatched++;
            $harvUnmatchedRows[] = [
                'local_name' => $curName2,
                'efis_raw' => nstr($efisCell),
                'efis_list' => $efisList,
                'area_ha' => $areaNum,
                'crop_text' => ($crop !== '' ? $crop : null),
                'gross_yield' => $grossText,
                'avg_yield' => $avgText,
                'note' => null,
            ];
            if ($harvUnmatched <= 40) {
                $warnings[] = 'Урожай: не смог привязать строку «'.$curName2.'» (ЕФИС '.$efis.')';
            }
            continue;
        }
        $fieldId = (int)$fid;

        if (!isset($localNames[$fieldId]) || $localNames[$fieldId] === '') {
            $localNames[$fieldId] = $curName2;
        }

        if (!isset($harvRows[$fieldId])) $harvRows[$fieldId] = [];
        $harvRows[$fieldId][] = [
            'efis_code' => $efis,
            'area_ha' => $areaNum,
            'crop_text' => ($crop !== '' ? $crop : null),
            'gross_yield' => $grossText,
            'avg_yield' => $avgText,
            'note' => null,
        ];
    }

    // --- preview/import ---
    $summary = [
        'year' => $year,
        'file' => $name,
        'fields_created' => 0,
        'fields_found' => 0,
        'passport_updated' => 0,
        'efis_links_added' => 0,
        'plan_rows_added' => 0,
        'harvest_rows_added' => 0,
        'unmatched_saved' => 0,
    ];

    if ($mode === 'import') {
        $db->beginTransaction();
        try {
            // Upsert fields + passport + efis links
            foreach ($map as $code => $info) {
                $st = $db->prepare('SELECT id FROM fields WHERE field_code=? LIMIT 1');
                $st->execute([$code]);
                $fid = $st->fetchColumn();
                if (!$fid) {
                    $stI = $db->prepare('INSERT INTO fields (field_code, area_ha) VALUES (?, NULL)');
                    $stI->execute([$code]);
                    $fid = (int)$db->lastInsertId();
                    $summary['fields_created']++;
                } else {
                    $summary['fields_found']++;
                    $fid = (int)$fid;
                }

                // passport
                $cad = (string)($info['cadastral_full'] ?? '');
                if ($cad !== '') {
                    $stP = $db->prepare('INSERT INTO field_passport (field_id, cadastral_full) VALUES (?, ?) ON DUPLICATE KEY UPDATE cadastral_full=IF(cadastral_full IS NULL OR cadastral_full="", VALUES(cadastral_full), cadastral_full)');
                    $stP->execute([$fid, $cad]);
                    $summary['passport_updated']++;
                }

                // efis links
                foreach (($info['efis'] ?? []) as $ef) {
                    if ($ef === '') continue;
                    $stE = $db->prepare('INSERT IGNORE INTO field_efis (field_id, efis_code, area_ha, note) VALUES (?, ?, NULL, NULL)');
                    $stE->execute([$fid, $ef]);
                    $summary['efis_links_added'] += (int)$stE->rowCount();
                }
            }

            // Set local_name (do not overwrite existing non-empty)
            foreach ($localNames as $fid => $lname) {
                $lname = trim((string)$lname);
                if ($lname === '') continue;
                $stP = $db->prepare('INSERT INTO field_passport (field_id, local_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE local_name=IF(local_name IS NULL OR local_name="", VALUES(local_name), local_name)');
                $stP->execute([(int)$fid, $lname]);
                $summary['passport_updated']++;
            }

            // Plan rows (replace for season)
            foreach ($planRows as $fid => $rows) {
                $stC = $db->prepare('SELECT COUNT(*) FROM field_plan_rows WHERE field_id=? AND `year`=?');
                $stC->execute([(int)$fid, $year]);
                $existing = (int)$stC->fetchColumn();
                if ($existing > 0) {
                    $warnings[] = 'План: поле ID '.$fid.' — уже было строк: '.$existing.' (перезаписали)';
                }
                $db->prepare('DELETE FROM field_plan_rows WHERE field_id=? AND `year`=?')->execute([(int)$fid, $year]);

                $pos = 0;
                $stI = $db->prepare('INSERT INTO field_plan_rows (field_id, year, pos, efis_code, area_ha, crop_text, fertilized, purchased, elite, note) VALUES (?,?,?,?,?,?,?,?,?,?)');
                foreach ($rows as $r0) {
                    $stI->execute([
                        (int)$fid,
                        $year,
                        $pos++,
                        $r0['efis_code'],
                        $r0['area_ha'],
                        $r0['crop_text'] !== '' ? $r0['crop_text'] : null,
                        $r0['fertilized'],
                        $r0['purchased'],
                        $r0['elite'],
                        $r0['note'],
                    ]);
                    $summary['plan_rows_added']++;
                }
            }

            // Harvest rows (replace for season)
            foreach ($harvRows as $fid => $rows) {
                $stC = $db->prepare('SELECT COUNT(*) FROM field_harvest_rows WHERE field_id=? AND `year`=?');
                $stC->execute([(int)$fid, $year]);
                $existing = (int)$stC->fetchColumn();
                if ($existing > 0) {
                    $warnings[] = 'Урожай: поле ID '.$fid.' — уже было строк: '.$existing.' (перезаписали)';
                }
                $db->prepare('DELETE FROM field_harvest_rows WHERE field_id=? AND `year`=?')->execute([(int)$fid, $year]);

                $pos = 0;
                $stI = $db->prepare('INSERT INTO field_harvest_rows (field_id, year, pos, efis_code, area_ha, crop_text, gross_yield, avg_yield, note) VALUES (?,?,?,?,?,?,?,?,?)');
                foreach ($rows as $r0) {
                    $stI->execute([
                        (int)$fid,
                        $year,
                        $pos++,
                        $r0['efis_code'],
                        $r0['area_ha'],
                        $r0['crop_text'],
                        $r0['gross_yield'],
                        $r0['avg_yield'],
                        $r0['note'],
                    ]);
                    $summary['harvest_rows_added']++;
                }
            }

            // Save unmatched rows to queue (for manual resolving)
            ensure_excel_import_queue_tables($db);
            $stB = $db->prepare('INSERT INTO excel_import_batch (year, file_name, user_id) VALUES (?,?,?)');
            $stB->execute([$year, $name, (int)($_SESSION['user']['id'] ?? 0)]);
            $batchId = (int)$db->lastInsertId();

            $stU = $db->prepare('INSERT INTO excel_import_unmatched (batch_id, year, kind, local_name, efis_raw, efis_json, area_ha, crop_text, gross_yield, avg_yield, fertilized, purchased, elite, note)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            foreach ($planUnmatchedRows as $r) {
                $stU->execute([
                    $batchId,
                    $year,
                    'plan',
                    $r['local_name'],
                    $r['efis_raw'],
                    json_encode($r['efis_list'] ?? [], JSON_UNESCAPED_UNICODE),
                    $r['area_ha'],
                    $r['crop_text'] !== '' ? $r['crop_text'] : null,
                    null,
                    null,
                    $r['fertilized'],
                    $r['purchased'],
                    $r['elite'],
                    $r['note'],
                ]);
                $summary['unmatched_saved']++;
            }

            foreach ($harvUnmatchedRows as $r) {
                $stU->execute([
                    $batchId,
                    $year,
                    'harvest',
                    $r['local_name'],
                    $r['efis_raw'],
                    json_encode($r['efis_list'] ?? [], JSON_UNESCAPED_UNICODE),
                    $r['area_ha'],
                    $r['crop_text'],
                    $r['gross_yield'],
                    $r['avg_yield'],
                    null,
                    null,
                    null,
                    $r['note'],
                ]);
                $summary['unmatched_saved']++;
            }

            // Audit log
            try {
                log_event_dynamic($db, 'excel_import', 'Импорт из Excel: '.$name.' (сезон '.$year.')', (int)($_SESSION['user']['id'] ?? 0));
            } catch (Throwable $e) {}

            $db->commit();
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable $e2) {}
            json_err('Ошибка импорта: '.$e->getMessage());
        }
    } else {
        // Preview counts (no DB writes)
        $summary['fields_found'] = count($map);
        $summary['passport_updated'] = count($map);
        $cntEf = 0;
        foreach ($map as $x) $cntEf += count($x['efis'] ?? []);
        $summary['efis_links_added'] = $cntEf;
        $cntPlan = 0;
        foreach ($planRows as $fid => $rows) $cntPlan += count($rows);
        $cntHarv = 0;
        foreach ($harvRows as $fid => $rows) $cntHarv += count($rows);
        $summary['plan_rows_added'] = $cntPlan;
        $summary['harvest_rows_added'] = $cntHarv;
        if ($planUnmatched > 0) $warnings[] = 'Размещение: строк без привязки: '.$planUnmatched;
        if ($harvUnmatched > 0) $warnings[] = 'Урожай: строк без привязки: '.$harvUnmatched;
        if (($planUnmatched + $harvUnmatched) > 0) $warnings[] = 'После импорта откройте вкладку «Непривязанные», чтобы вручную привязать эти строки к кодам полей.';
    }

    json_ok([
        'summary' => $summary,
        'warnings' => $warnings,
    ]);

} catch (Throwable $e) {
    json_err('Ошибка чтения XLSX: '.$e->getMessage());
}
