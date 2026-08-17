<?php

/**
 * Record what the router says a session has transferred.
 *
 * RADIUS accounting reports ABSOLUTE counters for a session on every interim
 * update, so this stores rather than adds. That makes it idempotent: a
 * retransmission or a duplicate converges on the same stored value instead of
 * inflating it.
 *
 * The stored counters only ever move UPWARD (GREATEST below), so a late,
 * reordered or replayed packet carrying lower counters — a retransmitted
 * Acct-Start, whose counters are zero — cannot walk a session's usage
 * backwards and hand back allowance that was already spent.
 *
 * $inputOctets and $outputOctets must already have their gigawords companions
 * folded in — see radius_octets_64().
 */
function record_session_usage(mysqli $db, string $sessionId, string $username, int $inputOctets, int $outputOctets): void
{
    $stmt = $db->prepare(
        'INSERT INTO radius_sessions (session_id, username, input_octets, output_octets)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            input_octets = GREATEST(input_octets, VALUES(input_octets)),
            output_octets = GREATEST(output_octets, VALUES(output_octets))'
    );
    $stmt->bind_param('ssii', $sessionId, $username, $inputOctets, $outputOctets);
    $stmt->execute();
    $stmt->close();
}

/** Total bytes a code has transferred, across all its sessions. */
function usage_bytes_for_code(mysqli $db, string $username): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(input_octets + output_octets), 0) AS total
           FROM radius_sessions WHERE username = ?'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['total'];
}

/**
 * Clear a code's recorded usage, giving it its full quota again.
 *
 * Deletes only session rows. The attendee's `entries` row — their prize-draw
 * entry — is never touched.
 */
function reset_usage_for_code(mysqli $db, string $username): void
{
    $stmt = $db->prepare('DELETE FROM radius_sessions WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Bytes transferred across every code. */
function total_usage_bytes(mysqli $db): int
{
    $row = $db->query(
        'SELECT COALESCE(SUM(input_octets + output_octets), 0) AS total FROM radius_sessions'
    )->fetch_assoc();
    return (int) $row['total'];
}

/** A byte count staff can read at a glance. */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, 1) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}
