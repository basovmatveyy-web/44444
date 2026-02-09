<?php
require_once __DIR__ . '/guard.php';
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
  header('Location: dashboard.php'); exit;
}
$user = $_SESSION['user'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>СХПК «Береговой» — Админ‑панель</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="logo.png">
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="select_patch.css">
<link rel="stylesheet" href="admin.css">
</head>
<body class="bg" data-role="<?=htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8')?>">
<div id="bg-layer"></div><div id="bg-overlay"></div><div class="overlay"></div>

<header class="topbar topbar-admin">
  <div class="brand">
    <img src="logo.png" class="logo" alt="">
    <div>
      <small class="muted">Панель управления</small>
      <div><strong>СХПК «Береговой»</strong></div>
    </div>
  </div>
  <nav class="topnav">
    <button class="burger" id="navToggle" aria-label="Меню">☰</button>
    <a class="link" href="modules.php">Разделы</a>
    <a class="link" href="dashboard.php">К карте</a>
    <a class="link" href="logout.php">Выход</a>
  </nav>
</header>

<main class="admin-layout">
  <!-- Левое меню (всегда слева) -->
  <aside class="aside" id="aside">
    <div class="user-card">
      <img src="logo.png" alt="">
      <div>
        <div class="user-name"><?=htmlspecialchars(($user['surname'] ?? '').' '.($user['name'] ?? ''))?></div>
        <div class="role-badge">Администратор</div>
      </div>
    </div>

    <nav class="menu">
      <button class="menu-item active" data-tab="overview"><span class="ico">📊</span><span>Обзор</span></button>
      <button class="menu-item" data-tab="users"><span class="ico">👥</span><span>Пользователи</span></button>
      <button class="menu-item" data-tab="cultures"><span class="ico">🌾</span><span>Культуры</span></button>
      <button class="menu-item" data-tab="logs"><span class="ico">🗂️</span><span>Журнал</span></button>
      <button class="menu-item" data-tab="history"><span class="ico">🕘</span><span>История</span></button>
      <div class="sep"></div>

<button class="menu-item" data-tab="telegram">
  <span class="ico">✈️</span>
  <span>Telegram</span>
</button>

<button class="menu-item" data-tab="axenta">
  <span class="ico">🚚</span>
  <span>Транспорт (Axenta)</span>
</button>
</nav>

    <div class="foot muted sm">© <?=date('Y')?> СХПК «Береговой»</div>
  </aside>

  <!-- Контент справа -->
  <section class="content">
    <!-- KPI -->
    <div class="kpi-grid">
      <div class="kpi pane">
        <div class="label">Пользователи</div>
        <div class="value" id="kpiUsers">—</div>
      </div>
      <div class="kpi pane">
        <div class="label">Культуры</div>
        <div class="value" id="kpiCultures">—</div>
      </div>
      <div class="kpi pane">
        <div class="label">События</div>
        <div class="value" id="kpiLogs">—</div>
      </div>
    </div>

    <!-- Вкладки -->
    <div class="panes">
      <!-- Обзор -->
      <div class="pane show" id="pane-overview">
        <h2>Обзор системы</h2>
        <div class="sub">Свежая статистика и лента событий.</div>

        <div class="overview-grid">
          <div class="pane">
            <h3>Последние события</h3>
            <div class="log-list" id="logStream">
              <div class="log-item"><div class="dot"></div><div>Загрузка…</div><div class="time"></div></div>
            </div>
          </div>

          <div class="pane">
            <h3>Недавние пользователи</h3>
            <table class="table" id="tblLastUsers">
              <thead><tr><th>ID</th><th>Логин</th><th>Роль</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Пользователи -->
      <div class="pane" id="pane-users">
        <h2>Пользователи</h2>
        <div class="sub">Управление ролями: «Админ» и «Бухгалтер».</div>
        <table class="table" id="tblUsers">
          <thead><tr><th>ID</th><th>Логин</th><th>Фамилия</th><th>Имя</th><th>Роль</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Культуры -->
      <div class="pane" id="pane-cultures">
        <h2>Культуры</h2>
        <div class="sub">Справочник для карточек участков, отчётов и экспорта.</div>

        <div class="row cultures-tools">
          <input id="cultureSearch" type="search" placeholder="Поиск по названию (можно по номеру)" autocomplete="off">
          <button class="btn" type="button" id="btnCulturesClear">Сброс</button>
          <button class="btn" type="button" id="btnCulturesRefresh">Обновить</button>
        </div>

        <form id="formAddCulture" class="row cultures-tools" autocomplete="off">
          <input name="title" id="cultureAddTitle" placeholder="Новая культура" required>
          <button class="btn primary" type="submit">Добавить</button>
        </form>

        <div class="sub">Культур: <strong id="culturesCount">—</strong></div>

        <table class="table" id="tblCultures">
          <thead><tr><th>Название</th><th style="width:240px"></th></tr></thead>
          <tbody></tbody>
        </table>

        <div class="sub" id="culturesEmpty" hidden>Ничего не найдено.</div>
      </div>

<!-- Журнал -->
      <div class="pane" id="pane-logs">
        <h2>Журнал действий</h2>
        <table class="table logs" id="tblLogs">
          <thead><tr><th>Время</th><th>Событие</th><th>Пользователь</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- История изменений полей -->
      <div class="pane" id="pane-history">
        <h2>История изменений полей</h2>
        <div class="sub">Показывает, кто, когда и что поменял в карточках участков.</div>

        <div class="history-toolbar">
          <div class="h-group">
            <label class="h-label">Участок</label>
            <input id="histFieldCode" class="h-input" placeholder="Например: 125 или 1253" maxlength="4" inputmode="numeric">
          </div>

          <div class="h-group">
            <label class="h-label">Пользователь</label>
            <select id="histUser" class="h-input">
              <option value="">Все</option>
            </select>
          </div>

          <div class="h-group">
            <label class="h-label">Период</label>
            <div class="h-dates">
              <input id="histFrom" class="h-input" type="date">
              <span class="h-sep">—</span>
              <input id="histTo" class="h-input" type="date">
            </div>
          </div>

          <div class="h-actions">
            <button class="btn primary" id="histApply">Показать</button>
            <button class="btn" id="histReset">Сброс</button>
          </div>
        </div>

        <div class="history-meta">
          <div class="pill" id="histCount">Загрузка…</div>
          <div class="pill" id="histHint">Подсказка: кликните по записи, чтобы раскрыть «Было → Стало».</div>
        </div>

        <!-- Десктоп: таблица -->
        <div class="history-table-wrap">
          <table class="table" id="tblHistory">
            <thead>
              <tr>
                <th>Время</th>
                <th>Участок</th>
                <th>Пользователь</th>
                <th>Изменения</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <!-- Мобайл: таймлайн -->
        <div class="history-timeline" id="histTimeline"></div>

        <div class="history-more">
          <button class="btn" id="histMore" style="display:none">Показать ещё</button>
        </div>

        <!-- Модалка деталей (свои классы, чтобы не конфликтовать с общим .modal из styles.css) -->
        <div class="hist-modal" id="histModal" aria-hidden="true" hidden>
          <div class="hist-modal-card" role="dialog" aria-modal="true">
            <div class="hist-modal-head">
              <div>
                <div class="hist-modal-title" id="histModalTitle">Изменения</div>
                <div class="hist-modal-sub" id="histModalSub"></div>
              </div>
              <button class="hist-modal-x" id="histModalClose" aria-label="Закрыть">✕</button>
            </div>
            <div class="hist-modal-body" id="histModalBody"></div>
          </div>
        </div>
      </div>

      <!-- Транспорт / Axenta -->
      <div class="pane" id="pane-axenta" style="display:none">
        <div class="axenta-mobilebar" id="axMobileBar" aria-hidden="true">
          <button class="axenta-exit" id="axExit" type="button">Выход</button>
          <div class="axenta-mid" id="axMid">
            <button class="axenta-data" id="axDataBtn" type="button">Данные</button>
            <button class="axenta-copy" id="axCopyLogin" type="button" hidden>Логин</button>
            <button class="axenta-copy" id="axCopyPass" type="button" hidden>Пароль</button>
          </div>
          <div class="axenta-mobiletitle">Axenta</div>
        </div>
        <h2>Транспорт — Axenta</h2>
        <div class="sub">Встроенный кабинет мониторинга транспорта (w.avtoscan.com).</div>

        <div class="axenta-toolbar">
          <button class="btn" id="axReload" type="button">Обновить</button>
          <span class="axenta-note">Подсказка: для входа используйте свои учётные данные Axenta.</span>
        </div>

        <div class="axenta-frame-wrap">
          <iframe id="axFrame" title="Axenta" src="about:blank" data-src="https://w.avtoscan.com/" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          <div class="axenta-fallback" id="axFallback" hidden>
            <div class="axenta-fb-card">
              <button class="axenta-fb-close" id="axHide" type="button" aria-label="Скрыть подсказку">✕</button>
              <div class="axenta-fb-title">Axenta загружается…</div>
              <div class="axenta-fb-sub">Пожалуйста, подождите немного!</div>
              <div class="axenta-loader" role="progressbar" aria-label="Загрузка Axenta"></div>
              <div class="axenta-fb-actions">
                <button class="btn" id="axHide2" type="button">Продолжить внутри</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Секретный код / копирование данных (только UI, без API) -->
        <div class="axenta-secret" id="axSecret" hidden>
          <div class="axenta-secret-card" role="dialog" aria-modal="true" aria-labelledby="axSecretTitle">
            <button class="axenta-secret-close" id="axSecretClose" type="button" aria-label="Закрыть">✕</button>
            <div class="axenta-secret-title" id="axSecretTitle">Доступ к данным</div>
            <div class="axenta-secret-sub">Введите секретный код</div>
            <input class="axenta-secret-input" id="axSecretInput" type="password" inputmode="text" autocomplete="off" placeholder="Кодовое слово" />
            <div class="axenta-secret-msg" id="axSecretMsg" aria-live="polite"></div>
            <div class="axenta-secret-actions">
              <button class="btn secondary" id="axSecretCancel" type="button">Отмена</button>
              <button class="btn" id="axSecretOk" type="button">Подтвердить</button>
            </div>
          </div>
        </div>

        <!-- Блокировка вкладки на 2 минуты после 3 ошибок -->
        <div class="axenta-lock" id="axLock" hidden>
          <div class="axenta-lock-card" role="status" aria-live="polite">
            <div class="axenta-lock-title">Доступ временно заблокирован</div>
            <div class="axenta-lock-sub">Попробуйте снова через <span id="axLockTimer">2:00</span></div>
          </div>
        </div>
      </div>

    </div>
  
<section class="pane" id="pane-telegram" style="display:none">
  <div class="tg-head">
    <div>
      <h2>Telegram</h2>
      <div class="sub">Настройки бота, уведомления, подписчики и рассылки — в едином стиле админ‑панели.</div>
    </div>
    <div class="tg-head-actions">
      <button class="btn" id="tgRefreshBtn" type="button">Обновить данные</button>
    </div>
  </div>

  <div class="tg-status" aria-live="polite">
    <div class="pill" id="tgBotBadge">Бот: —</div>
    <div class="pill" id="tgHookBadge">Webhook: —</div>
    <div class="pill" id="tgSubsBadge">Подписчики: —</div>
    <div class="tg-token-tail" id="tgTokenTail">Токен: —</div>
  </div>

  <div class="tg-grid">
    <div class="tg-card">
      <div class="tg-card-head">
        <h3>Подключение</h3>
        <div class="tg-card-sub">Укажите токен бота и Chat ID администратора. Секрет используется для webhook/привязки.</div>
      </div>

      <div class="tg-form">
        <div class="tg-field">
          <label class="tg-label" for="tgToken">Токен бота</label>
          <input id="tgToken" type="text" placeholder="1234567890:ABCDE..." autocomplete="off">
          <div class="tg-hint">В поле токен не подставляется автоматически. При сохранении пустого значения токен будет очищен.</div>
        </div>

        <div class="tg-field">
          <label class="tg-label" for="tgAdminChat">Chat ID администратора</label>
          <input id="tgAdminChat" type="text" placeholder="Например: 123456789" autocomplete="off" inputmode="numeric">
          <div class="tg-hint">Используется как основной получатель тестов и уведомлений.</div>
        </div>

        <div class="tg-split">
          <div class="tg-field">
            <label class="tg-label" for="tgSecret">Секрет webhook/привязки</label>
            <input id="tgSecret" type="text" readonly>
          </div>
          <div class="tg-field tg-field-actions">
            <button id="tgRegenSecretBtn" class="btn" type="button">Сгенерировать заново</button>
          </div>
        </div>

        <div class="tg-actions">
          <button id="tgSaveBtn" class="btn primary" type="button">Сохранить</button>
          <button id="tgSaveAndHookBtn" class="btn" type="button">Сохранить + webhook</button>
        </div>

        <div class="tg-test">
          <label class="tg-label" for="tgTestText">Тестовое сообщение</label>
          <div class="tg-test-row">
            <input id="tgTestText" type="text" placeholder="Проверка связи с ботом…" autocomplete="off">
            <button id="tgTestBtn" class="btn" type="button">Отправить</button>
          </div>
          <div class="tg-hint">Отправляет сообщение в Telegram администратору (Chat ID выше).</div>
        </div>
      </div>
    </div>

    <div class="tg-stack">
      <div class="tg-card">
        <div class="tg-card-head">
          <h3>Уведомления</h3>
          <div class="tg-card-sub">Включайте только то, что действительно нужно получать в Telegram.</div>
        </div>

        <div class="tg-switches">
          <label class="tg-switch">
            <input id="tgNotifySave" type="checkbox">
            <span class="tg-switch-ui" aria-hidden="true"></span>
            <span class="tg-switch-text">
              <span class="tg-switch-title">Изменения участков</span>
              <span class="tg-switch-sub">Сохранение карточек/изменения данных и обработок.</span>
            </span>
          </label>

          <label class="tg-switch">
            <input id="tgNotifyLogins" type="checkbox">
            <span class="tg-switch-ui" aria-hidden="true"></span>
            <span class="tg-switch-text">
              <span class="tg-switch-title">Входы пользователей</span>
              <span class="tg-switch-sub">Успешные/неуспешные авторизации в систему.</span>
            </span>
          </label>

          <label class="tg-switch">
            <input id="tgNotifyUsers" type="checkbox">
            <span class="tg-switch-ui" aria-hidden="true"></span>
            <span class="tg-switch-text">
              <span class="tg-switch-title">Управление пользователями</span>
              <span class="tg-switch-sub">Создание, удаление, смена ролей.</span>
            </span>
          </label>

          <label class="tg-switch">
            <input id="tgNotifyErrors" type="checkbox">
            <span class="tg-switch-ui" aria-hidden="true"></span>
            <span class="tg-switch-text">
              <span class="tg-switch-title">Ошибки и сбои</span>
              <span class="tg-switch-sub">Критичные ошибки, чтобы реагировать быстрее.</span>
            </span>
          </label>
        </div>
      </div>

      <div class="tg-card">
        <div class="tg-card-head">
          <h3>Webhook</h3>
          <div class="tg-card-sub">Доставка событий в Telegram через webhook (рекомендуется).</div>
        </div>

        <div class="tg-webhook">
          <div class="tg-webhook-info">
            <div class="tg-meta-label">Состояние</div>
            <div class="tg-webhook-meta" id="tgHookInfo">—</div>
          </div>

          <div class="tg-webhook-actions">
            <button id="tgSetHookBtn" class="btn" type="button">Поставить webhook</button>
            <button id="tgInfoHookBtn" class="btn" type="button">Проверить</button>
            <button id="tgDelHookBtn" class="btn danger" type="button">Снять webhook</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="tg-bottom">
    <div class="tg-card">
      <div class="tg-card-top">
        <div>
          <h3 style="margin:0">Подписчики</h3>
          <div class="tg-card-sub">Кто получает рассылки и системные уведомления.</div>
        </div>
        <div class="tg-card-actions">
          <input id="tgSubsSearch" class="tg-mini" type="text" placeholder="Поиск по chat/имени…" autocomplete="off">
          <button class="btn" id="tgLoadSubsBtn" type="button">Обновить</button>
        </div>
      </div>

      <div class="tg-subs-scroll" role="region" aria-label="Список подписчиков">
        <div class="tg-empty" id="tgSubsEmpty">Загрузка…</div>
        <div id="tgSubsList"></div>
      </div>
    </div>

    <div class="tg-card">
      <div class="tg-card-head">
        <h3>Широковещательная рассылка</h3>
        <div class="tg-card-sub">Сообщение будет отправлено всем подписчикам. Используйте для объявлений и срочных уведомлений.</div>
      </div>

      <textarea id="tgBroadcastText" placeholder="Текст сообщения…" style="min-height:140px;resize:vertical"></textarea>

      <div class="row" style="margin-top:10px">
        <button class="btn primary" id="tgBroadcastBtn" type="button">Отправить всем</button>
        <button class="btn" id="tgBroadcastClearBtn" type="button">Очистить</button>
      </div>

      <div class="tg-broadcast-meta" id="tgBroadcastMeta"></div>
    </div>
  </div>

  <div class="tg-footnote" id="tgBotMeta"></div>

  <!-- === TG legacy aliases for admin.js (to avoid "Cannot set properties of null") === -->
  <form id="formTg" style="display:none">
    <input type="text" id="tg_token" />
    <input type="text" id="tg_chat" />
    <input type="checkbox" id="tg_on_save" />
    <input type="checkbox" id="tg_on_login" />
    <button id="tgTest" type="button" style="display:none">Test</button>
  </form>
  <!-- === /TG legacy aliases === -->
</section>
</section>
</main>

<div id="scrim" class="scrim"></div>
<div id="toasts"></div>
<script src="admin.js"></script>
<script src="admin_telegram_v5.js"></script>
</body>
</html>
