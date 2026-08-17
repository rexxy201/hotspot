<?php
/**
 * Integration test: a code over its quota is rejected, and a code under it is
 * accepted with a Mikrotik-Total-Limit carrying what is left.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$authPort = 18150;

$db = get_db();
$db->query('DELETE FROM radius_sessions');
$db->query('DELETE FROM wifi_credentials');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $authPort,
    'radius_acct_port' => '18151',
    'radius_nas_ip' => '127.0.0.1',
    'data_quota_mb' => '100',            // 100 MB = 104857600 bytes
]);

issue_credential($db, '10001000', 600);  // under quota
issue_credential($db, '20002000', 600);  // will be over quota
record_session_usage($db, 'over-1', '20002000', 104857600, 1);

$root = dirname(__DIR__);
$daemonLog = $root . '/logs/test-quota-daemon.log';
@unlink($daemonLog);
$logStart = is_file($root . '/logs/radius.log') ? filesize($root . '/logs/radius.log') : 0;
$descriptors = [1 => ['file', $daemonLog, 'a'], 2 => ['file', $daemonLog, 'a']];
$proc = proc_open('php ' . escapeshellarg($root . '/radius_server.php'), $descriptors, $pipes, $root, null, ['bypass_shell' => true]);

$ready = false;
for ($i = 0; $i < 80; $i++) {
    usleep(250000);
    $tail = @file_get_contents($root . '/logs/radius.log', false, null, $logStart);
    if ($tail !== false && strpos($tail, 'Listening on UDP') !== false) { $ready = true; break; }
}
assert_true($ready, 'the daemon started');

function auth_probe(int $port, string $secret, string $user): ?array
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $body = radius_encode_attr(R_ATTR_USER_NAME, $user)
          . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password($user, $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($body)) . $auth . $body;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $f = '';
    $fp = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $f, $fp);
    socket_close($sock);
    if ($got === false || strlen($buf) < 20) {
        return null;
    }
    return [ord($buf[0]), radius_parse_attributes(substr($buf, 20))];
}

/** Pull the Mikrotik-Total-Limit out of a reply's vendor attributes. */
function total_limit_from(array $parsed): ?int
{
    if (!isset($parsed[R_ATTR_VENDOR_SPECIFIC])) {
        return null;
    }
    $inner = $parsed[R_ATTR_VENDOR_SPECIFIC];
    if (ord($inner[4]) !== MT_TOTAL_LIMIT) {
        return null;
    }
    return radius_uint32(substr($inner, 6, 4));
}

$under = auth_probe($authPort, $secret, '10001000');
assert_equals(R_ACCESS_ACCEPT, $under[0], 'a code under its quota is accepted');

$over = auth_probe($authPort, $secret, '20002000');
assert_equals(R_ACCESS_REJECT, $over[0], 'a code over its quota is rejected');

// With no usage recorded, the full quota should be offered as the limit.
$limit = total_limit_from($under[1]);
assert_true($limit !== null, 'the Accept carries a Mikrotik-Total-Limit');
assert_equals(104857600, $limit, 'the limit is the full quota when nothing has been used');

// After using half, the limit offered should be the remainder.
record_session_usage($db, 'half-1', '10001000', 52428800, 0);
$half = auth_probe($authPort, $secret, '10001000');
assert_equals(R_ACCESS_ACCEPT, $half[0], 'a half-used code is still accepted');
assert_equals(52428800, total_limit_from($half[1]), 'the limit offered is what is left, not the full quota');

// Quota off: no limit attribute should be sent at all.
save_settings($db, ['data_quota_mb' => '0']);
sleep(11);   // the daemon re-reads settings every 10 seconds
$noQuota = auth_probe($authPort, $secret, '20002000');
assert_equals(R_ACCESS_ACCEPT, $noQuota[0], 'with the quota off, a previously over-quota code is accepted again');

proc_terminate($proc);
proc_close($proc);

test_summary();
