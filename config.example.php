<?php
// Copy this file to config.php and fill in real values.
// config.php is gitignored — never commit real credentials.
// Every constant reads an environment variable first so tests can override
// values (e.g. DB_NAME) without editing this file.

// Database
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'wifi_portal');
define('DB_USER', getenv('DB_USER') ?: 'wifi_portal_user');
define('DB_PASS', getenv('DB_PASS') ?: 'change-me');

// Admin login — generate with: php hash_password.php "your-strong-password"
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$replace-with-generated-hash');

// SMTP (email delivery)
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.example.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'you@example.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'change-me');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'EYIF 2026 Wi-Fi');

// Twilio (SMS delivery)
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: 'change-me');
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '+1234567890');
