<?php
/**
 * Operational health-check for the RADIUS daemon — unlike
 * lib/radius_protocol.php (deliberately free of sockets/DB/app state so it
 * stays unit-testable), this talks to a real socket and reads $settings.
 * Shared by admin/radius.php and setup.php so there's one implementation of
 * "is the daemon actually up" rather than two that can drift.
 */
require_once __DIR__ . '/radius_protocol.php';

/**
 * Probe the daemon over loopback and explain precisely what is wrong.
 *
 * Any reply — Accept or Reject — proves the daemon is listening; only a
 * timeout means nothing is there. The daemon answers the reserved
 * "__healthcheck__" username without touching the database, and accepts
 * loopback packets regardless of the trusted-router setting.
 *
 * @return array [bool $ok, string $message]
 */
function radius_diagnose(array $settings): array
{
    $port = (int) $settings['radius_auth_port'];
    $secret = (string) $settings['radius_secret'];

    if ($secret === '') {
        return [false, 'No shared secret is set. Enter one below and save it — the daemon exits immediately without a secret.'];
    }
    if (!extension_loaded('sockets')) {
        return [false, 'PHP ext-sockets is not enabled for the web server. The daemon also needs it in the CLI PHP binary.'];
    }

    $pidFile = dirname(__DIR__) . '/logs/radius.pid';
    $pidAlive = false;
    if (is_file($pidFile)) {
        $pid = (int) trim((string) @file_get_contents($pidFile));
        $pidAlive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $attrs = radius_encode_attr(R_ATTR_USER_NAME, '__healthcheck__')
           . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password('probe', $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($attrs)) . $auth . $attrs;
    @socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);

    if ($got !== false && strlen($buf) >= 20) {
        return [true, "The daemon is UP and answering on UDP {$port}. For live logins also confirm: (1) UDP {$port} is open inbound on the server firewall, and (2) the router's public IP below matches the router — the daemon ignores packets from anywhere else."];
    }

    if (!is_file($pidFile)) {
        return [false, "Nothing answered on UDP {$port} and there is no logs/radius.pid — the daemon has never been started. Run: bash start_radius.sh start"];
    }
    if (!$pidAlive) {
        return [false, "Nothing answered on UDP {$port} and the process in logs/radius.pid is gone — it crashed or was killed. Check the RADIUS Log page, then run: bash start_radius.sh restart"];
    }
    return [false, "The daemon process is alive but nothing answered on UDP {$port}. It probably could not bind the port (another process using it?). Check the RADIUS Log page."];
}
