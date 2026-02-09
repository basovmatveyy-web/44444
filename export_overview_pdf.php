<?php
/**
 * export_overview_pdf.php — PDF-отчёт "Сводка хозяйства".
 * Генерируется напрямую из БД (как и export_field_pdf.php), без Composer.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/api_common.php';

// Попытка подключить Dompdf (вариант без Composer)
if (!class_exists('Dompdf\\Dompdf')) {
    $dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
    if (file_exists($dompdfAutoload)) {
        require_once $dompdfAutoload;
    }
}

use Dompdf\Dompdf;
use Dompdf\Options;

if (!class_exists('Dompdf\\Dompdf')) {
    http_response_code(500);
    echo 'Библиотека Dompdf не найдена. Убедитесь, что папка dompdf лежит рядом с export_overview_pdf.php.';
    exit;
}

// Пороговые значения (для читабельного отчёта)
$WATER_DAYS = 10;
$TREAT_DAYS = 30;
$HARVEST_AFTER_SOW_DAYS = 150;

try {
    $db = pdo();

    $rows = $db->query(
        "SELECT f.field_code, f.area_ha, f.sow_date, f.last_water_date, f.treatment_date, f.harvest_date,
                f.culture_id, c.title AS culture
         FROM fields f
         LEFT JOIN cultures c ON c.id = f.culture_id
         ORDER BY f.field_code"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка чтения данных из базы.';
    exit;
}

$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');

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
    $days = (int)$diff->format('%r%a');
    return $days < 0 ? 0 : $days;
};

$fieldsTotal = 0;
$areaTotal = 0.0;
$missingCulture = 0;
$missingSow = 0;
$opsAlerts = 0;
$attentionTotal = 0;

$cultStats = [];
$risks = [];

foreach ($rows as $r) {
    $fieldsTotal++;

    $area = $r['area_ha'];
    if ($area !== null && $area !== '') $areaTotal += (float)$area;

    $cultureTitle = (string)($r['culture'] ?? '');
    if ($cultureTitle === '') $cultureTitle = 'Не указана';
    if (!isset($cultStats[$cultureTitle])) $cultStats[$cultureTitle] = ['title'=>$cultureTitle,'count'=>0,'area'=>0.0];
    $cultStats[$cultureTitle]['count']++;
    if ($area !== null && $area !== '') $cultStats[$cultureTitle]['area'] += (float)$area;

    $reasons = [];

    if (empty($r['culture_id'])) { $missingCulture++; $reasons[] = 'Не указана культура'; }
    if (empty($r['sow_date']) || $r['sow_date'] === '0000-00-00') { $missingSow++; $reasons[] = 'Не указана дата сева'; }

    $waterDays = $daysSince($r['last_water_date'] ?? null);
    if ($waterDays === null) {
        if (!empty($r['sow_date']) && $r['sow_date'] !== '0000-00-00') { $opsAlerts++; $reasons[] = 'Нет данных по поливу'; }
    } elseif ($waterDays >= $WATER_DAYS) {
        $opsAlerts++; $reasons[] = 'Полив давно (' . $waterDays . ' дн.)';
    }

    $tDays = $daysSince($r['treatment_date'] ?? null);
    if ($tDays !== null && $tDays >= $TREAT_DAYS) { $opsAlerts++; $reasons[] = 'Обработка давно (' . $tDays . ' дн.)'; }

    $sowDays = $daysSince($r['sow_date'] ?? null);
    $hasHarvest = !empty($r['harvest_date']) && $r['harvest_date'] !== '0000-00-00';
    if ($sowDays !== null && !$hasHarvest && $sowDays >= $HARVEST_AFTER_SOW_DAYS) { $opsAlerts++; $reasons[] = 'Уборка не отмечена (' . $sowDays . ' дн. после сева)'; }

    if ($reasons) {
        $attentionTotal++;
        $risks[] = [
            'field_code' => (string)($r['field_code'] ?? ''),
            'reasons' => $reasons,
        ];
    }
}

$cultures = array_values($cultStats);
usort($cultures, function($a, $b){
    $da = (float)($a['area'] ?? 0);
    $db = (float)($b['area'] ?? 0);
    if ($da === $db) return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
    return $db <=> $da;
});
usort($risks, function($a, $b){
    return count($b['reasons'] ?? []) <=> count($a['reasons'] ?? []);
});
$risks = array_slice($risks, 0, 30);

function hx($v, string $empty = '—') : string {
    $v = is_null($v) ? '' : trim((string)$v);
    if ($v === '') $v = $empty;
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$areaTotalStr = number_format((float)round($areaTotal, 2), 2, ',', ' ');
$kpi = [
    ['Участков', (string)$fieldsTotal],
    ['Общая площадь', $areaTotalStr . ' га'],
    ['Требуют внимания', (string)$attentionTotal],
    ['Без культуры', (string)$missingCulture],
    ['Без даты сева', (string)$missingSow],
    ['Полив/обработка/уборка: риски', (string)$opsAlerts],
];

$generated = $now->format('d.m.Y H:i');

// «Таблицы» в Dompdf: на некоторых хостингах встречается битая/неполная автозагрузка,
// из-за чего падает FrameReflower\\Table. Поэтому ниже — div-верстка без <table>.
$cultRows = '';
foreach ($cultures as $c) {
    $title = hx($c['title'] ?? '');
    $count = (int)($c['count'] ?? 0);
    $area = (float)($c['area'] ?? 0);
    $areaStr = number_format(round($area, 2), 2, ',', ' ');
    $share = $areaTotal > 0 ? round(($area / $areaTotal) * 100, 1) : 0;
    $cultRows .= '<div class="trow">'
        . '<div class="tcell c1">' . $title . '</div>'
        . '<div class="tcell c2 right">' . $count . '</div>'
        . '<div class="tcell c3 right">' . $areaStr . '</div>'
        . '<div class="tcell c4 right">' . hx($share, '0') . '%</div>'
        . '<div class="clr"></div>'
        . '</div>';
}
if ($cultRows === '') {
    $cultRows = '<div class="trow"><div style="color:#6b7280">Нет данных</div></div>';
}

$riskRows = '';
foreach ($risks as $r) {
    $code = hx($r['field_code'] ?? '');
    $reasons = $r['reasons'] ?? [];
    $rr = '';
    foreach ($reasons as $reason) {
        $rr .= '<span class="chip">' . hx($reason, '') . '</span> ';
    }
    $riskRows .= '<div class="trow">'
        . '<div class="tcell r1"><strong>' . $code . '</strong></div>'
        . '<div class="tcell r2">' . $rr . '</div>'
        . '<div class="clr"></div>'
        . '</div>';
}
if ($riskRows === '') {
    $riskRows = '<div class="trow"><div style="color:#6b7280">Риски не обнаружены (по заданным правилам).</div></div>';
}

$html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сводка по полям — СХПК «Береговой»</title>
<style>
@page { size: A4; margin: 14mm; }
body{ font-family: "DejaVu Sans", DejaVuSans, sans-serif; font-size: 12px; color:#0b1220; }
h1{ margin:0 0 4px; font-size: 20px; }
.muted{ color:#6b7280; font-size: 11px; margin-bottom: 10px; }
	/* Dompdf плохо дружит с grid/flex → делаем надежной версткой на inline-block + float */
	.grid{ font-size:0; }
	.grid .card{ display:inline-block; width:49%; vertical-align:top; font-size:12px; margin-right:2%; }
	.grid .card:last-child{ margin-right:0; }
	.card{ border:1px solid #e5e7eb; border-radius: 12px; padding: 10px 12px; background:#f9fafb; }
	.kpi-row{ padding: 6px 0; border-bottom:1px dashed #e5e7eb; }
.kpi-row:last-child{ border-bottom:0; }
	.kpi-label{ float:left; width:72%; font-weight:600; color:#111827; }
	.kpi-val{ float:right; width:28%; text-align:right; font-weight:700; }
	/* div-таблица (без <table>, чтобы Dompdf не требовал FrameReflower\\Table) */
	.tbl{ width:100%; border:1px solid #e5e7eb; border-radius: 12px; background:#fff; }
	.thead{ background:#f3f4f6; color:#374151; font-size: 11px; font-weight:700; border-bottom:1px solid #e5e7eb; }
	.trow{ padding: 6px 8px; border-bottom:1px solid #eef2f7; }
	.trow:last-child{ border-bottom:0; }
	.tcell{ float:left; box-sizing:border-box; }
	.right{ text-align:right; }
	.clr{ clear:both; height:0; line-height:0; }
	.c1{ width:46%; }
	.c2{ width:18%; }
	.c3{ width:18%; }
	.c4{ width:18%; }
	.r1{ width:18%; }
	.r2{ width:82%; }
.chip{ display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; border:1px solid #e5e7eb; background:#fff; margin: 2px 4px 2px 0; }
.footer{ margin-top: 12px; font-size: 10px; color:#9ca3af; text-align:right; }
</style>
</head>
<body>
  <h1>Сводка по полям</h1>
  <div class="muted">СХПК «Береговой» · сформировано {$generated}</div>

  <div class="grid">
    <div class="card">
      <div style="font-weight:700; margin-bottom:8px">Ключевые показатели</div>
HTML;

foreach ($kpi as [$label, $val]) {
    $html .= '<div class="kpi-row">'
        . '<div class="kpi-label">' . hx($label, '') . '</div>'
        . '<div class="kpi-val">' . hx($val, '') . '</div>'
        . '<div class="clr"></div>'
        . '</div>';
}

$html .= <<<HTML
    </div>

    <div class="card">
      <div style="font-weight:700; margin-bottom:8px">Пороговые правила (для подсветки рисков)</div>
      <div class="muted" style="margin:0">
        Полив: {$WATER_DAYS} дн · Обработка: {$TREAT_DAYS} дн · Уборка: {$HARVEST_AFTER_SOW_DAYS} дн после сева
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:10px">
    <div style="font-weight:700; margin-bottom:8px">Культуры</div>
	    <div class="tbl">
	      <div class="thead trow">
	        <div class="tcell c1">Культура</div>
	        <div class="tcell c2 right">Участков</div>
	        <div class="tcell c3 right">Площадь, га</div>
	        <div class="tcell c4 right">Доля</div>
	        <div class="clr"></div>
	      </div>
	      {$cultRows}
	    </div>
  </div>

  <div class="card" style="margin-top:10px">
    <div style="font-weight:700; margin-bottom:8px">Участки, требующие внимания</div>
	    <div class="tbl">
	      <div class="thead trow">
	        <div class="tcell r1">Код</div>
	        <div class="tcell r2">Причины</div>
	        <div class="clr"></div>
	      </div>
	      {$riskRows}
	    </div>
  </div>

  <div class="footer">PDF сформирован автоматически системой цифрового управления полями</div>
</body>
</html>
HTML;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'beregovoy_overview_' . $now->format('Y-m-d_Hi') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
echo $dompdf->output();
