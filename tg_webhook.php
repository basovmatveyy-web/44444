<?php
/**
 * tg_webhook.php — вебхук Telegram (v3)
 * Новый бот с inline‑меню, регистрацией и входом в аккаунт.
 * Дизайн сайта и админ‑панели не трогаем — меняется только логика бота.
 */

require_once __DIR__ . '/tg_lib.php';

$s = tg_settings();
$raw = file_get_contents('php://input');
$update = json_decode($raw ?: 'null', true);

if (!$s['token']) { http_response_code(200); exit('no-token'); }

// Проверка секретного токена вебхука
$headers = function_exists('getallheaders') ? getallheaders() : [];
$recvSecret = '';
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'x-telegram-bot-api-secret-token') { $recvSecret = $v; break; }
}
if ($s['secret'] && $recvSecret !== $s['secret']) {
    http_response_code(403);
    exit('bad-secret');
}

if (!is_array($update)) { http_response_code(200); exit('ok'); }

/* ----------------- Вспомогательные функции ----------------- */

/** Отправка простого сообщения */
function tg_answer(string $text, ?int $chatId = null, array $opts = []) {
    return tg_send_message($text, $chatId, $opts);
}

/** Рендер inline‑клавиатуры в параметр reply_markup */
function tg_kb(array $rows) {
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

/** Поиск пользователя по chat_id (привязка через users.tg_user_id) */
function tg_user_by_chat(int $chatId) : ?array {
    $db = pdo();
    $st = $db->prepare("SELECT id, username, surname, name, role FROM users WHERE tg_user_id = ? LIMIT 1");
    $st->execute([$chatId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

/** Отвязать чат от всех пользователей (чтобы привязать к другому аккаунту) */
function tg_unbind_chat(int $chatId) : void {
    $db = pdo();
    $st = $db->prepare("UPDATE users SET tg_user_id = NULL WHERE tg_user_id = ?");
    $st->execute([$chatId]);
}

/** Привязать чат к пользователю */
function tg_bind_chat(int $chatId, int $userId) : void {
    tg_unbind_chat($chatId);
    $db = pdo();
    $st = $db->prepare("UPDATE users SET tg_user_id = ? WHERE id = ?");
    $st->execute([$chatId, $userId]);
}

/** Человекочитаемая роль */
function tg_role_title(string $role) : string {
    if ($role === 'admin') return 'Администратор';
    if ($role === 'buh') return 'Бухгалтер';
    return 'Пользователь';
}

/** Базовый URL сайта для генерации ссылок (PDF и др.) */
function tg_is_https_request() : bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($p === 'https') return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_HTTPS']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_HTTPS']) === 'on') return true;
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return true;
    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $j = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
        if (is_array($j) && (($j['scheme'] ?? '') === 'https')) return true;
    }
    return false;
}

function tg_base_url() : string {
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = tg_is_https_request() ? 'https' : 'http';
    // Telegram webhook работает только по HTTPS. Если TLS терминируется прокси и PHP
    // не видит HTTPS, всё равно предпочитаем https (кроме явной локалки).
    if ($scheme === 'http' && !preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~i', (string)$host)) {
        $scheme = 'https';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir    = rtrim(str_replace('\\\\', '/', dirname($script)), '/');
    if ($dir === '/' || $dir === '.') $dir = '';
    return $scheme . '://' . $host . $dir;
}

/** Главный текст меню */
function tg_main_menu_text(?array $user) : string {
    if ($user) {
        $name = trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? ''));
        $name = $name !== '' ? $name : ($user['username'] ?? '—');
        $name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $roleTitle = htmlspecialchars(tg_role_title($user['role'] ?? 'user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "🏠 <b>Главное меню</b>
"
             . "Вы вошли как: <b>{$name}</b>
"
             . "Роль: <b>{$roleTitle}</b>

"
             . "Выберите действие:";
    } else {
        return "👋 <b>Привет!</b>
"
             . "Я бот системы СХПК «Береговой».

"
             . "Через меня можно:
"
             . "• смотреть участки по коду
"
             . "• работать со своим аккаунтом
"
             . "• получать уведомления (для админов)

"
             . "Выберите, с чего начать:";
    }
}

/** Клавиатура главного меню */
function tg_main_menu_kb(?array $user) : array {
    if ($user) {
        $kb = [
            [
                ['text' => '📍 Мои участки', 'callback_data' => 'fields_my'],
                ['text' => '🔍 Найти участок', 'callback_data' => 'field_search'],
            ],
            [
                ['text' => '👤 Профиль', 'callback_data' => 'profile'],
            ],
        ];
        if (($user['role'] ?? '') === 'admin') {
            $kb[] = [
                ['text' => '🛡 Админ‑панель', 'callback_data' => 'admin_panel'],
            ];
        }
        return $kb;
    } else {
        return [
            [
                ['text' => '🔑 Войти', 'callback_data' => 'auth_login'],
                ['text' => '📝 Регистрация', 'callback_data' => 'auth_register'],
            ],
            [
                ['text' => '🔍 Быстрый просмотр участка', 'callback_data' => 'field_search'],
            ],
            [
                ['text' => 'ℹ️ Помощь', 'callback_data' => 'help'],
            ],
        ];
    }
}

/** Показать главное меню (send / edit) */
function tg_show_main_menu(int $chatId, ?array $user, ?int $messageId = null, ?string $cbId = null) {
    $text = tg_main_menu_text($user);
    $kb = tg_main_menu_kb($user);
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }
    if ($cbId) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
    }
}

/** Получить / обновить сессию */
function tg_get_session(int $chatId) : array {
    if (!function_exists('tg_session_get')) {
        return ['state' => null, 'data' => []];
    }
    return tg_session_get($chatId);
}
function tg_set_session(int $chatId, ?string $state, array $data = []) : void {
    if (!function_exists('tg_session_set')) return;
    tg_session_set($chatId, $state, $data);
}

