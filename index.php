<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/credentials.php';

$db = get_db();
$settings = get_settings($db);

// Preserve Mikrotik's hotspot redirect parameters across the form submission
// so connect.php can hand the attendee back to Mikrotik's own login-only URL.
$mikrotikParams = [
    'mac' => $_GET['mac'] ?? '',
    'ip' => $_GET['ip'] ?? '',
    'link-login-only' => $_GET['link-login-only'] ?? '',
    'link-orig' => $_GET['link-orig'] ?? '',
];

// --- Silent login ---------------------------------------------------------
// If this device already holds a valid credential, connect it straight through
// instead of asking for details it has already given. This is the phone that
// dropped off the Wi-Fi and came back, not a new attendee.
//
// The MAC arrives in the query string, so it is client-supplied and unverifiable
// from here (the portal sits behind the router's NAT). Two rules contain that:
//   1. We only ever REUSE a still-valid credential. A forged MAC cannot create
//      or renew one, so at worst it rides a session that already exists.
//   2. The code is never displayed on this path — only posted to the router. A
//      spoofed MAC therefore yields Wi-Fi that was free anyway, not somebody
//      else's prize-draw code.
$silentCode = '';
$silentLoginUrl = '';

// An explicit "not you?" click always wins — a borrowed or handed-on phone must
// be able to reach the form.
$forget = ($_GET['forget'] ?? '') === '1';

if (!$forget && $settings['silent_login_enabled'] === '1' && $mikrotikParams['mac'] !== '') {
    $known = find_valid_credential_by_mac($db, $mikrotikParams['mac']);
    if ($known !== null) {
        // Same validation the success page applies: only auto-post to the
        // configured gateway, never to a host named in the query string.
        $candidate = $mikrotikParams['link-login-only'];
        $isGateway = filter_var($candidate, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($candidate, PHP_URL_SCHEME), ['http', 'https'], true)
            && parse_url($candidate, PHP_URL_HOST) === MIKROTIK_GATEWAY_HOST;
        if ($isGateway) {
            $silentCode = (string) $known['username'];
            $silentLoginUrl = $candidate;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($settings['event_name']) ?> Wi-Fi</title>
<link rel="stylesheet" href="assets/style.css">
<style>:root { --brand-color: <?= htmlspecialchars($settings['brand_color']) ?>; }</style>
</head>
<body>
<div class="portal">
  <div class="portal-card">
    <?php if ($silentLoginUrl !== ''): ?>
      <?php if ($settings['event_logo_path']): ?>
        <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
      <?php endif; ?>
      <h1>Welcome back</h1>
      <p class="intro">Reconnecting you to <?= htmlspecialchars($settings['event_name']) ?> Wi-Fi…</p>
      <?php // The code is deliberately NOT shown here — see the note above. ?>
      <form id="silent-login" method="POST" action="<?= htmlspecialchars($silentLoginUrl) ?>">
        <input type="hidden" name="username" value="<?= htmlspecialchars($silentCode) ?>">
        <input type="hidden" name="password" value="<?= htmlspecialchars($silentCode) ?>">
        <button type="submit">Continue</button>
      </form>
      <script>document.getElementById('silent-login').submit();</script>
      <p class="hint"><a href="index.php?forget=1">Not you? Sign in with your own details</a></p>
    <?php else: ?>
    <?php if ($settings['event_logo_path']): ?>
      <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
    <?php endif; ?>
    <?php // With a logo present the event name is already visible, so the
          // heading is hidden visually but kept for screen readers. ?>
    <h1<?= $settings['event_logo_path'] ? ' class="visually-hidden"' : '' ?>><?= htmlspecialchars($settings['event_name']) ?></h1>
    <p class="tagline"><?= htmlspecialchars($settings['event_tagline']) ?></p>
    <p class="details"><?= htmlspecialchars($settings['event_dates']) ?></p>

    <form method="POST" action="connect.php" id="connect-form">
      <div class="field">
        <label for="name">Full Name</label>
        <input type="text" id="name" name="name" autocomplete="name" required>
      </div>
      <div class="field">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" autocomplete="tel" inputmode="tel" required>
      </div>
      <div class="field">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" autocomplete="email" inputmode="email" required>
      </div>
      <?php foreach ($mikrotikParams as $key => $value): ?>
        <input type="hidden" name="mikrotik_<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
      <?php endforeach; ?>
      <button type="submit" id="connect-submit">Connect to Wi-Fi</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($settings['powered_by_logo_path']): ?>
    <p class="powered-by">Powered by <img src="<?= htmlspecialchars($settings['powered_by_logo_path']) ?>" alt="MangoNet"></p>
  <?php endif; ?>
</div>
<script>
// Give immediate feedback on submit: issuing a code involves email + SMS
// delivery, so the response is not instant and an unchanged button invites
// double-taps (which the duplicate-entry handling absorbs, but which look
// broken to the attendee).
// The reconnect path renders no sign-up form, so this element is absent there.
document.getElementById('connect-form')?.addEventListener('submit', function () {
  var btn = document.getElementById('connect-submit');
  btn.setAttribute('aria-busy', 'true');
  btn.textContent = 'Connecting…';
});
</script>
</body>
</html>
