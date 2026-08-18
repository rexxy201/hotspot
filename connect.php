<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/entries.php';
require_once __DIR__ . '/lib/radius.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/sms.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/edo_lga.php';

function validate_submission(array $post): array {
    $errors = [];
    $name = trim($post['name'] ?? '');
    $phone = trim($post['phone'] ?? '');
    $email = trim($post['email'] ?? '');
    $lga = trim($post['lga'] ?? '');
    // A textarea, so newlines are expected content, not something to
    // collapse — only trim the ends.
    $techQuestion = trim($post['tech_question'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        $errors[] = 'Enter a valid phone number.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    // Validated against the fixed EDO_LGAS list, not just "non-empty" — the
    // form only ever offers those 18 values, so anything else means the
    // request didn't come from the real form (or was tampered with).
    if (!in_array($lga, EDO_LGAS, true)) {
        $errors[] = 'Select your LGA from the list.';
    }
    if ($techQuestion === '') {
        $errors[] = 'Answer the question about the biggest technology problem Edo should solve.';
    } elseif (function_exists('mb_strlen') ? mb_strlen($techQuestion) > 1000 : strlen($techQuestion) > 1000) {
        $errors[] = 'Keep your answer under 1000 characters.';
    }
    return [$errors, $name, $phone, $email, $lga, $techQuestion];
}

$db = get_db();

[$errors, $name, $phone, $email, $lga, $techQuestion] = validate_submission($_POST);

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
<link rel="stylesheet" href="<?= asset_url(__DIR__, 'assets/style.css') ?>">
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
            create_entry($db, $name, $phone, $email, $code, $lga, $techQuestion);
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
    } else {
        $code = $existing['code'];
    }

    // Issue or RENEW the Wi-Fi credential on every submission, not just for new
    // entries. Credentials expire (session_minutes), so a returning attendee on
    // day 2 has an entries row but no valid credential — issuing only for new
    // entries would show them the success page and then have RADIUS reject them.
    // issue_credential() is an idempotent upsert, so this refreshes the expiry
    // and picks up any change to the rate cap.
    // The router hands us the client MAC on its redirect, and index.php carries
    // it through the form. Binding it to the credential is what lets a device
    // that drops off the Wi-Fi reconnect later without re-typing its details.
    $submittedMac = (string) ($_POST['mikrotik_mac'] ?? '');
    radius_add_user($db, $code, $settings, $submittedMac);

    // The daemon refuses an over-quota code at RADIUS. Without this check the
    // attendee would see "You're connected" and only then be refused by the
    // router — the same misleading sequence Stage 1 fixed for expired codes.
    $quotaMb = (int) ($settings['data_quota_mb'] ?? 0);
    $overQuota = $quotaMb > 0 && usage_bytes_for_code($db, $code) >= ($quotaMb * 1048576);
} catch (\Throwable $e) {
    app_log('connect.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
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
<title><?= $overQuota ? 'Data limit reached' : ($linkLoginOnlyValid ? 'Connecting…' : 'Signed up') ?> — <?= htmlspecialchars($settings['event_name']) ?></title>
<link rel="stylesheet" href="<?= asset_url(__DIR__, 'assets/style.css') ?>">
<style>:root { --brand-color: <?= htmlspecialchars($settings['brand_color']) ?>; }</style>
</head>
<body>
<div class="portal">
  <div class="portal-card">
    <?php if ($settings['event_logo_path']): ?>
      <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
    <?php endif; ?>
    <?php if ($overQuota): ?>
      <h1>Data limit reached</h1>
      <p class="warning">You've used your full data allowance for <?= htmlspecialchars($settings['event_name']) ?>, so we can't put you back online.</p>
      <p class="hint">Please see event staff if you need more data.</p>
      <p class="code-label">Your code</p>
      <strong class="code" id="code"><?= htmlspecialchars($code) ?></strong>
      <p class="hint">Keep this code — it's still your entry for the prize draw.</p>
    <?php elseif ($linkLoginOnlyValid): ?>
    <?php // Honest by construction: this page cannot actually know the router
          // accepted the login the instant it renders — the auto-submit below
          // fires into a hidden form this script never sees the response of.
          // Claiming "You're connected" unconditionally here used to mean
          // exactly that: a claim, not a fact. Now it starts as "Connecting…"
          // and only becomes "You're connected" once connect-status.php
          // confirms the router actually sent RADIUS accounting for this
          // code — see the poll loop below and lib/usage.php. ?>
    <svg class="success-icon" id="status-icon-connecting" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
      <path d="M12 2a10 10 0 0 1 10 10"></path>
    </svg>
    <svg class="success-icon" id="status-icon-connected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden>
      <circle cx="12" cy="12" r="10"></circle>
      <path d="m8 12 3 3 5-6"></path>
    </svg>
    <h1 id="status-heading">Connecting you now…</h1>
    <p class="code-label">Your code</p>
    <strong class="code" id="code"><?= htmlspecialchars($code) ?></strong>
    <p class="hint">Save this code — it's your entry for the prize draw either way.</p>
    <p class="hint" id="status-note">This takes a few seconds.</p>

    <?php if (!$emailSent): ?><p class="warning">We couldn't email your code — it's shown above, please save it.</p><?php endif; ?>
    <?php if (!$smsSent): ?><p class="warning">We couldn't text your code — it's shown above, please save it.</p><?php endif; ?>

    <form id="mikrotik-login" method="POST" action="<?= htmlspecialchars($linkLoginOnly) ?>">
      <input type="hidden" name="username" value="<?= htmlspecialchars($code) ?>">
      <input type="hidden" name="password" value="<?= htmlspecialchars($code) ?>">
      <noscript><button type="submit">Continue to internet</button></noscript>
    </form>
    <script>
    (function () {
      document.getElementById('mikrotik-login').submit();

      var heading = document.getElementById('status-heading');
      var note = document.getElementById('status-note');
      var spinner = document.getElementById('status-icon-connecting');
      var check = document.getElementById('status-icon-connected');
      var code = <?= json_encode($code) ?>;

      var elapsedMs = 0;
      var POLL_EVERY_MS = 2000;
      var GIVE_UP_AFTER_MS = 25000;
      // A couple of seconds' head start before the first poll: the form
      // above has to actually reach the router and the router has to
      // process it before any accounting record could possibly exist yet,
      // so polling immediately would only spend the budget on guaranteed
      // early misses.
      var START_DELAY_MS = 2500;

      var showConnected = function () {
        spinner.hidden = true;
        check.hidden = false;
        heading.textContent = "You're connected";
        note.textContent = '';
      };

      var showTimedOut = function () {
        heading.textContent = "Signed up — having trouble getting online?";
        note.textContent = 'Your code above is already saved either way. If Wi-Fi still isn\'t working, show it to event staff.';
      };

      var poll = function () {
        elapsedMs += POLL_EVERY_MS;
        fetch('connect-status.php?code=' + encodeURIComponent(code), { cache: 'no-store' })
          .then(function (res) { return res.ok ? res.json() : null; })
          .then(function (data) {
            if (data && data.connected) {
              showConnected();
              return;
            }
            if (elapsedMs >= GIVE_UP_AFTER_MS) {
              showTimedOut();
              return;
            }
            setTimeout(poll, POLL_EVERY_MS);
          })
          .catch(function () {
            // A transient fetch failure isn't a verdict either way — keep
            // trying within the same overall budget rather than giving up
            // on one dropped request.
            if (elapsedMs >= GIVE_UP_AFTER_MS) {
              showTimedOut();
              return;
            }
            setTimeout(poll, POLL_EVERY_MS);
          });
      };

      setTimeout(poll, START_DELAY_MS);
    })();
    </script>
    <?php else: ?>
    <?php // No real router handoff was even attempted (e.g. this page was
          // reached without valid Mikrotik redirect params) — so there is
          // nothing to honestly claim about internet access either way.
          // Signed-up-but-neutral, not a connectivity claim. ?>
    <svg class="success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="10"></circle>
      <path d="m8 12 3 3 5-6"></path>
    </svg>
    <h1>You're signed up</h1>
    <p class="code-label">Your code</p>
    <strong class="code" id="code"><?= htmlspecialchars($code) ?></strong>
    <p class="hint">Save this code — it's your entry for the prize draw.</p>

    <?php if (!$emailSent): ?><p class="warning">We couldn't email your code — it's shown above, please save it.</p><?php endif; ?>
    <?php if (!$smsSent): ?><p class="warning">We couldn't text your code — it's shown above, please save it.</p><?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($settings['powered_by_logo_path']): ?>
    <p class="powered-by">Powered by <img src="<?= htmlspecialchars($settings['powered_by_logo_path']) ?>" alt="MangoNet"></p>
  <?php endif; ?>
</div>
</body>
</html>
