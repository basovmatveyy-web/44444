<?php
require_once __DIR__.'/guard.php';
if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'admin')) {
  header('Location: dashboard.php'); exit;
}
$user = $_SESSION['user'];

// CSRF meta (для fetch-запросов админки)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>СХПК «Береговой» — Админ‑панель</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars($csrf)?>">
<link rel="icon" href="logo.png">
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="select_patch.css">
<style>
/* Небольшие допы для админ‑панели (вписаны в ваш стиль) */
.admin-layout{display:grid;grid-template-columns:260px 1fr;gap:16px;padding:80px 16px 20px}
@media (max-width:1100px){.admin-layout{grid-template-columns:1fr} .aside{position:static}}
.aside{position:sticky;top:74px;align-self:start}
.aside .menu{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.aside .menu .item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);
  background:rgba(255,255,255,.03);color:var(--text);cursor:pointer;text-decoration:none}
.aside .menu .item.active{background:rgba(255,255,255,.06)}
.pane{padding:16px}
.section{display:none}
.section.active{display:block}
.table{width:100%;border-collapse:separate;border-spacing:0 6px}
.table th,.table td{padding:10px 12px;background:rgba(255,255,255,.03);border:1px solid var(--border)}
.table th{font-weight:700}
.table tr td:first-child,.table tr th:first-child{border-top-left-radius:12px;border-bottom-left-radius:12px}
.table tr td:last-child,.table tr th:last-child{border-top-right-radius:12px;border-bottom-right-radius:12px}
/* шапка и карточка админа */
.user-card{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--border);border-radius:14px;background:rgba(255,255,255,.03)}
.user-card img{width:44px;height:44px;border-radius:12px;box-shadow:var(--shadow)}
.role-badge{font-size:12px;color:var(--muted)}
</style>
</head>
<body class="bg">
<div id="bg-layer"></div>
<div id="bg-overlay"></div>
<div class="overlay"></div>

<header class="topbar">
  <div class="brand"><img class="logo" src="logo.png" alt=""><div>СХПК «Береговой»</div></div>
  <nav class="topnav">
    <a class="link" href="dashboard.php">К карте</a>
    <a class="link" href="logout.php">Выход</a>
  </nav>
</header>

<main class="admin-layout">
  <!-- Левое меню -->
  <aside class="aside" id="aside">
    <div class="user-card">
      <img src="logo.png" alt="">
      <div>
        <div class="user-name"><?=htmlspecialchars(($user['surname'] ?? '').' '.($user['name'] ?? ''))?></div>
        <div class="role-badge">Администратор</div>
      </div>
    </div>

    <div class="menu" id="menu">
      <a href="#" class="item active" data-pane="overview">Обзор</a>
      <a href="#" class="item" data-pane="users">Пользователи</a>
      <a href="#" class="item" data-pane="cultures">Культуры</a>
      <a href="#" class="item" data-pane="logs">Журнал</a>
      <a href="#" class="item" data-pane="telegram">Telegram</a>
    </div>
    <div class="foot">© 2025 СХПК «Береговой»</div>
  </aside>

  <!-- Контент -->
  <section>
    <!-- Обзор -->
    <section class="glass section active" id="pane-overview">
      <div class="pane">
        <h2>Обзор</h2>
        <div class="muted">Сводные показатели по системе.</div>
        <div id="overviewBox" class="space"></div>
      </div>
    </section>

    <!-- Пользователи -->
    <section class="glass section" id="pane-users">
      <div class="pane">
        <h2>Пользователи</h2>
        <div id="usersBox" class="space"></div>
      </div>
    </section>

    <!-- Культуры -->
    <section class="glass section" id="pane-cultures">
      <div class="pane">
        <h2>Культуры</h2>
        <div id="culturesBox" class="space"></div>
      </div>
    </section>

    <!-- Журнал -->
    <section class="glass section" id="pane-logs">
      <div class="pane">
        <h2>Журнал</h2>
        <div id="logsBox" class="space"></div>
      </div>
    </section>

    <!-- Telegram (расширенная панель) -->
    <section class="glass section" id="pane-telegram">
      <?php readfile(__DIR__.'/telegram_panel_pro.html'); ?>
    </section>
  </section>
</main>

<div id="scrim" class="scrim"></div>
<div id="toasts"></div>

<script>
// Мини-роутер вкладок (бережно, без влияния на существующий admin.js)
document.addEventListener('DOMContentLoaded', function(){
  var menu = document.getElementById('menu');
  if (!menu) return;
  menu.addEventListener('click', function(e){
    var a = e.target.closest('a.item'); if(!a) return;
    e.preventDefault();
    var pane = a.getAttribute('data-pane');
    Array.prototype.forEach.call(menu.querySelectorAll('.item'), function(it){ it.classList.remove('active'); });
    a.classList.add('active');
    ['overview','users','cultures','logs','telegram'].forEach(function(k){
      var sec = document.getElementById('pane-'+k);
      if (sec) sec.classList.toggle('active', k===pane);
    });
    // лениво подгружаем данные существующими API вашего admin.js (если он их дергает по onload)
    if (window.loadTg && pane==='telegram') { window.loadTg(); }
  });
});
</script>

<script src="admin.js"></script>
<script src="admin_telegram_v6.js"></script>
</body>
</html>
