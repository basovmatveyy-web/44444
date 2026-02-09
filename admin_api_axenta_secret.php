<?php
/**
 * admin_api_axenta_secret.php
 * Серверный доступ к учётным данным Axenta:
 * - проверка секретного кода
 * - выдача логина/пароля только после успешной проверки
 * - блокировка на 2 минуты после 3 ошибок
 */

ob_start();
require_once __DIR__ . '/api_common.php';
require_admin();
require_once __DIR__ . '/admin_api_util.php';

// Запрет кеширования (важно для «секретов»)
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

// --- Настройки ---
$SECRET_WORD = 'Мустанг';
$AX_LOGIN    = 'milk_river';
$AX_PASS     = '!12345Aa';

$now = time();

// --- Lock cleanup ---
$lock_until = (int)($_SESSION['axenta_lock_until'] ?? 0);
if ($lock_until && $lock_until <= $now) {
  unset($_SESSION['axenta_lock_until']);
  unset($_SESSION['axenta_fail_count']);
  $lock_until = 0;
}

function ax_reply($arr, $code = 200) {
  reply_json($arr, $code);
}

$action = $_GET['action'] ?? 'status';

// --- STATUS ---
if ($action === 'status') {
  $granted = !empty($_SESSION['axenta_secret_granted']);
  ax_reply([
    'ok' => true,
    'granted' => $granted,
    'lock_until' => $lock_until,
  ], 200);
}

// If locked: block other actions
if ($lock_until && $lock_until > $now) {
  ax_reply([
    'ok' => false,
    'error' => 'locked',
    'lock_until' => $lock_until,
  ], 429);
}

// --- VERIFY CODE ---
if ($action === 'verify') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ax_reply(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  }

  $code = trim((string)($_POST['code'] ?? ''));

  if ($code !== '' && hash_equals($SECRET_WORD, $code)) {
    $_SESSION['axenta_secret_granted'] = 1;
    unset($_SESSION['axenta_fail_count']);
    ax_reply(['ok'=>true, 'granted'=>true, 'login'=>$AX_LOGIN, 'pass'=>$AX_PASS], 200);
  }

  // wrong code
  $fails = (int)($_SESSION['axenta_fail_count'] ?? 0);
  $fails++;
  $_SESSION['axenta_fail_count'] = $fails;
  $attempts_left = max(0, 3 - $fails);

  if ($fails >= 3) {
    $_SESSION['axenta_lock_until'] = $now + 120; // 2 minutes
    unset($_SESSION['axenta_fail_count']);
    unset($_SESSION['axenta_secret_granted']);
    ax_reply([
      'ok' => false,
      'error' => 'locked',
      'lock_until' => (int)$_SESSION['axenta_lock_until'],
      'attempts_left' => 0,
    ], 429);
  }

  ax_reply([
    'ok' => false,
    'error' => 'wrong_code',
    'attempts_left' => $attempts_left,
  ], 403);
}

// --- GET SECRET VALUE (login/password) ---
if ($action === 'get') {
  if (empty($_SESSION['axenta_secret_granted'])) {
    ax_reply(['ok'=>false, 'error'=>'denied'], 403);
  }

  $what = $_GET['what'] ?? '';
  if ($what === 'login') {
    ax_reply(['ok'=>true, 'value'=>$AX_LOGIN], 200);
  }
  if ($what === 'pass') {
    ax_reply(['ok'=>true, 'value'=>$AX_PASS], 200);
  }

  ax_reply(['ok'=>false, 'error'=>'bad_request'], 400);
}

ax_reply(['ok'=>false, 'error'=>'bad_request'], 400);
