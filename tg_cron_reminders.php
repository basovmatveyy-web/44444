<?php
/**
 * tg_cron_reminders.php — отправка напоминаний по участкам.
 *
 * Скрипт рассчитан на запуск по cron, например (каждые 5 минут):
 *   /usr/bin/php /path/to/tg_cron_reminders.php >/dev/null 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/tg_lib.php';

$s = tg_settings();
if (!($s['token'] ?? '')) {
    echo "no-token\n";
    exit;
}

if (!function_exists('tg_reminders_due')) {
    echo "no-reminders-support\n";
    exit;
}

$due = tg_reminders_due(50);
if (!$due) {
    echo "no-due\n";
    exit;
}

foreach ($due as $row) {
    $id        = (int)($row['id'] ?? 0);
    $chatId    = (int)($row['chat_id'] ?? 0);
    $code      = preg_replace('~\D~', '', (string)($row['field_code'] ?? ''));
    $text      = trim((string)($row['text'] ?? ''));
    $remindAt  = (string)($row['remind_at'] ?? '');
    if (!$id || !$chatId || strlen($code) < 3 || strlen($code) > 4) {
        continue;
    }

    try {
        $dt = new DateTimeImmutable($remindAt);
        $human = $dt->format('d.m.Y H:i');
    } catch (Exception $e) {
        $human = $remindAt;
    }

    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $msg = "⏰ <b>Напоминание по участку {$safeCode}</b>\n\n";
    if ($safeText !== '') {
        $msg .= $safeText . "\n\n";
    }
    $msg .= "<i>Запланировано на {$human}</i>";

    $kb = [
        [
            ['text' => "📄 Карточка {$code}", 'callback_data' => 'field_view:'.$code],
            ['text' => '📄 PDF-отчёт', 'callback_data' => 'field_pdf:'.$code],
        ],
    ];

    tg_send_message($msg, $chatId, [
        'parse_mode' => 'HTML',
        'reply_markup' => tg_kb($kb),
    ]);

    tg_reminder_mark_done($id);
}

echo "ok\n";
