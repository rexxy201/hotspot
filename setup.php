<?php
/**
 * Guided setup wizard — the only thing allowed to write .env (never by
 * hand, never committed). Safe to re-run any time: every field prefills
 * from the current .env, so touching one setting doesn't mean retyping
 * everything else.
 *
 * Auth model: the very first run (no .env yet) is open — there is nothing
 * to protect yet, and no admin password exists to gate it with anyway.
 * Every run after that requires SETUP_ACCESS_CODE (a short PIN, default
 * 2112, changeable from the Security stage) — deliberately separate from
 * the admin password so this is reachable without a trip through /admin/.
 * Rate-limited (lib/rate_limit.php) since a short PIN is easy to
 * brute-force otherwise, and from here on this page can rewrite DB/SMTP/
 * Twilio credentials and drop every table.
 */

require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/assets.php';

$envPath = __DIR__ . '/.env';
$envExisted = is_file($envPath);

function read_env_file(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key !== '') {
            $out[$key] = $value;
        }
    }
    return $out;
}

/** Write .env as KEY="value" lines, one per known field, in a fixed order. */
function write_env_file(string $path, array $values): bool
{
    $order = [
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'APP_KEY',
        'ADMIN_PASSWORD_HASH',
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME',
        'TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM_NUMBER',
        'MIKROTIK_GATEWAY_HOST',
        'PORTAL_HOST',
        'COMPANY_NAME',
        'SETUP_ACCESS_CODE',
    ];
    $lines = ['# Written by setup.php — do not edit by hand while the wizard is in use;', '# re-run the wizard instead so nothing here gets out of sync.'];
    foreach ($order as $key) {
        $value = (string) ($values[$key] ?? '');
        // Quote whenever the value could otherwise be misread (spaces, #, quotes).
        $needsQuoting = $value === '' || preg_match('/[\s#"\']/', $value) === 1;
        $lines[] = $key . '=' . ($needsQuoting ? '"' . str_replace('"', '\\"', $value) . '"' : $value);
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0640);
    return @rename($tmp, $path);
}

function test_db_connection(string $host, string $name, string $user, string $pass): array
{
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect($host, $user, $pass, $name);
        $tableCount = 0;
        if ($result = $db->query('SHOW TABLES')) {
            $tableCount = $result->num_rows;
        }
        $db->close();
        return [true, "Connected. {$tableCount} table(s) found."];
    } catch (\Throwable $e) {
        return [false, 'Could not connect: ' . $e->getMessage()];
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

/** The tables the app requires to run at all — see schema.sql. */
const REQUIRED_TABLES = ['entries', 'settings', 'wifi_credentials', 'radius_sessions'];

/**
 * Connect with the given credentials and make sure the schema is actually
 * there. Runs on every save, not just the first one, and only ever
 * CREATES — never drops or touches existing data:
 *   - Every required table already exists: no-op, silent.
 *   - The database is completely empty (a fresh one — exactly the state
 *     that took the site down once already, when a re-run pointed
 *     setup.php at a new database and nobody separately remembered to
 *     load schema.sql by hand): load schema.sql automatically. Safe
 *     because an empty database has nothing to lose.
 *   - Some but not all required tables exist (an unexpected half-built
 *     state): touch nothing, surface a warning instead. Auto-fixing a
 *     partial schema risks silently papering over something real, unlike
 *     an empty database.
 *
 * @return array{0: bool, 1: string} [ok, message] — ok=false means the
 *   connection itself failed (so the caller should refuse to save these
 *   credentials at all), not that an informational warning was raised.
 */
function ensure_schema(string $host, string $name, string $user, string $pass, string $schemaPath): array
{
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect($host, $user, $pass, $name);

        $existing = [];
        if ($result = $db->query('SHOW TABLES')) {
            while ($row = $result->fetch_row()) {
                $existing[] = $row[0];
            }
        }

        $missing = array_diff(REQUIRED_TABLES, $existing);
        $present = array_intersect(REQUIRED_TABLES, $existing);

        if (empty($missing)) {
            $db->close();
            return [true, ''];
        }

        if (empty($present)) {
            $schemaSql = (string) file_get_contents($schemaPath);
            if ($schemaSql === '' || !$db->multi_query($schemaSql)) {
                $err = $db->error;
                $db->close();
                return [true, "Connected, but this database has no tables and schema.sql failed to load automatically: {$err}. Use Danger Zone \u{2192} Drop & recreate to try again, or load schema.sql by hand."];
            }
            do {
                if ($res = $db->store_result()) {
                    $res->free();
                }
            } while ($db->more_results() && $db->next_result());
            $db->close();
            return [true, 'This looked like a brand-new, empty database, so the required tables were created automatically from schema.sql.'];
        }

        $db->close();
        $missingList = implode(', ', $missing);
        return [true, "Connected, but this database is missing some expected tables ({$missingList}) — looks like a partial or unexpected schema. Nothing was changed automatically; use Danger Zone \u{2192} Drop & recreate if you want a clean rebuild (this deletes whatever is already there)."];
    } catch (\Throwable $e) {
        return [false, 'Could not connect with these database settings, so nothing was saved: ' . $e->getMessage()];
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

function test_smtp_connection(string $host, int $port, string $user, string $pass): array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [false, 'vendor/ is missing — run composer install (the deploy workflow does this automatically).'];
    }
    require_once $autoload;
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return [false, 'PHPMailer is not available.'];
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = $user !== '';
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = $port === 465 ? 'ssl' : 'tls';
        $mail->Timeout = 8;
        $mail->SMTPDebug = 0;
        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return [true, 'Connected and authenticated successfully.'];
        }
        // PHPMailer's ErrorInfo is sometimes empty on a hard failure (DNS
        // resolution, connection refused) — a blank "Could not connect: "
        // message is worse than a generic one.
        $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'no further detail from PHPMailer — check the host/port are correct and reachable from this server.';
        return [false, 'Could not connect: ' . $detail];
    } catch (\Throwable $e) {
        $detail = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
        return [false, 'Could not connect: ' . $detail];
    }
}

