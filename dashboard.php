<?php
require_once __DIR__ . '/guard.php';
$user = $_SESSION['user'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>СХПК «Береговой» — Карта и просмотр</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=$_SESSION['csrf']?>">
<link rel="icon" href="logo.png">
<link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="select_patch.css">
  <link rel="stylesheet" href="field_editor_pro.css">
</head>
<body class="bg" data-role="<?=htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8')?>">
<div id="bg-layer"></div><div id="bg-overlay"></div><div class="overlay"></div>

<header class="topbar">
  <div class="brand">
    <img src="logo.png" class="logo" alt="">
    <span>СХПК «Береговой»</span>
  </div>
  <nav class="topnav">
    <a class="link" href="modules.php">Разделы</a>
   <?php if ($user['role']==='admin'): ?>
  <a class="link" href="admin.php">Админ-панель</a>
<?php endif; ?>

    <a class="link" href="logout.php">Выход</a>
  </nav>
</header>

<main class="map-layout">
  <section class="map-window glass">
    <div class="map-toolbar">
      <span class="muted sm">Карта хозяйства</span>

      <?php if ($user['role']==='admin'): ?>
        <button class="btn ghost sm" id="btnMapEdit" type="button" title="Редактор: разметка полей поверх карты">Разметка</button>
      <?php endif; ?>

      <label class="gesture-toggle switch" title="Жесты карты (перетаскивание и пинч) — только на телефонах">
        <input type="checkbox" id="gestureInput" checked>
        <span class="slider"></span>
      </label>

      <div>
        <button class="icon-btn" id="zoomIn">+</button>
        <button class="icon-btn" id="zoomOut">−</button>
        <button class="icon-btn" id="reset">⤾</button>
      </div>
    </div>
    <div class="map-viewport" id="viewport">
      <img id="mapImg" src="map.png" alt="Карта" draggable="false" width="3509" height="2481">
      <svg id="mapSvg" class="map-svg" width="3509" height="2481" viewBox="0 0 3509 2481" aria-hidden="true"></svg>
      <div id="pulse" style="position:absolute;width:18px;height:18px;border-radius:50%;background:rgba(78,168,255,.9);box-shadow:0 0 0 8px rgba(78,168,255,.18);pointer-events:none;transform:translate(-50%,-50%);display:none"></div>

      <?php if ($user['role']==='admin'): ?>
      <div id="mapEditor" class="map-editor glass" hidden>
        <div class="map-editor-head">
          <div>
            <div class="muted sm">Редактор карты</div>
            <div class="map-editor-title">Разметка полей</div>
          </div>
          <button class="icon-btn" id="btnMapEditClose" type="button" title="Закрыть">✕</button>
        </div>

        <div class="map-editor-row">
          <label class="muted sm" for="mapCode">Код поля</label>
          <select id="mapCode" class="map-editor-select" data-ui="custom-select"></select>
        </div>

        <div class="map-editor-row">
          <label class="muted sm">Цвет заливки</label>
          <div class="map-color-grid" id="mapColor">
            <button type="button" class="map-color-btn c-blue active" data-map-color="blue">
              <span class="swatch"></span>
              <span class="label">Голубой<small>как сейчас</small></span>
            </button>
            <button type="button" class="map-color-btn c-green" data-map-color="green">
              <span class="swatch"></span>
              <span class="label">Зелёный<small>свежий</small></span>
            </button>
            <button type="button" class="map-color-btn c-pink" data-map-color="pink">
              <span class="swatch"></span>
              <span class="label">Розовый<small>яркий</small></span>
            </button>
            <button type="button" class="map-color-btn c-yellow" data-map-color="yellow">
              <span class="swatch"></span>
              <span class="label">Жёлтый<small>акцент</small></span>
            </button>
            <button type="button" class="map-color-btn c-orange" data-map-color="orange">
              <span class="swatch"></span>
              <span class="label">Оранжевый<small>тёплый</small></span>
            </button>
            <button type="button" class="map-color-btn c-pale" data-map-color="pale">
              <span class="swatch"></span>
              <span class="label">Бело‑розовый<small>очень нежный</small></span>
            </button>
          </div>
        </div>

        <div class="map-editor-hint muted sm">
          Кликни по карте, чтобы поставить точки контура. Двойной клик — замкнуть контур.
        </div>

        <div class="map-editor-actions">
          <button class="btn" id="btnPolyUndo" type="button">Назад</button>
          <button class="btn" id="btnPolyClear" type="button">Очистить</button>
          <button class="btn danger" id="btnPolyDelete" type="button" disabled>Удалить</button>
          <button class="btn primary" id="btnPolySave" type="button" disabled>Сохранить</button>
        </div>

        <div class="map-editor-footer muted sm" id="mapEditorStatus">—</div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <aside class="glass" style="padding:14px">
    <h3>Просмотр участка</h3>
    <form id="formCode" class="row gap" autocomplete="off" novalidate>
      <input name="code" id="code" inputmode="numeric" maxlength="4" placeholder="Кадастровый № (3–4 цифры)">
      <button class="btn primary" type="submit">Показать</button>
    </form>

    <div class="space dash-actions">
      <button class="btn dash-btn" id="btnList" type="button">Список всех</button>
      <button class="btn dash-btn" id="btnOverview" type="button">Сводка хозяйства</button>
      <a class="btn dash-btn" href="export_fields_csv.php" target="_blank">Экспорт CSV</a>
      <a class="btn dash-btn" href="export_overview_pdf.php" target="_blank">Отчёт PDF</a>
      <button class="btn dash-btn" id="btnPrintCard" type="button" disabled>Печать карточки</button>
    </div>

    <div id="fieldView" class="space"></div>
    <div id="overviewWrap" class="space"></div>
    <div id="listWrap" class="space"></div>
  </aside>
</main>

<!-- Модалка редактирования (админ) -->
<div class="modal" id="modalEdit" hidden>
  <div class="modal-body glass fe-edit" data-fe-edit>
    <div class="fe-edit__layout">
      <aside class="fe-edit__nav" aria-label="Разделы карточки">
        <div class="fe-edit__brand">
          <span class="fe-edit__brand-dot" aria-hidden="true"></span>
          <div class="fe-edit__brand-txt">
            <b>Редактор участка</b>
            <span>структура и данные</span>
          </div>
        </div>

        <div class="fe-edit__navlist" role="tablist">
          <button type="button" class="fe-edit__tab is-active" role="tab" aria-selected="true" data-fe-tab="main">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>Основное</b><small>код, площадь, культура</small></span>
          </button>
          <button type="button" class="fe-edit__tab" role="tab" aria-selected="false" data-fe-tab="calendar">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>Календарь</b><small>вспашка, сев, полив</small></span>
          </button>
          <button type="button" class="fe-edit__tab" role="tab" aria-selected="false" data-fe-tab="treat">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>Обработки</b><small>дата и описание</small></span>
          </button>
          <button type="button" class="fe-edit__tab" role="tab" aria-selected="false" data-fe-tab="harvest">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>Урожай</b><small>уборка и показатели</small></span>
          </button>
          <button type="button" class="fe-edit__tab" role="tab" aria-selected="false" data-fe-tab="notes">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>Заметки</b><small>комментарии и детали</small></span>
          </button>
          <button type="button" class="fe-edit__tab" role="tab" aria-selected="false" data-fe-tab="history">
            <span class="fe-edit__tab-ico" aria-hidden="true"></span>
            <span class="fe-edit__tab-txt"><b>История</b><small>последние операции</small></span>
          </button>
        </div>

        <div class="fe-edit__navmeta">
          <div class="fe-edit__navmeta-row"><b>Подсказки</b></div>
          <div class="fe-edit__navmeta-row">Ctrl+S — сохранить • Esc — закрыть</div>
          <div class="fe-edit__navmeta-row">Разделы слева переключаются без перезагрузки.</div>
        </div>
      </aside>

      <section class="fe-edit__main">
        <header class="fe-edit__topbar" id="feEditTopbar">
          <div class="fe-edit__title">
            <div class="fe-edit__kicker">Карточка участка</div>
            <h2 id="editTitle">Редактирование участка</h2>
            <div class="fe-edit__chips" aria-live="polite">
              <span class="fe-badge" id="feBadgeState" data-state="clean"><i></i><span>Готово</span></span>
              <span class="fe-badge"><i></i><span id="feBadgeCode">Код: —</span></span>
              <span class="fe-badge"><i></i><span id="feBadgeArea">Площадь: —</span></span>
            </div>
          </div>

          <div class="fe-edit__actions">
            <button class="fe-btn ghost" type="button" data-close="#modalEdit">Закрыть</button>
            <button class="fe-btn primary" type="submit" form="formEdit">Сохранить</button>
          </div>
        </header>

        <div class="fe-edit__content" id="feEditScroll">
          <form id="formEdit" class="fe-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?=$_SESSION['csrf']?>">

            <div class="fe-panel is-active" role="tabpanel" data-fe-panel="main">
              <div class="fe-sheet">
                <div class="fe-sheet__head">
                  <h3>Основное</h3>
                  <p>Базовые атрибуты участка. Эти поля чаще всего используются в отчётах.</p>
                </div>
                <div class="fe-grid">
                  <div class="fe-field fe-col-4">
                    <label for="edit_field_code">Кадастровый №</label>
                    <input name="field_code" id="edit_field_code" maxlength="4" required placeholder="3–4 цифры">
                    <div class="fe-hint">Пример: 0123</div>
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_area_ha">Площадь (га)</label>
                    <input name="area_ha" id="edit_area_ha" type="number" step="0.01" min="0" placeholder="Напр. 12.50">
                    <div class="fe-hint">Для сводок и KPI</div>
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_culture_id">Культура</label>
                    <select name="culture_id" id="edit_culture_id" data-ui="custom-select"></select>
                    <div class="fe-hint">Выбор влияет на отчёты</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="fe-panel" role="tabpanel" data-fe-panel="calendar">
              <div class="fe-sheet">
                <div class="fe-sheet__head">
                  <h3>Календарь работ</h3>
                  <p>Ключевые даты по участку — удобно для контроля и планирования.</p>
                </div>
                <div class="fe-grid">
                  <div class="fe-field fe-col-4">
                    <label for="edit_plow_date">Дата вспашки</label>
                    <input name="plow_date" id="edit_plow_date" type="date">
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_sow_date">Дата сева</label>
                    <input name="sow_date" id="edit_sow_date" type="date">
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_last_water_date">Последний полив</label>
                    <input name="last_water_date" id="edit_last_water_date" type="date">
                  </div>
                </div>
              </div>
            </div>

            <div class="fe-panel" role="tabpanel" data-fe-panel="treat">
              <div class="fe-sheet">
                <div class="fe-sheet__head">
                  <h3>Обработки</h3>
                  <p>Фиксируй обработку и чем выполнялась — удобно для истории и аудита.</p>
                </div>
                <div class="fe-grid">
                  <div class="fe-field fe-col-4">
                    <label for="edit_treatment_date">Дата обработки</label>
                    <input name="treatment_date" id="edit_treatment_date" type="date">
                  </div>
                  <div class="fe-field fe-col-8">
                    <label for="edit_treatment_desc">Чем обрабатывалось</label>
                    <input name="treatment_desc" id="edit_treatment_desc" placeholder="Препараты, нормы, техника">
                    <div class="fe-hint">Можно через запятую — как удобно</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="fe-panel" role="tabpanel" data-fe-panel="harvest">
              <div class="fe-sheet">
                <div class="fe-sheet__head">
                  <h3>Урожай</h3>
                  <p>Показатели уборки. Эти данные часто просит бухгалтерия и руководство.</p>
                </div>
                <div class="fe-grid">
                  <div class="fe-field fe-col-4">
                    <label for="edit_harvest_date">Дата уборки</label>
                    <input name="harvest_date" id="edit_harvest_date" type="date">
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_gross_yield">Валовый сбор</label>
                    <input name="gross_yield" id="edit_gross_yield" placeholder="т, ц, кг — как удобно">
                  </div>
                  <div class="fe-field fe-col-4">
                    <label for="edit_avg_yield">Средняя урожайность</label>
                    <input name="avg_yield" id="edit_avg_yield" placeholder="ц/га, т/га — как удобно">
                  </div>
                </div>
              </div>
            </div>

            <div class="fe-panel" role="tabpanel" data-fe-panel="notes">
              <div class="fe-sheet">
                <div class="fe-sheet__head">
                  <h3>Заметки</h3>
                  <p>Любые детали и комментарии по участку.</p>
                </div>
                <div class="fe-grid">
                  <div class="fe-field fe-col-12">
                    <label for="edit_notes">Комментарий</label>
                    <textarea name="notes" id="edit_notes" rows="6" placeholder="Любые детали и комментарии"></textarea>
                    <div class="fe-hint">Поддерживает обычный текст</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="fe-panel" role="tabpanel" data-fe-panel="history">
              <div class="fe-sheet">
                <div class="fe-sheet__head fe-sheet__head--tools">
                  <div>
                    <h3>История операций</h3>
                    <p>Последние записи по участку. Полный журнал доступен в отдельном окне.</p>
                  </div>
                  <div class="fe-sheet__tools">
                    <button class="fe-btn ghost sm" type="button" id="feHistFullFromEdit">Все записи</button>
                  </div>
                </div>
                <div id="feHistEmbedEdit" class="fe-hist" data-fe-hist="edit"></div>
              </div>
            </div>

            <div class="fe-footnote muted sm">Изменения сохраняются в базе данных сразу после нажатия «Сохранить».</div>
          </form>
        </div>

        <!-- Нижняя панель действий (появляется на телефонах) -->
        <footer class="fe-edit__bottombar" aria-label="Действия">
          <button class="fe-btn ghost" type="button" data-close="#modalEdit">Закрыть</button>
          <button class="fe-btn primary" type="submit" form="formEdit">Сохранить</button>
        </footer>
      </section>
    </div>
  </div>
</div>

<!-- История операций по участку -->
<div class="modal" id="modalHistory" hidden>
  <div class="modal-body glass modal-history">
    <div class="mh-head">
      <div>
        <div class="mh-label">История участка</div>
        <h2 id="mhTitle" class="mh-title">—</h2>
        <div id="mhSub" class="mh-sub muted sm">—</div>
      </div>
      <div class="mh-actions">
        <button class="btn ghost" type="button" id="mhAddBtn" hidden>Добавить запись</button>
        <button class="btn" type="button" data-close="#modalHistory">Закрыть</button>
      </div>
    </div>

    <div class="mh-tabs" id="mhTabs"></div>

    <div id="mhAddWrap" class="mh-add" hidden></div>
    <div id="mhList" class="mh-list"></div>
  </div>
</div>

<div id="toasts"></div>
<script src="app_dashboard.js"></script>
<script src="app_dashboard_patch.js"></script>
  <script src="custom_select.js"></script>
  <script src="field_editor_pro.js"></script>
</body>
</html>
