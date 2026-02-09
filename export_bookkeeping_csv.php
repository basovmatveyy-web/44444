<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/year_lib.php';

ensure_field_year_data_table($pdo);
ensure_field_passport_tables($pdo);
ensure_field_accounting_rows_tables($pdo);
ensure_field_bookkeeping_tables($pdo);

$year = normalize_season_year($_GET['year'] ?? null, 2026);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bookkeeping_' . $year . '.csv"');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

$header = [
  'Сезон','Код','Местное название','Полный кадастр','Тип записи',
  'Культура (осн.)','Площадь (га)','Вспашка','Сев','Обработка (дата)','Обработка (тип)','Последний полив','Уборка','Уборочная площадь (га)','Валовый сбор','Средняя урожайность',
  'План: удобр','План: покуп','План: элит','Заметка по плану','Заметки сезона','Примечание паспорта',
  'Раздел','ЕФИС','Строка: площадь (га)','Строка: размещение/культура','Строка: валовый','Строка: урожайность',
  'Бухг.: наименование','Бухг.: ед','Бухг.: кол-во','Бухг.: цена','Бухг.: сумма','Бухг.: комментарий',
  'Док: тип','Док: №','Док: дата','Док: ссылка','Док: комментарий'
];
$COLS = count($header);

fputcsv($out, $header, ';');

function c($v): string {
  if ($v === null) return '';
  $s = (string)$v;
  if ($s === '0000-00-00') return '';
  return $s;
}

/**
 * $colsAfterType должен содержать все колонки начиная с "Культура" (т.е. $COLS - 5 колонок)
 */
function out_row($out, int $year, array $r, string $type, array $colsAfterType, int $COLS): void {
  $row = [
    (string)$year,
    c($r['field_code'] ?? ''),
    c($r['local_name'] ?? ''),
    c($r['cadastral_full'] ?? ''),
    $type,
  ];
  $row = array_merge($row, $colsAfterType);
  if (count($row) < $COLS) $row = array_merge($row, array_fill(0, $COLS - count($row), ''));
  fputcsv($out, $row, ';');
}

// 1) Map of season data per field_id
$seasonSql = "
  SELECT
    f.id AS field_id,
    CASE
      WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN c0.title
      ELSE c1.title
    END AS culture,
    f.area_ha,
    NULLIF(CAST(CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.plow_date ELSE d.plow_date END AS CHAR), '0000-00-00') AS plow_date,
    NULLIF(CAST(CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.sow_date ELSE d.sow_date END AS CHAR), '0000-00-00') AS sow_date,
    NULLIF(CAST(CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.treatment_date ELSE d.treatment_date END AS CHAR), '0000-00-00') AS treatment_date,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.treatment_desc ELSE d.treatment_desc END) AS treatment_desc,
    NULLIF(CAST(CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.last_water_date ELSE d.last_water_date END AS CHAR), '0000-00-00') AS last_water_date,
    NULLIF(CAST(CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.harvest_date ELSE d.harvest_date END AS CHAR), '0000-00-00') AS harvest_date,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN NULL ELSE d.harvest_area_ha END) AS harvest_area_ha,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.gross_yield ELSE d.gross_yield END) AS gross_yield,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.avg_yield ELSE d.avg_yield END) AS avg_yield,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN f.notes ELSE d.notes END) AS notes,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN NULL ELSE d.plan_fertilized END) AS plan_fertilized,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN NULL ELSE d.plan_purchased END) AS plan_purchased,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN NULL ELSE d.plan_elite END) AS plan_elite,
    (CASE WHEN d.id IS NULL AND :year = " . LEGACY_FIELDS_YEAR . " THEN NULL ELSE d.plan_notes END) AS plan_notes
  FROM fields f
  LEFT JOIN field_year_data d ON d.field_id = f.id AND d.`year` = :year
  LEFT JOIN cultures c1 ON c1.id = d.culture_id
  LEFT JOIN cultures c0 ON c0.id = f.culture_id
";
$stSeason = $pdo->prepare($seasonSql);
$stSeason->execute([':year' => $year]);
$seasonById = [];
while ($s = $stSeason->fetch(PDO::FETCH_ASSOC)) {
  $seasonById[(int)$s['field_id']] = $s;
}

function season_cols(int $fieldId, array $seasonById, array $r): array {
  $s = $seasonById[$fieldId] ?? null;
  return [
    c($s['culture'] ?? ''),
    c($s['area_ha'] ?? ''),
    c($s['plow_date'] ?? ''),
    c($s['sow_date'] ?? ''),
    c($s['treatment_date'] ?? ''),
    c($s['treatment_desc'] ?? ''),
    c($s['last_water_date'] ?? ''),
    c($s['harvest_date'] ?? ''),
    c($s['harvest_area_ha'] ?? ''),
    c($s['gross_yield'] ?? ''),
    c($s['avg_yield'] ?? ''),
    c($s['plan_fertilized'] ?? ''),
    c($s['plan_purchased'] ?? ''),
    c($s['plan_elite'] ?? ''),
    c($s['plan_notes'] ?? ''),
    c($s['notes'] ?? ''),
    c($r['passport_notes'] ?? ''),
  ];
}

$emptyTail17 = array_fill(0, 17, ''); // колонки 23..39

// 2) FIELD summary rows
$stFields = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes
                           FROM fields f
                           LEFT JOIN field_passport p ON p.field_id = f.id
                           ORDER BY TRIM(f.field_code) ASC");
$stFields->execute();
while ($r = $stFields->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $cols = array_merge(season_cols($fid, $seasonById, $r), $emptyTail17);
  out_row($out, $year, $r, 'FIELD', $cols, $COLS);
}

