<?php
/**
 * Polled by connect.php's success page (see the JS at the bottom of that
 * file) to find out whether a code is ACTUALLY online yet, rather than
 * just assuming it the moment the auto-login form is submitted.
 *
 * "Actually online" here means: our RADIUS daemon has received at least
 * one Accounting-Request for this username (see radius_server.php's
 * R_ACCOUNTING_REQUEST handling, which calls record_session_usage() into
 * radius_sessions on every accounting packet). The router only ever sends
 * that once it has genuinely applied the session — it is not something we
 * can fake or infer any earlier, and unlike a client-side reachability
 * probe it does not depend on guessing at a third-party URL or fighting
 * CORS/opaque-response ambiguity. This is the same signal Admin -> Raffle
 * Entries already uses to show a device as "Active".
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/rate_limit.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$ip = client_ip();
// Generous cap: a real poll cycle is ~10-15 requests over 20-30 seconds.
// This only ever returns a boolean, so there is nothing sensitive to leak
// even under abuse — the cap exists to keep a runaway client (or a script
// hammering random codes) from generating unbounded DB queries.
if (!rate_limit_check('connect_status', $ip, 60, 60)) {
    http_response_code(429);
    echo json_encode(['connected' => false]);
    exit;
}
rate_limit_record('connect_status', $ip);

$code = (string) ($_GET['code'] ?? '');
if (preg_match('/^[0-9]{8}$/', $code) !== 1) {
    http_response_code(400);
    echo json_encode(['connected' => false]);
    exit;
}

try {
    $db = get_db();
    $stmt = $db->prepare('SELECT 1 FROM radius_sessions WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $connected = $stmt->get_result()->num_rows > 0;
    $stmt->close();
} catch (\Throwable $e) {
    app_log('connect-status.php: ' . $e->getMessage());
    $connected = false;
}

echo json_encode(['connected' => $connected]);
