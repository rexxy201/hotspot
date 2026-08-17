<?php
/**
 * radius_server.php — UDP RADIUS daemon for the EYIF Wi-Fi portal.
 *
 * Mikrotik sends Access-Requests here; we answer from wifi_credentials. This
 * replaces FreeRADIUS: nothing to install, one codebase, and the credential
 * table is the app's own.
 *
 * Run it as a CLI process, never over HTTP:
 *   bash start_radius.sh start
 * or under systemd via deploy/mangonet-radius.service.
 *
 * Requires ext-sockets. Reads its configuration from the settings table, so
 * changing the shared secret or the trusted router IP in the admin UI takes
 * effect without editing files.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("radius_server.php must be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/credentials.php';
require_once __DIR__ . '/lib/radius_protocol.php';

const LOG_DIR = __DIR__ . '/logs';

function radius_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    if (is_dir(LOG_DIR)) {
        file_put_contents(LOG_DIR . '/radius.log', $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Run a database closure, reconnecting once if the daemon's idle connection
 * was dropped by MySQL. Without this a long-quiet night would leave the daemon
 * alive but rejecting everyone.
 */
function db_run(callable $fn)
{
    try {
        return $fn(get_db());
    } catch (mysqli_sql_exception $e) {
        radius_log('DB error, reconnecting once: ' . $e->getMessage());
        reset_db();
        return $fn(get_db());
    }
}

if (!extension_loaded('sockets')) {
    fwrite(STDERR, "[RADIUS] ext-sockets is not enabled for this PHP binary. Cannot start.\n");
    exit(1);
}

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0775, true);
}

$db = get_db();
$settings = get_settings($db);
$secret = (string) $settings['radius_secret'];
$bindPort = (int) $settings['radius_auth_port'];
$allowedNasIp = (string) $settings['radius_nas_ip'];

if ($secret === '') {
    fwrite(STDERR, "[RADIUS] radius_secret is not set. Configure it in Admin -> RADIUS, then start the daemon.\n");
    exit(1);
}

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
    fwrite(STDERR, '[RADIUS] socket_create failed: ' . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}
if (!socket_bind($sock, '0.0.0.0', $bindPort)) {
    fwrite(STDERR, "[RADIUS] cannot bind UDP {$bindPort}: " . socket_strerror(socket_last_error($sock)) . "\n");
    exit(1);
}
// A 1-second receive timeout keeps the loop responsive to the restart flag.
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

file_put_contents(LOG_DIR . '/radius.pid', (string) getmypid());

$restartFlag = LOG_DIR . '/radius.restart';
@unlink($restartFlag); // clear a stale flag from a previous run

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $cleanup = function () {
        @unlink(LOG_DIR . '/radius.pid');
        radius_log('Daemon stopping.');
        exit(0);
    };
    pcntl_signal(SIGTERM, $cleanup);
    pcntl_signal(SIGINT, $cleanup);
}

radius_log("Listening on UDP 0.0.0.0:{$bindPort}");
radius_log('Trusted router IP: ' . ($allowedNasIp !== '' ? $allowedNasIp : 'any (not restricted)'));

$lastSettingsReload = time();

