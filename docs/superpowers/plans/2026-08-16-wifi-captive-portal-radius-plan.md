# Wi-Fi Hotspot Captive Portal (RADIUS-integrated) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP + MySQL captive portal for EYIF 2026's Mikrotik-hosted Wi-Fi hotspot that captures attendee name/phone/email, issues an 8-digit code that is both a raffle entry and a working RADIUS Wi-Fi credential, delivers the code by email and SMS, and gives an admin a password-gated view of entries plus editable branding (logos, event text, brand color).

**Architecture:** Plain PHP (no framework), mysqli with prepared statements, PHPMailer for SMTP email, Twilio's HTTP API for SMS. FreeRADIUS (configured separately, not by this app's code) uses its own standard SQL schema (`radcheck`/`radreply`/`radacct`/`nas`) against the same MySQL database; this app writes a `radcheck` row for every new entry so the code becomes a valid RADIUS login the moment it's issued. Everything — FreeRADIUS, MySQL, PHP — runs on one VPS per the spec's deployment decision.

**Tech Stack:** PHP 8.x, MySQL 8, mysqli, PHPMailer (via Composer), Twilio HTTP API (via cURL, no SDK), FreeRADIUS 3.x with `rlm_sql_mysql`, vanilla HTML/CSS/JS.

## Global Constraints

