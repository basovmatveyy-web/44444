<?php
require_once __DIR__ . '/config.php';
// Если пользователь уже авторизован — сразу показываем выбор раздела.
if (!empty($_SESSION['user'])) { header('Location: modules.php'); exit; }
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>СХПК «БЕРЕГОВОЙ» — Вход</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=$_SESSION['csrf']?>">
<link rel="icon" href="logo.png">
<link rel="preload" href="oboi.png" as="image">
<link rel="stylesheet" href="styles.css">
</head>
<body class="bg">
<div id="bg-layer"></div><div id="bg-overlay"></div><div class="overlay"></div>

<header class="topbar">
  <div class="brand"><img src="logo.png" class="logo" alt=""><span>СХПК «Береговой»</span></div>
  <nav class="topnav"><a class="link" href="#" id="openRegister">Регистрация</a></nav>
</header>

<main class="center-wrap">
  <section class="glass card-xl">
    <div class="card-header">
      <div class="glass-badge">
        <img class="badge-logo" src="logo.png" alt="">
        <div><div class="badge-title">СХПК «Береговой»</div><div class="badge-sub">Единая система управления полями</div></div>
      </div>
    </div>
    <div class="grid">
      <div>
        <h1>Добро пожаловать</h1>
        <p class="muted">Войдите, чтобы перейти к рабочей панели.</p>
        <form id="formLogin" class="form" autocomplete="off">
          <label>Логин
            <input name="username" required placeholder="Ваш логин" value="<?= isset($_COOKIE['last_user']) ? safe($_COOKIE['last_user']) : '' ?>">
          </label>
          <label>Пароль
            <input type="password" name="password" required placeholder="Пароль">
          </label>
          <div class="row gap">
            <button class="btn primary" type="submit">Войти</button>
            <a class="btn ghost" href="#" id="openRegister2">Регистрация</a>
          </div>
          <p class="muted sm">Нет аккаунта? Зарегистрируйтесь — вам будет назначена роль «Пользователь».</p>
        </form>
      </div>
      <aside>
        <div class="glass pane">
          <h3>Быстрый просмотр участка</h3>
          <p class="muted sm">Кадастровый номер — 3–4 цифры, например: <b>125</b> или <b>1253</b>.</p>
          <form id="formQuick" class="row gap" autocomplete="off" novalidate>
            <input maxlength="4" name="code" pattern="\d{3,4}" required placeholder="Например 125">
            <button class="btn primary">Посмотреть</button>
          </form>
          <div id="quickResult" class="space"></div>
          <div class="hint"><b>Примечание:</b> редактирование доступно только администраторам.</div>
        </div>
      </aside>
    </div>
  </section>
</main>

<footer class="foot muted sm">© СХПК «Береговой» — Все права защищены</footer>

<div class="modal" id="modalRegister" hidden>
  <div class="modal-body glass">
    <h2>Регистрация</h2>
    <form id="formRegister" class="form" autocomplete="off">
      <label>Фамилия <input name="surname" required></label>
      <label>Имя <input name="name" required></label>
      <label>Логин <input name="username" required></label>
      <label>Пароль <input type="password" name="password" required></label>
      <div class="row gap">
        <button class="btn primary" type="submit">Создать аккаунт</button>
        <button class="btn" type="button" data-close="#modalRegister">Отмена</button>
      </div>
      <p class="muted sm">После регистрации вы получите роль «Пользователь». Права администратора назначает администратор.</p>
    </form>
  </div>
</div>

<div id="toasts"></div>
<script src="app.js"></script>
</body>
</html>
