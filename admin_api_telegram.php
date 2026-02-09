<?php
/**
 * admin_api_telegram.php — полный API «Telegram — настройки» (v2)
 */
require_once __DIR__ . '/tg_lib.php';
require_admin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function bool01($v){ return ($v==='1' || $v===1 || $v==='true' || $v===true || $v==='on') ? '1' : '0'; }
function is_https_request() : bool {
    // Direct HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;

    // Common reverse-proxy headers (nginx/apache/Cloudflare/etc.)
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($p === 'https') return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_HTTPS']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_HTTPS']) === 'on') return true;
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return true;

    // Cloudflare sends JSON in CF-VISITOR like: {"scheme":"https"}
    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $j = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
        if (is_array($j) && (($j['scheme'] ?? '') === 'https')) return true;
    }

    return false;
}

function _app_dir_path() : string {
    // Keep correct base even if the project is installed in a subfolder.
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '/' || $dir === '.' ) $dir = '';
    return $dir;
}

function _client_https_hint() : bool {
    // In many shared hostings / proxies PHP may not see HTTPS,
    // but the browser still sends Referer/Origin with https://
    $ref = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    return (stripos($ref, 'https://') === 0);
}

function sanitize_base_url(?string $u) : string {
    $u = trim((string)$u);
    if ($u === '') return '';
    $p = @parse_url($u);
    if (!is_array($p) || empty($p['host'])) return '';
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') return '';
    $host = $p['host'];
    $port = isset($p['port']) ? (int)$p['port'] : 0;
    $path = rtrim((string)($p['path'] ?? ''), '/');
    $base = $scheme.'://'.$host;
    if ($port && !in_array($port, [80,443], true)) $base .= ':'.$port;
    if ($path !== '') $base .= $path;
    return $base;
}

function base_url(bool $forceHttpsForWebhook = false) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = _app_dir_path();

    $preferHttps = $forceHttpsForWebhook || is_https_request() || _client_https_hint();

    // Telegram *requires* https for webhook. If we can't detect scheme reliably,
    // we still prefer https (unless it's явно локалка).
    if (!$preferHttps && !preg_match('~^(localhost|127\\.0\\.0\\.1)(:\\d+)?$~i', (string)$host)) {
        $preferHttps = true;
    }
    $sch = $preferHttps ? 'https' : 'http';
    return $sch . '://' . $host . $dir;
}

function webhook_base_url() : string {
    // Allow explicit origin from UI (browser knows the real scheme).
    $fromClient = sanitize_base_url($_POST['base'] ?? $_GET['base'] ?? null);
    if ($fromClient !== '') return $fromClient;
    return base_url(true);
}
function respond_settings() {
    $s = tg_settings();
    $wh = !empty($s['token']) ? tg_api('getWebhookInfo') : ['ok'=>false];
    $me = !empty($s['token']) ? tg_get_me($s['token']) : ['ok'=>false];
    reply_ok([
        'settings' => [
            'token'         => $s['token'],
            'admin_chat'    => $s['admin_chat'],
            'secret'        => $s['secret'],
            'notify_save'   => $s['notify_save'] ? '1':'0',
            'notify_logins' => $s['notify_logins']? '1':'0',
            'notify_users'  => $s['notify_users']? '1':'0',
            'notify_errors' => $s['notify_errors']?'1':'0',
        ],
        'bot'     => $me,
        'webhook' => $wh,
        'base'    => base_url(true),
    ]);
}

try {
    switch ($action) {
        case 'load':
        case 'get':
        case 'get_settings':
            respond_settings();
            break;

        case 'save':
        case 'save_settings': {
            $token  = trim((string)($_POST['token'] ?? ''));
            $chatId = trim((string)($_POST['admin_chat'] ?? ''));
            $nsave  = bool01($_POST['notify_save']  ?? setting_get('tg_notify_save','1'));
            $nlog   = bool01($_POST['notify_logins']?? setting_get('tg_notify_logins','1'));
            $nuser  = bool01($_POST['notify_users'] ?? setting_get('tg_notify_users','1'));
            $nerr   = bool01($_POST['notify_errors']?? setting_get('tg_notify_errors','1'));

            if ($token !== '') {
                $me = tg_get_me($token);
                if (!($me['ok'] ?? false)) {
                    reply_err('Неверный токен: '.json_encode($me, JSON_UNESCAPED_UNICODE));
                }
            }

            setting_set('tg_bot_token', $token);
            setting_set('tg_admin_chat', preg_replace('~\D~','',$chatId));
            setting_set('tg_notify_save', $nsave);
            setting_set('tg_notify_logins', $nlog);
            setting_set('tg_notify_users', $nuser);
            setting_set('tg_notify_errors', $nerr);

            $hookRes = null;
            if (!empty($_POST['set_webhook'])) {
                $hookRes = tg_set_webhook(webhook_base_url());
            }
            reply_ok(['saved'=>true,'webhook'=>$hookRes]);
        } break;

        case 'send_test': {
            $text = trim((string)($_POST['text'] ?? 'Проверка связи с ботом.'));
            $res  = tg_send_message($text);
            if (!($res['ok'] ?? false)) reply_err('Не удалось отправить: '.json_encode($res, JSON_UNESCAPED_UNICODE));
            reply_ok(['sent'=>true, 'response'=>$res]);
        } break;

        case 'webhook_set':    reply_json(tg_set_webhook(webhook_base_url())); break;
        case 'webhook_delete': reply_json(tg_delete_webhook()); break;
        case 'webhook_info':   reply_json(tg_api('getWebhookInfo')); break;

        case 'regenerate_secret': { $new = tg_regenerate_secret(); reply_ok(['secret'=>$new]); } break;

        case 'subscribers_list': {
            tg_ensure_subscribers();
            $rows = pdo()->query("SELECT chat_id,type,username,first_name,last_name,title,is_admin,last_seen_at FROM tg_subscribers ORDER BY last_seen_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            reply_ok(['items'=>$rows]);
        } break;
        case 'subscriber_delete': {
            $cid = (int)($_POST['chat_id'] ?? 0);
            if (!$cid) reply_err('chat_id?');
            $st = pdo()->prepare("DELETE FROM tg_subscribers WHERE chat_id=?");
            $st->execute([$cid]);
            reply_ok(['deleted'=>true]);
        } break;

        case 'broadcast': {
            $text = trim((string)($_POST['text'] ?? ''));
            if ($text==='') reply_err('empty text');
            $res = tg_broadcast($text);
            reply_ok($res);
        } break;

        default: reply_err('Unknown action');
    }
} catch (Throwable $e) {
    reply_err('Ошибка: '.$e->getMessage(), 500);
}
