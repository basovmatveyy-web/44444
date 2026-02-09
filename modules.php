<?php
require_once __DIR__ . '/guard.php';
$user = $_SESSION['user'];
$fullName = trim((string)($user['surname'] ?? '') . ' ' . (string)($user['name'] ?? ''));
$role = (string)($user['role'] ?? 'user');
$roleLabel = 'Пользователь';
if ($role === 'admin') $roleLabel = 'Администратор';
if ($role === 'buh') $roleLabel = 'Бухгалтер';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>СХПК «Береговой» — Разделы</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="logo.png">
<link rel="stylesheet" href="styles.css">
</head>
<body class="bg" data-role="<?=safe($role)?>">
<div id="bg-layer"></div><div id="bg-overlay"></div><div class="overlay"></div>

<header class="topbar">
  <div class="brand">
    <img src="logo.png" class="logo" alt="">
    <span>СХПК «Береговой»</span>
  </div>
  <nav class="topnav">
    <?php if ($role === 'admin'): ?>
      <a class="link" href="admin.php">Админ‑панель</a>
    <?php endif; ?>
    <a class="link" href="logout.php">Выход</a>
  </nav>
</header>

<main class="center-wrap">
  <section class="glass card-xl module-shell">
    <div class="module-head">
      <div>
        <h1 class="module-title">Выберите раздел</h1>
        <p class="muted">Доступные направления работы в системе.</p>
      </div>
      <div class="module-user">
        <div class="module-user-name"><?=safe($fullName !== '' ? $fullName : ($user['username'] ?? ''))?></div>
        <div class="module-user-role"><?=safe($roleLabel)?></div>
      </div>
    </div>

    <div class="module-grid">
      <a class="module-card" href="dashboard.php" aria-label="Открыть раздел Растениеводство">
        <div class="module-card-top">
          <div class="module-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M12 21s-6-4.2-6-10a6 6 0 0 1 12 0c0 5.8-6 10-6 10Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
              <path d="M12 12c-1.8 0-3.5-1-4.5-2.6M12 12c1.8 0 3.5-1 4.5-2.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              <path d="M12 12v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </div>
          <div>
            <div class="module-card-title">Растениеводство</div>
            <div class="module-card-sub">Карта полей, культуры, история, экспорт.</div>
          </div>
        </div>
        <div class="module-card-bottom">
          <span class="module-tag ok">Готово</span>
          <span class="module-cta">Открыть →</span>
        </div>
      </a>

      <a class="module-card" href="animals/" aria-label="Открыть раздел Животноводство">
        <div class="module-card-top">
          <div class="module-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M7 10c0-2 1.6-4 5-4s5 2 5 4v5c0 1.7-1.3 3-3 3h-4c-1.7 0-3-1.3-3-3v-5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
              <path d="M7 11 4.7 9.7A1.5 1.5 0 0 1 4 8.4V7.2C4 6.5 4.5 6 5.2 6h.8c.5 0 1 .2 1.3.6L8 7.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M17 11l2.3-1.3c.4-.2.7-.7.7-1.3V7.2c0-.7-.5-1.2-1.2-1.2h-.8c-.5 0-1 .2-1.3.6L16 7.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M10 18v2M14 18v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </div>
          <div>
            <div class="module-card-title">Животноводство</div>
            <div class="module-card-sub">Учёт поголовья, события, корма, отчёты.</div>
          </div>
        </div>
        <div class="module-card-bottom">
          <span class="module-tag dev">В разработке</span>
          <span class="module-cta">Открыть →</span>
        </div>
      </a>

      <a class="module-card" href="buh/" aria-label="Открыть раздел Бухгалтерия">
        <div class="module-card-top">
          <div class="module-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
              <path d="M8 7h8M8 11h8M8 15h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              <path d="M16.5 15.5 18 17l2-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div>
            <div class="module-card-title">Бухгалтерия</div>
            <div class="module-card-sub">Реестры, выплаты, документы, сверки.</div>
          </div>
        </div>
        <div class="module-card-bottom">
          <span class="module-tag dev">В разработке</span>
          <span class="module-cta">Открыть →</span>
        </div>
      </a>
    </div>

    <div class="module-hint muted sm">
      Подсказка: к выбору раздела можно вернуться через кнопку <b>«Разделы»</b> в шапке.
    </div>
  </section>
</main>

<footer class="foot muted sm">© <?=date('Y')?> СХПК «Береговой»</footer>
</body>
</html>
