<?php

/**
 * Issue (or replace) a Wi-Fi credential for a code.
 *
 * `$minutes` may be negative, which produces an already-expired row — used by
 * the tests to exercise the expiry path.
 */
function issue_credential(mysqli $db, string $code, int $minutes, ?string $rateLimit = null, ?string $mac = null): void
{
    // The expiry is computed by the database, not by PHP: `find_valid_credential()`
    // and `count_active_credentials()` compare against MySQL's NOW(), and PHP's
    // timezone is not guaranteed to match the database server's.
    $stmt = $db->prepare(
        'INSERT INTO wifi_credentials (username, password, mac, rate_limit, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
         ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            mac = VALUES(mac),
            rate_limit = VALUES(rate_limit),
            expires_at = VALUES(expires_at)'
    );
    // The code is both the username and the password.
    $stmt->bind_param('ssssi', $code, $code, $mac, $rateLimit, $minutes);
    $stmt->execute();
    $stmt->close();
}

/** The credential for $username, or null if missing or expired. */
function find_valid_credential(mysqli $db, string $username): ?array
{
    $stmt = $db->prepare(
        'SELECT id, username, password, mac, rate_limit, expires_at
           FROM wifi_credentials
          WHERE username = ? AND expires_at > NOW()
          LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Revoke Wi-Fi access for a code.
 *
 * This deletes ONLY the credential. The attendee's `entries` row is their
 * prize-draw entry and must survive revocation.
 */
function revoke_credential(mysqli $db, string $username): void
{
    $stmt = $db->prepare('DELETE FROM wifi_credentials WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Record that a credential just authenticated successfully. */
function touch_credential(mysqli $db, string $username): void
{
    $stmt = $db->prepare('UPDATE wifi_credentials SET last_used_at = NOW() WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** How many credentials are currently valid. */
function count_active_credentials(mysqli $db): int
{
    $row = $db->query('SELECT COUNT(*) AS c FROM wifi_credentials WHERE expires_at > NOW()')->fetch_assoc();
    return (int) $row['c'];
}