/** Формат карточки участка */
function tg_format_field_card(array $row) : string {
    // Безопасное экранирование значений
    $esc = function($v, $empty = 'нет данных') {
        $v = is_null($v) ? '' : trim((string)$v);
        if ($v === '' || $v === '0000-00-00') {
            return $empty;
        }
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    // Форматирование даты с относительным описанием
    $fmtDate = function($key, string $empty = 'нет данных') use ($row, $esc) {
        $raw = $row[$key] ?? null;
        $raw = is_null($raw) ? '' : trim((string)$raw);
        if ($raw === '' || $raw === '0000-00-00') {
            return $empty;
        }
        $ts = strtotime($raw);
        if (!$ts) {
            return $esc($raw, $empty);
        }
        $nice = date('d.m.Y', $ts);

        try {
            $today = new DateTimeImmutable('today');
            $dt    = new DateTimeImmutable(date('Y-m-d', $ts));
            $diff  = $today->diff($dt)->days;
            $suffix = '';

            if ($dt <= $today) {
                if ($diff === 0) {
                    $suffix = ' (сегодня)';
                } elseif ($diff === 1) {
                    $suffix = ' (вчера)';
                } else {
                    $suffix = ' (' . $diff . ' дн. назад)';
                }
            } else {
                if ($diff === 1) {
                    $suffix = ' (завтра)';
                } else {
                    $suffix = ' (через ' . $diff . ' дн.)';
                }
            }

            return $esc($nice, $empty) . $suffix;
        } catch (Exception $e) {
            return $esc($nice, $empty);
        }
    };

    // Площадь
    $areaVal = (float)($row['area_ha'] ?? 0);
    if ($areaVal > 0) {
        $areaText = rtrim(rtrim(number_format($areaVal, 2, '.', ' '), '0'), '.') . ' га';
    } else {
        $areaText = 'не указана';
    }

    // Урожайность
    $gross = (float)($row['gross_yield'] ?? 0);
    $avg   = (float)($row['avg_yield'] ?? 0);
    $grossText = $gross > 0 ? rtrim(rtrim(number_format($gross, 2, '.', ' '), '0'), '.') . ' т' : 'нет данных';
    $avgText   = $avg   > 0 ? rtrim(rtrim(number_format($avg,   2, '.', ' '), '0'), '.') . ' т/га' : 'нет данных';

    // Статус по датам
    $plow = $row['plow_date'] ?? null;
    $sow  = $row['sow_date'] ?? null;
    $treat= $row['treatment_date'] ?? null;
    $harv = $row['harvest_date'] ?? null;

    $status = 'статус не определён (нет данных по работам)';
    if (!empty($harv) && $harv !== '0000-00-00') {
        $status = 'уборка завершена';
    } elseif (!empty($sow) && $sow !== '0000-00-00') {
        if (!empty($treat) && $treat !== '0000-00-00') {
            $status = 'посеяно, выполнена обработка';
        } else {
            $status = 'посеяно, обработка не внесена';
        }
    } elseif (!empty($plow) && $plow !== '0000-00-00') {
        $status = 'вспахано, не засеяно';
    }

    $code = $esc($row['field_code'] ?? '');
    $culture = $esc($row['culture'] ?? '', 'не указана');
    $notes   = trim((string)($row['notes'] ?? ''));
    $notes   = $notes !== '' ? htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

    $text  = "📍 <b>Участок {$code}</b>\n\n";

    $text .= "📊 <b>Основное</b>\n";
    $text .= "• Культура: <b>{$culture}</b>\n";
    $text .= "• Площадь: <b>{$areaText}</b>\n";
    $text .= "• Статус: <b>" . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n";

    $text .= "📅 <b>Работы</b>\n";
    $text .= "• Вспашка: " . $fmtDate('plow_date') . "\n";
    $text .= "• Посев: "   . $fmtDate('sow_date') . "\n";
    $text .= "• Обработка: " . $fmtDate('treatment_date', 'нет данных') . "\n";
    $text .= "• Уборка: "  . $fmtDate('harvest_date', 'ещё не выполнена') . "\n\n";

    $text .= "🌾 <b>Урожайность</b>\n";
    $text .= "• Валовый сбор: {$grossText}\n";
    $text .= "• Средняя: {$avgText}\n\n";

    if ($notes !== '') {
        $text .= "📝 <b>Заметки</b>\n";
        $text .= $notes;
    }

    return $text;
}

/** Сформировать HTML для PDF-отчёта по участку */
function tg_build_field_pdf_html(array $field) : string {
    $hx = function($v, string $empty = '—') : string {
        $v = is_null($v) ? '' : trim((string)$v);
        if ($v === '' || $v === '0000-00-00') {
            $v = $empty;
        }
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $codeDigits = preg_replace('~\D~', '', (string)($field['field_code'] ?? ''));
    if ($codeDigits === '') {
        $codeDigits = '----';
    }

    $codeEsc  = $hx($field['field_code'] ?? $codeDigits);
    $culture  = $hx($field['culture'] ?? '', 'не указана');
    $area     = $hx($field['area_ha'] ?? '');
    $plow     = $hx($field['plow_date'] ?? '');
    $sow      = $hx($field['sow_date'] ?? '');
    $treatD   = $hx($field['treatment_date'] ?? '');
    $treatT   = $hx($field['treatment_desc'] ?? '', 'не указана');
    $water    = $hx($field['last_water_date'] ?? '');
    $harvest  = $hx($field['harvest_date'] ?? '', 'ещё не выполнена');
    $gross    = $hx($field['gross_yield'] ?? '');
    $avg      = $hx($field['avg_yield'] ?? '');
    $notesRaw = (string)($field['notes'] ?? '');
    $notes    = $notesRaw !== '' ? nl2br($hx($notesRaw, ''), false) : '<span class="muted">нет заметок</span>';

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

    return $html;
}



/** Получить информацию по участку */

/** Сформировать HTML для сводного PDF-отчёта по всем участкам */
function tg_build_all_fields_pdf_html(array $rows) : string {
    $hx = function($v, string $empty = '—') : string {
        $v = is_null($v) ? '' : trim((string)$v);
        if ($v === '' or $v === '0000-00-00') {
            $v = $empty;
        }
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $totalFields = count($rows);
    $totalArea = 0.0;
    $byCulture = [];

    foreach ($rows as $r) {
        $area = (float)($r['area_ha'] ?? 0);
        $totalArea += $area;
        $cult = trim((string)($r['culture'] ?? 'Не указана'));
        if ($cult === '') $cult = 'Не указана';
        if (!isset($byCulture[$cult])) {
            $byCulture[$cult] = ['area' => 0.0, 'cnt' => 0];
        }
        $byCulture[$cult]['area'] += $area;
        $byCulture[$cult]['cnt']++;
    }

    uasort($byCulture, function($a, $b) {
        return ($b['area'] <=> $a['area']) ?: ($b['cnt'] <=> $a['cnt']);
    });

    $totalAreaStr = number_format($totalArea, 1, ',', ' ');

    $summaryLines = [];
    foreach ($byCulture as $cult => $info) {
        $areaStr = number_format($info['area'], 1, ',', ' ');
        $cnt = (int)$info['cnt'];
        $cultEsc = htmlspecialchars($cult, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaryLines[] = "        <li><b>{$cultEsc}</b> — {$areaStr} га, участков: {$cnt}</li>";
    }
    $summaryHtml = $summaryLines ? "<ul>\n" . implode("\n", $summaryLines) . "\n      </ul>" : "<p class=\"muted\">Нет данных по культурам.</p>";

    $today = (new DateTimeImmutable('now'))->format('d.m.Y H:i');

    $rowsHtmlParts = [];
    foreach ($rows as $r) {
        $code   = $hx($r['field_code'] ?? '');
        $cult   = $hx($r['culture'] ?? 'Не указана');
        $area   = $hx($r['area_ha'] ?? '');
        $sow    = $hx($r['sow_date'] ?? '');
        $harv   = $hx($r['harvest_date'] ?? '');
        $avg    = $hx($r['avg_yield'] ?? '');
        $rowsHtmlParts[] = "      <tr>"
                          . "<td>{$code}</td>"
                          . "<td>{$cult}</td>"
                          . "<td style=\"text-align:right\">{$area}</td>"
                          . "<td>{$sow}</td>"
                          . "<td>{$harv}</td>"
                          . "<td>{$avg}</td>"
                          . "</tr>";
    }
    $rowsHtml = $rowsHtmlParts ? implode("\n", $rowsHtmlParts) : "";

    $html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сводный отчёт по всем полям</title>
<style>
@page {
    size: A4;
    margin: 16mm;
}
body {
    font-family: "DejaVu Sans", DejaVuSans, sans-serif;
    font-size: 12px;
    line-height: 1.4;
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
.header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    background: #e5f0ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 600;
}
.card {
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 10px 12px;
    background: #f9fafb;
    margin-bottom: 10px;
}
.stats-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.stats-item {
    flex: 1 1 120px;
}
.stats-label {
    font-size: 11px;
    color: #6b7280;
}
.stats-value {
    font-size: 14px;
    font-weight: 600;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    page-break-inside: auto;
}
th, td {
    border: 1px solid #e5e7eb;
    padding: 4px 6px;
    font-size: 11px;
}
th {
    background: #eff6ff;
    text-align: left;
}
tbody tr:nth-child(even) {
    background: #f9fafb;
}
.footer {
    margin-top: 16px;
    font-size: 10px;
    color: #9ca3af;
    text-align: right;
}
</style>
</head>
<body>
<div class="header-row">
  <div>
    <h1>Сводный отчёт по всем полям</h1>
    <div class="muted">СХПК «Береговой» — общий обзор по участкам</div>
  </div>
  <div class="badge">PDF-отчёт</div>
</div>

<div class="card">
  <div class="stats-row">
    <div class="stats-item">
      <div class="stats-label">Всего участков</div>
      <div class="stats-value">{$totalFields}</div>
    </div>
    <div class="stats-item">
      <div class="stats-label">Суммарная площадь, га</div>
      <div class="stats-value">{$totalAreaStr}</div>
    </div>
  </div>
</div>

<h2>По культурам</h2>
<div class="card">
{$summaryHtml}
</div>

<h2>Список полей</h2>
<div class="card">
  <table>
    <thead>
      <tr>
        <th>Код</th>
        <th>Культура</th>
        <th>Площадь, га</th>
        <th>Сев</th>
        <th>Уборка</th>
        <th>Средняя урожайность</th>
      </tr>
    </thead>
    <tbody>
{$rowsHtml}
    </tbody>
  </table>
</div>

<div class="footer">
  Отчёт сформирован {$today}
</div>
</body>
</html>
HTML;

    return $html;
}

function tg_load_field(string $code) : ?array {
    $code = preg_replace('~\D~','', $code);
    if (strlen($code) < 3 || strlen($code) > 4) return null;
    $db = pdo();
    $st = $db->prepare("SELECT f.field_code, f.area_ha, f.plow_date, f.sow_date, f.treatment_date, f.treatment_desc,
                               f.last_water_date, f.harvest_date, f.gross_yield, f.avg_yield, f.notes,
                               c.title AS culture
                        FROM fields f
                        LEFT JOIN cultures c ON c.id = f.culture_id
                        WHERE f.field_code = ? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Проверить, есть ли участок в избранном */
function tg_is_fav(int $chatId, string $code) : bool {
    if (!function_exists('tg_favorites_is')) return false;
    return tg_favorites_is($chatId, $code);
}

/** Показать карточку участка с кнопками */
function tg_show_field(int $chatId, string $code, ?int $messageId = null, ?string $cbId = null) {
    // Аккуратно вытащим цифровой код участка (поддержка ввода вида «поле 1234»)
    $raw    = $code;
    $digits = preg_replace('~\D~', '', $raw ?? '');

    if ($digits === '') {
        tg_answer(
            "Я не вижу в сообщении кода участка.\n\n"
          . "Примеры, как можно отправить код:\n"
          . "• <code>1234</code>\n"
          . "• <code>поле 1234</code>\n"
          . "• <code>участок 1234</code>",
            $chatId
        );
        if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        return;
    }

    if (strlen($digits) < 3 || strlen($digits) > 4) {
        tg_answer(
            "Код участка должен содержать 3–4 цифры.\n\n"
          . "Пример: <code>/field 1253</code> или просто <code>1253</code>.",
            $chatId
        );
        if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        return;
    }

    $row = tg_load_field($digits);
    if (!$row) {
        $safe = htmlspecialchars($digits, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        tg_answer(
            "Я не нашёл участок с кодом <b>{$safe}</b>.\n\n"
          . "🔹 Проверьте, правильно ли указан код.\n"
          . "🔹 Уточните код у агронома или в веб-панели.\n\n"
          . "Можете отправить другой код участка.",
            $chatId
        );
        if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        return;
    }

    $text = tg_format_field_card($row);
    $isFav = tg_is_fav($chatId, $row['field_code']);
    $favBtn = $isFav
        ? ['text' => '⭐ Убрать из избранного', 'callback_data' => 'fav_del:'.$row['field_code']]
        : ['text' => '⭐ В избранное', 'callback_data' => 'fav_add:'.$row['field_code']];
    $pdfBtn = [
        'text' => '📄 PDF-отчёт',
        'callback_data' => 'field_pdf:'.$row['field_code'],
    ];
    $remBtn = [
        'text' => '⏰ Напомнить',
        'callback_data' => 'field_remind:'.$row['field_code'],
    ];
    $taskBtn = [
        'text' => '📌 Задача',
        'callback_data' => 'field_task_add:'.$row['field_code'],
    ];
    $kb = [
        [ $pdfBtn, $favBtn ],
        [ $remBtn, $taskBtn ],
        [
            ['text' => '🔁 Другой участок', 'callback_data' => 'field_search'],
            ['text' => '🏠 Меню', 'callback_data' => 'home'],
        ],
    ];
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }
    if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
}


function tg_show_favorites(int $chatId, ?int $messageId = null, ?string $cbId = null, int $page = 1) {
    if (!function_exists('tg_favorites_list')) {
        tg_answer("Избранные участки недоступны.", $chatId);
        return;
    }
    $rows = tg_favorites_list($chatId);

    if (!$rows) {
        $text = "⭐ У вас пока нет избранных участков.\n\n"
              . "Найдите участок по коду и добавьте его в избранное кнопкой «⭐» на карточке участка.";
        $kb = [
            [ ['text' => '🔍 Найти участок', 'callback_data' => 'field_search'] ],
            [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
        ];
    } else {
        $perPage = 5;
        $total   = count($rows);
        $pages   = max(1, (int)ceil($total / $perPage));

        if ($page < 1) $page = 1;
        if ($page > $pages) $page = $pages;

        $offset = ($page - 1) * $perPage;
        $slice  = array_slice($rows, $offset, $perPage);

        $lines = [];
        $lines[] = "⭐ <b>Мои участки</b>";
        $lines[] = "Всего: <b>{$total}</b>. Страница {$page} из {$pages}.";
        $lines[] = "";

        foreach ($slice as $r) {
            $code = htmlspecialchars($r['field_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $cult = htmlspecialchars($r['culture'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $area = htmlspecialchars($r['area_ha'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = "• <b>{$code}</b> — {$cult}, {$area} га";
        }

        $text = implode("\n", $lines);

        $kb = [];

        // Кнопки по участкам
        foreach ($slice as $r) {
            $code = $r['field_code'];
            $kb[] = [
                ['text' => "📍 {$code}", 'callback_data' => 'field_view:'.$code],
                ['text' => "✖ {$code}", 'callback_data' => 'fav_del:'.$code],
            ];
        }

        // Пагинация
        if ($pages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $pagRow[] = ['text' => '⬅️ Назад', 'callback_data' => 'fields_my_page:'.($page - 1)];
            }
            if ($page < $pages) {
                $pagRow[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'fields_my_page:'.($page + 1)];
            }
            if ($pagRow) {
                $kb[] = $pagRow;
            }
        }

        // Общие действия
        $kb[] = [ ['text' => '🔍 Найти участок', 'callback_data' => 'field_search'] ];
        $kb[] = [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ];
    }

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }
    if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
}


/** Текст /help */

function tg_show_my_reminders(int $chatId, ?int $messageId = null, ?string $cbId = null, int $page = 1) {
    if (!function_exists('tg_ensure_reminders')) {
        tg_answer("Напоминания недоступны на этом сервере.", $chatId);
        return;
    }
    tg_ensure_reminders();
    $db = pdo();

    // Всего активных напоминаний
    $st = $db->prepare("SELECT COUNT(*) FROM `tg_reminders` WHERE `chat_id` = ? AND `done_at` IS NULL");
    $st->execute([$chatId]);
    $total = (int)$st->fetchColumn();

    if ($total === 0) {
        $text = "⏰ У вас пока нет активных напоминаний.\n\n"
              . "Создайте напоминание из карточки участка — кнопка «⏰ Напомнить».";
        $kb = [
            [ ['text' => '📍 Мои участки', 'callback_data' => 'fields_my'] ],
            [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
        ];
    } else {
        $perPage = 5;
        $pages = (int)max(1, ceil($total / $perPage));
        if ($page < 1) $page = 1;
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $st = $db->prepare("SELECT `id`,`field_code`,`remind_at`,`text` FROM `tg_reminders` WHERE `chat_id` = ? AND `done_at` IS NULL ORDER BY `remind_at` ASC, `id` ASC LIMIT ? OFFSET ?");
        $st->bindValue(1, $chatId, PDO::PARAM_INT);
        $st->bindValue(2, $perPage, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lines = [];
        $lines[] = "⏰ <b>Мои напоминания</b>";
        $lines[] = "Всего активных: <b>{$total}</b>. Страница {$page} из {$pages}.";
        $lines[] = "";

        foreach ($rows as $r) {
            $id   = (int)($r['id'] ?? 0);
            $code = htmlspecialchars((string)($r['field_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $txt  = trim((string)($r['text'] ?? ''));
            try {
                $dt = new DateTimeImmutable((string)($r['remind_at'] ?? ''));
                $human = $dt->format('d.m.Y H:i');
            } catch (Exception $e) {
                $human = (string)($r['remind_at'] ?? '');
            }
            if ($txt === '') {
                $txt = 'без текста';
            }
            if (mb_strlen($txt) > 60) {
                $txt = mb_substr($txt, 0, 57) . '...';
            }
            $safeTxt = htmlspecialchars($txt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = "• <b>{$human}</b> — участок <b>{$code}</b> — {$safeTxt}";
        }

        $text = implode("\n", $lines);

        $kb = [];

        foreach ($rows as $r) {
            $id   = (int)($r['id'] ?? 0);
            $code = htmlspecialchars((string)($r['field_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $kb[] = [
                ['text' => "❌ {$code}", 'callback_data' => 'remind_del:'.$id],
                ['text' => '↪ +1 день', 'callback_data' => 'remind_shift:'.$id.':1'],
                ['text' => '↪ +3 дня', 'callback_data' => 'remind_shift:'.$id.':3'],
            ];
        }

        // Пагинация
        if ($pages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $pagRow[] = ['text' => '⬅️ Назад', 'callback_data' => 'my_reminders_page:'.($page-1)];
            }
            if ($page < $pages) {
                $pagRow[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'my_reminders_page:'.($page+1)];
            }
            if ($pagRow) {
                $kb[] = $pagRow;
            }
        }

        $kb[] = [
            ['text' => '👤 Профиль', 'callback_data' => 'profile'],
            ['text' => '🏠 Меню', 'callback_data' => 'home'],
        ];
    }

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }
    if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
}


function tg_show_my_tasks(int $chatId, ?int $messageId = null, ?string $cbId = null, int $page = 1) {
    if (!function_exists('tg_ensure_tasks')) {
        tg_answer("Задачи недоступны на этом сервере.", $chatId);
        return;
    }
    tg_ensure_tasks();
    $db = pdo();

    $st = $db->prepare("SELECT COUNT(*) FROM `tg_tasks` WHERE `chat_id` = ? AND `status` = 0");
    $st->execute([$chatId]);
    $total = (int)$st->fetchColumn();

    if ($total === 0) {
        $text = "📝 У вас пока нет активных задач.\n\n"
              . "Откройте карточку участка и нажмите «📌 Задача», чтобы добавить первую.";
        $kb = [
            [ ['text' => '📍 Мои участки', 'callback_data' => 'fields_my'] ],
            [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
        ];
    } else {
        $perPage = 5;
        $pages = (int)max(1, ceil($total / $perPage));
        if ($page < 1) $page = 1;
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $st = $db->prepare("SELECT `id`,`field_code`,`text`,`due_at`,`created_at` FROM `tg_tasks` WHERE `chat_id` = ? AND `status` = 0 ORDER BY 
            CASE WHEN `due_at` IS NULL THEN 1 ELSE 0 END,
            `due_at` ASC,
            `created_at` ASC,
            `id` ASC
            LIMIT ? OFFSET ?");
        $st->bindValue(1, $chatId, PDO::PARAM_INT);
        $st->bindValue(2, $perPage, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lines = [];
        $lines[] = "📝 <b>Мои задачи</b>";
        $lines[] = "Всего активных: <b>{$total}</b>. Страница {$page} из {$pages}.";
        $lines[] = "";

        foreach ($rows as $r) {
            $id   = (int)($r['id'] ?? 0);
            $code = htmlspecialchars((string)($r['field_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $txt  = trim((string)($r['text'] ?? ''));
            if ($txt === '') $txt = 'без описания';
            if (mb_strlen($txt) > 70) {
                $txt = mb_substr($txt, 0, 67) . '...';
            }
            $safeTxt = htmlspecialchars($txt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $dueHuman = '';
            if (!empty($r['due_at'])) {
                try {
                    $dt = new DateTimeImmutable((string)$r['due_at']);
                    $dueHuman = $dt->format('d.m.Y H:i');
                } catch (Exception $e) {
                    $dueHuman = (string)$r['due_at'];
                }
            }
            if ($dueHuman !== '') {
                $lines[] = "• <b>{$code}</b> — до <b>{$dueHuman}</b> — {$safeTxt}";
            } else {
                $lines[] = "• <b>{$code}</b> — {$safeTxt}";
            }
        }

        $text = implode("\n", $lines);

        $kb = [];
        foreach ($rows as $r) {
            $id   = (int)($r['id'] ?? 0);
            $code = htmlspecialchars((string)($r['field_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $kb[] = [
                ['text' => "✅ {$code}", 'callback_data' => 'task_done:'.$id],
                ['text' => '❌ Удалить', 'callback_data' => 'task_del:'.$id],
                ['text' => '↪ +1 день', 'callback_data' => 'task_shift:'.$id.':1'],
            ];
        }

        if ($pages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $pagRow[] = ['text' => '⬅️ Назад', 'callback_data' => 'my_tasks_page:'.($page-1)];
            }
            if ($page < $pages) {
                $pagRow[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'my_tasks_page:'.($page+1)];
            }
            if ($pagRow) {
                $kb[] = $pagRow;
            }
        }

        $kb[] = [
            ['text' => '👤 Профиль', 'callback_data' => 'profile'],
            ['text' => '🏠 Меню', 'callback_data' => 'home'],
        ];
    }

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }
    if ($cbId) tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
}


/**
 * Показать недельный календарь задач и напоминаний для пользователя.
 *
 * @param int         $chatId
 * @param int|null    $messageId
 * @param string|null $cbId
 * @param string|null $weekStart 'Y-m-d' (понедельник), если null — берём текущую неделю
 */
function tg_show_calendar(int $chatId, ?int $messageId = null, ?string $cbId = null, ?string $weekStart = null) : void {
    try {
        if ($weekStart) {
            $monday = new DateTimeImmutable($weekStart . ' 00:00:00');
        } else {
            $monday = new DateTimeImmutable('today');
            // Приводим к понедельнику текущей недели
            $dow = (int)$monday->format('N'); // 1..7, где 1 — понедельник
            $monday = $monday->modify('-'.($dow-1).' days');
        }
    } catch (Exception $e) {
        $monday = new DateTimeImmutable('today');
        $dow = (int)$monday->format('N');
        $monday = $monday->modify('-'.($dow-1).' days');
    }

    $sunday = $monday->modify('+6 days');

    $startStr = $monday->format('Y-m-d 00:00:00');
    $endStr   = $sunday->format('Y-m-d 23:59:59');

    if (function_exists('tg_ensure_tasks')) {
        tg_ensure_tasks();
    }
    if (function_exists('tg_ensure_reminders')) {
        tg_ensure_reminders();
    }

    $db = pdo();

    // Задачи с датой в пределах недели
    $tasksByDay = [];
    $tasksNoDate = [];
    if (function_exists('tg_ensure_tasks')) {
        $st = $db->prepare("SELECT `id`,`field_code`,`text`,`due_at`
                            FROM `tg_tasks`
                            WHERE `chat_id` = ? AND `status` = 0
                            ORDER BY
                                CASE WHEN `due_at` IS NULL THEN 1 ELSE 0 END,
                                `due_at` ASC,
                                `created_at` ASC,
                                `id` ASC");
        $st->execute([$chatId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $text = trim((string)($row['text'] ?? ''));
            $code = trim((string)($row['field_code'] ?? ''));
            $due  = $row['due_at'] ?? null;
            if ($due && $due !== '0000-00-00 00:00:00') {
                try {
                    $dt = new DateTimeImmutable((string)$due);
                    $key = $dt->format('Y-m-d');
                    if ($dt >= new DateTimeImmutable($startStr) && $dt <= new DateTimeImmutable($endStr)) {
                        $tasksByDay[$key][] = $row;
                    }
                } catch (Exception $e) {
                    $tasksNoDate[] = $row;
                }
            } else {
                $tasksNoDate[] = $row;
            }
        }
    }

    // Напоминания в пределах недели
    $remByDay = [];
    if (function_exists('tg_ensure_reminders')) {
        $st = $db->prepare("SELECT `id`,`field_code`,`text`,`remind_at`
                            FROM `tg_reminders`
                            WHERE `chat_id` = ? AND `done_at` IS NULL
                              AND `remind_at` BETWEEN ? AND ?
                            ORDER BY `remind_at` ASC, `id` ASC");
        $st->execute([$chatId, $startStr, $endStr]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rem = $row['remind_at'] ?? null;
            if ($rem && $rem !== '0000-00-00 00:00:00') {
                try {
                    $dt = new DateTimeImmutable((string)$rem);
                    $key = $dt->format('Y-m-d');
                    $remByDay[$key][] = $row;
                } catch (Exception $e) {
                    // пропускаем битую дату
                }
            }
        }
    }

    $weekLabel = $monday->format('d.m') . '–' . $sunday->format('d.m');
    $textLines = [];
    $textLines[] = "📅 <b>Календарь на неделю {$weekLabel}</b>";
    $textLines[] = "";

    $dayNamesFull = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

    $cursor = $monday;
    for ($i = 0; $i < 7; $i++) {
        $key = $cursor->format('Y-m-d');
        $label = $dayNamesFull[$i] . ' ' . $cursor->format('d.m');

        $dayTasks = $tasksByDay[$key] ?? [];
        $dayRems  = $remByDay[$key] ?? [];

        if (empty($dayTasks) && empty($dayRems)) {
            $cursor = $cursor->modify('+1 day');
            continue;
        }

        $textLines[] = "<b>{$label}</b>";

        foreach ($dayRems as $r) {
            $t = trim((string)($r['text'] ?? ''));
            $code = trim((string)($r['field_code'] ?? ''));
            $timeStr = '';
            try {
                $dt = new DateTimeImmutable((string)$r['remind_at']);
                $timeStr = $dt->format('H:i');
            } catch (Exception $e) {}
            $tEsc = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $codeEsc = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $prefix = $timeStr ? "⏰ {$timeStr}" : "⏰";
            if ($codeEsc !== '') {
                $textLines[] = "{$prefix} — участок <b>{$codeEsc}</b>: {$tEsc}";
            } else {
                $textLines[] = "{$prefix}: {$tEsc}";
            }
        }

        foreach ($dayTasks as $r) {
            $t = trim((string)($r['text'] ?? ''));
            $code = trim((string)($r['field_code'] ?? ''));
            $timeStr = '';
            try {
                $dt = new DateTimeImmutable((string)$r['due_at']);
                $timeStr = $dt->format('H:i');
            } catch (Exception $e) {}
            $tEsc = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $codeEsc = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $prefix = $timeStr ? "📝 {$timeStr}" : "📝";
            if ($codeEsc !== '') {
                $textLines[] = "{$prefix} — участок <b>{$codeEsc}</b>: {$tEsc}";
            } else {
                $textLines[] = "{$prefix}: {$tEsc}";
            }
        }

        $textLines[] = "";
        $cursor = $cursor->modify('+1 day');
    }

    if (!$tasksByDay && !$remByDay) {
        $textLines[] = "На этой неделе у вас нет запланированных задач и напоминаний.";
    }

    if (!empty($tasksNoDate)) {
        $textLines[] = "<b>Без даты</b>";
        foreach ($tasksNoDate as $r) {
            $t = trim((string)($r['text'] ?? ''));
            $code = trim((string)($r['field_code'] ?? ''));
            $tEsc = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $codeEsc = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($codeEsc !== '') {
                $textLines[] = "• участок <b>{$codeEsc}</b>: {$tEsc}";
            } else {
                $textLines[] = "• {$tEsc}";
            }
        }
    }

    $prevMonday = $monday->modify('-7 days')->format('Y-m-d');
    $nextMonday = $monday->modify('+7 days')->format('Y-m-d');

    $kb = [
        [
            ['text' => '⬅️ Пред. неделя', 'callback_data' => 'calendar_week:'.$prevMonday],
            ['text' => 'След. неделя ➡️', 'callback_data' => 'calendar_week:'.$nextMonday],
        ],
        [
            ['text' => '📝 Мои задачи', 'callback_data' => 'my_tasks'],
            ['text' => '⏰ Мои напоминания', 'callback_data' => 'my_reminders'],
        ],
        [
            ['text' => '👤 Профиль', 'callback_data' => 'profile'],
            ['text' => '🏠 Меню', 'callback_data' => 'home'],
        ],
    ];

    $text = implode("\n", $textLines);

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($kb),
    ];
    if ($messageId) {
        $params['message_id'] = $messageId;
        tg_api('editMessageText', $params);
    } else {
        tg_api('sendMessage', $params);
    }

    if ($cbId) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
    }
}

function tg_help_text() : string {
    return "ℹ️ <b>Справка</b>\n"
         . "— Главное меню: /start\n"
         . "— Быстрый просмотр участка: /field 1234\n"
         . "— Ваш chat id: /id\n"
         . "— Привязка админ‑чата: /bind SECRET\n"
         . "— Сводка по системе: /stats (для админа)\n"
         . "— Последние события: /last (для админа)";
}

/* ----------------- Обработка сообщений по состояниям ----------------- */

function tg_handle_stateful_message(int $chatId, string $text, array $session, ?array $user) : void {
    $state = $session['state'] ?? null;
    $data  = $session['data']  ?? [];

    // Глобальный выход
    if ($text === '/cancel') {
        tg_set_session($chatId, null, []);
        tg_answer("❌ Действие отменено.", $chatId);
        tg_show_main_menu($chatId, $user, null, null);
        return;
    }

    $db = pdo();

    switch ($state) {
        case 'reg_surname': {
            $surname = trim($text);
            if ($surname === '') {
                tg_answer("Введите фамилию:", $chatId);
                return;
            }
            $data['surname'] = $surname;
            tg_set_session($chatId, 'reg_name', $data);
            tg_answer("Отлично. Теперь введите имя:", $chatId);
        } break;

        case 'reg_name': {
            $name = trim($text);
            if ($name === '') {
                tg_answer("Введите имя:", $chatId);
                return;
            }
            $data['name'] = $name;
            tg_set_session($chatId, 'reg_username', $data);
            tg_answer("Придумайте логин (username):", $chatId);
        } break;

        case 'reg_username': {
            $username = trim($text);
            if ($username === '') {
                tg_answer("Логин не может быть пустым. Введите логин:", $chatId);
                return;
            }
            $st = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $st->execute([$username]);
            if ((int)$st->fetchColumn() > 0) {
                tg_answer("Такой логин уже используется. Введите другой логин:", $chatId);
                return;
            }
            $data['username'] = $username;
            tg_set_session($chatId, 'reg_password', $data);
            tg_answer("Придумайте пароль (минимум 4 символа):", $chatId);
        } break;

        case 'reg_password': {
            $password = trim($text);
            if (strlen($password) < 4) {
                tg_answer("Пароль слишком короткий. Введите пароль ещё раз (не менее 4 символов):", $chatId);
                return;
            }
            $surname = trim((string)($data['surname'] ?? ''));
            $name    = trim((string)($data['name'] ?? ''));
            $username= trim((string)($data['username'] ?? ''));
            if ($surname === '' || $name === '' || $username === '') {
                tg_set_session($chatId, null, []);
                tg_answer("Произошла ошибка сессии. Попробуйте зарегистрироваться ещё раз.", $chatId);
                return;
            }
            // На всякий случай проверим логин ещё раз
            $st = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $st->execute([$username]);
            if ((int)$st->fetchColumn() > 0) {
                // Логин уже занят — отправим подсказки и вернёмся к шагу выбора логина
                tg_answer(
                    "Такой логин уже используется.\n\n"
                  . "Попробуйте добавить цифру или выбрать другой вариант.\n"
                  . "Примеры:\n"
                  . "• <code>{$username}1</code>\n"
                  . "• <code>{$username}_agro</code>",
                    $chatId
                );
                tg_set_session($chatId, 'reg_username', ['surname' => $surname, 'name' => $name]);
                return;
            }

            // Сохраняем все данные и выводим экран подтверждения
            $data = [
                'surname' => $surname,
                'name' => $name,
                'username' => $username,
                'password' => $password,
            ];
            tg_set_session($chatId, 'reg_confirm', $data);

            $safeSurname  = htmlspecialchars($surname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeName     = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $text = "Проверьте данные перед регистрацией:\n\n"
                  . "👤 Фамилия: <b>{$safeSurname}</b>\n"
                  . "👤 Имя: <b>{$safeName}</b>\n"
                  . "🔑 Логин: <code>{$safeUsername}</code>\n\n"
                  . "Если всё верно — подтвердите регистрацию кнопкой ниже.";

            $kb = [
                [
                    ['text' => '✅ Всё верно', 'callback_data' => 'reg_ok'],
                    ['text' => '❌ Исправить', 'callback_data' => 'reg_cancel'],
                ],
            ];

            tg_answer($text, $chatId, ['reply_markup' => tg_kb($kb)]);
        } break;

        case 'login_username': {
            $username = trim($text);
            if ($username === '') {
                tg_answer("Введите логин:", $chatId);
                return;
            }
            $data = ['username' => $username];
            tg_set_session($chatId, 'login_password', $data);
            tg_answer("Введите пароль:", $chatId);
        } break;

        case 'login_password': {
            $username = trim((string)($data['username'] ?? ''));
            $password = trim($text);
            if ($username === '' || $password === '') {
                tg_answer("Логин или пароль пустой. Попробуйте войти ещё раз.", $chatId);
                tg_set_session($chatId, null, []);
                return;
            }
            $st = $db->prepare("SELECT id, username, surname, name, role, password FROM users WHERE username = ? LIMIT 1");
            $st->execute([$username]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || (string)$u['password'] !== $password) {
                tg_answer("⛔ Неверный логин или пароль. Попробуйте ещё раз.", $chatId);
                tg_set_session($chatId, 'login_username', []);
                tg_answer("Введите логин:", $chatId);
                return;
            }

            // Привязываем чат к этому пользователю
            tg_bind_chat($chatId, (int)$u['id']);

            tg_set_session($chatId, null, []);
            $fullName = htmlspecialchars(trim(($u['surname'] ?? '').' '.($u['name'] ?? '')) ?: ($u['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            tg_answer("✅ Вы успешно вошли как <b>{$fullName}</b>.", $chatId);
            $user = $u;
            tg_show_main_menu($chatId, $user, null, null);
        } break;

        case 'field_search': {
            $raw = $text;
            $code = preg_replace('~\D~', '', $raw);
            if ($code === '') {
                tg_answer(
                    "Отправьте код участка, который хотите посмотреть.\n\n"
                  . "Можно просто цифрами: <code>1234</code> или текстом: <code>поле 1234</code>.",
                    $chatId
                );
                return;
            }
            if (strlen($code) < 3 || strlen($code) > 4) {
                tg_answer(
                    "Код участка должен содержать 3–4 цифры.\n\n"
                  . "Пример: <code>1253</code>.",
                    $chatId
                );
                return;
            }
            tg_show_field($chatId, $code, null, null);
            // Состояние оставляем, чтобы можно было ввести ещё один код
        } break;

        case 'remind_ask_text': {
            $code = preg_replace('~\D~', '', (string)($data['field_code'] ?? ''));
            $remAt = (string)($data['remind_at'] ?? '');
            $textRem = trim($text);
            if ($textRem === '') {
                tg_answer("Текст напоминания пустой. Напишите, о чём напомнить, или отправьте /cancel для отмены.", $chatId);
                return;
            }
            if (strlen($code) < 3 || strlen($code) > 4 || $remAt === '') {
                tg_set_session($chatId, null, []);
                tg_answer("Произошла ошибка при сохранении напоминания. Попробуйте создать его ещё раз.", $chatId);
                return;
            }
            if (function_exists('tg_reminder_add')) {
                tg_reminder_add($chatId, $code, $remAt, $textRem);
            }
            try {
                $dt = new DateTimeImmutable($remAt);
            } catch (Exception $e) {
                $dt = null;
            }
            $human = $dt ? $dt->format('d.m.Y H:i') : $remAt;
            tg_set_session($chatId, null, []);
            tg_answer("✅ Напоминание по участку <b>{$code}</b> на <b>{$human}</b> создано.", $chatId, ['parse_mode' => 'HTML']);
            return;
        } break;


        case 'task_add_text': {
            $code = preg_replace('~\D~', '', (string)($data['field_code'] ?? ''));
            $textTask = trim($text);
            if ($textTask === '') {
                tg_answer("Текст задачи пустой. Напишите, что нужно сделать, или отправьте /cancel для отмены.", $chatId);
                return;
            }
            if (strlen($code) < 3 || strlen($code) > 4) {
                tg_set_session($chatId, null, []);
                tg_answer("Произошла ошибка при сохранении задачи. Попробуйте создать её ещё раз из карточки участка.", $chatId);
                return;
            }
            if (function_exists('tg_task_add')) {
                tg_task_add($chatId, $code, $textTask, null);
            }
            tg_set_session($chatId, null, []);
            tg_answer("✅ Задача по участку <b>{$code}</b> создана.", $chatId, ['parse_mode' => 'HTML']);
            // После создания покажем карточку участка
            tg_show_field($chatId, $code, null, null);
            return;
        } break;

        case 'admin_broadcast': {
            // Только для админа
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_answer("⛔ Рассылка доступна только администраторам.", $chatId);
                tg_set_session($chatId, null, []);
                return;
            }
            $textToSend = trim($text);
            if ($textToSend === '') {
                tg_answer("Текст пустой. Введите текст рассылки или /cancel для отмены:", $chatId);
                return;
            }
            $res = tg_broadcast($textToSend);
            $sent   = (int)($res['sent']   ?? 0);
            $failed = (int)($res['failed'] ?? 0);
            tg_answer("📢 Рассылка завершена.\n✅ Отправлено: {$sent}\n⚠️ Ошибок: {$failed}", $chatId);
            tg_set_session($chatId, null, []);
            tg_show_main_menu($chatId, $user, null, null);
        } break;

        default: {
            // Неизвестное состояние — сброс
            tg_set_session($chatId, null, []);
            tg_show_main_menu($chatId, $user, null, null);
        } break;
    }
}

/* ----------------- Обработка callback_query ----------------- */

function tg_handle_callback(array $cb) : void {
    $chatId = (int)($cb['message']['chat']['id'] ?? 0);
    $msgId  = (int)($cb['message']['message_id'] ?? 0);
    $data   = (string)($cb['data'] ?? '');
    $cbId   = (string)($cb['id'] ?? '');
    if (!$chatId) return;

    tg_subscriber_upsert($cb['message']);

    $user    = tg_user_by_chat($chatId);
    $session = tg_get_session($chatId);

    if ($data === 'home') {
        tg_set_session($chatId, null, []);
        tg_show_main_menu($chatId, $user, $msgId, $cbId);
        return;
    }

    if ($data === 'help') {
        $text = tg_help_text();
        $kb = [
            [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
        ];
        $params = [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => tg_kb($kb),
        ];
        tg_api('editMessageText', $params);
        tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        return;
    }

    switch (true) {
                case ($data === 'reg_ok'): {
            $state = $session['state'] ?? null;
            $d     = $session['data']  ?? [];
            if ($state !== 'reg_confirm') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
                return;
            }

            $surname  = trim((string)($d['surname'] ?? ''));
            $name     = trim((string)($d['name'] ?? ''));
            $username = trim((string)($d['username'] ?? ''));
            $password = trim((string)($d['password'] ?? ''));

            if ($surname === '' || $name === '' || $username === '' || $password === '') {
                tg_set_session($chatId, null, []);
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
                tg_answer("Произошла ошибка сессии. Попробуйте зарегистрироваться ещё раз.", $chatId);
                return;
            }

            $db = pdo();
            $st = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $st->execute([$username]);
            if ((int)$st->fetchColumn() > 0) {
                // Пока подтверждали, логин уже могли занять
                tg_set_session($chatId, 'reg_username', ['surname' => $surname, 'name' => $name]);
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
                tg_answer(
                    "Такой логин уже используется.\n\n"
                  . "Выберите, пожалуйста, другой логин.",
                    $chatId
                );
                return;
            }

            // Привяжем этот чат только к одному пользователю
            tg_unbind_chat($chatId);

            $st = $db->prepare("INSERT INTO users (surname, name, username, password, role, tg_user_id) VALUES (?,?,?,?,?,?)");
            $st->execute([$surname, $name, $username, $password, 'user', $chatId]);
            $userId = (int)$db->lastInsertId();

            tg_set_session($chatId, null, []);
            $user = [
                'id' => $userId,
                'surname' => $surname,
                'name' => $name,
                'username' => $username,
                'role' => 'user',
            ];
            $fullName = htmlspecialchars(trim($surname.' '.$name) ?: $username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_answer("✅ Регистрация завершена. Вы вошли как <b>{$fullName}</b>.", $chatId);
            tg_show_main_menu($chatId, $user, null, null);
        } break;

        case ($data === 'reg_cancel'): {
            if (($session['state'] ?? null) === 'reg_confirm') {
                tg_set_session($chatId, null, []);
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
                tg_answer("Регистрация отменена.\n\nВы можете начать заново, выбрав пункт «Регистрация» в меню.", $chatId);
                return;
            }
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

case ($data === 'auth_login'): {
            tg_set_session($chatId, 'login_username', []);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_answer("Введите логин:", $chatId);
        } break;

        case ($data === 'auth_register'): {
            tg_set_session($chatId, 'reg_surname', []);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_answer("Давайте зарегистрируемся. Сначала введите вашу фамилию:", $chatId);
        } break;

        case ($data === 'field_search'): {
            tg_set_session($chatId, 'field_search', []);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_answer("Введите код участка (3–4 цифры):", $chatId);
        } break;

        case ($data === 'fields_my'): {
            tg_show_favorites($chatId, $msgId, $cbId, 1);
        } break;

        case (strpos($data, 'fields_my_page:') === 0): {
            $page = (int)substr($data, strlen('fields_my_page:'));
            if ($page < 1) $page = 1;
            tg_show_favorites($chatId, $msgId, $cbId, $page);
        } break;

        case (strpos($data, 'field_view:') === 0): {
            $code = substr($data, strlen('field_view:'));
            tg_show_field($chatId, $code, $msgId, $cbId);
        } break;

        case (strpos($data, 'field_remind:') === 0): {
            $code = substr($data, strlen('field_remind:'));
            $code = preg_replace('~\D~', '', $code);
            if (strlen($code) < 3 || strlen($code) > 4) {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Некорректный код участка', 'show_alert' => true]);
                break;
            }
            $text = "⏰ Когда напомнить по участку <b>{$code}</b>?";
            $kb = [
                [
                    ['text' => 'Через 1 день', 'callback_data' => 'remind_time:'.$code.':1'],
                    ['text' => 'Через 3 дня', 'callback_data' => 'remind_time:'.$code.':3'],
                ],
                [
                    ['text' => 'Через 7 дней', 'callback_data' => 'remind_time:'.$code.':7'],
                ],
                [
                    ['text' => '❌ Отмена', 'callback_data' => 'remind_cancel'],
                ],
            ];
            tg_set_session($chatId, 'remind_pick_time', ['field_code' => $code]);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_send_message($text, $chatId, [
                'parse_mode' => 'HTML',
                'reply_markup' => tg_kb($kb),
            ]);
        } break;

        case (strpos($data, 'remind_time:') === 0): {
            $payload = substr($data, strlen('remind_time:'));
            $parts = explode(':', $payload);
            $code = preg_replace('~\D~', '', $parts[0] ?? '');
            $days = (int)($parts[1] ?? 0);
            if (strlen($code) < 3 || strlen($code) > 4 || $days <= 0) {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Некорректные параметры напоминания', 'show_alert' => true]);
                break;
            }
            $now = new DateTimeImmutable('now');
            $remAtDt = $now->modify('+'.$days.' days');
            $remAt   = $remAtDt->format('Y-m-d H:i:s');
            $human   = $remAtDt->format('d.m.Y H:i');
            tg_set_session($chatId, 'remind_ask_text', [
                'field_code' => $code,
                'remind_at'  => $remAt,
            ]);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_send_message(
                "✏️ Что напомнить по участку <b>{$code}</b>?\n\n"
              . "Напишите текст напоминания в одном сообщении.\n"
              . "<i>Время напоминания: {$human}</i>",
                $chatId,
                ['parse_mode' => 'HTML']
            );
        } break;

        case ($data === 'remind_cancel'): {
            tg_set_session($chatId, null, []);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Напоминание отменено', 'show_alert' => false]);
        } break;

        case (strpos($data, 'field_pdf:') === 0): {
            $code = substr($data, strlen('field_pdf:'));
            $code = preg_replace('~\D~', '', $code);
            if (strlen($code) >= 3 && strlen($code) <= 4) {
                $row = tg_load_field($code);
                if (!$row) {
                    tg_send_message("❌ Я не нашёл данных по участку {$code}, PDF сделать не могу.", $chatId);
                } else {
                    // Подключаем Dompdf (вариант без Composer)
                    if (!class_exists('Dompdf\Dompdf')) {
                        $dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
                        if (file_exists($dompdfAutoload)) {
                            require_once $dompdfAutoload;
                        }
                    }

                    if (!class_exists('Dompdf\Dompdf')) {
                        tg_send_message("❌ Не удалось сформировать PDF: библиотека Dompdf не найдена на сервере.", $chatId);
                    } else {
                        $html = tg_build_field_pdf_html($row);

                        $options = new \Dompdf\Options();
                        $options->set('isRemoteEnabled', true);
                        $options->set('defaultFont', 'DejaVu Sans');

                        $dompdf = new \Dompdf\Dompdf($options);
                        $dompdf->loadHtml($html, 'UTF-8');
                        $dompdf->setPaper('A4', 'portrait');
                        $dompdf->render();
                        $pdfData = $dompdf->output();

                        $tmpFile = tempnam(sys_get_temp_dir(), 'tgpdf_');
                        if ($tmpFile !== false && $pdfData !== false) {
                            file_put_contents($tmpFile, $pdfData);
                            $filename = 'field_' . $code . '_report.pdf';
                            $res = tg_send_document_file($chatId, $tmpFile, $filename, "📄 PDF-отчёт по участку {$code}");
                            @unlink($tmpFile);
                            if (!($res['ok'] ?? false)) {
                                $err = $res['error'] ?? 'ошибка отправки';
                                tg_send_message("❌ Не удалось отправить PDF: {$err}", $chatId);
                            }
                        } else {
                            tg_send_message("❌ Не удалось сохранить временный файл для PDF.", $chatId);
                        }
                    }
                }
            } else {
                tg_send_message("❌ Неверный код участка для PDF.", $chatId);
            }
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case (strpos($data, 'fav_add:') === 0): {
            $code = substr($data, strlen('fav_add:'));
            if (function_exists('tg_favorites_add')) {
                tg_favorites_add($chatId, $code);
            }
            tg_show_field($chatId, $code, $msgId, $cbId);
        } break;

        case (strpos($data, 'fav_del:') === 0): {
            $code = substr($data, strlen('fav_del:'));
            if (function_exists('tg_favorites_remove')) {
                tg_favorites_remove($chatId, $code);
            }
            // После удаления — либо обновляем список, либо просто карточку
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_show_field($chatId, $code, $msgId, null);
        } break;

        case ($data === 'profile'): {
            if (!$user) {
                $text = "Вы ещё не авторизованы. Нажмите «🔑 Войти» или «📝 Регистрация».";
                $kb = [
                    [
                        ['text' => '🔑 Войти', 'callback_data' => 'auth_login'],
                        ['text' => '📝 Регистрация', 'callback_data' => 'auth_register'],
                    ],
                    [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
                ];
            } else {
                $fullName = trim(($user['surname'] ?? '').' '.($user['name'] ?? '')) ?: ($user['username'] ?? '—');
                $fullName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $roleTitle = htmlspecialchars(tg_role_title($user['role'] ?? 'user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $text = "👤 <b>Профиль</b>\n"
                      . "Имя: <b>{$fullName}</b>\n"
                      . "Логин: <code>".htmlspecialchars($user['username'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>\n"
                      . "Роль: <b>{$roleTitle}</b>";
                $kb = [
                    [
                        ['text' => '📍 Мои участки', 'callback_data' => 'fields_my'],
                        ['text' => '⏰ Мои напоминания', 'callback_data' => 'my_reminders'],
                    ],
                    [
                        ['text' => '📝 Мои задачи', 'callback_data' => 'my_tasks'],
                        ['text' => '📅 Календарь', 'callback_data' => 'calendar'],
                    ],
                    [
                        ['text' => '🚪 Выйти', 'callback_data' => 'logout_confirm'],
                    ],
                    [ ['text' => '🏠 Меню', 'callback_data' => 'home'] ],
                ];
            }
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;




        case ($data === 'logout_confirm'): {
            if (!$user) {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Вы не авторизованы',
                    'show_alert' => false,
                ]);
                tg_show_main_menu($chatId, null, $msgId, null);
            } else {
                $kb = [
                    [
                        ['text' => '✅ Да, выйти', 'callback_data' => 'logout'],
                    ],
                    [
                        ['text' => '⬅️ Отмена', 'callback_data' => 'profile'],
                    ],
                ];
                $params = [
                    'chat_id' => $chatId,
                    'message_id' => $msgId,
                    'text' => "Вы точно хотите выйти из аккаунта?",
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => tg_kb($kb),
                ];
                tg_api('editMessageText', $params);
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            }
        } break;

        case ($data === 'logout'): {
            if ($user) {
                if (function_exists('tg_unbind_chat')) {
                    tg_unbind_chat($chatId);
                }
                if (function_exists('tg_set_session')) {
                    tg_set_session($chatId, null, []);
                }
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Вы вышли из аккаунта',
                    'show_alert' => false,
                ]);
                tg_show_main_menu($chatId, null, $msgId, null);
            } else {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Вы не авторизованы',
                    'show_alert' => false,
                ]);
                tg_show_main_menu($chatId, null, $msgId, null);
            }
        } break;

        case ($data === 'my_reminders'): {
            tg_show_my_reminders($chatId, $msgId, $cbId, 1);
        } break;

        case (strpos($data, 'my_reminders_page:') === 0): {
            $page = (int)substr($data, strlen('my_reminders_page:'));
            if ($page < 1) $page = 1;
            tg_show_my_reminders($chatId, $msgId, $cbId, $page);
        } break;

        case (strpos($data, 'remind_del:') === 0): {
            $id = (int)substr($data, strlen('remind_del:'));
            if ($id > 0 && function_exists('tg_reminder_cancel')) {
                tg_reminder_cancel($id, $chatId);
            }
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Напоминание отменено', 'show_alert' => false]);
            tg_show_my_reminders($chatId, $msgId, null, 1);
        } break;

        case (strpos($data, 'remind_shift:') === 0): {
            $payload = substr($data, strlen('remind_shift:'));
            $parts   = explode(':', $payload);
            $id      = (int)($parts[0] ?? 0);
            $days    = (int)($parts[1] ?? 0);
            if ($id > 0 && $days !== 0 && function_exists('tg_reminder_reschedule')) {
                $newRem = tg_reminder_reschedule($id, $chatId, $days);
                if ($newRem) {
                    try {
                        $dt = new DateTimeImmutable($newRem);
                        $human = $dt->format('d.m.Y H:i');
                    } catch (Exception $e) {
                        $human = $newRem;
                    }
                    tg_api('answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text' => "Новое время: {$human}",
                        'show_alert' => false,
                    ]);
                } else {
                    tg_api('answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text' => 'Не удалось изменить напоминание',
                        'show_alert' => true,
                    ]);
                }
            } else {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Некорректные параметры напоминания',
                    'show_alert' => true,
                ]);
            }
            tg_show_my_reminders($chatId, $msgId, null, 1);
        } break;



        case ($data === 'calendar'): {
            tg_show_calendar($chatId, $msgId, $cbId, null);
        } break;

        case (strpos($data, 'calendar_week:') === 0): {
            $weekStart = substr($data, strlen('calendar_week:'));
            $weekStart = preg_replace('~[^0-9\-]~', '', $weekStart);
            if ($weekStart === '') {
                $weekStart = null;
            }
            tg_show_calendar($chatId, $msgId, $cbId, $weekStart ?: null);
        } break;


        case ($data === 'my_tasks'): {
            tg_show_my_tasks($chatId, $msgId, $cbId, 1);
        } break;

        case (strpos($data, 'my_tasks_page:') === 0): {
            $page = (int)substr($data, strlen('my_tasks_page:'));
            if ($page < 1) $page = 1;
            tg_show_my_tasks($chatId, $msgId, $cbId, $page);
        } break;

        case (strpos($data, 'task_done:') === 0): {
            $id = (int)substr($data, strlen('task_done:'));
            if ($id > 0 && function_exists('tg_task_mark_done')) {
                tg_task_mark_done($id, $chatId);
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Задача отмечена выполненной',
                    'show_alert' => false,
                ]);
            } else {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Не удалось отметить задачу',
                    'show_alert' => true,
                ]);
            }
            tg_show_my_tasks($chatId, $msgId, null, 1);
        } break;

        case (strpos($data, 'task_del:') === 0): {
            $id = (int)substr($data, strlen('task_del:'));
            if ($id > 0 && function_exists('tg_task_delete')) {
                tg_task_delete($id, $chatId);
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Задача удалена',
                    'show_alert' => false,
                ]);
            } else {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Не удалось удалить задачу',
                    'show_alert' => true,
                ]);
            }
            tg_show_my_tasks($chatId, $msgId, null, 1);
        } break;

        case (strpos($data, 'task_shift:') === 0): {
            $payload = substr($data, strlen('task_shift:'));
            $parts   = explode(':', $payload);
            $id      = (int)($parts[0] ?? 0);
            $days    = (int)($parts[1] ?? 0);
            if ($id > 0 && $days !== 0 && function_exists('tg_task_reschedule')) {
                $newDue = tg_task_reschedule($id, $chatId, $days);
                if ($newDue) {
                    try {
                        $dt = new DateTimeImmutable($newDue);
                        $human = $dt->format('d.m.Y H:i');
                    } catch (Exception $e) {
                        $human = $newDue;
                    }
                    tg_api('answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text' => "Новый срок: {$human}",
                        'show_alert' => false,
                    ]);
                } else {
                    tg_api('answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text' => 'Не удалось изменить задачу',
                        'show_alert' => true,
                    ]);
                }
            } else {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Некорректные параметры задачи',
                    'show_alert' => true,
                ]);
            }
            tg_show_my_tasks($chatId, $msgId, null, 1);
        } break;

        case (strpos($data, 'field_task_add:') === 0): {
            $code = substr($data, strlen('field_task_add:'));
            $code = preg_replace('~\D~', '', $code);
            if (strlen($code) < 3 || strlen($code) > 4) {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Неверный код участка',
                    'show_alert' => true,
                ]);
            } else {
                tg_set_session($chatId, 'task_add_text', ['field_code' => $code]);
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Введите текст задачи одним сообщением',
                    'show_alert' => false,
                ]);
                tg_send_message(
                    "📝 <b>Новая задача по участку {$code}</b>\n\n"
                  . "Отправьте текст задачи одним сообщением.",
                    $chatId,
                    ['parse_mode' => 'HTML']
                );
            }
        } break;

        case ($data === 'admin_panel'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            $fullName = trim(($user['surname'] ?? '').' '.($user['name'] ?? '')) ?: ($user['username'] ?? '—');
            $fullName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = "🛡 <b>Админ‑панель</b>\n"
                  . "Вы вошли как: <b>{$fullName}</b>\n\n"
                  . "Выберите действие:";
            $kb = [
                [
                    ['text' => '📊 Статистика', 'callback_data' => 'admin_stats'],
                    ['text' => '📜 Журнал', 'callback_data' => 'admin_logs'],
                ],
                [
                    ['text' => '📢 Рассылка', 'callback_data' => 'admin_broadcast'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case ($data === 'admin_stats'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            $db = pdo();
            $u = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $c = (int)$db->query("SELECT COUNT(*) FROM cultures")->fetchColumn();
            $f = (int)$db->query("SELECT COUNT(*) FROM fields")->fetchColumn();
            $subs = 0;
            try {
                tg_ensure_subscribers();
                $subs = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers")->fetchColumn();
            } catch (Throwable $e) {}

            $text = "📊 <b>Статистика</b>\n"
                  . "👥 Пользователи: <b>{$u}</b>\n"
                  . "🌾 Культуры: <b>{$c}</b>\n"
                  . "📍 Участки: <b>{$f}</b>\n"
                  . "🤖 Подписчики бота: <b>{$subs}</b>";
            $kb = [
                [
                    ['text' => '🌾 По культурам', 'callback_data' => 'admin_stats_cultures'],
                    ['text' => '📍 Крупные поля', 'callback_data' => 'admin_stats_fields'],
                ],
                [
                    ['text' => '🤖 Активность бота', 'callback_data' => 'admin_stats_bot'],
                    ['text' => '📄 PDF по всем полям', 'callback_data' => 'admin_pdf_all_fields'],
                ],
                [
                    ['text' => '📜 Журнал', 'callback_data' => 'admin_logs'],
                    ['text' => '📢 Рассылка', 'callback_data' => 'admin_broadcast'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case ($data === 'admin_stats_cultures'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            $db = pdo();
            $sql = "SELECT c.title AS culture, COUNT(f.field_code) AS fields_cnt, COALESCE(SUM(f.area_ha),0) AS area_total
                    FROM cultures c
                    LEFT JOIN fields f ON f.culture_id = c.id
                    GROUP BY c.id
                    ORDER BY area_total DESC, fields_cnt DESC, culture ASC
                    LIMIT 10";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) {
                $text = "🌾 Данных по культурам пока нет.";
            } else {
                $lines = [];
                $lines[] = "🌾 <b>Культуры — топ по площади</b>";
                $lines[] = "ТОП-10 по суммарной площади, га.";
                $lines[] = "";
                foreach ($rows as $r) {
                    $culture = htmlspecialchars($r['culture'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $cnt     = (int)($r['fields_cnt'] ?? 0);
                    $areaHa  = (float)($r['area_total'] ?? 0);
                    $areaStr = number_format($areaHa, 1, ',', ' ');
                    $lines[] = "• <b>{$culture}</b> — {$areaStr} га, участков: <b>{$cnt}</b>";
                }
                $text = implode("\n", $lines);
            }
            $kb = [
                [
                    ['text' => '📍 Крупные поля', 'callback_data' => 'admin_stats_fields'],
                ],
                [
                    ['text' => '↩️ Общая статистика', 'callback_data' => 'admin_stats'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case ($data === 'admin_stats_fields'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            $db = pdo();
            $sql = "SELECT f.field_code, f.area_ha, c.title AS culture
                    FROM fields f
                    LEFT JOIN cultures c ON c.id = f.culture_id
                    ORDER BY f.area_ha DESC, f.field_code ASC
                    LIMIT 10";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) {
                $text = "📍 Данных по участкам пока нет.";
            } else {
                $lines = [];
                $lines[] = "📍 <b>Крупнейшие поля</b>";
                $lines[] = "ТОП-10 по площади, га.";
                $lines[] = "";
                foreach ($rows as $r) {
                    $code  = htmlspecialchars((string)($r['field_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $cult  = htmlspecialchars((string)($r['culture'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $area  = (float)($r['area_ha'] ?? 0);
                    $areaStr = number_format($area, 1, ',', ' ');
                    $lines[] = "• <b>{$code}</b> — {$areaStr} га — {$cult}";
                }
                $text = implode("\n", $lines);
            }
            $kb = [
                [
                    ['text' => '🌾 По культурам', 'callback_data' => 'admin_stats_cultures'],
                ],
                [
                    ['text' => '↩️ Общая статистика', 'callback_data' => 'admin_stats'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case ($data === 'admin_stats_bot'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            $db = pdo();
            $total = 0; $active7 = 0; $active30 = 0; $private = 0; $groups = 0;
            try {
                tg_ensure_subscribers();
                $total = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers")->fetchColumn();
                $active7 = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
                $active30 = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
                $private = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers WHERE type = 'private'")->fetchColumn();
                $groups  = (int)$db->query("SELECT COUNT(*) FROM tg_subscribers WHERE type IN ('group','supergroup')")->fetchColumn();
            } catch (Throwable $e) {}

            $lines = [];
            $lines[] = "🤖 <b>Активность бота</b>";
            $lines[] = "";
            $lines[] = "Всего чатов: <b>{$total}</b>";
            $lines[] = "— приватных: <b>{$private}</b>";
            $lines[] = "— групп/каналов: <b>{$groups}</b>";
            $lines[] = "";
            $lines[] = "Активны за 7 дней: <b>{$active7}</b>";
            $lines[] = "Активны за 30 дней: <b>{$active30}</b>";
            $text = implode("\n", $lines);

            $kb = [
                [
                    ['text' => '📊 Общая статистика', 'callback_data' => 'admin_stats'],
                    ['text' => '📜 Журнал', 'callback_data' => 'admin_logs'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;

        case ($data === 'admin_logs'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Только для администраторов',
                    'show_alert' => true,
                ]);
                return;
            }
            $db = pdo();
            $rows = [];
            try {
                if (function_exists('tg_ensure_audit_log')) {
                    tg_ensure_audit_log();
                }
                $q = $db->query("SELECT a.created_at, COALESCE(a.event,a.action) AS evt, a.details, u.username
                                 FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                                 ORDER BY a.id DESC LIMIT 10");
                if ($q) {
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e) {
                $rows = [];
            }
            if (!$rows) {
                $text = "📜 Журнал пока пуст или недоступен.";
            } else {
                $lines = ["📜 <b>Последние события</b>"];
                foreach ($rows as $r) {
                    $evt = htmlspecialchars($r['evt'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $details = trim((string)($r['details'] ?? ''));
                    if ($details !== '' && mb_strlen($details) > 90) {
                        $details = mb_substr($details, 0, 87) . '...';
                    }
                    $details = $details !== '' ? htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
                    $dtRaw = (string)($r['created_at'] ?? '');
                    try {
                        $dt = new DateTimeImmutable($dtRaw);
                        $dtStr = $dt->format('d.m.Y H:i');
                    } catch (Exception $e) {
                        $dtStr = $dtRaw;
                    }
                    $userName = trim((string)($r['username'] ?? ''));
                    if ($userName === '') {
                        $userName = 'система';
                    }
                    $userName = htmlspecialchars($userName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if ($details !== '') {
                        $lines[] = "• <b>{$dtStr}</b> — {$evt} ({$userName})\n  {$details}";
                    } else {
                        $lines[] = "• <b>{$dtStr}</b> — {$evt} ({$userName})";
                    }
                }
                $text = implode("\n", $lines);
            }
            $kb = [
                [
                    ['text' => '📊 Статистика', 'callback_data' => 'admin_stats'],
                    ['text' => '📢 Рассылка', 'callback_data' => 'admin_broadcast'],
                ],
                [
                    ['text' => '🏠 Меню', 'callback_data' => 'home'],
                ],
            ];
            $params = [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb($kb),
            ];
            tg_api('editMessageText', $params);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;


        case ($data === 'admin_pdf_all_fields'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Только для администраторов',
                    'show_alert' => true,
                ]);
                return;
            }

            try {
                $db = pdo();
            } catch (Throwable $e) {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Ошибка подключения к базе данных',
                    'show_alert' => true,
                ]);
                return;
            }

            $sql = "SELECT f.field_code, f.area_ha, f.sow_date, f.harvest_date, f.avg_yield, c.title AS culture
                    FROM fields f
                    LEFT JOIN cultures c ON c.id = f.culture_id
                    ORDER BY f.field_code ASC";
            $rows = [];
            try {
                $q = $db->query($sql);
                if ($q) {
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e) {
                $rows = [];
            }

            if (!$rows) {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Нет данных по участкам для отчёта',
                    'show_alert' => true,
                ]);
                return;
            }

            // Подключаем Dompdf (вариант без Composer)
            if (!class_exists('Dompdf\Dompdf')) {
                $dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
                if (file_exists($dompdfAutoload)) {
                    require_once $dompdfAutoload;
                }
            }

            if (!class_exists('Dompdf\Dompdf')) {
                tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => 'Dompdf недоступен на сервере',
                    'show_alert' => true,
                ]);
                return;
            }

            $html = tg_build_all_fields_pdf_html($rows);

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfData = $dompdf->output();

            $tmpFile = tempnam(sys_get_temp_dir(), 'tgpdf_all_');
            if ($tmpFile !== false && $pdfData !== false) {
                file_put_contents($tmpFile, $pdfData);
                $todayShort = (new DateTimeImmutable('now'))->format('Ymd_His');
                $filename = 'fields_report_' . $todayShort . '.pdf';
                $res = tg_send_document_file($chatId, $tmpFile, $filename, "📄 Сводный PDF-отчёт по всем полям");
                @unlink($tmpFile);
                if (!($res['ok'] ?? false)) {
                    $err = $res['error'] ?? 'ошибка отправки';
                    tg_send_message("❌ Не удалось отправить PDF: {$err}", $chatId);
                }
            } else {
                tg_send_message("❌ Не удалось сохранить временный файл для PDF.", $chatId);
            }

            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;


case ($data === 'admin_broadcast'): {
            if (!$user || ($user['role'] ?? '') !== 'admin') {
                tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Только для администраторов', 'show_alert' => true]);
                return;
            }
            tg_set_session($chatId, 'admin_broadcast', []);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
            tg_answer("Отправьте текст рассылки для всех подписчиков бота. Для отмены используйте /cancel.", $chatId);
        } break;

        default: {
            tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
        } break;
    }
}

/* ----------------- Обработка входящих апдейтов ----------------- */

if (isset($update['callback_query'])) {
    tg_handle_callback($update['callback_query']);
} elseif (isset($update['message'])) {
    $msg = $update['message'];
    tg_subscriber_upsert($msg);

    $chatId = (int)($msg['chat']['id'] ?? 0);
    if (!$chatId) { http_response_code(200); exit('ok'); }

    $text = trim((string)($msg['text'] ?? ''));
    $text = preg_replace('~\s+~u',' ', $text);
    $user = tg_user_by_chat($chatId);
    $session = tg_get_session($chatId);

    // Отдельно обрабатываем команду /bind
    if (stripos($text, '/bind ') === 0) {
        $secret = trim(substr($text, 6));
        global $s;
        if ($secret !== '' && $secret === $s['secret']) {
            setting_set('tg_admin_chat', (string)$chatId);
            tg_answer("✅ Этот чат привязан как <b>админ‑чат</b>.\nChat ID: <code>{$chatId}</code>", $chatId);
        } else {
            tg_answer("⛔ Неверный секрет. Скопируйте секрет из админ‑панели Telegram‑настроек.", $chatId);
        }
        http_response_code(200);
        exit('ok');
    }

    // Глобальные команды
    if ($text === '/start' || stripos($text, '/start ') === 0) {
        tg_set_session($chatId, null, []);
        tg_show_main_menu($chatId, $user, null, null);
        http_response_code(200);
        exit('ok');
    }

    if ($text === '/help') {
        tg_answer(tg_help_text(), $chatId);
        http_response_code(200);
        exit('ok');
    }

    if ($text === '/id') {
        tg_answer("Ваш chat id: <code>{$chatId}</code>", $chatId);
        http_response_code(200);
        exit('ok');
    }

    if (stripos($text, '/field') === 0) {
        // Поддерживаем /field 1234 и /field1234
        $code = trim(substr($text, strlen('/field')));
        if ($code === '') {
            tg_answer("Укажите код участка: <code>/field 1253</code>", $chatId);
        } else {
            tg_show_field($chatId, $code, null, null);
        }
        http_response_code(200);
        exit('ok');
    }

    if ($text === '/stats') {
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            tg_answer("⛔ Команда /stats доступна только администраторам.", $chatId);
        } else {
            $db = pdo();
            $u = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $c = (int)$db->query("SELECT COUNT(*) FROM cultures")->fetchColumn();
            $f = (int)$db->query("SELECT COUNT(*) FROM fields")->fetchColumn();
            $textStats = "Сводка:\n👥 Пользователи: <b>{$u}</b>\n🌾 Культуры: <b>{$c}</b>\n📍 Участки: <b>{$f}</b>";
            tg_answer($textStats, $chatId);
        }
        http_response_code(200);
        exit('ok');
    }

    if ($text === '/last') {
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            tg_answer("⛔ Команда /last доступна только администраторам.", $chatId);
        } else {
            $db = pdo();
            $q = $db->query("SELECT a.created_at, COALESCE(a.event,a.action) AS evt, a.details, u.username
                             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                             ORDER BY a.id DESC LIMIT 5");
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                tg_answer("Журнал пока пуст.", $chatId);
            } else {
                $lines = [];
                foreach ($rows as $r) {
                    $evt = htmlspecialchars($r['evt'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $det = htmlspecialchars($r['details'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $usr = htmlspecialchars($r['username'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $tm  = htmlspecialchars($r['created_at'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $lines[] = "• <b>{$evt}</b> — {$usr}\n  {$det}\n  <i>{$tm}</i>";
                }
                tg_answer("Последние события:\n\n".implode("\n\n", $lines), $chatId);
            }
        }
        http_response_code(200);
        exit('ok');
    }

    if (stripos($text, '/broadcast ') === 0) {
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            tg_answer("⛔ Команда /broadcast доступна только администраторам.", $chatId);
        } else {
            $msg = trim(substr($text, 11));
            if ($msg !== '') {
                $res = tg_broadcast($msg);
                $sent   = (int)($res['sent'] ?? 0);
                $failed = (int)($res['failed'] ?? 0);
                tg_answer("Рассылка завершена: отправлено {$sent}, ошибок {$failed}.", $chatId);
            }
        }
        http_response_code(200);
        exit('ok');
    }

    // Если есть активное состояние — отдаём в state‑машину
    if (!empty($session['state'])) {
        tg_handle_stateful_message($chatId, $text, $session, $user);
        http_response_code(200);
        exit('ok');
    }

    // По умолчанию — показываем меню
    tg_show_main_menu($chatId, $user, null, null);
}

http_response_code(200);
echo 'ok';