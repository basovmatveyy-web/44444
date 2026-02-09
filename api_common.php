<?php
/**
 * api_common.php — общие утилиты для JSON-эндпоинтов.
 * Этот вариант безопасен к «повторным объявлениям» — все функции
 * обёрнуты в function_exists().
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

//* Если заголовок ещё не отправлен, выставим JSON */
//if (!headers_sent()) {
 //   header('Content-Type: application/json; charset=utf-8');
//}

/* ---------------- JSON helpers ---------------- */

if (!function_exists('json_ok')) {
    function json_ok(array $data = []) : void {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_err')) {
    function json_err(string $message, int $code = 400) : void {
        http_response_code($code);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ---------------- Security ---------------- */

if (!function_exists('check_csrf_or_die')) {
    function check_csrf_or_die(string $token = '') : void {
        if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(403);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF-токен недействителен'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/* ---------------- Backward-compat helpers ---------------- */

// Старые эндпоинты в проекте вызывают need_csrf();
// Оставляем алиас, чтобы не ловить фаталы.
if (!function_exists('need_csrf')) {
    function need_csrf() : void {
        $token = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        check_csrf_or_die($token);
    }
}

/* ---------------- Auth / ACL ---------------- */

if (!function_exists('require_user')) {
    function require_user() : void {
        if (empty($_SESSION['user']) || empty($_SESSION['user']['id'])) {
            http_response_code(401);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin() : void {
        $role = $_SESSION['user']['role'] ?? 'user';
        if ($role !== 'admin') {
            http_response_code(403);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Недостаточно прав: требуется роль «администратор»'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/* ---------------- PDO accessor ---------------- */

if (!function_exists('pdo')) {
    function pdo() : PDO {
        /** @var PDO $pdo */
        global $pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }
}

/* ---------------- Helpers ---------------- */

if (!function_exists('null_if_empty')) {
    function null_if_empty(?string $v) : ?string {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }
}

if (!function_exists('post_str')) {
    function post_str(string $name) : string {
        return trim((string)($_POST[$name] ?? ''));
    }
}
