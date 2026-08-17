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

    // Store the canonical form so find_valid_credential_by_mac() cannot miss on
    // formatting. A non-MAC becomes NULL rather than a junk string.
    $mac = $mac === null ? null : (normalize_mac($mac) ?: null);
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
    // `seconds_remaining` is computed by MySQL rather than by the caller because
    // PHP and the database server do not share a timezone here (PHP runs on UTC,
    // MySQL on UTC+1). `expires_at` comes back as a bare datetime string with no
    // offset, so PHP would parse it in *its own* timezone: `strtotime($row['expires_at'])
    // - time()` silently adds the offset (an hour) to every session. Letting MySQL
    // subtract two of its own timestamps keeps the arithmetic in one timezone, so
    // callers (e.g. the RADIUS daemon's Session-Timeout) never have to know about it.
    $stmt = $db->prepare(
        'SELECT id, username, password, mac, rate_limit, expires_at,
                TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
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

/**
 * Canonicalise a MAC to uppercase colon-separated form, or '' if it is not one.
 *
 * Routers differ in case and separator, so storing and comparing a canonical
 * form stops a lookup missing purely on formatting. Returning '' for anything
 * that is not a MAC means callers get one obviously-invalid value to check
 * rather than having to validate the shape themselves.
 */
function normalize_mac(string $mac): string
{
    // Validate the SHAPE before stripping separators. Stripping first would
    // silently reduce a malformed value (a 7-octet string, a vendor-prefixed
    // one) to a plausible but WRONG 12-hex MAC, which issue_credential() would
    // then store — letting a different, real device with that MAC later resume
    // on someone else's credential.
    $candidate = strtoupper(trim($mac));
    if (preg_match('/^[0-9A-F]{2}([:-][0-9A-F]{2}){5}$/', $candidate) !== 1
        && preg_match('/^[0-9A-F]{12}$/', $candidate) !== 1) {
        return '';
    }
    $hex = preg_replace('/[^0-9A-F]/', '', $candidate);
    return implode(':', str_split($hex, 2));
}

/**
 * The still-valid credential bound to a device, or null.
 *
 * Used only by the silent-login path. The `expires_at > NOW()` filter is load
 * bearing: the MAC is supplied by the client, so matching an expired row here
 * would let a forged MAC revive a dead credential.
 */
function find_valid_credential_by_mac(mysqli $db, string $mac): ?array
{
    $normalised = normalize_mac($mac);
    if ($normalised === '') {
        return null;
    }
    // ORDER BY is load bearing here, unlike in find_valid_credential(): `mac` is
    // a non-unique KEY, so more than one valid credential can be bound to the
    // same device (issue_credential() upserts on the code, not the MAC). Without
    // an explicit ordering the optimiser may return the older, nearly-expired
    // row, and its seconds_remaining becomes the RADIUS Session-Timeout — cutting
    // the attendee off early. Resuming must pick the credential with the most
    // time left.
    $stmt = $db->prepare(
        'SELECT id, username, password, mac, rate_limit, expires_at,
                TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
           FROM wifi_credentials
          WHERE mac = ? AND expires_at > NOW()
          ORDER BY expires_at DESC, id DESC
          LIMIT 1'
    );
    $stmt->bind_param('s', $normalised);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
