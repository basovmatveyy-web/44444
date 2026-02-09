Telegram Admin Module
=====================

Files included:
- admin_telegram_v7.js      — shim/utlities (TG.*)
- admin_tg.js               — main UI logic (safe DOM; fixes null .value errors)
- admin_api_telegram.php    — backend API with schema auto-create
- tg_webhook.php            — minimal webhook endpoint
- telegram_schema.sql       — optional SQL (API creates schema automatically)

Include scripts (order matters):
<script src="admin.js"></script>
<script src="admin_telegram_v7.js?v=shim"></script>
<script src="admin_tg.js?v=2.4"></script>

Add the HTML panel block from the chat message to your Telegram tab.
