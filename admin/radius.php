<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';
require_once __DIR__ . '/../lib/radius_diagnostics.php';
require_once __DIR__ . '/../lib/portal_host.php';
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
    // resolve_portal_host() prefers the admin-configured PORTAL_HOST (Setup
    // > Network) over this request's Host header — see lib/portal_host.php
    // for why trusting the request alone was a real bug (bare-IP
    // troubleshooting, a staging alias, or a later domain change would all
    // silently bake the wrong host into this router-shell script).
    $portalHost = resolve_portal_host();
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

// Download the hotspot login-page bridge, with this portal's real host
// filled in. Without this file uploaded to the router, a device's first
// request never reaches index.php at all — it sees Mikrotik's own built-in
// login form instead, and nothing in this app (RADIUS, the raffle form,
// silent reconnect) ever engages. This was in the original design spec but
// never actually shipped as a file — added after comparing against a
// sibling project that does ship one.
if (($_GET['download'] ?? '') === 'login-html') {
    $template = (string) file_get_contents(dirname(__DIR__) . '/deploy/mikrotik-login.html');
    // Same resolver as the .rsc download above — same host in both files,
    // sourced from the same one place. See lib/portal_host.php.
    $portalHost = resolve_portal_host();
    $out = strtr($template, ['__PORTAL_HOST__' => $portalHost]);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="login.html"');
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
    <button type="button" id="open-login-instructions" class="btn-link secondary">Download hotspot login page</button>
  </div>
</div>

<p class="table-note" style="margin-bottom:var(--space-4)">
  <strong>Both files need to reach the router before any of this works.</strong>
  The router config sets up RADIUS; the hotspot login page is what actually sends a
  connecting phone to this portal in the first place — without it uploaded to the
  router's hotspot files as <code>login.html</code>, devices see Mikrotik's own
  built-in login form and never reach this app at all. See Phase 8 in
  deploy/GO-LIVE.md.
</p>
<p class="table-note" style="margin-bottom:var(--space-4)">
  Both downloads above will point at <code><?= htmlspecialchars(resolve_portal_host()) ?></code>.
  <?php if (portal_host_is_configured()): ?>
    That's the Portal domain configured in Setup &rarr; Network — change it there
    (re-run setup.php) and both downloads pick up the new value immediately.
  <?php else: ?>
    That's auto-detected from the address you're viewing this page at right now,
    <strong>not</strong> a saved setting — if you ever load /admin/ over the
    server's bare IP or a different alias, a download taken from there would carry
    the wrong host. Set "Portal domain" in Setup &rarr; Network (re-run setup.php,
    PIN-gated) to fix this permanently.
  <?php endif; ?>
</p>

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

<?php // Loads the file into a hidden iframe on click rather than letting the
      // trigger button be a plain link: Content-Disposition:attachment
      // saves the file without navigating this tab away, so the
      // instructions modal below can open in that same click instead of
      // racing a page unload. ?>
<iframe id="login-html-download-frame" hidden aria-hidden="true" title="Hotspot login page download"></iframe>

<?php // Router setup walkthrough, shown every time "Download hotspot login
      // page" is clicked. Deliberately NOT dismissible the normal way (no
      // × button, no backdrop click, no Escape) — closing requires typing
      // the confirmation phrase below, so it can't be reflexively clicked
      // past and forgotten before the router is actually configured. See
      // the script at the bottom of this file. ?>
<div class="modal-overlay" id="login-instructions-modal" hidden>
  <div class="modal-card modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="login-instructions-title">
    <h2 id="login-instructions-title" tabindex="-1">Configure the router — start to finish</h2>
    <p class="table-note">
      Your download just started (check your browser's downloads) — pre-filled with
      this portal's host, <code><?= htmlspecialchars(resolve_portal_host()) ?></code>.
      Work through every step below on the router itself before closing this.
    </p>

    <ol class="modal-steps">
      <li>
        <strong>Save the downloaded file as <code>login.html</code>.</strong>
        <p>It already started downloading behind this window. If you need it again later, close this and click the button once more.</p>
      </li>
      <li>
        <strong>Find your two hotspot profile names on the router.</strong>
        <p>In Winbox/WebFig's terminal: <code>/ip hotspot profile print</code> and <code>/ip hotspot user profile print</code>. Usually <code>hsprof1</code> and <code>default</code> — the "Download router config" .rsc file assumes exactly that, so edit it first if yours are named differently.</p>
      </li>
      <li>
        <strong>Upload <code>login.html</code> to the router.</strong>
        <p>Files (drag the download in), or IP &rarr; Hotspot &rarr; Server Profiles &rarr; (your profile) &rarr; General tab &rarr; HTML Directory. RouterOS ships a default <code>hotspot</code> folder — if your profile points there, replace that folder's own <code>login.html</code> with this one.</p>
      </li>
      <li>
        <strong>Turn on the login method this app needs.</strong>
        <p>IP &rarr; Hotspot &rarr; Server Profiles &rarr; (your profile) &rarr; Login tab &rarr; check <strong>HTTP CHAP</strong> and/or <strong>HTTP PAP</strong>. Without one of these the router rejects the credentials this portal posts to it after sign-up.</p>
      </li>
      <li>
        <strong>Allow this portal through the Walled Garden.</strong>
        <p>IP &rarr; Hotspot &rarr; Walled Garden &rarr; add <code>dst-host = <?= htmlspecialchars(resolve_portal_host()) ?></code>, action <code>allow</code>. Already done automatically if you've imported the "Download router config" .rsc file below — check there first rather than adding a duplicate entry.</p>
      </li>
      <li>
        <strong>Import the RADIUS config too, if you haven't yet.</strong>
        <p>"Download router config" above gives you the matching <code>.rsc</code> file — that one sets up RADIUS itself; this one only routes phones to this portal in the first place. Both are required together.</p>
      </li>
      <li>
        <strong>Test it on a real phone.</strong>
        <p>Forget/disconnect this Wi-Fi network on a phone, rejoin it, and confirm it lands on <em>this</em> portal — not Mikrotik's own plain login form. If it still shows Mikrotik's default page, the HTML Directory in step 3 isn't pointing at the file you just uploaded.</p>
      </li>
    </ol>

    <div class="modal-lock">
      <div class="field">
        <label for="login-instructions-confirm">Type <code>configured</code> once every step above is actually done on the router:</label>
        <input type="text" id="login-instructions-confirm" autocomplete="off" spellcheck="false" placeholder="configured">
      </div>
      <button type="button" id="login-instructions-confirm-btn" disabled>I'm done configuring the router</button>
    </div>
  </div>
</div>

<script>
(function () {
  var openBtn = document.getElementById('open-login-instructions');
  var modal = document.getElementById('login-instructions-modal');
  var frame = document.getElementById('login-html-download-frame');
  var title = document.getElementById('login-instructions-title');
  var confirmInput = document.getElementById('login-instructions-confirm');
  var confirmBtn = document.getElementById('login-instructions-confirm-btn');
  if (!openBtn || !modal || !confirmInput || !confirmBtn) return;

  var CONFIRM_PHRASE = 'configured';

  var checkMatch = function () {
    confirmBtn.disabled = confirmInput.value.trim().toLowerCase() !== CONFIRM_PHRASE;
  };

  openBtn.addEventListener('click', function () {
    // Cache-bust so a second click re-downloads instead of the browser
    // silently no-op'ing an unchanged iframe src.
    if (frame) frame.src = 'radius.php?download=login-html&_=' + Date.now();
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    confirmInput.value = '';
    checkMatch();
    // Focus the heading, not the input — this modal is meant to be read
    // start to finish before anyone reaches for the confirmation field.
    if (title) title.focus();
  });

  confirmInput.addEventListener('input', checkMatch);
  confirmInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !confirmBtn.disabled) {
      event.preventDefault();
      confirmBtn.click();
    }
  });

  confirmBtn.addEventListener('click', function () {
    if (confirmInput.value.trim().toLowerCase() !== CONFIRM_PHRASE) return;
    modal.hidden = true;
    document.body.style.overflow = '';
    openBtn.focus();
  });

  // No backdrop-click, Escape-key, or × handler is wired up on purpose —
  // see the comment above the modal markup. Typing the phrase and clicking
  // the button above is the only way this closes.
})();
</script>
<?php admin_layout_end(); ?>
