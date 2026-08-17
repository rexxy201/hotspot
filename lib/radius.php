<?php
require_once __DIR__ . '/credentials.php';

/**
 * Issue the Wi-Fi credential for a freshly created entry.
 *
 * The 8-digit code is both the RADIUS username and password. How long it stays
 * valid, and what speed cap applies, come from the admin settings.
 */
function radius_add_user(mysqli $db, string $code, array $settings): void
{
    $minutes = max(1, (int) ($settings['session_minutes'] ?? 720));
    $rate = trim((string) ($settings['rate_limit'] ?? ''));
    issue_credential($db, $code, $minutes, $rate !== '' ? $rate : null);
}

/** Whether a code currently has a valid (unexpired) Wi-Fi credential. */
function radius_user_exists(mysqli $db, string $code): bool
{
    return find_valid_credential($db, $code) !== null;
}
