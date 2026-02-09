TG Upgrade — краткие шаги внедрения (без правки дизайна):

1) В phpMyAdmin выполните файл sql_telegram_upgrade.sql в базе bereg_db.
2) Залейте в корень сайта файлы:
   - tg_lib.php
   - admin_api_telegram.php
   - tg_webhook.php
   - tg_set_webhook.php
   - telegram_panel_pro.html
   - admin_telegram_v6.js
3) В admin.php в уже существующей вкладке Telegram вставьте:
   <?php readfile(__DIR__.'/telegram_panel_pro.html'); ?>
   И ниже вашего admin.js подключите:
   <script src="admin_telegram_v6.js"></script>
4) В админке: введите токен → «Сохранить и поставить webhook» → /start → /bind <секрет>.