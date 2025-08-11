<?php
// php/session.php
declare(strict_types=1);

/**
 * Startar en säker session och erbjuder enkla login-hjälpare.
 * Inloggning kan stängas av via REQUIRE_LOGIN (false som standard).
 */

const REQUIRE_LOGIN = false; // <-- sätt true när du vill kräva login

// 1) Grundinställningar för sessions (körs innan session_start)
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // 'Strict' om du inte behöver cross-site

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ===== Hjälpfunktioner =====
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (REQUIRE_LOGIN && !is_logged_in()) {
        // anpassa: skicka till login-sida
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

function login_user(int $userId, array $extra = []): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user'] = $extra; // valfritt: role, name etc.
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// Kör kravkontroll (just nu gör den inget om REQUIRE_LOGIN=false)
require_login();
