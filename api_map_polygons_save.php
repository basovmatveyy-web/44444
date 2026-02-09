<?php
require_once __DIR__ . '/api_common.php';
require_user();
require_admin();

// CSRF: поддерживаем либо X-CSRF-Token, либо поле csrf в JSON
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$raw = file_get_contents('php://input');
if ($raw === false) json_err('Пустой запрос');

$payload = json_decode($raw, true);
if (!is_array($payload)) json_err('Некорректный JSON');

if ($csrf === '' && isset($payload['csrf'])) $csrf = (string)$payload['csrf'];
check_csrf_or_die($csrf);

$data = $payload['data'] ?? null;
if (!is_array($data)) json_err('Поле data отсутствует');

// Валидация структуры
$version = (int)($data['version'] ?? 1);
$features = $data['features'] ?? [];
if (!is_array($features)) json_err('features должен быть массивом');

$clean = ['version' => $version, 'features' => []];

$allowed_colors = ['blue','green','pink','yellow','orange','pale'];

foreach ($features as $f) {
  if (!is_array($f)) continue;
  $code = preg_replace('/\D/', '', (string)($f['code'] ?? ''));
  if (strlen($code) < 3 || strlen($code) > 4) continue;
  $pts = $f['points'] ?? null;
  if (!is_array($pts) || count($pts) < 3) continue;

  $outPts = [];
  foreach ($pts as $p) {
    if (!is_array($p)) continue;
    $x = isset($p[0]) ? (float)$p[0] : null;
    $y = isset($p[1]) ? (float)$p[1] : null;
    if ($x === null || $y === null) continue;
    // разумные границы картинки
    if ($x < -100 || $y < -100 || $x > 5000 || $y > 5000) continue;
    $outPts[] = [$x, $y];
  }
  if (count($outPts) < 3) continue;

  // уберём повторы подряд
  $dedup = [];
  $prev = null;
  foreach ($outPts as $pt) {
    $k = $pt[0].','.$pt[1];
    if ($prev === $k) continue;
    $dedup[] = $pt;
    $prev = $k;
  }
  if (count($dedup) < 3) continue;

  $color = (string)($f['color'] ?? 'blue');
  $color = preg_replace('/[^a-z_]/', '', strtolower($color));
  if (!in_array($color, $allowed_colors, true)) $color = 'blue';

  $clean['features'][] = ['code' => $code, 'points' => $dedup, 'color' => $color];
}

$path = __DIR__ . '/map_polygons.json';
$tmp = $path . '.tmp';
$bytes = @file_put_contents($tmp, json_encode($clean, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
if ($bytes === false) json_err('Не удалось записать временный файл (проверьте права на запись)');
if (!@rename($tmp, $path)) {
  @unlink($tmp);
  json_err('Не удалось обновить map_polygons.json (проверьте права на запись)');
}

json_ok(['saved' => count($clean['features'])]);
