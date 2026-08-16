<?php
function require_admin_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['is_admin'])) {
        header('Location: login.php');
        exit;
    }
}
