<?php
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
