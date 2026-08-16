<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';

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
    <?php if ($settings['event_logo_path']): ?>
      <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
    <?php endif; ?>
    <h1><?= htmlspecialchars($settings['event_name']) ?></h1>
    <p class="tagline"><?= htmlspecialchars($settings['event_tagline']) ?></p>
    <p class="details"><?= htmlspecialchars($settings['event_dates']) ?> &middot; <?= htmlspecialchars($settings['event_venue']) ?></p>

    <p class="intro">Enter your details to get online. We'll send you a code that also enters you into the prize draw.</p>

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
      <p class="hint">Your code arrives by email and SMS. Keep it — it's your prize-draw entry.</p>
    </form>
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
document.getElementById('connect-form').addEventListener('submit', function () {
  var btn = document.getElementById('connect-submit');
  btn.setAttribute('aria-busy', 'true');
  btn.textContent = 'Connecting…';
});
</script>
</body>
</html>
