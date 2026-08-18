<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/app_log.php';
// db.php is required by every entrypoint (index.php, connect.php, every
// admin page, radius_server.php) — registering here, once, gives the
// whole app the same "uncaught error reaches a readable log" safety net,
// with no need to remember to add it to each new file individually.
app_log_register_handlers();

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
    try {
        if ($db instanceof mysqli) {
            @$db->close();
        }
    } catch (Throwable $e) {
        // A half-dead link can throw on close. Dropping the handle is the whole
        // point, so swallow it and clear regardless.
    } finally {
        db_holder(null, true);
    }
}