function test_twilio_credentials(string $sid, string $token): array
{
    if ($sid === '' || $token === '') {
        return [false, 'Enter both the Account SID and Auth Token.'];
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $err !== '') {
        return [false, "Could not reach Twilio: {$err}"];
    }
    if ($status === 200) {
        return [true, 'Twilio credentials are valid.'];
    }
    if ($status === 401) {
        return [false, 'Twilio rejected these credentials (401 Unauthorized).'];
    }
    return [false, "Twilio returned HTTP {$status}."];
}

/**
 * @param array $envValues Saved .env values.
 * @param array $posted    Credentials currently typed into the form, if any.
 *                         Preferred, because during a re-run the saved .env can
 *                         still hold the old password you came here to correct.
 * @return array{0: bool, 1: string}
 */
function check_daemon(array $envValues, array $posted = []): array
{
    // Track where each credential came from, so a rejection can say whether it
    // used what you just typed or the value still sitting in .env. Without this
    // an "access denied" leaves you guessing which one was actually tried.
    $fromForm = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
        $typed = trim((string) ($posted[$k] ?? ''));
        if ($typed !== '') {
            $envValues[$k] = $typed;
            $fromForm[] = $k;
        }
    }
    $passSource = in_array('DB_PASS', $fromForm, true)
        ? 'the password you typed in step 1'
        : 'the password saved in .env (the box was blank, which means "keep current")';
    if (($envValues['DB_HOST'] ?? '') === '') {
        return [false, 'Enter your database settings in step 1 first — the daemon status is read from the settings table.'];
    }
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect(
            $envValues['DB_HOST'],
            $envValues['DB_USER'] ?? '',
            $envValues['DB_PASS'] ?? '',
            $envValues['DB_NAME'] ?? ''
        );
        require_once __DIR__ . '/lib/settings.php';
        require_once __DIR__ . '/lib/radius_diagnostics.php';
        // get_settings() decrypts radius_secret via setting_decrypt(), which
        // reads the APP_KEY constant directly — normally defined by
        // config.php, but setup.php deliberately never loads config.php (it
        // reads .env itself so it keeps working even before a real APP_KEY
        // exists). Only surfaced once a real secret was actually encrypted;
        // harmless before that because setting_decrypt() short-circuits on
        // an unencrypted value without ever touching APP_KEY.
        if (!defined('APP_KEY')) {
            define('APP_KEY', $envValues['APP_KEY'] ?? '');
        }
        $settings = get_settings($db);
        $db->close();
        return radius_diagnose($settings);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'access denied') !== false) {
            return [false, sprintf(
                'Database login rejected. Tried %s@%s on database "%s" using %s. '
                . 'This check reads the RADIUS port and secret from the database — it never uses SSH. '
                . 'Note MySQL treats user@localhost and user@127.0.0.1 as different accounts. (%s)',
                $envValues['DB_USER'] ?? '?',
                $envValues['DB_HOST'] ?? '?',
                $envValues['DB_NAME'] ?? '?',
                $passSource,
                $msg
            )];
        }
        return [false, 'Could not check: ' . $msg];
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

