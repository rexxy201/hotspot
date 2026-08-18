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
require_once __DIR__ . '/lib/usage.php';

const LOG_DIR = __DIR__ . '/logs';
// Rotate at 8MB. The daemon can be flooded by any device on the event SSID,
// and a full disk would take MySQL and the portal down with it.
const LOG_MAX_BYTES = 8388608;

/**
 * Whether $nasIp means "accept a packet from any source" rather than a real
 * single address to match against.
 *
 * Blank is the documented wildcard (see Admin -> Wi-Fi & RADIUS's field
 * hint: "Leave blank to accept any source"). "0.0.0.0" is treated exactly
 * the same way — it LOOKS like a wildcard/placeholder value and is exactly
 * what got typed into this field once already (a router IP genuinely was
 * not known yet), but as a literal string it is a strict allowlist of
 * "packets whose source address is 0.0.0.0" — which no real packet ever
 * is, so every real login attempt was silently dropped. Treating both the
 * same closes that whole failure mode rather than just this one instance
 * of it.
 */
function nas_ip_unrestricted(string $nasIp): bool
{
    return $nasIp === '' || $nasIp === '0.0.0.0';
}

/**
 * Whether stdout looks like a terminal a person is reading.
 *
 * posix_isatty() is absent on Windows and on PHP builds without ext-posix, so
 * fall back to assuming non-interactive: under a supervisor that is both the
 * common case and the safe one (it suppresses the duplicate write).
 */
function radius_stdout_is_interactive(): bool
{
    static $interactive = null;
    if ($interactive === null) {
        $interactive = function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
    return $interactive;
}

function radius_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    // Only mirror to stdout for an interactive run. Under a supervisor, stdout
    // is redirected into logs/radius.log — the same file written below — which
    // would double every line AND defeat rotation, because the supervisor keeps
    // appending to the renamed inode after a rollover.
    if (radius_stdout_is_interactive()) {
        echo $line;
    }
    if (!is_dir(LOG_DIR)) {
        return;
    }
    $path = LOG_DIR . '/radius.log';

    // Only stat periodically — this runs on every logged packet.
    static $writes = 0;
    if ((++$writes % 100) === 0) {
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > LOG_MAX_BYTES) {
            // Single generation is enough: this is a live-tail diagnostic log,
            // not an audit trail.
            @rename($path, LOG_DIR . '/radius.log.1');
        }
    }

    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log ignored packets without letting a flood fill the disk: one line per
 * source IP (per reason), then a single summary per minute.
 *
 * $reason describes why the packet was dropped. Left empty it defaults to the
 * source-IP allowlist wording and names the trusted router. Every drop path
 * reachable from the wire must come through here — a direct radius_log() call
 * writes a line per packet and can flood the log staff read during the event.
 */
function radius_log_drop(string $from, string $expected, string $reason = ''): void
{
    static $seen = [];

    // Track each reason separately, so a flood of one kind cannot mask the
    // first sighting of another.
    $key = $reason === '' ? $from : $from . '|' . $reason;
    $why = $reason !== ''
        ? $reason
        : 'trusted router is ' . ($expected !== '' ? $expected : 'not set');

    $now = time();
    // Table full: stay silent about new sources rather than clearing the table.
    // Clearing would return every tracked source to the "first seen" branch and
    // resume full-rate logging — the opposite of what this function is for.
    // A flood from many (or spoofed) sources is exactly when the cap matters.
    if (!isset($seen[$key]) && count($seen) >= 256) {
        return;
    }
    if (!isset($seen[$key])) {
        $seen[$key] = ['since' => $now, 'count' => 0];
        radius_log("Ignored packet from {$from} ({$why})");
        return;
    }
    $seen[$key]['count']++;
    if ($now - $seen[$key]['since'] >= 60) {
        radius_log("Ignored {$seen[$key]['count']} further packets from {$from} in the last minute ({$why})");
        $seen[$key] = ['since' => $now, 'count' => 0];
    }
}

/**
 * Make a wire-supplied value safe to write into a line-oriented log: strip
 * anything non-printable (a newline would let a crafted username forge whole
 * log entries) and cap the length.
 */
