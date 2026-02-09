<?php
// field_get.php — БЕЗ каких-либо join к историям. Только ваша текущая схема.
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$code = trim($_GET['code'] ?? '');
if (!preg_match('/^\d{3,4}$/', $code)) {
    echo json_encode(['ok'=>false,'error'=>'Неверный код']); exit;
}

$sql = "
SELECT
  f.field_code,
  f.area_ha,
  DATE_FORMAT(f.plow_date,'%Y-%m-%d')      AS plow_date,
  DATE_FORMAT(f.sow_date,'%Y-%m-%d')       AS sow_date,
  c.title                                   AS culture,
  DATE_FORMAT(f.treatment_date,'%Y-%m-%d')  AS treatment_date,
  f.treatment_desc                          AS treatment_desc,
  DATE_FORMAT(f.last_water_date,'%Y-%m-%d') AS last_water_date,
  DATE_FORMAT(f.harvest_date,'%Y-%m-%d')    AS harvest_date,
  f.gross_yield,
  f.avg_yield,
  f.notes,
  f.culture_id
FROM fields f
LEFT JOIN cultures c ON c.id = f.culture_id
WHERE f.field_code = ?
LIMIT 1
";

try {
    $st = $pdo->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'not_found']); exit;
    }
    echo json_encode(['ok'=>true,'field'=>$row], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_error']);
}
