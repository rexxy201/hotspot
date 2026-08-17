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

/** Send one Accounting-Request and return true if it was acknowledged. */
function acct_probe(int $port, string $secret, array $attrs): bool
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
    return $got !== false && strlen($buf) >= 20 && ord($buf[0]) === R_ACCOUNTING_RESPONSE;
}

// Interim update: 1000 in, 2000 out.
$ok = acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-one',
    R_ATTR_USER_NAME => '55556666',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 2000),
]);
assert_true($ok, 'the daemon acknowledges an Accounting-Request');
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

proc_terminate($proc);
proc_close($proc);

test_summary();
