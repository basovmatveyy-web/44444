<?php
require_once __DIR__ . '/api_common.php';
$st = $pdo->query("
  SELECT f.field_code, f.area_ha, f.sow_date, f.harvest_date, c.title AS culture
  FROM fields f
  LEFT JOIN cultures c ON c.id=f.culture_id
  ORDER BY f.field_code ASC
");
$items = $st->fetchAll();
json_ok(['items'=>$items]);