while (true) {
    // The admin RADIUS Log page drops this flag to request a restart. Only
    // exit if we actually removed it, otherwise an undeletable flag would trap
    // us in a respawn loop.
    if (is_file($restartFlag)) {
        if (@unlink($restartFlag)) {
            radius_log('Restart requested from the admin UI — exiting for the supervisor to respawn.');
            exit(0);
        }
        radius_log('Restart flag present but could not be deleted (permissions?) — ignoring.');
    }

    // Re-read the trusted router IP and secret every 10s so admin changes take
    // effect without a restart.
    if (time() - $lastSettingsReload >= 10) {
        $lastSettingsReload = time();
        try {
            $fresh = db_run(fn(mysqli $d) => get_settings($d));
            if ((string) $fresh['radius_secret'] !== '') {
                $secret = (string) $fresh['radius_secret'];
            }
            if ((string) $fresh['radius_nas_ip'] !== $allowedNasIp) {
                radius_log("Trusted router IP changed to: " . ($fresh['radius_nas_ip'] ?: 'any'));
                $allowedNasIp = (string) $fresh['radius_nas_ip'];
            }
            $settings = $fresh;
        } catch (Throwable $e) {
            radius_log('Could not reload settings: ' . $e->getMessage());
        }
    }

    $buf = '';
    $from = '';
    $fromPort = 0;
    $received = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    if ($received === false || $received < 20) {
        continue; // receive timeout, or a runt packet
    }

    // Loopback is always allowed: the admin "Test connectivity" probe comes
    // from 127.0.0.1, not from the router.
    $isLocal = ($from === '127.0.0.1' || $from === '::1');
    if ($allowedNasIp !== '' && !$isLocal && $from !== $allowedNasIp) {
        radius_log("Ignored packet from {$from} (trusted router is {$allowedNasIp})");
        continue;
    }

    $code = ord($buf[0]);
    $identifier = ord($buf[1]);
    $declaredLength = unpack('n', substr($buf, 2, 2))[1];
    if ($declaredLength < 20 || $declaredLength > $received) {
        radius_log("Malformed packet from {$from} (declared length {$declaredLength}, got {$received})");
        continue;
    }
    $requestAuth = substr($buf, 4, 16);
    $attrs = radius_parse_attributes(substr($buf, 20, $declaredLength - 20));

    if ($code === R_ACCOUNTING_REQUEST) {
        // Stage 1 acknowledges accounting so the router does not retry; Stage 3
        // will parse the octet counters here for bandwidth quotas.
        $reply = radius_build_reply(R_ACCOUNTING_RESPONSE, $identifier, $requestAuth, $secret, '');
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    if ($code !== R_ACCESS_REQUEST) {
        continue;
    }

    $username = $attrs[R_ATTR_USER_NAME] ?? '';
    $mac = $attrs[R_ATTR_CALLING_STATION] ?? '';

    // The admin health-check probes with this reserved username purely to prove
    // the daemon is listening; answer without touching the database.
    if ($username === '__healthcheck__') {
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'health-check ok'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    radius_log("Access-Request user={$username} mac={$mac} from={$from}");

    try {
        $row = db_run(fn(mysqli $d) => find_valid_credential($d, $username));
    } catch (Throwable $e) {
        radius_log('DB lookup failed: ' . $e->getMessage());
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Database error'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    if ($row === null) {
        radius_log("REJECT {$username}: unknown or expired");
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid or expired code'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    // Verify the password with whichever method the router used.
    if (isset($attrs[R_ATTR_CHAP_PASSWORD])) {
        $challenge = $attrs[R_ATTR_CHAP_CHALLENGE] ?? $requestAuth;
        $authOk = radius_check_chap($attrs[R_ATTR_CHAP_PASSWORD], $challenge, (string) $row['password']);
    } else {
        $supplied = radius_decrypt_password($attrs[R_ATTR_USER_PASSWORD] ?? '', $requestAuth, $secret);
        $authOk = hash_equals((string) $row['password'], $supplied);
    }

    if (!$authOk) {
        radius_log("REJECT {$username}: wrong password");
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid credentials'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    // Seconds left on this credential.
    //
    // MUST come from the SQL-computed seconds_remaining, never from
    // strtotime($row['expires_at']) - time(): PHP and MySQL run in different
    // timezones on this deployment (measured at 1h, later 2h with DST), so
    // PHP-side date arithmetic on a MySQL timestamp inflates every session by
    // the offset — a 60-minute code silently granting 120 minutes.
    // Floored at 60 so a code seconds from expiry never hands the router a
    // zero or negative timeout.
    $remaining = max(60, (int) $row['seconds_remaining']);

    $replyAttrs = radius_encode_attr(R_ATTR_SESSION_TIMEOUT, pack('N', $remaining))
                . radius_encode_vsa(VENDOR_MIKROTIK, MT_UPTIME_LIMIT, pack('N', $remaining));

    $rate = (string) ($row['rate_limit'] ?? '');
    if ($rate === '') {
        $rate = (string) $settings['rate_limit'];
    }
    if ($rate !== '') {
        $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, $rate);
    }

    $reply = radius_build_reply(R_ACCESS_ACCEPT, $identifier, $requestAuth, $secret, $replyAttrs);
    socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
    radius_log("ACCEPT {$username}: {$remaining}s remaining" . ($rate !== '' ? ", rate {$rate}" : ''));

    try {
        db_run(fn(mysqli $d) => touch_credential($d, $username));
    } catch (Throwable $e) {
        radius_log('Could not update last_used_at: ' . $e->getMessage()); // non-fatal
    }
}
