<?php
// cultures_list.php — вернуть список культур для селектора
require_once __DIR__ . '/api_common.php'; // <-- обязательно, тут json_ok/pdo

try {
    $pdo = pdo();
    // В твоей схеме поле называется title. Если у тебя колонка name — смени title -> name.
    $st = $pdo->query("SELECT id, title FROM cultures ORDER BY title ASC");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_ok(['items' => $rows]);
} catch (Throwable $e) {
    json_err('Не удалось загрузить культуры', 500);
}