- Codes are 8 digits, numeric only, zero-padded (`00000000`–`99999999`).
- `entries.phone` and `entries.email` are both `UNIQUE`; a submission matching either an existing row is treated as a duplicate — no new `entries` row, no new `radcheck` row, existing code is re-sent.
- All DB queries use `mysqli` prepared statements — no string-concatenated SQL, anywhere.
- `config.php` holds real credentials and is gitignored; `config.example.php` is the committed template. Config values are read via `getenv() ?: 'default'` so tests can override them (e.g. `DB_NAME`) without touching `config.php`.
- Admin password is stored as a bcrypt hash (`password_hash`/`password_verify`) — never plaintext — generated via the standalone `hash_password.php` CLI script.
- Logo uploads: PNG/JPG only (verified by actual file content via `mime_content_type`, not just the extension), size-capped at 2MB, stored under a randomly generated filename, never `include`d or executed.
- CSV export neutralizes any field starting with `=`, `+`, `-`, or `@` (CSV/formula injection).
- RADIUS: the code is both the `radcheck` username and `Cleartext-Password` value; each user also gets `Simultaneous-Use := 1`.
- No PHP test framework (no PHPUnit) — tests use the tiny custom assertion harness built in Task 1 (`tests/bootstrap.php`), consistent with the spec's "manual/lightweight testing, no framework" decision. Where a script can't be meaningfully unit-tested (e.g. a top-level page like `connect.php`), the task's verification step is a `curl` command with documented expected output instead of a unit test.
- Deployment target (confirmed default): a single DigitalOcean Ubuntu droplet running FreeRADIUS + MySQL + the PHP app together. Sessions expire at the end of each event day (`Session-Timeout`, wired in Task 15's FreeRADIUS config, not application code).

---

## Task 1: Project scaffolding — config, DB connection, schema, test harness, Composer

**Files:**
- Create: `config.example.php`
- Create: `db.php`
- Create: `schema.sql`
- Create: `composer.json`
- Create: `tests/bootstrap.php`
- Create: `tests/fixtures/radius_schema.sql`
- Create: `.gitignore`

**Interfaces:**
- Produces: `get_db(): mysqli` — used by every later PHP file that touches the database.
- Produces: `assert_equals($expected, $actual, string $message): void`, `assert_true($condition, string $message): void`, `test_summary(): void` — used by every test file in later tasks.
- Produces: constants `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `ADMIN_PASSWORD_HASH`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` (from `config.php`, which the engineer creates locally by copying `config.example.php`).

- [ ] **Step 1: Write `config.example.php`**

```php
<?php
// Copy this file to config.php and fill in real values.
// config.php is gitignored — never commit real credentials.
// Every constant reads an environment variable first so tests can override
// values (e.g. DB_NAME) without editing this file.

// Database
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'wifi_portal');
define('DB_USER', getenv('DB_USER') ?: 'wifi_portal_user');
define('DB_PASS', getenv('DB_PASS') ?: 'change-me');

// Admin login — generate with: php hash_password.php "your-strong-password"
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$replace-with-generated-hash');

// SMTP (email delivery)
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.example.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'you@example.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'change-me');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'EYIF 2026 Wi-Fi');

// Twilio (SMS delivery)
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: 'change-me');
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '+1234567890');
```

- [ ] **Step 2: Write `db.php`**

```php
<?php
require_once __DIR__ . '/config.php';

function get_db(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
    }
    return $db;
}
```

- [ ] **Step 3: Write `schema.sql`**

```sql
CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  code CHAR(8) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT
);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('event_name', 'Edo Youth Impact Forum 2026'),
  ('event_tagline', 'Empowered Youth, Transformed Future'),
  ('event_dates', 'Tuesday 18th & Wednesday 19th August 2026'),
  ('event_venue', 'Victor Uwaifo Creative Hub, Benin City, Edo State'),
  ('brand_color', '#1a7a4c'),
  ('event_logo_path', ''),
  ('powered_by_logo_path', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
```

- [ ] **Step 4: Write `tests/fixtures/radius_schema.sql`**

This mirrors the columns of FreeRADIUS's own `radcheck` table (imported for real from FreeRADIUS during VPS setup in Task 15) so `lib/radius.php` can be tested locally against a plain MySQL table without installing FreeRADIUS.

```sql
CREATE TABLE IF NOT EXISTS radcheck (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '==',
  value VARCHAR(253) NOT NULL DEFAULT ''
);
```

- [ ] **Step 5: Write `tests/bootstrap.php`**

```php
<?php
$GLOBALS['__failures'] = 0;

function assert_equals($expected, $actual, string $message): void {
    if ($expected === $actual) {
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
        $GLOBALS['__failures']++;
    }
}

function assert_true($condition, string $message): void {
    assert_equals(true, (bool) $condition, $message);
}

function test_summary(): void {
    if ($GLOBALS['__failures'] > 0) {
        echo "\n{$GLOBALS['__failures']} FAILURE(S)\n";
        exit(1);
    }
    echo "\nALL PASSED\n";
    exit(0);
}
```

- [ ] **Step 6: Write `composer.json`**

```json
{
    "require": {
        "phpmailer/phpmailer": "^6.9"
    }
}
```

- [ ] **Step 7: Write `.gitignore`**

```
config.php
vendor/
uploads/logos/*
!uploads/logos/.gitkeep
```

- [ ] **Step 8: Install dependencies and set up local + test databases**

```bash
composer install
```

```bash
mysql -u root -e "CREATE DATABASE wifi_portal; CREATE DATABASE wifi_portal_test;"
mysql -u root wifi_portal < schema.sql
mysql -u root wifi_portal_test < schema.sql
mysql -u root wifi_portal_test < tests/fixtures/radius_schema.sql
cp config.example.php config.php
```

Expected: no errors; `wifi_portal` and `wifi_portal_test` both exist with `entries` and `settings` tables, and `wifi_portal_test` additionally has `radcheck`.

- [ ] **Step 9: Verify `get_db()` connects**

```bash
DB_NAME=wifi_portal_test php -r "require 'db.php'; var_dump(get_db()->query('SELECT 1'));"
```

Expected: output shows a `mysqli_result` object, no connection errors.

- [ ] **Step 10: Commit**

```bash
git init
git add config.example.php db.php schema.sql composer.json tests/bootstrap.php tests/fixtures/radius_schema.sql .gitignore composer.lock
git commit -m "chore: scaffold project, DB schema, and test harness"
```

---

## Task 2: `lib/settings.php` — branding settings storage

**Files:**
- Create: `lib/settings.php`
- Test: `tests/settings_test.php`

**Interfaces:**
- Consumes: `get_db(): mysqli` (Task 1).
- Produces: `SETTINGS_DEFAULTS` (array constant), `get_settings(mysqli $db): array`, `save_settings(mysqli $db, array $settings): void` — used by `index.php` (Task 9), `connect.php` (Task 10), `admin/settings.php` (Task 13).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';

$db = get_db();
$db->query('DELETE FROM settings');

$defaults = get_settings($db);
assert_equals('Edo Youth Impact Forum 2026', $defaults['event_name'], 'get_settings returns default event_name when table is empty');

save_settings($db, ['event_name' => 'Test Event', 'brand_color' => '#ff0000']);
$updated = get_settings($db);
assert_equals('Test Event', $updated['event_name'], 'save_settings persists event_name');
assert_equals('#ff0000', $updated['brand_color'], 'save_settings persists brand_color');
assert_equals('Empowered Youth, Transformed Future', $updated['event_tagline'], 'save_settings leaves untouched keys at default');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: FAIL — `Fatal error: require_once(): Failed opening required '../lib/settings.php'`

- [ ] **Step 3: Write `lib/settings.php`**

```php
<?php
const SETTINGS_DEFAULTS = [
    'event_name' => 'Edo Youth Impact Forum 2026',
    'event_tagline' => 'Empowered Youth, Transformed Future',
    'event_dates' => 'Tuesday 18th & Wednesday 19th August 2026',
    'event_venue' => 'Victor Uwaifo Creative Hub, Benin City, Edo State',
    'brand_color' => '#1a7a4c',
    'event_logo_path' => '',
    'powered_by_logo_path' => '',
];

function get_settings(mysqli $db): array {
    $settings = SETTINGS_DEFAULTS;
    $result = $db->query('SELECT setting_key, setting_value FROM settings');
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['setting_key'], $settings)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function save_settings(mysqli $db, array $settings): void {
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach (SETTINGS_DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $value = $settings[$key];
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/settings.php tests/settings_test.php
git commit -m "feat: add branding settings storage"
```

---

## Task 3: `lib/entries.php` — raffle entry storage and code generation

**Files:**
- Create: `lib/entries.php`
- Test: `tests/entries_test.php`

**Interfaces:**
- Consumes: `get_db(): mysqli` (Task 1).
- Produces: `find_entry_by_email_or_phone(mysqli $db, string $email, string $phone): ?array`, `code_exists(mysqli $db, string $code): bool`, `generate_unique_code(mysqli $db): string`, `create_entry(mysqli $db, string $name, string $phone, string $email, string $code): void` — used by `connect.php` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/entries.php';

$db = get_db();
$db->query('DELETE FROM entries');

assert_equals(null, find_entry_by_email_or_phone($db, 'nobody@example.com', '0000000000'), 'find_entry_by_email_or_phone returns null when no match exists');

$code = generate_unique_code($db);
assert_equals(8, strlen($code), 'generate_unique_code returns an 8-character string');
assert_true(ctype_digit($code), 'generate_unique_code returns digits only');

create_entry($db, 'Jane Doe', '08010000000', 'jane@example.com', $code);

$foundByEmail = find_entry_by_email_or_phone($db, 'jane@example.com', '00000000000');
assert_equals($code, $foundByEmail['code'], 'find_entry_by_email_or_phone matches by email');

$foundByPhone = find_entry_by_email_or_phone($db, 'nobody@example.com', '08010000000');
assert_equals($code, $foundByPhone['code'], 'find_entry_by_email_or_phone matches by phone');

assert_true(code_exists($db, $code), 'code_exists is true for an inserted code');
assert_true(!code_exists($db, '00000000'), 'code_exists is false for an unused code');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/entries_test.php`
Expected: FAIL — `Failed opening required '../lib/entries.php'`

- [ ] **Step 3: Write `lib/entries.php`**

```php
<?php
function find_entry_by_email_or_phone(mysqli $db, string $email, string $phone): ?array {
    $stmt = $db->prepare('SELECT id, name, phone, email, code FROM entries WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->bind_param('ss', $email, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function code_exists(mysqli $db, string $code): bool {
    $stmt = $db->prepare('SELECT 1 FROM entries WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function generate_unique_code(mysqli $db): string {
    do {
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    } while (code_exists($db, $code));
    return $code;
}

function create_entry(mysqli $db, string $name, string $phone, string $email, string $code): void {
    $stmt = $db->prepare('INSERT INTO entries (name, phone, email, code) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $name, $phone, $email, $code);
    $stmt->execute();
    $stmt->close();
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/entries_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/entries.php tests/entries_test.php
git commit -m "feat: add raffle entry storage and unique code generation"
```

---

## Task 4: `lib/radius.php` — RADIUS credential provisioning

**Files:**
- Create: `lib/radius.php`
- Test: `tests/radius_test.php`

**Interfaces:**
- Consumes: `get_db(): mysqli` (Task 1); a `radcheck` table (Task 1's `tests/fixtures/radius_schema.sql` locally, FreeRADIUS's real schema in production per Task 15).
- Produces: `radius_add_user(mysqli $db, string $code): void`, `radius_user_exists(mysqli $db, string $code): bool` — used by `connect.php` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/radius.php';

$db = get_db();
$db->query('DELETE FROM radcheck');

radius_add_user($db, '04829371');

$row = $db->query("SELECT value FROM radcheck WHERE username = '04829371' AND attribute = 'Cleartext-Password'")->fetch_assoc();
assert_equals('04829371', $row['value'], 'radius_add_user sets Cleartext-Password to the code');

$row2 = $db->query("SELECT value FROM radcheck WHERE username = '04829371' AND attribute = 'Simultaneous-Use'")->fetch_assoc();
assert_equals('1', $row2['value'], 'radius_add_user limits the code to one simultaneous session');

assert_true(radius_user_exists($db, '04829371'), 'radius_user_exists finds the inserted user');
assert_true(!radius_user_exists($db, '99999999'), 'radius_user_exists returns false for an unknown code');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: FAIL — `Failed opening required '../lib/radius.php'`

- [ ] **Step 3: Write `lib/radius.php`**

```php
<?php
function radius_add_user(mysqli $db, string $code): void {
    $stmt = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)');

    $attr = 'Cleartext-Password';
    $op = ':=';
    $stmt->bind_param('ssss', $code, $attr, $op, $code);
    $stmt->execute();

    $attr = 'Simultaneous-Use';
    $limit = '1';
    $stmt->bind_param('ssss', $code, $attr, $op, $limit);
    $stmt->execute();

    $stmt->close();
}

function radius_user_exists(mysqli $db, string $code): bool {
    $stmt = $db->prepare('SELECT 1 FROM radcheck WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/radius.php tests/radius_test.php
git commit -m "feat: provision RADIUS credentials for each new entry"
```

---

## Task 5: `lib/csv.php` — CSV injection safety

**Files:**
- Create: `lib/csv.php`
- Test: `tests/csv_test.php`

**Interfaces:**
- Produces: `csv_safe_field(string $value): string` — used by `admin/index.php` (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/csv.php';

assert_equals("'=cmd", csv_safe_field('=cmd'), 'csv_safe_field neutralizes a leading =');
assert_equals("'+2348010000000", csv_safe_field('+2348010000000'), 'csv_safe_field neutralizes a leading +');
assert_equals("'-1+1", csv_safe_field('-1+1'), 'csv_safe_field neutralizes a leading -');
assert_equals("'@SUM(1)", csv_safe_field('@SUM(1)'), 'csv_safe_field neutralizes a leading @');
assert_equals('Jane Doe', csv_safe_field('Jane Doe'), 'csv_safe_field leaves normal text untouched');
assert_equals('', csv_safe_field(''), 'csv_safe_field handles an empty string');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/csv_test.php`
Expected: FAIL — `Failed opening required '../lib/csv.php'`

- [ ] **Step 3: Write `lib/csv.php`**

```php
<?php
function csv_safe_field(string $value): string {
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/csv_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/csv.php tests/csv_test.php
git commit -m "feat: neutralize CSV/formula injection in exported fields"
```

---

## Task 6: `lib/uploads.php` — logo upload validation

**Files:**
- Create: `lib/uploads.php`
- Create: `uploads/logos/.gitkeep`
- Test: `tests/uploads_test.php`

**Interfaces:**
- Produces: `ALLOWED_LOGO_MIME_TYPES` (array constant), `MAX_LOGO_BYTES` (int constant), `validate_logo_upload(array $file): array` (returns `[bool $ok, ?string $error, ?string $extension]`), `store_logo_upload(array $file, string $extension, string $uploadsDir): string` — used by `admin/settings.php` (Task 13).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/uploads.php';

// A real, minimal 1x1 PNG so mime_content_type() reports image/png.
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$tmpPath = tempnam(sys_get_temp_dir(), 'logo');
file_put_contents($tmpPath, $pngBytes);

[$ok, $error, $ext] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($pngBytes),
    'tmp_name' => $tmpPath,
]);
assert_true($ok, 'validate_logo_upload accepts a real PNG');
assert_equals('png', $ext, 'validate_logo_upload identifies the PNG extension');

[$tooBigOk] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => MAX_LOGO_BYTES + 1,
    'tmp_name' => $tmpPath,
]);
assert_true(!$tooBigOk, 'validate_logo_upload rejects files over the size cap');

$textPath = tempnam(sys_get_temp_dir(), 'notimage');
file_put_contents($textPath, '<script>alert(1)</script>');
[$textOk] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => 100,
    'tmp_name' => $textPath,
]);
assert_true(!$textOk, 'validate_logo_upload rejects a non-image file even with image bytes claimed');

