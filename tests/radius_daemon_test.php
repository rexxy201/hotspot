<?php
/**
 * Integration test: starts the real daemon, speaks real RADIUS to it over UDP.
 *
 * Uses port 18120 and the test database so it never collides with a running
 * production daemon on 1812.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$port = 18120;

$db = get_db();
$db->query('DELETE FROM wifi_credentials');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $port,
    // Pin the accounting port too. Without this the daemon binds whatever the
    // settings table holds — the 1813 default — which collides with a real
    // local daemon and fails in a way that looks like a code bug.
    'radius_acct_port' => '18121',
    // Must be a real address: the daemon refuses to start with this unset,
    // because an unrestricted daemon lets any device brute-force codes over
    // CHAP (which never involves the shared secret). The daemon's loopback
    // bypass means this test's probes from 127.0.0.1 are accepted regardless.
    'radius_nas_ip' => '127.0.0.1',
    'rate_limit' => '5M/5M',
]);
issue_credential($db, '04829371', 60, '5M/5M');
issue_credential($db, '55556666', -5); // already expired

// Start the daemon against the test database.
$root = dirname(__DIR__);
$daemonLog = $root . '/logs/test-daemon.log';
@unlink($daemonLog); // keeps startup failures (STDERR) from a previous run out of the way
$descriptors = [1 => ['file', $daemonLog, 'a'], 2 => ['file', $daemonLog, 'a']];

// The daemon only mirrors log lines to stdout on an interactive terminal, so
// under proc_open its stdout redirect stays empty. logs/radius.log is the
// authoritative sink, and that is what the readiness wait below reads. It is a
// long-lived append-only file, so remember where it ends now and only look at
// what this run appends — otherwise a "Listening on UDP" line from an earlier
// run would report readiness instantly.
$radiusLog = $root . '/logs/radius.log';
$radiusLogOffset = is_file($radiusLog) ? (int) filesize($radiusLog) : 0;
// env = null means "inherit this process's environment". The test itself is
// launched with DB_NAME=wifi_portal_test, so the daemon picks up the test
// database automatically. Passing an explicit env array would replace the whole
// environment (losing PATH, and $_ENV is not always populated).
//
// bypass_shell makes the PHP process the direct child. On Windows proc_open
// otherwise launches it through cmd.exe, and proc_terminate would then kill
// only the shell — leaving an orphaned daemon holding the UDP port. The option
// is ignored on POSIX.
$proc = proc_open(
    'php ' . escapeshellarg($root . '/radius_server.php'),
    $descriptors,
    $pipes,
    $root,
    null,
    ['bypass_shell' => true]
);
assert_true(is_resource($proc), 'the daemon process started');

// Wait for the socket to be bound rather than guessing at a fixed delay: a
// probe sent to a port nobody is listening on does not wait out its receive
// timeout, it fails immediately on the ICMP port-unreachable, so a daemon that
// is slow to start would fail every probe in a few milliseconds.
$deadline = microtime(true) + 20;
$listening = false;
while (microtime(true) < $deadline) {
    clearstatcache(true, $radiusLog);
    $fresh = is_file($radiusLog)
        ? (string) @file_get_contents($radiusLog, false, null, $radiusLogOffset)
        : '';
    if (str_contains($fresh, 'Listening on UDP')) {
        $listening = true;
        break;
    }
    usleep(100000);
}
assert_true($listening, 'the daemon bound its UDP socket');
usleep(200000);

/** Send one Access-Request and return [code, attributes] or null on timeout. */
function radius_probe(int $port, string $secret, string $username, string $password): ?array
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $attrs = radius_encode_attr(R_ATTR_USER_NAME, $username)
           . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password($password, $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($attrs)) . $auth . $attrs;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $received = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);
    if ($received === false || strlen($buf) < 20) {
        return null;
    }
    return [ord($buf[0]), radius_parse_attributes(substr($buf, 20))];
}

$accept = radius_probe($port, $secret, '04829371', '04829371');
assert_true($accept !== null, 'the daemon replied to a valid Access-Request');
assert_equals(R_ACCESS_ACCEPT, $accept[0], 'a valid code is accepted');
assert_true(isset($accept[1][R_ATTR_SESSION_TIMEOUT]), 'the Accept carries a Session-Timeout');
$timeout = unpack('N', $accept[1][R_ATTR_SESSION_TIMEOUT])[1];
assert_true($timeout > 0 && $timeout <= 3600, 'Session-Timeout reflects the remaining time');
assert_true(isset($accept[1][R_ATTR_VENDOR_SPECIFIC]), 'the Accept carries Mikrotik vendor attributes');

$wrong = radius_probe($port, $secret, '04829371', '99999999');
assert_equals(R_ACCESS_REJECT, $wrong[0], 'a wrong password is rejected');

$expired = radius_probe($port, $secret, '55556666', '55556666');
assert_equals(R_ACCESS_REJECT, $expired[0], 'an expired credential is rejected');

$unknown = radius_probe($port, $secret, '00000000', '00000000');
assert_equals(R_ACCESS_REJECT, $unknown[0], 'an unknown code is rejected');

// A successful auth must be recorded.
$touched = $db->query("SELECT last_used_at FROM wifi_credentials WHERE username = '04829371'")->fetch_assoc();
assert_true($touched['last_used_at'] !== null, 'a successful auth updates last_used_at');

// Shut the daemon down.
$status = proc_get_status($proc);
if ($status['running']) {
    // proc_terminate sends SIGTERM; the daemon cleans up its PID file.
    proc_terminate($proc);
}
proc_close($proc);

test_summary();
