<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';
require_once __DIR__ . '/../lib/radius_diagnostics.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

$error = '';
$notice = '';

// Download the router config as a .rsc file.
if (($_GET['download'] ?? '') === 'rsc') {
    $template = (string) file_get_contents(dirname(__DIR__) . '/deploy/mikrotik-setup.rsc');
    // HTTP_HOST is client-supplied and lands inside a quoted RouterOS string
    // that the admin pastes into a router shell. Anything but a plain
    // host[:port] is rejected rather than escaped, so a crafted Host header
    // cannot close the quote and append commands.
    $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $portalHost = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $rawHost) === 1
        ? $rawHost
        : 'your-portal-domain';
    $out = strtr($template, [
        '__RADIUS_SECRET__' => (string) $settings['radius_secret'],
        '__VPS_IP__' => (string) ($_SERVER['SERVER_ADDR'] ?? 'YOUR_SERVER_IP'),
        '__AUTH_PORT__' => (string) $settings['radius_auth_port'],
        '__ACCT_PORT__' => (string) $settings['radius_acct_port'],
        // Two different RouterOS namespaces, two different default names:
        // "/ip hotspot profile" (server profile) vs "/ip hotspot user profile".
        '__HS_PROFILE__' => 'hsprof1',
        '__HS_USER_PROFILE__' => 'default',
        '__PORTAL_HOST__' => $portalHost,
    ]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="eyif-radius.rsc"');
    // The body contains the plaintext shared secret — keep it out of caches.
    header('Cache-Control: no-store, private');
    echo $out;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $error = 'That form had expired — nothing was saved. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typedSecret = trim((string) ($_POST['radius_secret'] ?? ''));
    if ($typedSecret !== '' && preg_match('/[\s\x00-\x1F]/', $typedSecret) === 1) {
        // The secret is written into a RouterOS config line; whitespace would
        // truncate it there and a control character could break out of it.
        $error = 'The shared secret cannot contain spaces, tabs or line breaks. Generate one with: openssl rand -base64 24 (then remove any trailing newline).';
    } else {
        $newSettings = [
            'radius_auth_port' => (string) max(1, (int) ($_POST['radius_auth_port'] ?? 1812)),
            'radius_nas_ip' => trim((string) ($_POST['radius_nas_ip'] ?? '')),
            'session_minutes' => (string) max(1, (int) ($_POST['session_minutes'] ?? 720)),
            'rate_limit' => trim((string) ($_POST['rate_limit'] ?? '')),
            // An unchecked checkbox sends nothing, so absence means off.
            'silent_login_enabled' => isset($_POST['silent_login_enabled']) ? '1' : '0',
            'data_quota_mb' => (string) max(0, (int) ($_POST['data_quota_mb'] ?? 0)),
        ];
        // Only overwrite the secret when a new one was actually typed, so saving
        // the form does not wipe it.
        if ($typedSecret !== '') {
            $newSettings['radius_secret'] = $typedSecret;
        }
        save_settings($db, $newSettings);
        $settings = get_settings($db);
        $notice = 'RADIUS settings saved. Restart the daemon from the RADIUS Log page for the new port to take effect.';
    }
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
    <?= csrf_field() ?>
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
    <div class="field">
      <label for="data_quota_mb">Data limit per code (MB)</label>
      <input type="text" id="data_quota_mb" name="data_quota_mb" inputmode="numeric"
             value="<?= htmlspecialchars($settings['data_quota_mb']) ?>">
      <p class="field-hint">How much a single code may download and upload in total before it is cut off. <code>0</code> means unlimited. The router enforces this, so a device is disconnected the moment it hits the limit — it does not wait for the next login. Usage is shown per code in Raffle Entries.</p>
    </div>
    <div class="field">
      <label for="silent_login_enabled" class="checkbox-label">
        <input type="checkbox" id="silent_login_enabled" name="silent_login_enabled" value="1"
               <?= $settings['silent_login_enabled'] === '1' ? 'checked' : '' ?>>
        Reconnect known devices without the form
      </label>
      <p class="field-hint">When a device comes back to the portal still holding a valid code, connect it straight through instead of asking for its details again. Expired codes always get the form. Turn this off if device detection misbehaves.</p>
    </div>
    <button type="submit">Save RADIUS settings</button>
  </form>
</section>
<?php admin_layout_end(); ?>