// ---------------------------------------------------------------------
// Access gate for re-runs — a short PIN (SETUP_ACCESS_CODE), not the admin
// password, so this is reachable without a trip through /admin/. Rate
// limited: a short PIN is easy to brute-force otherwise, and from here on
// this page can rewrite DB/SMTP/Twilio credentials and drop every table.
// ---------------------------------------------------------------------
if ($envExisted) {
    require_once __DIR__ . '/lib/rate_limit.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $unlockError = '';
    if (empty($_SESSION['setup_unlocked'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock' && !csrf_verify()) {
            $unlockError = 'That form had expired. Please try again.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
            $ip = client_ip();
            if (!rate_limit_check('setup_unlock', $ip, 5, 900)) {
                $wait = (int) ceil(rate_limit_seconds_until_retry('setup_unlock', $ip, 900) / 60);
                $unlockError = "Too many attempts. Try again in {$wait} minute(s).";
            } else {
                $typed = (string) ($_POST['code'] ?? '');
                $expected = ($current['SETUP_ACCESS_CODE'] ?? '') !== '' ? $current['SETUP_ACCESS_CODE'] : '2112';
                if ($typed !== '' && hash_equals($expected, $typed)) {
                    $_SESSION['setup_unlocked'] = true;
                    rate_limit_reset('setup_unlock', $ip);
                } else {
                    rate_limit_record('setup_unlock', $ip);
                    $unlockError = 'Incorrect code.';
                }
            }
        }

        if (empty($_SESSION['setup_unlocked'])) {
            ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup — Locked</title>
<link rel="stylesheet" href="<?= asset_url(__DIR__, 'assets/style.css') ?>">
</head>
<body>
<div class="portal">
  <div class="portal-card login-card">
    <h1>Setup is locked</h1>
    <p class="intro">Enter the access code to re-run setup.</p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="unlock">
      <?php if ($unlockError): ?><p class="error" role="alert"><?= htmlspecialchars($unlockError) ?></p><?php endif; ?>
      <div class="field">
        <label for="code">Access code</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="off" required autofocus>
      </div>
      <button type="submit">Unlock</button>
    </form>
  </div>
</div>
</body>
</html>
            <?php
            exit;
        }
    }
}

$current = read_env_file($envPath);

// ---------------------------------------------------------------------
// AJAX test endpoints — never touch .env, only report whether values work.
// ---------------------------------------------------------------------
if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    $post = $_POST;
    [$ok, $message] = match ($action) {
        'test_db' => test_db_connection(
            trim((string) ($post['DB_HOST'] ?? '')),
            trim((string) ($post['DB_NAME'] ?? '')),
            trim((string) ($post['DB_USER'] ?? '')),
            (string) ($post['DB_PASS'] ?? '')
        ),
        'test_smtp' => test_smtp_connection(
            trim((string) ($post['SMTP_HOST'] ?? '')),
            max(1, (int) ($post['SMTP_PORT'] ?? 587)),
            trim((string) ($post['SMTP_USER'] ?? '')),
            (string) ($post['SMTP_PASS'] ?? '')
        ),
        'test_twilio' => test_twilio_credentials(
            trim((string) ($post['TWILIO_ACCOUNT_SID'] ?? '')),
            (string) ($post['TWILIO_AUTH_TOKEN'] ?? '')
        ),
        'check_daemon' => check_daemon($current, $post),
        'generate_app_key' => [true, bin2hex(random_bytes(32))],
        default => [false, 'Unknown check.'],
    };
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

// ---------------------------------------------------------------------
// Danger zone — drop & recreate, or erase data. Both require the DB
// fields just submitted to actually connect, AND a typed confirmation
// phrase, checked server-side (not just disabled client-side).
// ---------------------------------------------------------------------
$dangerNotice = '';
$dangerError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['danger_action'] ?? '', ['drop_recreate', 'erase_data'], true) && !csrf_verify()) {
    $dangerError = 'That form had expired — nothing was touched. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['danger_action'] ?? '', ['drop_recreate', 'erase_data'], true)) {
    $dangerAction = $_POST['danger_action'];
    $expected = $dangerAction === 'drop_recreate' ? 'DROP EVERYTHING' : 'ERASE DATA';
    $typed = trim((string) ($_POST['confirm_phrase'] ?? ''));
    $host = trim((string) ($_POST['DB_HOST'] ?? ''));
    $name = trim((string) ($_POST['DB_NAME'] ?? ''));
    $user = trim((string) ($_POST['DB_USER'] ?? ''));
    $pass = (string) ($_POST['DB_PASS'] ?? '');

    if ($typed !== $expected) {
        $dangerError = "Confirmation phrase didn't match — nothing was touched. Type exactly: {$expected}";
    } else {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = mysqli_init();
            $db->real_connect($host, $user, $pass, $name);

            if ($dangerAction === 'erase_data') {
                $db->query('SET FOREIGN_KEY_CHECKS=0');
                foreach (['entries', 'wifi_credentials', 'radius_sessions'] as $table) {
                    $db->query('DELETE FROM `' . $table . '`');
                }
                $db->query('SET FOREIGN_KEY_CHECKS=1');
                $dangerNotice = 'All raffle entries, Wi-Fi credentials, and RADIUS session data erased. Branding and RADIUS settings were kept.';
            } else {
                $tables = [];
                if ($result = $db->query('SHOW TABLES')) {
                    while ($row = $result->fetch_row()) {
                        $tables[] = $row[0];
                    }
                }
                $db->query('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $table) {
                    $db->query('DROP TABLE IF EXISTS `' . $table . '`');
                }
                $db->query('SET FOREIGN_KEY_CHECKS=1');
                $schemaSql = (string) file_get_contents(__DIR__ . '/schema.sql');
                if ($schemaSql === '' || !$db->multi_query($schemaSql)) {
                    throw new \RuntimeException('Dropped ' . count($tables) . ' table(s) but failed to reload schema.sql: ' . $db->error);
                }
                do {
                    if ($res = $db->store_result()) {
                        $res->free();
                    }
                } while ($db->more_results() && $db->next_result());
                $dangerNotice = 'Dropped ' . count($tables) . ' table(s) and recreated a blank schema from schema.sql. Every setting, entry, and credential is gone.';
            }
            $db->close();
        } catch (\Throwable $e) {
            $dangerError = 'Failed: ' . $e->getMessage();
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF);
        }
    }
}

