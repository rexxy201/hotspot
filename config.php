<?php
/**
 * Safe to commit — this file contains zero secret values.
 *
 * Every constant below is sourced from the environment. load_env() fills
 * that environment from .env (gitignored, lives only on the server) if one
 * is present; a real environment variable the host has already set always
 * wins. .env itself is written by setup.php — see that file — never by
 * hand and never committed.
 *
 * Nothing in this file needs editing per install. If .env is missing (a
 * fresh checkout that hasn't been through setup.php yet), every constant
 * below falls back to '' or a safe non-secret default, so the app fails
 * loudly and obviously (a DB connection error, a RuntimeException from
 * setting_encrypt() on the placeholder-less APP_KEY) rather than silently
 * running on a guessable default.
 */
require_once __DIR__ . '/lib/env.php';
load_env(__DIR__ . '/.env');

// The app-log safety net, registered here rather than in db.php: config.php
// is required more broadly (admin/login.php and almightypush.php both pull
// it in directly, without going through db.php), so every page that reaches
// this line — which is nearly the whole app — gets "an uncaught error lands
// in a readable log" for free. The one deliberate exception is setup.php,
// which never loads config.php (it has to keep working before a real config
// exists) and registers the same handlers on its own instead.
require_once __DIR__ . '/lib/app_log.php';
app_log_register_handlers();

// Database
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'wifi_portal');
define('DB_USER', getenv('DB_USER') ?: 'wifi_portal_user');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Encryption key for secrets stored in the settings table (RADIUS shared
// secret). Generate a fresh one per install — setup.php does this for you.
// Keep it in .env only — never in the database, or encrypting the database
// values would be pointless.
define('APP_KEY', getenv('APP_KEY') ?: '');

// Admin login — setup.php generates this from the password you choose.
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '');

// SMTP (email delivery)
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Wi-Fi Portal');

// Twilio (SMS delivery)
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
define('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: '');
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '');

// Mikrotik hotspot gateway — your venue's actual Mikrotik hotspot
// IP/hostname. connect.php only auto-submits the attendee's RADIUS credential
// to a `link-login-only` URL whose host exactly matches this value, so a
// crafted link pointing at an attacker-controlled host is rejected.
define('MIKROTIK_GATEWAY_HOST', getenv('MIKROTIK_GATEWAY_HOST') ?: '');

// This portal's own real hostname (e.g. eyifwifi.online) — the single
// source of truth admin/radius.php uses when generating the router config
// (.rsc) and hotspot login page (login.html) downloads. Optional: leave
// blank and those downloads fall back to auto-detecting the host from
// whatever request downloaded them, which is fine until that guess is
// wrong (bare-IP troubleshooting, a staging alias, a domain migration).
// See lib/portal_host.php.
define('PORTAL_HOST', getenv('PORTAL_HOST') ?: '');

// App-level toggles read directly by setup.php / security middleware.
define('COMPANY_NAME', getenv('COMPANY_NAME') ?: 'MangoNet');

// Short PIN that unlocks setup.php on a re-run, instead of the full admin
// password — deliberately separate from ADMIN_PASSWORD_HASH so setup.php
// doesn't need a trip through /admin/ to reach. Rate-limited in setup.php
// itself (see lib/rate_limit.php) because a short PIN is easy to brute-force
// otherwise, and this page can rewrite DB credentials and drop every table.
define('SETUP_ACCESS_CODE', getenv('SETUP_ACCESS_CODE') ?: '2112');
