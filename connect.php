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
    echo '<h1>Please fix the following:</h1><ul>';
    foreach ($errors as $error) {
        echo '<li>' . htmlspecialchars($error) . '</li>';
    }
    echo '</ul><p><a href="index.php">Go back</a></p>';
    exit;
}

try {
    $settings = get_settings($db);

    $existing = find_entry_by_email_or_phone($db, $email, $phone);

    if ($existing === null) {
        $code = generate_unique_code($db);
        try {
            create_entry($db, $name, $phone, $email, $code);
            radius_add_user($db, $code);
        } catch (mysqli_sql_exception $e) {
            // Likely a duplicate-key race: another near-simultaneous
            // submission with the same email/phone won the insert
            // (entries.email/entries.phone are UNIQUE). Fall back to the
            // entry that submission just created.
            $existing = find_entry_by_email_or_phone($db, $email, $phone);
            if ($existing === null) {
                // Unexpected — not actually a duplicate-key collision.
                throw $e;
            }
            $code = $existing['code'];
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
<title>Connected — <?= htmlspecialchars($settings['event_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="portal">
  <h1>You're in!</h1>
  <p>Your code: <strong id="code"><?= htmlspecialchars($code) ?></strong></p>
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
</body>
</html>
