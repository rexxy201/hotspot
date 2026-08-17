<?php
require_once __DIR__ . '/config.php';

/**
 * Holds the single mysqli connection.
 *
 * A plain `static` inside get_db() could never be cleared from outside, and the
 * long-running RADIUS daemon must be able to drop a dead handle — hence this
 * small holder.
 */
function db_holder(?mysqli $set = null, bool $clear = false): ?mysqli {
    static $db = null;
    if ($clear) {
        $db = null;
        return null;
    }
    if ($set !== null) {
        $db = $set;
    }
    return $db;
}

function get_db(): mysqli {
    $db = db_holder();
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
        db_holder($db);
    }
    return $db;
}

/**
 * Drop the cached connection so the next get_db() dials a fresh one.
 *
 * Only the long-running RADIUS daemon needs this: MySQL closes idle
 * connections, and without a reconnect the daemon would stay up while
 * rejecting every attendee.
 */
function reset_db(): void {
    $db = db_holder();
    if ($db instanceof mysqli) {
        @$db->close();
    }
    db_holder(null, true);
}