// 3) EFIS
$stE = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes,
                             e.efis_code, e.area_ha, e.note
                      FROM field_efis e
                      JOIN fields f ON f.id = e.field_id
                      LEFT JOIN field_passport p ON p.field_id = f.id
                      ORDER BY TRIM(f.field_code) ASC, e.efis_code ASC");
$stE->execute();
while ($r = $stE->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $tail = [
    '', // Раздел
    c($r['efis_code'] ?? ''),
    c($r['area_ha'] ?? ''),
    '', '', '',
    '', '', '', '', '', c($r['note'] ?? ''),
    '', '', '', '', ''
  ];
  $cols = array_merge(season_cols($fid, $seasonById, $r), $tail);
  out_row($out, $year, $r, 'EFIS', $cols, $COLS);
}

// 4) PLAN rows
$stPR = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes,
                              pr.efis_code, pr.area_ha, pr.crop_text, pr.fertilized, pr.purchased, pr.elite, pr.note
                       FROM field_plan_rows pr
                       JOIN fields f ON f.id = pr.field_id
                       LEFT JOIN field_passport p ON p.field_id = f.id
                       WHERE pr.`year` = :year
                       ORDER BY TRIM(f.field_code) ASC, pr.pos ASC, pr.id ASC");
$stPR->execute([':year' => $year]);
while ($r = $stPR->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $tail = [
    '',
    c($r['efis_code'] ?? ''),
    c($r['area_ha'] ?? ''),
    c($r['crop_text'] ?? ''),
    '', '',
    '', '', '', '', '', c($r['note'] ?? ''),
    '', '', '', '', ''
  ];
  // В этом типе удобно ещё и флаги из строки отразить — положим их в колонки "План:*" (частично)
  $sc = season_cols($fid, $seasonById, $r);
  $sc[11] = c($r['fertilized'] ?? '');
  $sc[12] = c($r['purchased'] ?? '');
  $sc[13] = c($r['elite'] ?? '');
  $cols = array_merge($sc, $tail);
  out_row($out, $year, $r, 'PLAN_ROW', $cols, $COLS);
}

// 5) HARVEST rows
$stHR = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes,
                              hr.efis_code, hr.area_ha, hr.crop_text, hr.gross_yield, hr.avg_yield, hr.note
                       FROM field_harvest_rows hr
                       JOIN fields f ON f.id = hr.field_id
                       LEFT JOIN field_passport p ON p.field_id = f.id
                       WHERE hr.`year` = :year
                       ORDER BY TRIM(f.field_code) ASC, hr.pos ASC, hr.id ASC");
$stHR->execute([':year' => $year]);
while ($r = $stHR->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $tail = [
    '',
    c($r['efis_code'] ?? ''),
    c($r['area_ha'] ?? ''),
    c($r['crop_text'] ?? ''),
    c($r['gross_yield'] ?? ''),
    c($r['avg_yield'] ?? ''),
    '', '', '', '', '', c($r['note'] ?? ''),
    '', '', '', '', ''
  ];
  $cols = array_merge(season_cols($fid, $seasonById, $r), $tail);
  out_row($out, $year, $r, 'HARVEST_ROW', $cols, $COLS);
}

// 6) ACCOUNTING rows
$stAR = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes,
                              ar.section, ar.efis_code, ar.item, ar.unit, ar.qty, ar.price, ar.amount, ar.note
                       FROM field_accounting_rows ar
                       JOIN fields f ON f.id = ar.field_id
                       LEFT JOIN field_passport p ON p.field_id = f.id
                       WHERE ar.`year` = :year
                       ORDER BY TRIM(f.field_code) ASC, ar.section ASC, ar.pos ASC, ar.id ASC");
$stAR->execute([':year' => $year]);
while ($r = $stAR->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $tail = [
    c($r['section'] ?? ''),
    c($r['efis_code'] ?? ''),
    '', '', '', '',
    c($r['item'] ?? ''),
    c($r['unit'] ?? ''),
    c($r['qty'] ?? ''),
    c($r['price'] ?? ''),
    c($r['amount'] ?? ''),
    c($r['note'] ?? ''),
    '', '', '', '', ''
  ];
  $cols = array_merge(season_cols($fid, $seasonById, $r), $tail);
  out_row($out, $year, $r, 'ACC_ROW', $cols, $COLS);
}

// 7) DOCS
$stDR = $pdo->prepare("SELECT f.id AS field_id, TRIM(f.field_code) AS field_code, p.local_name, p.cadastral_full, p.passport_notes,
                              d.doc_type, d.doc_no, NULLIF(CAST(d.doc_date AS CHAR), '0000-00-00') AS doc_date, d.link, d.note
                       FROM field_docs d
                       JOIN fields f ON f.id = d.field_id
                       LEFT JOIN field_passport p ON p.field_id = f.id
                       WHERE d.`year` = :year
                       ORDER BY TRIM(f.field_code) ASC, d.pos ASC, d.id ASC");
$stDR->execute([':year' => $year]);
while ($r = $stDR->fetch(PDO::FETCH_ASSOC)) {
  $fid = (int)$r['field_id'];
  $tail = [
    '',
    '',
    '', '', '', '',
    '', '', '', '', '', '',
    c($r['doc_type'] ?? ''),
    c($r['doc_no'] ?? ''),
    c($r['doc_date'] ?? ''),
    c($r['link'] ?? ''),
    c($r['note'] ?? '')
  ];
  $cols = array_merge(season_cols($fid, $seasonById, $r), $tail);
  out_row($out, $year, $r, 'DOC', $cols, $COLS);
}

fclose($out);
