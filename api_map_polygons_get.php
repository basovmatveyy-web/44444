<?php
require_once __DIR__ . '/api_common.php';
require_user();

$path = __DIR__ . '/map_polygons.json';
if (!is_file($path)) {
  json_ok(['data' => ['version'=>1,'features'=>[]]]);
}

$raw = @file_get_contents($path);
if ($raw === false) {
  json_err('Не удалось прочитать map_polygons.json');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  json_ok(['data' => ['version'=>1,'features'=>[]]]);
}
if (!isset($data['version'])) $data['version'] = 1;
if (!isset($data['features']) || !is_array($data['features'])) $data['features'] = [];

json_ok(['data' => $data]);