// ---------------------------------------------------------------------
// Final save — the only place .env is ever written.
// ---------------------------------------------------------------------
$saveNotice = '';
$saveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && !csrf_verify()) {
    $saveError = 'That form had expired — nothing was saved. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $newAdminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminHash = $current['ADMIN_PASSWORD_HASH'] ?? '';
    if ($newAdminPassword !== '') {
        $adminHash = password_hash($newAdminPassword, PASSWORD_BCRYPT);
    }

    $values = [
        'DB_HOST' => trim((string) ($_POST['DB_HOST'] ?? '')),
        'DB_NAME' => trim((string) ($_POST['DB_NAME'] ?? '')),
        'DB_USER' => trim((string) ($_POST['DB_USER'] ?? '')),
        // Blank password field means "keep the current one" — same
        // semantics as the rest of the admin panel's secret fields.
        'DB_PASS' => ($_POST['DB_PASS'] ?? '') !== '' ? $_POST['DB_PASS'] : ($current['DB_PASS'] ?? ''),
        'APP_KEY' => trim((string) ($_POST['APP_KEY'] ?? '')) ?: ($current['APP_KEY'] ?? ''),
        'ADMIN_PASSWORD_HASH' => $adminHash,
        'SMTP_HOST' => trim((string) ($_POST['SMTP_HOST'] ?? '')),
        'SMTP_PORT' => (string) max(1, (int) ($_POST['SMTP_PORT'] ?? 587)),
        'SMTP_USER' => trim((string) ($_POST['SMTP_USER'] ?? '')),
        'SMTP_PASS' => ($_POST['SMTP_PASS'] ?? '') !== '' ? $_POST['SMTP_PASS'] : ($current['SMTP_PASS'] ?? ''),
        'SMTP_FROM_EMAIL' => trim((string) ($_POST['SMTP_FROM_EMAIL'] ?? '')),
        'SMTP_FROM_NAME' => trim((string) ($_POST['SMTP_FROM_NAME'] ?? '')) ?: 'Wi-Fi Portal',
        'TWILIO_ACCOUNT_SID' => trim((string) ($_POST['TWILIO_ACCOUNT_SID'] ?? '')),
        'TWILIO_AUTH_TOKEN' => ($_POST['TWILIO_AUTH_TOKEN'] ?? '') !== '' ? $_POST['TWILIO_AUTH_TOKEN'] : ($current['TWILIO_AUTH_TOKEN'] ?? ''),
        'TWILIO_FROM_NUMBER' => trim((string) ($_POST['TWILIO_FROM_NUMBER'] ?? '')),
        'MIKROTIK_GATEWAY_HOST' => trim((string) ($_POST['MIKROTIK_GATEWAY_HOST'] ?? '')),
        'PORTAL_HOST' => trim((string) ($_POST['PORTAL_HOST'] ?? '')),
        'COMPANY_NAME' => trim((string) ($_POST['COMPANY_NAME'] ?? '')) ?: 'MangoNet',
        'SETUP_ACCESS_CODE' => trim((string) ($_POST['SETUP_ACCESS_CODE'] ?? '')) ?: ($current['SETUP_ACCESS_CODE'] ?? '2112'),
    ];

    $errors = [];
    if ($values['DB_HOST'] === '' || $values['DB_NAME'] === '' || $values['DB_USER'] === '') {
        $errors[] = 'Database host, name, and username are required.';
    }
    if (strlen($values['APP_KEY']) !== 64) {
        $errors[] = 'APP_KEY must be a 64-character hex string — use "Generate" if you don\'t have one yet.';
    }
    if ($values['ADMIN_PASSWORD_HASH'] === '') {
        $errors[] = 'Set an admin password — there is no existing one to keep.';
    }

    // Connect with whatever is about to be saved BEFORE saving it, and make
    // sure the schema is actually there — this is what stops a re-run from
    // silently pointing the live site at a database with no tables (that
    // took the site down once already; the wizard used to just trust
    // whatever DB fields were submitted and write them straight to .env).
    $schemaNotice = '';
    if (!$errors) {
        [$schemaOk, $schemaMessage] = ensure_schema($values['DB_HOST'], $values['DB_NAME'], $values['DB_USER'], $values['DB_PASS'], __DIR__ . '/schema.sql');
        if (!$schemaOk) {
            $errors[] = $schemaMessage;
        } else {
            $schemaNotice = $schemaMessage;
        }
    }

    if ($errors) {
        $saveError = implode(' ', $errors);
    } elseif (!write_env_file($envPath, $values)) {
        $saveError = '.env could not be written — check the web server user can write to the project root.';
    } else {
        $saveNotice = $envExisted
            ? 'Saved. Changes take effect on the next request — no restart needed.'
            : 'Saved! .env has been created. You can now log into /admin/ with the password you just set.';
        if ($schemaNotice !== '') {
            $saveNotice .= ' ' . $schemaNotice;
        }
        $current = $values;
        $envExisted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup — Wi-Fi Portal</title>
<link rel="stylesheet" href="<?= asset_url(__DIR__, 'assets/style.css') ?>">
<style>
.setup-shell { max-width: 720px; margin: 0 auto; padding: var(--space-5) var(--space-4); }
.setup-steps { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-5); }
.setup-step-tab {
  flex: 1 1 auto; min-width: 90px; text-align: center; padding: var(--space-2) var(--space-1);
  border-radius: var(--radius-sm); font-size: 12px; font-weight: 700; letter-spacing: .02em;
  background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--admin-text-muted);
  cursor: pointer; user-select: none; transition: background var(--transition), color var(--transition);
}
.setup-step-tab.is-active { background: var(--admin-active); color: #fff; border-color: var(--admin-active); }
.setup-step-tab.is-done::before { content: "✓ "; color: var(--green-l, #22c55e); }
.setup-stage { display: none; }
.setup-stage.is-active { display: block; }
.test-row { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--space-2); flex-wrap: wrap; }
.test-result { font-size: 13px; font-weight: 600; }
.test-result.ok { color: #34d399; }
.test-result.fail { color: #f87171; }
.setup-nav { display: flex; justify-content: space-between; gap: var(--space-2); margin-top: var(--space-5); }
.setup-nav button { width: auto; padding: 0 var(--space-5); }
.danger-panel { border: 1px solid #7A241D; border-radius: var(--radius-md); padding: var(--space-4); margin-top: var(--space-5); background: #2a0f0d; }
.danger-panel h3 { margin: 0 0 var(--space-2); color: #FFD9D5; font-size: 15px; }
.danger-panel p { color: var(--admin-text-muted); font-size: 13px; margin: 0 0 var(--space-3); }
.danger-form { display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center; margin-top: var(--space-2); }
.danger-form input[type="text"] { max-width: 220px; }
.danger-form button { width: auto; background: #5B1A15; }
.field-hint code { background: var(--admin-bg); padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body class="admin-body">
<div class="setup-shell">
  <h1><?= $envExisted ? 'Re-run Setup' : 'Welcome — let\'s get set up' ?></h1>
  <p class="page-sub">
    <?= $envExisted
        ? 'Every field below is prefilled from the current .env. Change only what you need — anything left as-is (or left blank on a password field) keeps its current value.'
        : 'This writes .env — the only place your real credentials live. Nothing here gets committed to git.' ?>
  </p>

  <?php if ($saveNotice): ?><p class="diag-ok" style="margin-bottom:var(--space-4)"><?= htmlspecialchars($saveNotice) ?></p><?php endif; ?>
  <?php if ($saveError): ?><p class="error" role="alert" style="margin-bottom:var(--space-4)"><?= htmlspecialchars($saveError) ?></p><?php endif; ?>
  <?php if ($dangerNotice): ?><p class="diag-ok" style="margin-bottom:var(--space-4)"><?= htmlspecialchars($dangerNotice) ?></p><?php endif; ?>
  <?php if ($dangerError): ?><p class="error" role="alert" style="margin-bottom:var(--space-4)"><?= htmlspecialchars($dangerError) ?></p><?php endif; ?>

  <div class="setup-steps" id="setup-steps">
    <div class="setup-step-tab is-active" data-stage="db">1. Database</div>
    <div class="setup-step-tab" data-stage="security">2. Security</div>
    <div class="setup-step-tab" data-stage="email">3. Email</div>
    <div class="setup-step-tab" data-stage="sms">4. SMS</div>
    <div class="setup-step-tab" data-stage="network">5. Network</div>
    <div class="setup-step-tab" data-stage="daemon">6. Daemon</div>
    <div class="setup-step-tab" data-stage="review">7. Review &amp; Save</div>
  </div>

  <form method="POST" id="setup-form" class="settings-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <section class="setup-stage is-active" data-stage="db">
      <h2 style="color:var(--color-accent);font-size:15px;">Database</h2>
      <div class="field">
        <label for="DB_HOST">Host</label>
        <input type="text" id="DB_HOST" name="DB_HOST" value="<?= htmlspecialchars($current['DB_HOST'] ?? 'localhost') ?>" required>
      </div>
      <div class="field">
        <label for="DB_NAME">Database name</label>
        <input type="text" id="DB_NAME" name="DB_NAME" value="<?= htmlspecialchars($current['DB_NAME'] ?? 'wifi_portal') ?>" required>
      </div>
      <div class="field">
        <label for="DB_USER">Username</label>
        <input type="text" id="DB_USER" name="DB_USER" value="<?= htmlspecialchars($current['DB_USER'] ?? 'wifi_portal_user') ?>" required>
      </div>
      <div class="field">
        <label for="DB_PASS">Password</label>
        <input type="password" id="DB_PASS" name="DB_PASS" autocomplete="new-password"
               placeholder="<?= $envExisted ? 'Leave blank to keep the current password' : '' ?>">
      </div>
      <div class="test-row">
        <button type="button" class="btn-inline secondary" data-test="test_db" data-fields="DB_HOST,DB_NAME,DB_USER,DB_PASS">Test connection</button>
        <span class="test-result" data-result-for="test_db"></span>
      </div>
    </section>

    <section class="setup-stage" data-stage="security">
      <h2 style="color:var(--color-accent);font-size:15px;">Security</h2>
      <div class="field">
        <label for="APP_KEY">App encryption key</label>
        <input type="text" id="APP_KEY" name="APP_KEY" value="<?= htmlspecialchars($current['APP_KEY'] ?? '') ?>" style="font-family:ui-monospace,monospace;font-size:13px;">
        <p class="field-hint">Encrypts the RADIUS shared secret at rest. <strong>Cannot be recovered if lost or changed</strong> — regenerating this on a re-run means re-entering the RADIUS secret in Admin → Wi-Fi &amp; RADIUS afterward.</p>
        <div class="test-row">
          <button type="button" class="btn-inline secondary" id="generate-app-key">Generate new key</button>
          <span class="test-result" id="app-key-result"></span>
        </div>
      </div>
      <div class="field" style="margin-top:var(--space-5)">
        <label for="admin_password">Admin password</label>
        <input type="password" id="admin_password" name="admin_password" autocomplete="new-password"
               placeholder="<?= $envExisted ? 'Leave blank to keep the current password' : 'Choose a strong password' ?>">
        <p class="field-hint">This is what logs into /admin/. <?= $envExisted ? 'Leave blank to keep the current one.' : '' ?></p>
      </div>
      <div class="field" style="margin-top:var(--space-5)">
        <label for="SETUP_ACCESS_CODE">Setup access code</label>
        <input type="text" id="SETUP_ACCESS_CODE" name="SETUP_ACCESS_CODE" inputmode="numeric" autocomplete="off"
               value="<?= htmlspecialchars($current['SETUP_ACCESS_CODE'] ?? '') ?>" placeholder="Default: 2112">
        <p class="field-hint">The short code that unlocks this page on a re-run, instead of the admin password above. Change it here any time — leave blank to keep the current one.</p>
      </div>
    </section>

    <section class="setup-stage" data-stage="email">
      <h2 style="color:var(--color-accent);font-size:15px;">Email (SMTP)</h2>
      <div class="field"><label for="SMTP_HOST">Host</label><input type="text" id="SMTP_HOST" name="SMTP_HOST" value="<?= htmlspecialchars($current['SMTP_HOST'] ?? '') ?>"></div>
      <div class="field"><label for="SMTP_PORT">Port</label><input type="text" id="SMTP_PORT" name="SMTP_PORT" value="<?= htmlspecialchars($current['SMTP_PORT'] ?? '587') ?>"></div>
      <div class="field"><label for="SMTP_USER">Username</label><input type="text" id="SMTP_USER" name="SMTP_USER" value="<?= htmlspecialchars($current['SMTP_USER'] ?? '') ?>"></div>
      <div class="field"><label for="SMTP_PASS">Password</label><input type="password" id="SMTP_PASS" name="SMTP_PASS" autocomplete="new-password" placeholder="<?= $envExisted ? 'Leave blank to keep the current password' : '' ?>"></div>
      <div class="field"><label for="SMTP_FROM_EMAIL">From address</label><input type="email" id="SMTP_FROM_EMAIL" name="SMTP_FROM_EMAIL" value="<?= htmlspecialchars($current['SMTP_FROM_EMAIL'] ?? '') ?>"></div>
      <div class="field"><label for="SMTP_FROM_NAME">From name</label><input type="text" id="SMTP_FROM_NAME" name="SMTP_FROM_NAME" value="<?= htmlspecialchars($current['SMTP_FROM_NAME'] ?? 'Wi-Fi Portal') ?>"></div>
      <div class="test-row">
        <button type="button" class="btn-inline secondary" data-test="test_smtp" data-fields="SMTP_HOST,SMTP_PORT,SMTP_USER,SMTP_PASS">Test connection</button>
        <span class="test-result" data-result-for="test_smtp"></span>
      </div>
      <p class="field-hint">This checks the connection and login only — it does not send a test email.</p>
    </section>

    <section class="setup-stage" data-stage="sms">
      <h2 style="color:var(--color-accent);font-size:15px;">SMS (Twilio)</h2>
      <div class="field"><label for="TWILIO_ACCOUNT_SID">Account SID</label><input type="text" id="TWILIO_ACCOUNT_SID" name="TWILIO_ACCOUNT_SID" value="<?= htmlspecialchars($current['TWILIO_ACCOUNT_SID'] ?? '') ?>"></div>
      <div class="field"><label for="TWILIO_AUTH_TOKEN">Auth token</label><input type="password" id="TWILIO_AUTH_TOKEN" name="TWILIO_AUTH_TOKEN" autocomplete="new-password" placeholder="<?= $envExisted ? 'Leave blank to keep the current token' : '' ?>"></div>
      <div class="field"><label for="TWILIO_FROM_NUMBER">From number</label><input type="text" id="TWILIO_FROM_NUMBER" name="TWILIO_FROM_NUMBER" value="<?= htmlspecialchars($current['TWILIO_FROM_NUMBER'] ?? '') ?>" placeholder="+1234567890"></div>
      <div class="test-row">
        <button type="button" class="btn-inline secondary" data-test="test_twilio" data-fields="TWILIO_ACCOUNT_SID,TWILIO_AUTH_TOKEN">Test credentials</button>
        <span class="test-result" data-result-for="test_twilio"></span>
      </div>
      <p class="field-hint">Checks the credentials against Twilio's API — it does not send a test SMS (that costs money).</p>
    </section>

    <section class="setup-stage" data-stage="network">
      <h2 style="color:var(--color-accent);font-size:15px;">Network</h2>
      <div class="field">
        <label for="MIKROTIK_GATEWAY_HOST">Mikrotik hotspot gateway IP</label>
        <input type="text" id="MIKROTIK_GATEWAY_HOST" name="MIKROTIK_GATEWAY_HOST" value="<?= htmlspecialchars($current['MIKROTIK_GATEWAY_HOST'] ?? '') ?>" placeholder="10.5.50.1">
        <p class="field-hint">The router's hotspot gateway. Required for the auto-login handoff after an attendee submits the form — see index.php.</p>
      </div>
      <div class="field">
        <label for="PORTAL_HOST">Portal domain</label>
        <input type="text" id="PORTAL_HOST" name="PORTAL_HOST" value="<?= htmlspecialchars($current['PORTAL_HOST'] ?? '') ?>" placeholder="e.g. eyifwifi.online">
        <p class="field-hint">This site's real hostname. Admin &rarr; Wi-Fi &amp; RADIUS uses this — not whatever address you happen to be viewing /admin/ from — when generating the router config (.rsc) and hotspot login page (login.html) downloads, so those files stay correct even if you troubleshoot over the server's bare IP or the domain changes later. Leave blank to auto-detect from the current request instead (fine until that guess is wrong).</p>
      </div>
      <div class="field">
        <label for="COMPANY_NAME">Company name</label>
        <input type="text" id="COMPANY_NAME" name="COMPANY_NAME" value="<?= htmlspecialchars($current['COMPANY_NAME'] ?? 'MangoNet') ?>">
      </div>
      <p class="field-hint">RADIUS shared secret, branding, and everything else operational lives in the Admin panel (Wi-Fi &amp; RADIUS / Branding Settings), not here — this wizard only covers what has to exist before the app can start at all.</p>
    </section>

    <section class="setup-stage" data-stage="daemon">
      <h2 style="color:var(--color-accent);font-size:15px;">RADIUS Daemon</h2>
      <p class="field-hint">This checks whether the background RADIUS daemon (a systemd service, not cron) is up and answering — same check the Admin → Wi-Fi &amp; RADIUS page uses. Only meaningful after your database settings are saved once.</p>
      <div class="test-row">
        <button type="button" class="btn-inline secondary" data-test="check_daemon" data-fields="DB_HOST,DB_NAME,DB_USER,DB_PASS">Check daemon status</button>
        <span class="test-result" data-result-for="check_daemon"></span>
      </div>
    </section>

    <section class="setup-stage" data-stage="review">
      <h2 style="color:var(--color-accent);font-size:15px;">Review &amp; Save</h2>
      <p class="field-hint">Saving writes .env immediately. Blank password fields keep whatever is currently set — nothing gets wiped by leaving a field empty.</p>
      <button type="submit" style="margin-top:var(--space-3)">Save and finish</button>
    </section>

    <div class="setup-nav">
      <button type="button" class="btn-inline secondary" id="setup-prev">Back</button>
      <button type="button" class="btn-inline" id="setup-next">Next</button>
    </div>
  </form>

  <?php // Deliberately OUTSIDE #setup-form: <form> cannot nest inside
        // <form> per the HTML spec, and browsers silently mangle the DOM
        // (auto-closing the outer form early) if you try — confirmed by
        // hand, this is what broke the "Erase data" button's styling
        // before it was pulled out here. Shown/hidden in sync with the
        // "Database" stage via the same JS that drives .setup-stage. ?>
  <div class="danger-panel setup-stage is-active" data-stage="db">
    <h3>Danger zone</h3>
    <p>Both actions run against whatever is currently typed in the Database fields above — test the connection first. Both require the exact confirmation phrase; nothing happens if it doesn't match.</p>
    <p><strong>Erase data</strong> — deletes raffle entries, Wi-Fi credentials, and RADIUS sessions. Branding and RADIUS settings are kept. Type <code>ERASE DATA</code> to confirm.</p>
    <form method="POST" class="danger-form" onsubmit="return document.getElementById('confirm-erase').value === 'ERASE DATA' || confirm('Confirmation phrase does not match yet — submit anyway and let the server reject it?');">
      <?= csrf_field() ?>
      <input type="hidden" name="danger_action" value="erase_data">
      <input type="hidden" name="DB_HOST" class="mirror-DB_HOST"><input type="hidden" name="DB_NAME" class="mirror-DB_NAME">
      <input type="hidden" name="DB_USER" class="mirror-DB_USER"><input type="hidden" name="DB_PASS" class="mirror-DB_PASS">
      <input type="text" id="confirm-erase" name="confirm_phrase" placeholder="Type: ERASE DATA">
      <button type="submit">Erase data</button>
    </form>
    <p style="margin-top:var(--space-3)"><strong>Drop &amp; recreate all tables</strong> — wipes absolutely everything, including settings, and rebuilds a blank schema from schema.sql. Type <code>DROP EVERYTHING</code> to confirm.</p>
    <form method="POST" class="danger-form" onsubmit="return document.getElementById('confirm-drop').value === 'DROP EVERYTHING' || confirm('Confirmation phrase does not match yet — submit anyway and let the server reject it?');">
      <?= csrf_field() ?>
      <input type="hidden" name="danger_action" value="drop_recreate">
      <input type="hidden" name="DB_HOST" class="mirror-DB_HOST"><input type="hidden" name="DB_NAME" class="mirror-DB_NAME">
      <input type="hidden" name="DB_USER" class="mirror-DB_USER"><input type="hidden" name="DB_PASS" class="mirror-DB_PASS">
      <input type="text" id="confirm-drop" name="confirm_phrase" placeholder="Type: DROP EVERYTHING">
      <button type="submit">Drop &amp; recreate</button>
    </form>
  </div>
</div>

<script>
(function () {
  var stages = ['db', 'security', 'email', 'sms', 'network', 'daemon', 'review'];
  var current = 0;
  var tabs = document.querySelectorAll('.setup-step-tab');
  var sections = document.querySelectorAll('.setup-stage');
  var prevBtn = document.getElementById('setup-prev');
  var nextBtn = document.getElementById('setup-next');

  function show(index) {
    current = Math.max(0, Math.min(stages.length - 1, index));
    tabs.forEach(function (tab, i) {
      tab.classList.toggle('is-active', i === current);
      tab.classList.toggle('is-done', i < current);
    });
    sections.forEach(function (section) {
      section.classList.toggle('is-active', section.dataset.stage === stages[current]);
    });
    prevBtn.style.visibility = current === 0 ? 'hidden' : 'visible';
    nextBtn.textContent = current === stages.length - 1 ? 'Review above ↑' : 'Next';
  }

  tabs.forEach(function (tab, i) {
    tab.addEventListener('click', function () { show(i); });
  });
  prevBtn.addEventListener('click', function () { show(current - 1); });
  nextBtn.addEventListener('click', function () {
    if (current === stages.length - 1) { return; }
    show(current + 1);
  });

  // Keep the hidden DB_* mirrors in the danger-zone forms in sync with the
  // real fields, so "test connection" and "erase/drop" always act on
  // whatever is currently typed, not stale values from page load.
  ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'].forEach(function (name) {
    var source = document.getElementById(name);
    var mirrors = document.querySelectorAll('.mirror-' + name);
    var sync = function () { mirrors.forEach(function (m) { m.value = source.value; }); };
    source.addEventListener('input', sync);
    sync();
  });

  function runTest(button) {
    var action = button.dataset.test;
    var fields = button.dataset.fields.split(',');
    var resultEl = document.querySelector('[data-result-for="' + action + '"]');
    var body = new URLSearchParams();
    fields.forEach(function (f) { body.set(f, document.getElementById(f).value); });
    resultEl.textContent = 'Testing…';
    resultEl.className = 'test-result';
    button.disabled = true;
    fetch('setup.php?ajax=' + action, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        resultEl.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        resultEl.className = 'test-result ' + (data.ok ? 'ok' : 'fail');
      })
      .catch(function () {
        resultEl.textContent = '✗ Request failed.';
        resultEl.className = 'test-result fail';
      })
      .finally(function () { button.disabled = false; });
  }
  document.querySelectorAll('[data-test]').forEach(function (button) {
    button.addEventListener('click', function () { runTest(button); });
  });

  var genBtn = document.getElementById('generate-app-key');
  if (genBtn) {
    genBtn.addEventListener('click', function () {
      var resultEl = document.getElementById('app-key-result');
      var field = document.getElementById('APP_KEY');
      if (field.value !== '' && !confirm('This field already has a key. Replacing it means the current encrypted RADIUS secret can no longer be decrypted — you will need to re-enter it in Admin → Wi-Fi & RADIUS. Continue?')) {
        return;
      }
      genBtn.disabled = true;
      fetch('setup.php?ajax=generate_app_key', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          field.value = data.message;
          resultEl.textContent = 'Generated.';
          resultEl.className = 'test-result ok';
        })
        .finally(function () { genBtn.disabled = false; });
    });
  }

  show(0);
})();
</script>
</body>
</html>
