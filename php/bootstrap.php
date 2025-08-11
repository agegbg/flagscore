<?php
// php/bootstrap.php
declare(strict_types=1);

// 1) Alltid UTF-8
header('Content-Type: text/html; charset=utf-8');

// 2) Tidszon (ändra vid behov)
date_default_timezone_set('Europe/Stockholm');

// 3) Säkra sessionsinställningar (innan session_start)
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // 'Strict' om du inte behöver cross-site
// Sätt secure=1 om du kör https:
if (!headers_sent()) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// 4) Starta session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 5) Ladda DB (PDO/MySQLi)
require_once __DIR__ . '/db.php';

// 6) CSRF-hjälpare
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">';
}
function csrf_validate(?string $token): bool {
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// 7) Flash-meddelanden
function flash_set(string $key, string $msg): void {
    $_SESSION['_flash'][$key] = $msg;
}
function flash_get(string $key): ?string {
    if (!isset($_SESSION['_flash'][$key])) return null;
    $msg = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $msg;
}
