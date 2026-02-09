<?php
/**
 * tg_set_webhook.php — утилита для установки/снятия вебхука.
 * Доступна только админам, авторизованным в панели.
 * Примеры:
 *   /tg_set_webhook.php?set=1
 *   /tg_set_webhook.php?delete=1
 *   /tg_set_webhook.php?info=1
 */

require_once __DIR__ . '/tg_lib.php';
require_admin();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'];

if (isset($_GET['set'])) {
    $res = tg_set_webhook($base);
    reply_json($res);
} elseif (isset($_GET['delete'])) {
    $res = tg_delete_webhook();
    reply_json($res);
} elseif (isset($_GET['info'])) {
    $res = tg_api('getWebhookInfo');
    reply_json($res);
} else {
    reply_json(['ok' => true, 'hint' => 'append ?set=1 or ?delete=1 or ?info=1']);
}
