<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/entries.php';
require_once __DIR__ . '/lib/radius.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/sms.php';

function validate_submission(array $post): array {
    $errors = [];
    $name = trim($post['name'] ?? '');
    $phone = trim($post['phone'] ?? '');
    $email = trim($post['email'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        $errors[] = 'Enter a valid phone number.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    return [$errors, $name, $phone, $email];
}

$db = get_db();

[$errors, $name, $phone, $email] = validate_submission($_POST);

if (!empty($errors)) {
    http_response_code(422);
    $errorSettings = get_settings($db);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check your details — <?= htmlspecialchars($errorSettings['event_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>:root { --brand-color: <?= htmlspecialchars($errorSettings['brand_color']) ?>; }</style>
</head>
<body>
<div class="portal">
  <div class="portal-card">
    <h1>Check your details</h1>
    <?php foreach ($errors as $error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <p class="hint">Nothing was submitted — go back and correct the highlighted fields.</p>
    <p style="margin-top:var(--space-4)"><a class="btn-link" href="index.php">Go back</a></p>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

try {
    $settings = get_settings($db);

    $existing = find_entry_by_email_or_phone($db, $email, $phone);

    if ($existing === null) {
        $code = generate_unique_code($db);
        try {
            create_entry($db, $name, $phone, $email, $code);
        } catch (mysqli_sql_exception $e) {
            // Only a duplicate-key violation (1062) on entries.email/
            // entries.phone (both UNIQUE) indicates the intended race:
            // another near-simultaneous submission with the same email/
            // phone won the insert. Any other error on create_entry() is
            // a real failure and must propagate to the outer catch.
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            $existing = find_entry_by_email_or_phone($db, $email, $phone);
            if ($existing === null) {
                // Unexpected — not actually a duplicate-key collision.
                throw $e;
            }
            $code = $existing['code'];
        }
        if ($existing === null) {
            // create_entry() succeeded with no race — this is a newly
            // created entry, so it needs a radcheck row. If this throws,
            // it propagates straight to the outer catch below (it can
            // never be a duplicate-key race, since radcheck.username has
            // no unique constraint).
            radius_add_user($db, $code);
        }
    } else {
        $code = $existing['code'];
    }
} catch (\Throwable $e) {
    error_log('connect.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo '<h1>Something went wrong</h1><p>Please see event staff for help connecting.</p>';
    exit;
}

$emailSent = send_code_email(make_smtp_mailer(), $settings, $email, $name, $code);
$smsSent = send_code_sms('twilio_http_post', $settings, $phone, $code);

$linkLoginOnly = $_POST['mikrotik_link-login-only'] ?? '';
$linkLoginOnlyValid = filter_var($linkLoginOnly, FILTER_VALIDATE_URL) !== false
    && in_array(parse_url($linkLoginOnly, PHP_URL_SCHEME), ['http', 'https'], true)
    && parse_url($linkLoginOnly, PHP_URL_HOST) === MIKROTIK_GATEWAY_HOST;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connected — <?= htmlspecialchars($settings['event_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>:root { --brand-color: <?= htmlspecialchars($settings['brand_color']) ?>; }</style>
</head>
<body>
<div class="portal">
  <div class="portal-card">
    <?php if ($settings['event_logo_path']): ?>
      <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
    <?php endif; ?>
    <svg class="success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="10"></circle>
      <path d="m8 12 3 3 5-6"></path>
    </svg>
    <h1>You're connected</h1>
    <p class="code-label">Your code</p>
    <strong class="code" id="code"><?= htmlspecialchars($code) ?></strong>
    <p class="hint">Save this code — it's your entry for the prize draw.</p>

    <?php if (!$emailSent): ?><p class="warning">We couldn't email your code — it's shown above, please save it.</p><?php endif; ?>
    <?php if (!$smsSent): ?><p class="warning">We couldn't text your code — it's shown above, please save it.</p><?php endif; ?>

    <?php if ($linkLoginOnlyValid): ?>
      <form id="mikrotik-login" method="POST" action="<?= htmlspecialchars($linkLoginOnly) ?>">
        <input type="hidden" name="username" value="<?= htmlspecialchars($code) ?>">
        <input type="hidden" name="password" value="<?= htmlspecialchars($code) ?>">
        <button type="submit">Continue to internet</button>
      </form>
      <script>document.getElementById('mikrotik-login').submit();</script>
    <?php endif; ?>
  </div>

  <?php if ($settings['powered_by_logo_path']): ?>
    <p class="powered-by">Powered by <img src="<?= htmlspecialchars($settings['powered_by_logo_path']) ?>" alt="MangoNet"></p>
  <?php endif; ?>
</div>
</body>
</html>