[$noFileOk] = validate_logo_upload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);
assert_true($noFileOk, 'validate_logo_upload treats "no file uploaded" as valid, since the field is optional');

unlink($tmpPath);
unlink($textPath);
test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/uploads_test.php`
Expected: FAIL — `Failed opening required '../lib/uploads.php'`

- [ ] **Step 3: Write `lib/uploads.php`**

```php
<?php
const ALLOWED_LOGO_MIME_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
const MAX_LOGO_BYTES = 2 * 1024 * 1024;

function validate_logo_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload failed.', null];
    }
    if ($file['size'] > MAX_LOGO_BYTES) {
        return [false, 'Logo must be under 2MB.', null];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!array_key_exists($mime, ALLOWED_LOGO_MIME_TYPES)) {
        return [false, 'Logo must be a PNG or JPG image.', null];
    }
    return [true, null, ALLOWED_LOGO_MIME_TYPES[$mime]];
}

function store_logo_upload(array $file, string $extension, string $uploadsDir): string {
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = rtrim($uploadsDir, '/') . '/' . $filename;
    move_uploaded_file($file['tmp_name'], $destination);
    return 'uploads/logos/' . $filename;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/uploads_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Create the uploads directory placeholder**

```bash
mkdir -p uploads/logos
touch uploads/logos/.gitkeep
```

- [ ] **Step 6: Commit**

```bash
git add lib/uploads.php uploads/logos/.gitkeep tests/uploads_test.php
git commit -m "feat: validate and store logo uploads safely"
```

---

## Task 7: `lib/mailer.php` — code delivery by email

**Files:**
- Create: `lib/mailer.php`
- Test: `tests/mailer_test.php`

**Interfaces:**
- Consumes: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` (Task 1's `config.php`); `PHPMailer\PHPMailer\PHPMailer` (Composer, Task 1).
- Produces: `build_code_email_body(array $settings, string $name, string $code): string`, `send_code_email(PHPMailer $mail, array $settings, string $toEmail, string $toName, string $code): bool`, `make_smtp_mailer(): PHPMailer` — used by `connect.php` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;

class FakeMailer extends PHPMailer {
    public bool $sent = false;
    public function send(): bool {
        $this->sent = true;
        return true;
    }
}

$settings = ['event_name' => 'Test Event'];
$fake = new FakeMailer();
$result = send_code_email($fake, $settings, 'jane@example.com', 'Jane', '04829371');

assert_true($result, 'send_code_email returns true when PHPMailer::send() succeeds');
assert_true($fake->sent, 'send_code_email actually calls PHPMailer::send()');
assert_true(str_contains($fake->Body, '04829371'), 'the email body contains the code');
assert_equals('Test Event Wi-Fi Code', $fake->Subject, 'the email subject includes the event name');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mailer_test.php`
Expected: FAIL — `Failed opening required '../lib/mailer.php'`

- [ ] **Step 3: Write `lib/mailer.php`**

```php
<?php
use PHPMailer\PHPMailer\PHPMailer;

function build_code_email_body(array $settings, string $name, string $code): string {
    $eventName = htmlspecialchars($settings['event_name'], ENT_QUOTES);
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $safeCode = htmlspecialchars($code, ENT_QUOTES);
    return "<p>Hi {$safeName},</p>" .
           "<p>Thanks for connecting to Wi-Fi at {$eventName}.</p>" .
           "<p>Your code is: <strong>{$safeCode}</strong></p>" .
           "<p>Keep this code — it's also your raffle entry.</p>";
}

function send_code_email(PHPMailer $mail, array $settings, string $toEmail, string $toName, string $code): bool {
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = $settings['event_name'] . ' Wi-Fi Code';
    $mail->Body = build_code_email_body($settings, $toName, $code);
    try {
        return $mail->send();
    } catch (\Exception $e) {
        error_log('send_code_email failed: ' . $e->getMessage());
        return false;
    }
}

function make_smtp_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    return $mail;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mailer_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/mailer.php tests/mailer_test.php
git commit -m "feat: send raffle code by email via PHPMailer/SMTP"
```

---

## Task 8: `lib/sms.php` — code delivery by SMS

**Files:**
- Create: `lib/sms.php`
- Test: `tests/sms_test.php`

**Interfaces:**
- Consumes: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` (Task 1's `config.php`).
- Produces: `twilio_http_post(string $accountSid, string $authToken, string $fromNumber, string $toPhone, string $body): array` (returns `['status' => int, 'body' => string]`), `send_code_sms(callable $transport, array $settings, string $toPhone, string $code): bool` — used by `connect.php` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/sms.php';

$settings = ['event_name' => 'Test Event'];

$successTransport = function (...$args) {
    return ['status' => 201, 'body' => '{"sid":"SMxxx"}'];
};
assert_true(send_code_sms($successTransport, $settings, '+2348010000000', '04829371'), 'send_code_sms returns true on a 2xx response');

$failTransport = function (...$args) {
    return ['status' => 400, 'body' => '{"message":"bad request"}'];
};
assert_true(!send_code_sms($failTransport, $settings, '+2348010000000', '04829371'), 'send_code_sms returns false on a non-2xx response');

$capturedBody = null;
$capturingTransport = function ($sid, $token, $from, $to, $body) use (&$capturedBody) {
    $capturedBody = $body;
    return ['status' => 201, 'body' => '{}'];
};
send_code_sms($capturingTransport, $settings, '+2348010000000', '04829371');
assert_true(str_contains($capturedBody, '04829371'), 'the SMS body contains the code');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sms_test.php`
Expected: FAIL — `Failed opening required '../lib/sms.php'`

- [ ] **Step 3: Write `lib/sms.php`**

```php
<?php
function twilio_http_post(string $accountSid, string $authToken, string $fromNumber, string $toPhone, string $body): array {
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'From' => $fromNumber,
        'To' => $toPhone,
        'Body' => $body,
    ]));
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $statusCode, 'body' => $response];
}

function send_code_sms(callable $transport, array $settings, string $toPhone, string $code): bool {
    $body = "Your {$settings['event_name']} Wi-Fi code is {$code}. This is also your raffle entry.";
    $result = $transport(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER, $toPhone, $body);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log('send_code_sms failed: HTTP ' . $result['status'] . ' ' . $result['body']);
        return false;
    }
    return true;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/sms_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/sms.php tests/sms_test.php
git commit -m "feat: send raffle code by SMS via Twilio's HTTP API"
```

---

## Task 9: `index.php` — attendee-facing portal page

**Files:**
- Create: `index.php`

**Interfaces:**
- Consumes: `get_db()` (Task 1), `get_settings(mysqli $db): array` (Task 2).
- Produces: an HTML form at `POST connect.php` with fields `name`, `phone`, `email`, and hidden fields `mikrotik_mac`, `mikrotik_ip`, `mikrotik_link-login-only`, `mikrotik_link-orig` — consumed by `connect.php` (Task 10).

- [ ] **Step 1: Write `index.php`**

```php
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
```

- [ ] **Step 2: Verify with the PHP built-in server**

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s "http://localhost:8000/index.php?mac=AA:BB:CC:DD:EE:FF&link-login-only=http://10.5.50.1/login"
kill %1
```

Expected: HTML output containing `id="connect-form"`, `Edo Youth Impact Forum 2026`, and `<input type="hidden" name="mikrotik_mac" value="AA:BB:CC:DD:EE:FF">` and `name="mikrotik_link-login-only" value="http://10.5.50.1/login"`.

- [ ] **Step 3: Commit**

```bash
git add index.php
git commit -m "feat: add attendee-facing portal page"
```

---

## Task 10: `connect.php` — form submission, code issuance, and Mikrotik auto-login

**Files:**
- Create: `connect.php`

**Interfaces:**
- Consumes: `get_db()` (Task 1); `get_settings()` (Task 2); `find_entry_by_email_or_phone()`, `generate_unique_code()`, `create_entry()` (Task 3); `radius_add_user()` (Task 4); `make_smtp_mailer()`, `send_code_email()` (Task 7); `twilio_http_post()`, `send_code_sms()` (Task 8); hidden `mikrotik_*` POST fields (Task 9).
- Produces: an HTML success page containing the issued code and, when `mikrotik_link-login-only` is present, an auto-submitting form posting `username`/`password` (both the code) to that URL.

- [ ] **Step 1: Write `connect.php`**

```php
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
$settings = get_settings($db);

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

$existing = find_entry_by_email_or_phone($db, $email, $phone);

if ($existing === null) {
    $code = generate_unique_code($db);
    create_entry($db, $name, $phone, $email, $code);
    radius_add_user($db, $code);
} else {
    $code = $existing['code'];
}

$emailSent = send_code_email(make_smtp_mailer(), $settings, $email, $name, $code);
$smsSent = send_code_sms('twilio_http_post', $settings, $phone, $code);

$linkLoginOnly = $_POST['mikrotik_link-login-only'] ?? '';
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

  <?php if ($linkLoginOnly): ?>
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
```

- [ ] **Step 2: Verify a fresh submission end-to-end**

Use fake SMTP/Twilio values in `config.php` for this local check (delivery will fail, which is expected and handled — see the "warning" paragraphs).

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -X POST http://localhost:8000/connect.php \
  -d "name=Jane Doe" -d "phone=08010000001" -d "email=jane.doe@example.com" \
  -d "mikrotik_link-login-only=http://10.5.50.1/login"
kill %1
```

Expected: response HTML contains `id="mikrotik-login"`, `action="http://10.5.50.1/login"`, two hidden inputs named `username` and `password` with the same 8-digit value, and `<strong id="code">` showing that same value.

- [ ] **Step 3: Verify the DB side-effects**

```bash
mysql -u root wifi_portal -e "SELECT name, phone, email, code FROM entries WHERE email = 'jane.doe@example.com';"
mysql -u root wifi_portal -e "SELECT username, attribute, value FROM radcheck WHERE username = (SELECT code FROM entries WHERE email = 'jane.doe@example.com');"
```

Expected: one `entries` row for Jane Doe with an 8-digit `code`; two `radcheck` rows for that same code — `Cleartext-Password` set to the code, `Simultaneous-Use` set to `1`.

- [ ] **Step 4: Verify duplicate resubmission reuses the code**

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -X POST http://localhost:8000/connect.php \
  -d "name=Jane Doe" -d "phone=08010000001" -d "email=jane.doe@example.com" \
  -o /tmp/resubmit.html
kill %1
mysql -u root wifi_portal -e "SELECT COUNT(*) FROM entries WHERE email = 'jane.doe@example.com';"
grep -o 'id="code">[0-9]*' /tmp/resubmit.html
```

Expected: the `entries` count is still `1` (no duplicate row), and the code shown in `/tmp/resubmit.html` matches the one from Step 2/3.

- [ ] **Step 5: Commit**

```bash
git add connect.php
git commit -m "feat: handle form submission, issue codes, and auto-login to Mikrotik"
```

---

## Task 11: Admin authentication — `admin/auth.php`, `admin/login.php`, `hash_password.php`

**Files:**
- Create: `admin/auth.php`
- Create: `admin/login.php`
- Create: `hash_password.php`
- Test: `tests/admin_auth_test.php`

**Interfaces:**
- Consumes: `ADMIN_PASSWORD_HASH` (Task 1's `config.php`).
- Produces: `require_admin_session(): void` — used by `admin/index.php` (Task 12) and `admin/settings.php` (Task 13). Sets `$_SESSION['is_admin'] = true` on successful login.

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';

$hash = password_hash('correct horse battery staple', PASSWORD_BCRYPT);
assert_true(password_verify('correct horse battery staple', $hash), 'password_verify accepts the correct password');
assert_true(!password_verify('wrong password', $hash), 'password_verify rejects an incorrect password');

test_summary();
```

This exercises the exact primitive `admin/login.php` relies on — since bcrypt hashing/verification is PHP's own built-in and can't be meaningfully re-tested beyond confirming the round trip works as expected in this environment.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/admin_auth_test.php`
Expected: this test has no dependency on missing files, so it should already pass — run it now to confirm the baseline: `ALL PASSED`. (There's no "fails first" step here since there's no new function being introduced — skip to Step 3.)

- [ ] **Step 3: Write `admin/auth.php`**

```php
<?php
function require_admin_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['is_admin'])) {
        header('Location: login.php');
        exit;
    }
}
```

- [ ] **Step 4: Write `admin/login.php`**

```php
<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['is_admin'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Admin Login</title><link rel="stylesheet" href="../assets/style.css"></head>
<body>
<div class="portal">
<h1>Admin Login</h1>
<form method="POST">
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <input type="password" name="password" placeholder="Admin password" required>
  <button type="submit">Log in</button>
</form>
</div>
</body>
</html>
```

- [ ] **Step 5: Write `hash_password.php`**

```php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}
if ($argc < 2) {
    fwrite(STDERR, "Usage: php hash_password.php <plaintext-password>\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_BCRYPT) . "\n";
```

- [ ] **Step 6: Run the test to confirm it still passes**

Run: `php tests/admin_auth_test.php`
Expected: `ALL PASSED`

- [ ] **Step 7: Verify the login flow manually with the built-in server**

```bash
php hash_password.php "test-admin-password-123"
```

Copy the printed hash into `config.php`'s `ADMIN_PASSWORD_HASH`, then:

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -c /tmp/cookies.txt -X POST http://localhost:8000/admin/login.php -d "password=wrong" | grep -o 'Invalid password.'
curl -s -c /tmp/cookies.txt -X POST http://localhost:8000/admin/login.php -d "password=test-admin-password-123" -D - -o /dev/null | grep -i "location: index.php"
kill %1
```

Expected: first command prints `Invalid password.`; second command's headers show a `Location: index.php` redirect.

- [ ] **Step 8: Commit**

```bash
git add admin/auth.php admin/login.php hash_password.php tests/admin_auth_test.php
git commit -m "feat: add admin password authentication"
```

---

## Task 12: `admin/index.php` — entries list and CSV export

**Files:**
- Create: `admin/index.php`

**Interfaces:**
- Consumes: `require_admin_session()` (Task 11); `get_db()` (Task 1); `csv_safe_field()` (Task 5).

- [ ] **Step 1: Write `admin/index.php`**

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/csv.php';
require_admin_session();

$db = get_db();
$result = $db->query('SELECT name, phone, email, code, created_at FROM entries ORDER BY created_at DESC');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="entries.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Phone', 'Email', 'Code', 'Submitted At']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            csv_safe_field($row['name']),
            csv_safe_field($row['phone']),
            csv_safe_field($row['email']),
            csv_safe_field($row['code']),
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Raffle Entries</title><link rel="stylesheet" href="../assets/style.css"></head>
<body>
<h1>Raffle Entries</h1>
<p><a href="?export=csv">Download CSV</a> | <a href="settings.php">Branding Settings</a></p>
<table>
<tr><th>Name</th><th>Phone</th><th>Email</th><th>Code</th><th>Submitted</th></tr>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
  <td><?= htmlspecialchars($row['name']) ?></td>
  <td><?= htmlspecialchars($row['phone']) ?></td>
  <td><?= htmlspecialchars($row['email']) ?></td>
  <td><?= htmlspecialchars($row['code']) ?></td>
  <td><?= htmlspecialchars($row['created_at']) ?></td>
</tr>
<?php endwhile; ?>
</table>
</body>
</html>
```

- [ ] **Step 2: Verify the CSV export and the HTML table**

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -c /tmp/cookies.txt -X POST http://localhost:8000/admin/login.php -d "password=test-admin-password-123" -o /dev/null
curl -s -b /tmp/cookies.txt "http://localhost:8000/admin/index.php?export=csv"
curl -s -b /tmp/cookies.txt "http://localhost:8000/admin/index.php" | grep -o "jane.doe@example.com"
kill %1
```

Expected: the CSV output includes a header row and the Jane Doe entry from Task 10's tests; the HTML request shows `jane.doe@example.com` in the table.

- [ ] **Step 3: Commit**

```bash
git add admin/index.php
git commit -m "feat: add admin entries list with CSV export"
```

---

## Task 13: `admin/settings.php` — branding form (logos, event text, brand color)

**Files:**
- Create: `admin/settings.php`

**Interfaces:**
- Consumes: `require_admin_session()` (Task 11); `get_db()` (Task 1); `get_settings()`, `save_settings()` (Task 2); `validate_logo_upload()`, `store_logo_upload()` (Task 6).

- [ ] **Step 1: Write `admin/settings.php`**

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/uploads.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'event_name' => trim($_POST['event_name'] ?? ''),
        'event_tagline' => trim($_POST['event_tagline'] ?? ''),
        'event_dates' => trim($_POST['event_dates'] ?? ''),
        'event_venue' => trim($_POST['event_venue'] ?? ''),
        'brand_color' => trim($_POST['brand_color'] ?? ''),
    ];

    [$eventLogoOk, $eventLogoError, $eventLogoExt] = validate_logo_upload($_FILES['event_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    [$poweredByOk, $poweredByError, $poweredByExt] = validate_logo_upload($_FILES['powered_by_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

    if (!$eventLogoOk) {
        $error = $eventLogoError;
    } elseif (!$poweredByOk) {
        $error = $poweredByError;
    } else {
        $uploadsDir = __DIR__ . '/../uploads/logos';
        if ($eventLogoExt) {
            $newSettings['event_logo_path'] = store_logo_upload($_FILES['event_logo'], $eventLogoExt, $uploadsDir);
        }
        if ($poweredByExt) {
            $newSettings['powered_by_logo_path'] = store_logo_upload($_FILES['powered_by_logo'], $poweredByExt, $uploadsDir);
        }
        save_settings($db, $newSettings);
        $settings = get_settings($db);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Branding Settings</title><link rel="stylesheet" href="../assets/style.css"></head>
<body>
<h1>Branding Settings</h1>
<p><a href="index.php">Back to entries</a></p>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="POST" enctype="multipart/form-data">
  <label>Event Name <input type="text" name="event_name" value="<?= htmlspecialchars($settings['event_name']) ?>"></label>
  <label>Tagline <input type="text" name="event_tagline" value="<?= htmlspecialchars($settings['event_tagline']) ?>"></label>
  <label>Dates <input type="text" name="event_dates" value="<?= htmlspecialchars($settings['event_dates']) ?>"></label>
  <label>Venue <input type="text" name="event_venue" value="<?= htmlspecialchars($settings['event_venue']) ?>"></label>
  <label>Brand Color <input type="color" name="brand_color" value="<?= htmlspecialchars($settings['brand_color']) ?>"></label>
  <label>Event Logo (PNG/JPG, max 2MB) <input type="file" name="event_logo" accept="image/png,image/jpeg"></label>
  <label>Powered-By Logo (PNG/JPG, max 2MB) <input type="file" name="powered_by_logo" accept="image/png,image/jpeg"></label>
  <button type="submit">Save</button>
</form>
</body>
</html>
```

- [ ] **Step 2: Verify text-field updates apply immediately to the portal page**

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -c /tmp/cookies.txt -X POST http://localhost:8000/admin/login.php -d "password=test-admin-password-123" -o /dev/null
curl -s -b /tmp/cookies.txt -X POST http://localhost:8000/admin/settings.php \
  -F "event_name=Rebrand Test Event" -F "event_tagline=New Tagline" \
  -F "event_dates=Some Date" -F "event_venue=Some Venue" -F "brand_color=#0000ff"
curl -s http://localhost:8000/index.php | grep -o "Rebrand Test Event"
kill %1
```

Expected: `index.php` now shows `Rebrand Test Event` instead of the EYIF default.

- [ ] **Step 3: Restore the EYIF defaults for the live event**

```bash
mysql -u root wifi_portal -e "
UPDATE settings SET setting_value='Edo Youth Impact Forum 2026' WHERE setting_key='event_name';
UPDATE settings SET setting_value='Empowered Youth, Transformed Future' WHERE setting_key='event_tagline';
UPDATE settings SET setting_value='Tuesday 18th & Wednesday 19th August 2026' WHERE setting_key='event_dates';
UPDATE settings SET setting_value='Victor Uwaifo Creative Hub, Benin City, Edo State' WHERE setting_key='event_venue';
UPDATE settings SET setting_value='#1a7a4c' WHERE setting_key='brand_color';
"
```

- [ ] **Step 4: Commit**

```bash
git add admin/settings.php
git commit -m "feat: add admin branding settings form for logos, text, and color"
```

---

## Task 14: `assets/style.css` — shared styling

**Files:**
- Create: `assets/style.css`

- [ ] **Step 1: Write `assets/style.css`**

```css
:root {
  --brand-color: #1a7a4c;
}
body {
  font-family: system-ui, sans-serif;
  margin: 0;
  background: #f4f4f4;
}
.portal {
  max-width: 480px;
  margin: 40px auto;
  padding: 24px;
  background: #fff;
  border-radius: 8px;
  text-align: center;
}
.logo { max-width: 200px; margin-bottom: 16px; }
input, button {
  width: 100%;
  padding: 12px;
  margin: 8px 0;
  box-sizing: border-box;
  font-size: 16px;
}
button {
  background: var(--brand-color);
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
.warning { color: #b45309; }
.error { color: #b91c1c; }
table { width: 100%; border-collapse: collapse; margin: 16px 0; }
th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; }
label { display: block; text-align: left; margin: 12px 0; font-size: 14px; }
```

- [ ] **Step 2: Verify it loads without errors**

```bash
DB_NAME=wifi_portal php -S localhost:8000 &
sleep 1
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/assets/style.css
kill %1
```

Expected: `200`

- [ ] **Step 3: Commit**

```bash
git add assets/style.css
git commit -m "feat: add shared portal and admin styling"
```

---

## Task 15: FreeRADIUS + VPS deployment guide and config templates

**Files:**
- Create: `deploy/setup.md`
- Create: `deploy/freeradius/clients.conf.snippet`
- Create: `deploy/freeradius/sql.conf.snippet`

This task is documentation and config templates rather than application code — FreeRADIUS itself isn't installed by this repo's code, it's installed on the VPS following this guide. There's no unit test; the verification step is running FreeRADIUS's own `radtest` tool against a real (or throwaway) FreeRADIUS install, per the spec's Testing section.

**Interfaces:**
- Consumes: the `radcheck`/`radreply`/`radacct`/`nas` schema FreeRADIUS ships with (not written by this repo); this app's `entries`/`settings`/`radcheck` tables in the same MySQL database (Tasks 1–4).

- [ ] **Step 1: Write `deploy/freeradius/clients.conf.snippet`**

```
# Append to /etc/freeradius/3.0/clients.conf
client mikrotik {
    ipaddr = <MIKROTIK-PUBLIC-IP>
    secret = <SHARED-SECRET>
    shortname = mikrotik-hotspot
}
```

- [ ] **Step 2: Write `deploy/freeradius/sql.conf.snippet`**

```
# Values to set inside /etc/freeradius/3.0/mods-available/sql
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "localhost"
    port = 3306
    login = "radius"
    password = "<RADIUS-DB-PASSWORD>"
    radius_db = "radius"
}
```

- [ ] **Step 3: Write `deploy/setup.md`**

```markdown
# VPS Deployment Guide — EYIF 2026 Wi-Fi Portal + RADIUS

Target: a single Ubuntu 22.04 droplet (DigitalOcean or equivalent), 1GB RAM is enough
for a one-off event.

## 1. Provision the VPS

- Create an Ubuntu 22.04 droplet. Note its public IP (`<VPS-IP>`).
- SSH in, apply updates: `apt update && apt upgrade -y`
- Create a non-root sudo user and disable root SSH login (standard hardening).

## 2. Install MySQL and create the app database

```bash
apt install -y mysql-server
mysql -u root -e "CREATE DATABASE wifi_portal;"
mysql -u root -e "CREATE USER 'wifi_portal_user'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'wifi_portal_user'@'localhost';"
```

Clone this repo to `/var/www/wifi-portal`, then:

```bash
mysql -u root wifi_portal < schema.sql
```

## 3. Install FreeRADIUS with MySQL support

```bash
apt install -y freeradius freeradius-mysql
```

Import FreeRADIUS's own bundled SQL schema (do not hand-write this — use the
one that ships with the package, so it matches what `rlm_sql` expects):

```bash
mysql -u root -e "CREATE DATABASE radius;"
mysql -u root -e "CREATE USER 'radius'@'localhost' IDENTIFIED BY '<RADIUS-DB-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'localhost';"
mysql -u root radius < /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql
```

Edit `/etc/freeradius/3.0/mods-available/sql` with the values in
`deploy/freeradius/sql.conf.snippet`, then enable the module and the inner-tunnel
reference:

```bash
ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
```

In `/etc/freeradius/3.0/sites-available/default` and `sites-available/inner-tunnel`,
uncomment the `sql` line in both the `authorize` and `accounting` sections.

## 4. Register the Mikrotik as a RADIUS client

Append `deploy/freeradius/clients.conf.snippet` (filled in with the Mikrotik's real
public IP and a freshly generated long random secret) to
`/etc/freeradius/3.0/clients.conf`.

Generate a secret:

```bash
openssl rand -base64 24
```

Restart FreeRADIUS:

```bash
systemctl restart freeradius
systemctl enable freeradius
```

## 5. Smoke-test FreeRADIUS before touching the Mikrotik

Insert a throwaway test user directly, isolate RADIUS problems from router problems:

```bash
mysql -u root radius -e "INSERT INTO radcheck (username, attribute, op, value) VALUES ('12345678', 'Cleartext-Password', ':=', '12345678');"
radtest 12345678 12345678 localhost 0 <SHARED-SECRET-FROM-CLIENTS.CONF>
```

Expected: `Received Access-Accept`. Then:

```bash
radtest wrongcode wrongcode localhost 0 <SHARED-SECRET-FROM-CLIENTS.CONF>
```

Expected: `Received Access-Reject`. Remove the throwaway row:

```bash
mysql -u root radius -e "DELETE FROM radcheck WHERE username = '12345678';"
```

## 6. Session expiry (end of each event day)

Add a `radreply` row per new user setting `Session-Timeout` to the number of
seconds remaining until that day's cutoff. Since this depends on wall-clock time at
signup, `lib/radius.php`'s `radius_add_user()` (in the app repo) computes this value
at insert time — confirm the event's daily cutoff time with the event team before
the event and set it in `config.php` if a `SESSION_CUTOFF_HOUR` constant is added, or
adjust `radius_add_user()` directly if the app repo doesn't yet compute it. (If this
step wasn't wired into `connect.php` before deployment, sessions simply won't expire
automatically — cut off Wi-Fi manually at the end of each day instead, which is an
acceptable fallback for a one-time event.)

## 7. Install PHP and a web server for the portal

```bash
apt install -y php php-mysqli php-curl nginx composer
```

Point nginx at the repo's root (`/var/www/wifi-portal`) with PHP-FPM configured
normally. Copy `config.example.php` to `config.php`, fill in the real DB, SMTP, and
Twilio credentials, then generate the admin password hash:

```bash
cd /var/www/wifi-portal
composer install --no-dev
php hash_password.php "<a-long-random-admin-password>"
```

Paste the printed hash into `config.php`'s `ADMIN_PASSWORD_HASH`, then delete any
shell history containing the plaintext password.

## 8. Firewall

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow from <MIKROTIK-PUBLIC-IP> to any port 1812,1813 proto udp
ufw enable
```

## 9. Mikrotik-side configuration (run on the router itself, e.g. via Winbox/SSH)

```
/radius add service=hotspot address=<VPS-IP> secret=<SHARED-SECRET> \
    authentication-port=1812 accounting-port=1813
/ip hotspot profile set [find] use-radius=yes

# Allow attendees to reach the portal before they're authenticated:
/ip hotspot walled-garden add dst-host=<your-domain-or-VPS-IP> action=allow
```

In the hotspot server profile's HTML login page settings, point the login page at
`http://<your-domain>/index.php`, so Mikrotik's redirect (with its `mac`, `ip`,
`link-login-only`, `link-orig` query parameters) lands on this app instead of
Mikrotik's built-in login form.

## 10. End-to-end check

1. Connect a test device to the event Wi-Fi.
2. Confirm it's redirected to the portal page (not Mikrotik's default login page).
3. Submit the form with real test contact info.
4. Confirm the code arrives by email and SMS.
5. Confirm the device is online immediately after submitting, without seeing
   Mikrotik's own login screen.
6. From another device, try to use the same code — confirm it's rejected
   (`Simultaneous-Use := 1`) while the first device stays connected.
```

- [ ] **Step 4: Commit**

```bash
git add deploy/
git commit -m "docs: add FreeRADIUS + VPS deployment guide and Mikrotik config templates"
```

---

## Self-Review Notes (for the plan author, already applied above)

- **Spec coverage:** branding/settings (Task 2, 13), duplicate-entry handling (Task 3, verified in Task 10 Step 4), RADIUS credential issuance (Task 4, 10), CSV injection safety (Task 5), logo upload validation (Task 6), email delivery (Task 7), SMS delivery (Task 8), portal page + Mikrotik param passthrough (Task 9), auto-login flow (Task 10), admin auth (Task 11), admin entries/export (Task 12), admin branding form (Task 13), styling (Task 14), FreeRADIUS/VPS/Mikrotik deployment (Task 15) — every spec section has a task.
- **Type/signature consistency checked:** `send_code_email(PHPMailer $mail, array $settings, string $toEmail, string $toName, string $code)` used identically in Task 7's test and Task 10's `connect.php`; `send_code_sms(callable $transport, array $settings, string $toPhone, string $code)` used identically in Task 8's test and Task 10; `validate_logo_upload(array $file): array` return shape `[bool, ?string, ?string]` used identically in Task 6's test and Task 13's `admin/settings.php`.
- **No placeholders:** every step has literal file contents or literal commands with stated expected output — nothing deferred to "later."
