<?php
/**
 * Integration test: starts the real daemon and sends it real accounting
 * packets, then asserts the usage landed in the database.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$authPort = 18140;
$acctPort = 18141;

$db = get_db();
$db->query('DELETE FROM radius_sessions');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $authPort,
    'radius_acct_port' => (string) $acctPort,
    'radius_nas_ip' => '127.0.0.1',
]);

$root = dirname(__DIR__);
$daemonLog = $root . '/logs/test-acct-daemon.log';
@unlink($daemonLog);
$logStart = is_file($root . '/logs/radius.log') ? filesize($root . '/logs/radius.log') : 0;

$descriptors = [1 => ['file', $daemonLog, 'a'], 2 => ['file', $daemonLog, 'a']];
$proc = proc_open('php ' . escapeshellarg($root . '/radius_server.php'), $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
assert_true(is_resource($proc), 'the daemon process started');

// Wait for BOTH sockets to be announced before probing.
$ready = false;
for ($i = 0; $i < 80; $i++) {
    usleep(250000);
    $tail = @file_get_contents($root . '/logs/radius.log', false, null, $logStart);
    if ($tail !== false && strpos($tail, 'Accounting on UDP') !== false) {
        $ready = true;
        break;
    }
}
assert_true($ready, 'the daemon bound its accounting socket');

/**
 * Send one Accounting-Request and return ['ok' => bool, 'port' => int].
 *
 * 'port' is the SOURCE port the reply came from. socket_recvfrom() accepts a
 * datagram from any source port, so without asserting this a reply sent out of
 * the AUTH socket would still look like a pass here — while in production the
 * router silently ignores it, because the source port does not match the
 * accounting request it is waiting on. That is the property being pinned.
 */
function acct_probe(int $port, string $secret, array $attrs): array
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $body = '';
    foreach ($attrs as $type => $value) {
        $body .= radius_encode_attr($type, $value);
    }
    // An Accounting-Request authenticator is MD5 over the packet with the
    // authenticator field zeroed, plus the secret.
    $header = chr(R_ACCOUNTING_REQUEST) . chr($id) . pack('n', 20 + strlen($body));
    $auth = md5($header . str_repeat("\x00", 16) . $body . $secret, true);
    $packet = $header . $auth . $body;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);
    return [
        'ok' => $got !== false && strlen($buf) >= 20 && ord($buf[0]) === R_ACCOUNTING_RESPONSE,
        'port' => (int) $fromPort,
    ];
}

/**
 * Send an Accounting-Request signed with the WRONG secret. Returns true if the
 * daemon answered at all — it must not.
 */
function acct_probe_badly_signed(int $port, array $attrs): bool
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
    $body = '';
    foreach ($attrs as $type => $value) {
        $body .= radius_encode_attr($type, $value);
    }
    $header = chr(R_ACCOUNTING_REQUEST) . chr(random_int(0, 255)) . pack('n', 20 + strlen($body));
    // Well-formed in every respect except the authenticator.
    $packet = $header . md5($header . str_repeat("\x00", 16) . $body . 'not-the-secret', true) . $body;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $f = '';
    $fp = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $f, $fp);
    socket_close($sock);
    return $got !== false;
}

// Interim update: 1000 in, 2000 out.
$probe = acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-one',
    R_ATTR_USER_NAME => '55556666',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 2000),
]);
assert_true($probe['ok'], 'the daemon acknowledges an Accounting-Request');
assert_equals($acctPort, $probe['port'], 'the accounting reply comes from the accounting socket, not the auth socket');
usleep(400000);
assert_equals(3000, usage_bytes_for_code($db, '55556666'), 'the reported usage was recorded');

// Same session reports again with higher absolute counters — must overwrite.
acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-one',
    R_ATTR_USER_NAME => '55556666',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 4000),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 5000),
]);
usleep(400000);
assert_equals(9000, usage_bytes_for_code($db, '55556666'), 'a later report for the same session overwrites rather than accumulating');

// Over 4GB: gigawords must be folded in.
acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_STOP),
    R_ATTR_ACCT_SESSION_ID => 'session-big',
    R_ATTR_USER_NAME => '44443333',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 1),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 0),
]);
usleep(400000);
assert_equals(4294968296, usage_bytes_for_code($db, '44443333'), 'gigawords are folded into the recorded usage');

// --- forged usage must not be recorded -----------------------------------
// The NAS source-IP allowlist is an unauthenticated UDP check, and loopback is
// exempt from it outright (see below), so the shared secret is the ONLY control
// standing between a local or spoofing sender and a usage row. Once the quota
// is enforced, a forged 4GB counter disconnects an attendee.
//
// NOTE on the allowlist: this cannot be written as "set radius_nas_ip to a
// non-loopback address and prove a loopback packet is dropped". radius_server.php
// computes $isLocal and skips the allowlist for 127.0.0.1/::1 unconditionally,
// for the admin health-check probe — so a loopback accounting packet is accepted
// no matter what radius_nas_ip says. The authenticator check below is what
// actually closes that write path, and is therefore what gets pinned.
assert_true(!acct_probe_badly_signed($acctPort, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-forged',
    R_ATTR_USER_NAME => '77778888',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 4000000000),
]), 'a badly-signed Accounting-Request is not acknowledged');
usleep(400000);
assert_equals(0, usage_bytes_for_code($db, '77778888'), 'a badly-signed Accounting-Request writes no usage row');

// The auth socket must still work — adding accounting must not break login.
$authSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_set_option($authSock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
$id = random_int(0, 255);
$reqAuth = random_bytes(16);
$body = radius_encode_attr(R_ATTR_USER_NAME, '__healthcheck__')
      . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password('probe', $reqAuth, $secret));
$packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($body)) . $reqAuth . $body;
socket_sendto($authSock, $packet, strlen($packet), 0, '127.0.0.1', $authPort);
$buf = '';
$f = '';
$fp = 0;
$gotAuth = @socket_recvfrom($authSock, $buf, 4096, 0, $f, $fp);
socket_close($authSock);
assert_true($gotAuth !== false, 'the auth socket still answers after accounting was added');
// Same source-port property as the accounting probe: a reply out of the wrong
// socket carries the wrong source port and the router silently ignores it.
assert_equals($authPort, (int) $fp, 'the auth reply comes from the auth socket, not the accounting socket');

proc_terminate($proc);
proc_close($proc);

test_summary();
