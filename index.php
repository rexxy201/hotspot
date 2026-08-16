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
  <?php if ($settings['event_logo_path']): ?>
    <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
  <?php endif; ?>
  <h1><?= htmlspecialchars($settings['event_name']) ?></h1>
  <p class="tagline"><?= htmlspecialchars($settings['event_tagline']) ?></p>
  <p class="details"><?= htmlspecialchars($settings['event_dates']) ?> &middot; <?= htmlspecialchars($settings['event_venue']) ?></p>

  <form method="POST" action="connect.php" id="connect-form">
    <input type="text" name="name" placeholder="Full Name" required>
    <input type="tel" name="phone" placeholder="Phone Number" required>
    <input type="email" name="email" placeholder="Email Address" required>
    <?php foreach ($mikrotikParams as $key => $value): ?>
      <input type="hidden" name="mikrotik_<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
    <?php endforeach; ?>
    <button type="submit">Connect</button>
  </form>

  <?php if ($settings['powered_by_logo_path']): ?>
    <p class="powered-by">Powered by <img src="<?= htmlspecialchars($settings['powered_by_logo_path']) ?>" alt="MangoNet"></p>
  <?php endif; ?>
</div>
</body>
</html>