function radius_log_safe(string $value, int $max = 64): string
{
    $clean = preg_replace('/[^\x20-\x7E]/', '.', substr($value, 0, $max));
    return $clean === '' ? '(empty)' : $clean;
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
$acctPort = (int) $settings['radius_acct_port'];
$allowedNasIp = (string) $settings['radius_nas_ip'];

if ($secret === '') {
    fwrite(STDERR, "[RADIUS] radius_secret is not set. Configure it in Admin -> RADIUS, then start the daemon.\n");
    exit(1);
}

if (nas_ip_unrestricted($allowedNasIp)) {
    // Warn loudly, but do NOT exit: the live-reload block below (search
    // "Re-read the trusted router IP") already accepts this same value
    // while the daemon is already running — an admin can save the field
    // blank from the UI and the daemon just keeps going with a wider
    // allowlist. Exiting only here, at startup, meant that exact same
    // saved value crash-looped the daemon on its next restart (systemd
    // auto-restart, a server reboot, or the admin's own "Restart daemon"
    // button): found live, 2026-08-18 — every start attempt exited
    // immediately and took every real attendee offline completely,
    // instead of merely running under the already-accepted reduced
    // defense-in-depth this value has always meant. Consistency between
    // "just started" and "already running" matters more than a hard stop.
    radius_log('WARNING: radius_nas_ip is not restricting anything (it is blank or 0.0.0.0) — accepting RADIUS packets from ANY source. CHAP does not involve the shared secret, so anyone on the network could attempt to brute-force attendee codes. Set the router\'s real public IP in Admin -> Wi-Fi & RADIUS once you have a stable one.');
}

if ($bindPort < 1 || $bindPort > 65535) {
    fwrite(STDERR, "[RADIUS] radius_auth_port is not a valid port ({$bindPort}). Set it in Admin -> Wi-Fi & RADIUS.\n");
    exit(1);
}

if ($acctPort < 1 || $acctPort > 65535) {
    fwrite(STDERR, "[RADIUS] radius_acct_port is not a valid port ({$acctPort}).\n");
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

$acctSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($acctSock === false) {
    fwrite(STDERR, '[RADIUS] accounting socket_create failed: ' . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}
if (!socket_bind($acctSock, '0.0.0.0', $acctPort)) {
    fwrite(STDERR, "[RADIUS] cannot bind UDP {$acctPort}: " . socket_strerror(socket_last_error($acctSock)) . "\n");
    exit(1);
}

// The loop waits with socket_select(), so these receives only run on a socket
// that is already readable. The 1-second timeout is a belt-and-braces guard so
// a spurious wakeup can never block the daemon and stall the restart flag.
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
socket_set_option($acctSock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

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
radius_log("Accounting on UDP 0.0.0.0:{$acctPort}");
radius_log('Trusted router IP: ' . (nas_ip_unrestricted($allowedNasIp) ? 'any (not restricted)' : $allowedNasIp));

$lastSettingsReload = time();

while (true) {
    // The admin RADIUS Log page drops this flag to request a restart. Only
    // exit if we actually removed it, otherwise an undeletable flag would trap
    // us in a respawn loop.
    if (is_file($restartFlag)) {
        if (@unlink($restartFlag)) {
            radius_log('Restart requested from the admin UI — exiting for the supervisor to respawn.');
            // Drop the pid file on the way out, exactly as the SIGTERM handler
            // does. A stale pid pointing at a dead process makes the
            // diagnostics page report "it crashed or was killed" during the
            // second or two between this exit and the supervisor's respawn.
            @unlink(LOG_DIR . '/radius.pid');
            exit(0);
        }
        static $flagWarned = false;
        if (!$flagWarned) {
            $flagWarned = true;
            radius_log('Restart flag present but could not be deleted (permissions?) — ignoring.');
        }
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
                $newNasIp = (string) $fresh['radius_nas_ip'];
                radius_log('Trusted router IP changed to: ' . (nas_ip_unrestricted($newNasIp) ? 'any (not restricted)' : $newNasIp));
                if (nas_ip_unrestricted($newNasIp) && !nas_ip_unrestricted($allowedNasIp)) {
                    radius_log('WARNING: radius_nas_ip was just widened to unrestricted — accepting RADIUS packets from ANY source. CHAP does not involve the shared secret, so anyone on the network could attempt to brute-force attendee codes.');
                }
                $allowedNasIp = $newNasIp;
            }
            $settings = $fresh;
        } catch (Throwable $e) {
            radius_log('Could not reload settings: ' . $e->getMessage());
        }
    }

    // Wait on both sockets at once. The 1-second timeout keeps the restart-flag
    // and settings-reload checks above running on schedule when the network is
    // quiet.
    $read = [$sock, $acctSock];
    $write = null;
    $except = null;
    $ready = @socket_select($read, $write, $except, 1);
    if ($ready === false) {
        // A persistent select error would otherwise spin this loop flat out:
        // nothing else here blocks. Pause briefly so a broken socket costs the
        // VPS a little latency rather than a whole CPU core.
        usleep(200000);
        continue;
    }
    if ($ready === 0) {
        continue;
    }

    // From here on, `continue` continues this foreach — skip the current packet
    // and move to the other ready socket. The while loop re-runs immediately
    // afterwards, so the restart-flag and settings checks are never starved.
    foreach ($read as $active) {
        $buf = '';
        $from = '';
        $fromPort = 0;
        $received = @socket_recvfrom($active, $buf, 4096, 0, $from, $fromPort);
        if ($received === false || $received < 20) {
            continue; // receive timeout, or a runt packet
        }

        // Loopback is always allowed: the admin "Test connectivity" probe comes
        // from 127.0.0.1, not from the router. This applies to the accounting
        // socket exactly as it does to the auth socket.
        $isLocal = ($from === '127.0.0.1' || $from === '::1');
        if (!nas_ip_unrestricted($allowedNasIp) && !$isLocal && $from !== $allowedNasIp) {
            radius_log_drop($from, $allowedNasIp);
            continue;
        }

        try {
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
                // Verify before doing anything else. An unauthenticated packet is
                // dropped silently and NOT acknowledged — acknowledging would tell
                // an attacker their guess was accepted, and there is no legitimate
                // sender that cannot compute this.
                if (!radius_verify_accounting(substr($buf, 0, $declaredLength), $secret)) {
                    // Rate-limited like every other wire-reachable log line: one
                    // line per source per minute. Logging each packet directly
                    // would let anything that clears the allowlist flood the
                    // operational log staff read during the event.
                    radius_log_drop($from, $allowedNasIp, 'Accounting-Request with a bad authenticator');
                    continue;
                }

                $sessionId = $attrs[R_ATTR_ACCT_SESSION_ID] ?? '';
                $acctUser = $attrs[R_ATTR_USER_NAME] ?? '';
                $statusType = radius_uint32($attrs[R_ATTR_ACCT_STATUS_TYPE] ?? '');

                // Acknowledge first, unconditionally: the router retries until it
                // is acknowledged, and a DB problem on our side must not turn into
                // a retry storm.
                $reply = radius_build_reply(R_ACCOUNTING_RESPONSE, $identifier, $requestAuth, $secret, '');
                socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);

                if ($sessionId !== '' && $acctUser !== '') {
                    // Counters are 32-bit; radius_octets_64() folds in the
                    // gigawords companion so a transfer over 4GB is not truncated.
                    $in = radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS);
                    $out = radius_octets_64($attrs, R_ATTR_ACCT_OUTPUT_OCTETS, R_ATTR_ACCT_OUTPUT_GIGAWORDS);
                    try {
                        db_run(fn(mysqli $d) => record_session_usage($d, $sessionId, $acctUser, $in, $out));
                        radius_log('ACCT ' . radius_log_safe($acctUser)
                            . ' type=' . $statusType
                            . ' in=' . $in . ' out=' . $out);
                    } catch (Throwable $e) {
                        radius_log('Could not record usage: ' . $e->getMessage());
                    }
                }
                continue;
            }

            if ($code !== R_ACCESS_REQUEST) {
                continue;
            }

            $username = $attrs[R_ATTR_USER_NAME] ?? '';
            $mac = $attrs[R_ATTR_CALLING_STATION] ?? '';

            // The admin health-check probes with this reserved username purely to prove
            // the daemon is listening; answer without touching the database. It comes
            // from 127.0.0.1, so require loopback — otherwise, with no NAS allowlist
            // set, any device on the SSID could reach this branch.
            if ($username === '__healthcheck__' && $isLocal) {
                $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                    radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'health-check ok'));
                socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
                continue;
            }

            radius_log('Access-Request user=' . radius_log_safe($username) . ' mac=' . radius_log_safe($mac) . " from={$from}");

            try {
                $row = db_run(fn(mysqli $d) => find_valid_credential($d, $username));
            } catch (Throwable $e) {
                radius_log('DB lookup failed: ' . $e->getMessage());
                $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                    radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Database error'));
                socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
                continue;
            }

            if ($row === null) {
                radius_log('REJECT ' . radius_log_safe($username) . ': unknown or expired');
                $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                    radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid or expired code'));
                socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
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
                radius_log('REJECT ' . radius_log_safe($username) . ': wrong password');
                $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                    radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid credentials'));
                socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
                continue;
            }

            // Data quota. 0 means unlimited.
            //
            // NOTE: the byte allowance is deliberately NOT called $remaining —
            // that name already holds the credential's seconds_remaining a few
            // lines below, and reusing it would silently send the router a
            // session length as a byte limit.
            $quotaMb = (int) ($settings['data_quota_mb'] ?? 0);
            $quotaBytes = $quotaMb * 1048576;
            $quotaRemaining = 0;

            if ($quotaBytes > 0) {
                try {
                    $used = db_run(fn(mysqli $d) => usage_bytes_for_code($d, $username));
                } catch (Throwable $e) {
                    // A usage lookup failure must not lock a paying attendee out, so
                    // fail open on the quota specifically — the credential itself has
                    // already been verified above.
                    radius_log('Could not read usage, allowing: ' . $e->getMessage());
                    $used = 0;
                }
                $quotaRemaining = $quotaBytes - $used;
                if ($quotaRemaining <= 0) {
                    radius_log('REJECT ' . radius_log_safe($username) . ': data quota exhausted');
                    $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                        radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Data limit reached'));
                    socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
                    continue;
                }
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

            // Session length rides on the standard Session-Timeout attribute (27),
            // which Mikrotik honours; there is no Mikrotik uptime-limit VSA.
            $replyAttrs = radius_encode_attr(R_ATTR_SESSION_TIMEOUT, pack('N', $remaining));

            // Ask for interim accounting every 5 minutes. Without this the router only
            // reports at session end, so a lost Stop packet loses the whole session's
            // usage and the admin Data column stays at zero all event.
            $replyAttrs .= radius_encode_attr(R_ATTR_ACCT_INTERIM_INTERVAL, pack('N', 300));

            $rate = (string) ($row['rate_limit'] ?? '');
            if ($rate === '') {
                $rate = (string) $settings['rate_limit'];
            }
            if ($rate !== '') {
                $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, $rate);
            }

            if ($quotaBytes > 0) {
                // Hand the router the REMAINING allowance so it disconnects the device
                // itself the moment the limit is hit, rather than the session running
                // on until the next login attempt.
                //
                // This must stay the LAST Mikrotik VSA appended: every Mikrotik VSA
                // rides inside attribute 26, and radius_parse_attributes() keeps only
                // the last of a repeated type — tests/radius_quota_test.php reads the
                // total limit that way.
                $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_TOTAL_LIMIT, pack('N', $quotaRemaining % 4294967296));
                if ($quotaRemaining >= 4294967296) {
                    // Over 4GB the limit needs its own gigawords companion, for the
                    // same reason the counters do.
                    $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_TOTAL_LIMIT_GIGAWORDS,
                        pack('N', intdiv($quotaRemaining, 4294967296)));
                }
            }

            $reply = radius_build_reply(R_ACCESS_ACCEPT, $identifier, $requestAuth, $secret, $replyAttrs);
            socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
            radius_log('ACCEPT ' . radius_log_safe($username) . ": {$remaining}s remaining" . ($rate !== '' ? ", rate {$rate}" : ''));

            try {
                db_run(fn(mysqli $d) => touch_credential($d, $username));
            } catch (Throwable $e) {
                radius_log('Could not update last_used_at: ' . $e->getMessage()); // non-fatal
            }
        } catch (Throwable $e) {
            radius_log('Unhandled error processing packet from ' . $from . ': ' . $e->getMessage());
            continue;
        }
    }
}
