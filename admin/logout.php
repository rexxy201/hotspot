<?php
// Deliberately kept independent of config.php/db.php — sign-out should
// never fail just because the database is having a bad moment — but still
// gets the same app-log safety net directly, for the same reason every
// other page does: an uncaught error here should be visible somewhere,
// not just a blank page.
require_once __DIR__ . '/../lib/app_log.php';
app_log_register_handlers();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear the session data, drop the cookie, then destroy the session itself so
// the admin is genuinely signed out rather than left with a reusable id.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
