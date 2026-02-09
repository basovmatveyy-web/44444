<?php
require_once __DIR__ . '/config.php';
$code = trim((string)($_GET['code'] ?? ''));
if (!preg_match('/^\d{3,4}$/',$code)) { http_response_code(400); echo "Неверный код"; exit; }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="field_'.$code.'.csv"');
$out = fopen('php://output','w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Код','Культура','Площадь (га)','Вспашка','Сев','Последний полив','Уборка','Валовый сбор','Средняя урожайность','Заметки'], ';');
$st=$pdo->prepare("
  SELECT f.field_code, c.title AS culture, f.area_ha, f.plow_date, f.sow_date, f.last_water_date, f.harvest_date, f.gross_yield, f.avg_yield, f.notes
  FROM fields f LEFT JOIN cultures c ON c.id=f.culture_id WHERE f.field_code=? LIMIT 1
");
$st->execute([$code]);
if($row=$st->fetch(PDO::FETCH_NUM)) fputcsv($out,$row,';');
fclose($out);
