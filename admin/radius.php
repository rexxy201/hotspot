<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

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

$error = '';
$notice = '';

// Download the router config as a .rsc file.
if (($_GET['download'] ?? '') === 'rsc') {
    $template = (string) file_get_contents(dirname(__DIR__) . '/deploy/mikrotik-setup.rsc');
    $portalHost = $_SERVER['HTTP_HOST'] ?? 'your-portal-domain';
    $out = strtr($template, [
        '__RADIUS_SECRET__' => (string) $settings['radius_secret'],
        '__VPS_IP__' => (string) ($_SERVER['SERVER_ADDR'] ?? 'YOUR_SERVER_IP'),
        '__AUTH_PORT__' => (string) $settings['radius_auth_port'],
        '__HS_PROFILE__' => 'hsprof1',
        '__PORTAL_HOST__' => $portalHost,
    ]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="eyif-radius.rsc"');
    echo $out;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'radius_auth_port' => (string) max(1, (int) ($_POST['radius_auth_port'] ?? 1812)),
        'radius_nas_ip' => trim((string) ($_POST['radius_nas_ip'] ?? '')),
        'session_minutes' => (string) max(1, (int) ($_POST['session_minutes'] ?? 720)),
        'rate_limit' => trim((string) ($_POST['rate_limit'] ?? '')),
    ];
    // Only overwrite the secret when a new one was actually typed, so saving
    // the form does not wipe it.
    $typedSecret = trim((string) ($_POST['radius_secret'] ?? ''));
    if ($typedSecret !== '') {
        $newSettings['radius_secret'] = $typedSecret;
    }
    save_settings($db, $newSettings);
    $settings = get_settings($db);
    $notice = 'RADIUS settings saved. Restart the daemon from the RADIUS Log page for the new port to take effect.';
}

[$diagOk, $diagMessage] = radius_diagnose($settings);
$activeCount = count_active_credentials($db);

admin_layout_start('radius.php', 'Wi-Fi & RADIUS', $settings);
?>
<div class="page-header">
  <div>
    <h1>Wi-Fi &amp; RADIUS</h1>
    <p class="page-sub"><?= (int) $activeCount ?> active Wi-Fi <?= $activeCount === 1 ? 'credential' : 'credentials' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn-link secondary" href="?download=rsc">Download router config</a>
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<section class="panel" style="margin-bottom:var(--space-4)">
  <div class="panel-header"><h2>Daemon status</h2></div>
  <p class="<?= $diagOk ? 'diag-ok' : 'error' ?>"><?= htmlspecialchars($diagMessage) ?></p>
</section>

<section class="panel">
  <div class="panel-header"><h2>Settings</h2></div>
  <form method="POST" class="settings-form">
    <div class="field">
      <label for="radius_secret">Shared secret</label>
      <input type="text" id="radius_secret" name="radius_secret"
             placeholder="<?= $settings['radius_secret'] !== '' ? 'Saved — type to replace' : 'Not set yet' ?>"
             autocomplete="off">
      <p class="field-hint">Must match the secret on the router. Stored encrypted. Leave blank to keep the current one. Generate one with: <code>openssl rand -base64 24</code></p>
    </div>
    <div class="field">
      <label for="radius_auth_port">Authentication port</label>
      <input type="text" id="radius_auth_port" name="radius_auth_port" inputmode="numeric"
             value="<?= htmlspecialchars($settings['radius_auth_port']) ?>">
      <p class="field-hint">Standard RADIUS auth port is 1812.</p>
    </div>
    <div class="field">
      <label for="radius_nas_ip">Router public IP</label>
      <input type="text" id="radius_nas_ip" name="radius_nas_ip"
             value="<?= htmlspecialchars($settings['radius_nas_ip']) ?>" placeholder="e.g. 102.89.x.x">
      <p class="field-hint">The daemon ignores RADIUS packets from any other address. Leave blank to accept any source (testing only).</p>
    </div>
    <div class="field">
      <label for="session_minutes">Session length (minutes)</label>
      <input type="text" id="session_minutes" name="session_minutes" inputmode="numeric"
             value="<?= htmlspecialchars($settings['session_minutes']) ?>">
      <p class="field-hint">How long a code stays valid. 720 = 12 hours, enough for one event day. Existing codes keep the length they were issued with.</p>
    </div>
    <div class="field">
      <label for="rate_limit">Speed cap</label>
      <input type="text" id="rate_limit" name="rate_limit"
             value="<?= htmlspecialchars($settings['rate_limit']) ?>" placeholder="e.g. 5M/5M">
      <p class="field-hint">Upload/download limit per device, applied at login. Leave blank for uncapped.</p>
    </div>
    <button type="submit">Save RADIUS settings</button>
  </form>
</section>
<?php admin_layout_end(); ?>
