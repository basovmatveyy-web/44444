<?php
/**
 * export_field_pdf.php — генерация красивого PDF-отчёта по участку
 * для Telegram-бота и веба.
 *
 * Вариант без Composer:
 *   1) Папка dompdf (dompdf-3.x) должна лежать в корне рядом с этим файлом.
 *   2) Внутри неё должен быть файл dompdf/autoload.inc.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/tg_lib.php';

// Попытка подключить Dompdf (вариант без Composer)
if (!class_exists('Dompdf\\Dompdf')) {
    $dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
    if (file_exists($dompdfAutoload)) {
        require_once $dompdfAutoload;
    }
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Если после автозагрузки класс всё ещё недоступен — честно скажем об этом
if (!class_exists('Dompdf\\Dompdf')) {
    http_response_code(500);
    echo 'Библиотека Dompdf не найдена. Убедитесь, что папка dompdf лежит рядом с export_field_pdf.php.';
    exit;
}

// Код участка
$code = trim((string)($_GET['code'] ?? ''));
$codeDigits = preg_replace('~\D~', '', $code);
if (strlen($codeDigits) !== 4) {
    http_response_code(400);
    echo 'Неверный код участка';
    exit;
}

// Загружаем данные по участку напрямую из БД
try {
    $db = pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка подключения к базе данных.';
    exit;
}

$st = $db->prepare("SELECT f.field_code, f.area_ha, f.plow_date, f.sow_date, f.treatment_date, f.treatment_desc,
                           f.last_water_date, f.harvest_date, f.gross_yield, f.avg_yield, f.notes,
                           c.title AS culture
                    FROM fields f
                    LEFT JOIN cultures c ON c.id = f.culture_id
                    WHERE f.field_code = ? LIMIT 1");

$st->execute([$codeDigits]);
$field = $st->fetch(PDO::FETCH_ASSOC);

if (!$field) {
    http_response_code(404);
    echo 'Данных по участку нет';
    exit;
}

// Аккуратный helper для экранирования
function hx($v, string $empty = '—') : string {
    $v = is_null($v) ? '' : trim((string)$v);
    if ($v === '' || $v === '0000-00-00') {
        $v = $empty;
    }
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$codeEsc  = hx($field['field_code'] ?? $codeDigits);
$culture  = hx($field['culture'] ?? '', 'не указана');
$area     = hx($field['area_ha'] ?? '');
$plow     = hx($field['plow_date'] ?? '');
$sow      = hx($field['sow_date'] ?? '');
$treatD   = hx($field['treatment_date'] ?? '');
$treatT   = hx($field['treatment_desc'] ?? '', 'не указана');
$water    = hx($field['last_water_date'] ?? '');
$harvest  = hx($field['harvest_date'] ?? '', 'ещё не выполнена');
$gross    = hx($field['gross_yield'] ?? '');
$avg      = hx($field['avg_yield'] ?? '');
$notesRaw = (string)($field['notes'] ?? '');
$notes    = $notesRaw !== '' ? nl2br(hx($notesRaw, ''), false) : '<span class="muted">нет заметок</span>';

$today = (new DateTimeImmutable('now'))->format('d.m.Y H:i');

$html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Карточка участка {$codeEsc}</title>
<style>
@page {
    size: A4;
    margin: 16mm;
}
body {
    font-family: "DejaVu Sans", DejaVuSans, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    color: #0b1220;
}
h1 {
    margin: 0 0 4px;
    font-size: 22px;
}
h2 {
    margin: 16px 0 6px;
    font-size: 15px;
}
.muted {
    color: #6b7280;
    font-size: 11px;
    margin-bottom: 8px;
}
.card {
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 10px 12px;
    margin: 8px 0;
    background: #f9fafb;
}
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 12px;
    row-gap: 4px;
}
.row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2px;
}
.label {
    font-weight: 600;
    color: #111827;
}
.value {
    text-align: right;
}
.footer {
    margin-top: 18px;
    font-size: 10px;
    color: #9ca3af;
    text-align: right;
}
.notes {
    min-height: 35mm;
    white-space: pre-line;
}
.header-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
}
.badge {
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .06em;
    background: #eef2ff;
    color: #4f46e5;
}
</style>
</head>
<body>
<div class="header-row">
  <div>
    <h1>Карточка участка {$codeEsc}</h1>
    <div class="muted">СХПК «Береговой» — сводный отчёт</div>
  </div>
  <div class="badge">PDF-отчёт</div>
</div>

<div class="card">
    <div class="row">
        <div class="label">Культура</div>
        <div class="value">{$culture}</div>
    </div>
    <div class="row">
        <div class="label">Площадь</div>
        <div class="value">{$area} га</div>
    </div>
</div>

<h2>Календарь работ</h2>
<div class="card grid">
    <div><span class="label">Вспашка:</span> {$plow}</div>
    <div><span class="label">Сев:</span> {$sow}</div>
    <div><span class="label">Обработка:</span> {$treatD}</div>
    <div><span class="label">Тип обработки:</span> {$treatT}</div>
    <div><span class="label">Последний полив:</span> {$water}</div>
    <div><span class="label">Уборка:</span> {$harvest}</div>
</div>

<h2>Урожайность</h2>
<div class="card grid">
    <div><span class="label">Валовый сбор:</span> {$gross}</div>
    <div><span class="label">Средняя урожайность:</span> {$avg}</div>
</div>

<h2>Заметки</h2>
<div class="card notes">
    {$notes}
</div>

<div class="footer">
    Отчёт сформирован {$today}
</div>
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

$filename = 'field_' . $codeDigits . '_report.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
echo $dompdf->output();
