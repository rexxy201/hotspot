<?php
// The app-log safety net (app_log_register_handlers()) is registered from
// config.php, not here — config.php is required more broadly than db.php
// is (admin/login.php and almightypush.php both require config.php
// directly without going through db.php), so registering it there covers
// every one of those too instead of just this file's own callers.
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
