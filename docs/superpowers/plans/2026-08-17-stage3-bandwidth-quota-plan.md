# Stage 3: Bandwidth Quotas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut a code off after it has used a configurable amount of data, so one attendee streaming video cannot consume the venue's whole link — and give staff a live view of who is using what.

**Architecture:** The daemon starts listening on the accounting port (1813) as well as the auth port, and records what the router reports. Each RADIUS accounting packet carries the **absolute** counters for a session, not a delta, so we store them keyed on `Acct-Session-Id` and upsert — a retransmitted or duplicated packet overwrites rather than double-counts, and no delta arithmetic is needed anywhere. A code's total usage is then simply the sum over its sessions. Enforcement happens in two places: at Access-Request we reject a code that is already over quota, and on Access-Accept we send `Mikrotik-Total-Limit` set to the *remaining* allowance, so the router itself disconnects mid-session at exactly the right byte rather than waiting for us to notice.

**Tech Stack:** PHP 8.4 CLI with `ext-sockets`, MySQL 8 via mysqli, the project's custom test harness (`tests/bootstrap.php`).

## Global Constraints

- All DB queries use `mysqli` prepared statements — no string-concatenated SQL, anywhere.
- No PHP test framework — tests use `tests/bootstrap.php`'s `assert_equals($expected, $actual, string $message)`, `assert_true($condition, string $message)`, `test_summary()`.
- Codes are 8 digits, numeric only, zero-padded. The code is both the RADIUS username and password.
- **Hitting a quota must never delete the attendee's `entries` row** — it is their prize-draw entry. Same invariant as revoking.
- **Never do date arithmetic in PHP on a MySQL timestamp.** PHP and MySQL run in different timezones on this deployment (measured 1–2 hours apart). Use SQL-computed values.
- The daemon must never crash on a malformed packet or a dropped DB connection — both are logged and skipped.
- The daemon only answers packets from the configured NAS IP, except from loopback. This applies to the new accounting socket exactly as it does to the auth socket.
- Wire-supplied values are sanitised with `radius_log_safe()` before being written to the log.
- Admin pages are gated by `require_admin_session()` and use `admin_layout_start()` / `admin_layout_end()`.

## Two things that will bite if you skip them

**1. Counters are 32-bit and wrap at 4GB.** `Acct-Input-Octets` (attr 42) and `Acct-Output-Octets` (attr 43) are 32-bit. Past 4GB the router reports the overflow in `Acct-Input-Gigawords` (attr 52) and `Acct-Output-Gigawords` (attr 53). The true value is `gigawords * 2^32 + octets`. Ignoring gigawords makes a 5GB user look like a 1GB user — the quota then never fires for exactly the people it exists to stop.

**2. Stage 1 deliberately turned accounting off.** `deploy/mikrotik-setup.rsc` currently sets `radius-accounting=no`, because a daemon that did not process accounting would have made the router retry against a closed port for the whole event. Task 6 turns it back on. Until it does, no accounting packets arrive and every quota reads zero — which looks exactly like "the feature does not work".

## File Structure

| File | Change |
|---|---|
| `schema.sql` | New `radius_sessions` table |
| `lib/usage.php` (new) | Record session counters, total usage per code, reset |
| `lib/radius_protocol.php` | Accounting attribute constants + `radius_octets_64()` |
| `radius_server.php` | Second socket on the accounting port; accounting handler; quota check and `Mikrotik-Total-Limit` on Accept |
| `lib/settings.php` | New `data_quota_mb` key |
| `admin/radius.php` | Quota field |
| `admin/entries.php` | Usage column + reset action |
| `admin/index.php` | Total data served stat |
| `deploy/mikrotik-setup.rsc` | `radius-accounting=yes` |
| `deploy/setup.md` | Document quotas |

---

## Task 1: `radius_sessions` schema and `lib/usage.php`

**Files:**
- Modify: `schema.sql`
- Modify: `tests/fixtures/radius_schema.sql`
- Create: `lib/usage.php`
- Test: `tests/usage_test.php`

**Interfaces:**
- Produces: `record_session_usage(mysqli $db, string $sessionId, string $username, int $inputOctets, int $outputOctets): void` — idempotent upsert of absolute counters.
- Produces: `usage_bytes_for_code(mysqli $db, string $username): int`
- Produces: `reset_usage_for_code(mysqli $db, string $username): void`
- Produces: `total_usage_bytes(mysqli $db): int`
- Produces: `format_bytes(int $bytes): string`

- [ ] **Step 1: Add the table to `schema.sql`**

Append after the `wifi_credentials` block:

```sql
-- One row per RADIUS accounting session.
--
-- The router reports ABSOLUTE counters for a session on every interim update,
-- not deltas, so we upsert on session_id and overwrite. A retransmitted or
-- duplicated packet is then harmless — it writes the same numbers again
-- instead of double-counting, and no delta arithmetic is needed anywhere.
--
-- Deleting rows here resets a code's usage. It never touches `entries`.
CREATE TABLE IF NOT EXISTS radius_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  username VARCHAR(64) NOT NULL,
  -- BIGINT: the 32-bit wire counters are combined with their gigawords
  -- companion before they get here, so these hold the true total.
  input_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY idx_session_user (username)
);
```

- [ ] **Step 2: Add the same block to `tests/fixtures/radius_schema.sql`**

Append the identical `CREATE TABLE IF NOT EXISTS radius_sessions (...)` statement (without the comment block) to that file.

- [ ] **Step 3: Apply to both databases**

```bash
mysql -u root wifi_portal < schema.sql
mysql -u root wifi_portal_test < schema.sql
mysql -u root wifi_portal_test -e "SHOW TABLES;"
```

Expected: `entries`, `radius_sessions`, `settings`, `wifi_credentials`.

- [ ] **Step 4: Write the failing test**

Create `tests/usage_test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/usage.php';

$db = get_db();
$db->query('DELETE FROM radius_sessions');

assert_equals(0, usage_bytes_for_code($db, '11112222'), 'a code with no sessions has used nothing');

record_session_usage($db, 'sess-A', '11112222', 1000, 2000);
assert_equals(3000, usage_bytes_for_code($db, '11112222'), 'usage is input plus output');

// THE important one: interim updates carry ABSOLUTE counters, not deltas. The
// same session reporting again must overwrite, not accumulate — otherwise a
// chatty router inflates usage and cuts people off early.
record_session_usage($db, 'sess-A', '11112222', 5000, 6000);
assert_equals(11000, usage_bytes_for_code($db, '11112222'), 're-reporting a session overwrites rather than accumulating');

// A byte-for-byte duplicate (a retransmission) must change nothing.
record_session_usage($db, 'sess-A', '11112222', 5000, 6000);
assert_equals(11000, usage_bytes_for_code($db, '11112222'), 'a duplicated packet does not double-count');

// A second session for the same code DOES add.
record_session_usage($db, 'sess-B', '11112222', 1000, 1000);
assert_equals(13000, usage_bytes_for_code($db, '11112222'), 'a second session adds to the total');

// Another code is unaffected.
record_session_usage($db, 'sess-C', '99998888', 500, 500);
assert_equals(13000, usage_bytes_for_code($db, '11112222'), "another code's usage is separate");
assert_equals(1000, usage_bytes_for_code($db, '99998888'), 'the second code has its own total');

assert_equals(14000, total_usage_bytes($db), 'total_usage_bytes sums every code');

// Values above 32 bits must survive the round trip — this is the 4GB case the
// gigawords handling exists for.
record_session_usage($db, 'sess-BIG', '77776666', 6000000000, 1000000000);
assert_equals(7000000000, usage_bytes_for_code($db, '77776666'), 'usage above 4GB is stored and summed correctly');

reset_usage_for_code($db, '11112222');
assert_equals(0, usage_bytes_for_code($db, '11112222'), 'reset clears that code');
assert_equals(1000, usage_bytes_for_code($db, '99998888'), 'reset leaves other codes alone');

// Human-readable sizes.
assert_equals('0 B', format_bytes(0), 'format_bytes handles zero');
assert_equals('512 B', format_bytes(512), 'format_bytes handles bytes');
assert_equals('1.0 KB', format_bytes(1024), 'format_bytes handles kilobytes');
assert_equals('1.5 MB', format_bytes(1572864), 'format_bytes handles megabytes');
assert_equals('2.0 GB', format_bytes(2147483648), 'format_bytes handles gigabytes');

test_summary();
```

- [ ] **Step 5: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/usage_test.php`
Expected: FAIL with `Failed opening required '.../lib/usage.php'`

- [ ] **Step 6: Write `lib/usage.php`**

```php
<?php

/**
 * Record what the router says a session has transferred.
 *
 * RADIUS accounting reports ABSOLUTE counters for a session on every interim
 * update, so this overwrites rather than adds. That makes it idempotent: a
 * retransmission, a duplicate, or an out-of-order packet all converge on the
 * same stored value instead of inflating it.
 *
 * $inputOctets and $outputOctets must already have their gigawords companions
 * folded in — see radius_octets_64().
 */
function record_session_usage(mysqli $db, string $sessionId, string $username, int $inputOctets, int $outputOctets): void
{
    $stmt = $db->prepare(
        'INSERT INTO radius_sessions (session_id, username, input_octets, output_octets)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            input_octets = VALUES(input_octets),
            output_octets = VALUES(output_octets)'
    );
    $stmt->bind_param('ssii', $sessionId, $username, $inputOctets, $outputOctets);
    $stmt->execute();
    $stmt->close();
}

/** Total bytes a code has transferred, across all its sessions. */
function usage_bytes_for_code(mysqli $db, string $username): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(input_octets + output_octets), 0) AS total
           FROM radius_sessions WHERE username = ?'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['total'];
}

/**
 * Clear a code's recorded usage, giving it its full quota again.
 *
 * Deletes only session rows. The attendee's `entries` row — their prize-draw
 * entry — is never touched.
 */
function reset_usage_for_code(mysqli $db, string $username): void
{
    $stmt = $db->prepare('DELETE FROM radius_sessions WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Bytes transferred across every code. */
function total_usage_bytes(mysqli $db): int
{
    $row = $db->query(
        'SELECT COALESCE(SUM(input_octets + output_octets), 0) AS total FROM radius_sessions'
    )->fetch_assoc();
    return (int) $row['total'];
}

/** A byte count staff can read at a glance. */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, 1) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/usage_test.php`
Expected: `ALL PASSED`

- [ ] **Step 8: Commit**

```bash
git add schema.sql tests/fixtures/radius_schema.sql lib/usage.php tests/usage_test.php
git commit -m "feat: record per-session RADIUS usage idempotently"
```

---

## Task 2: Accounting attributes in the protocol library

**Files:**
- Modify: `lib/radius_protocol.php`
- Test: `tests/radius_protocol_test.php`

**Interfaces:**
- Produces (constants): `R_ATTR_ACCT_STATUS_TYPE` (40), `R_ATTR_ACCT_INPUT_OCTETS` (42), `R_ATTR_ACCT_OUTPUT_OCTETS` (43), `R_ATTR_ACCT_SESSION_ID` (44), `R_ATTR_ACCT_INPUT_GIGAWORDS` (52), `R_ATTR_ACCT_OUTPUT_GIGAWORDS` (53), `ACCT_START` (1), `ACCT_STOP` (2), `ACCT_INTERIM` (3), `MT_TOTAL_LIMIT` (17), `MT_TOTAL_LIMIT_GIGAWORDS` (15).
- Produces: `radius_uint32(string $value): int` — decode a 4-byte big-endian attribute, 0 if malformed.
- Produces: `radius_octets_64(array $attrs, int $octetsAttr, int $gigawordsAttr): int` — fold a 32-bit counter and its gigawords companion into the true total.

- [ ] **Step 1: Write the failing test**

Append to `tests/radius_protocol_test.php`, immediately before the final `test_summary();`:

```php
// --- accounting counters -------------------------------------------------
assert_equals(0, radius_uint32(''), 'an empty value decodes to zero');
assert_equals(0, radius_uint32('abc'), 'a short value decodes to zero');
assert_equals(1000, radius_uint32(pack('N', 1000)), 'a 4-byte value decodes');
assert_equals(4294967295, radius_uint32(pack('N', 4294967295)), 'the full 32-bit range decodes unsigned');

// Octets alone, no gigawords attribute present.
$attrs = [R_ATTR_ACCT_INPUT_OCTETS => pack('N', 5000)];
assert_equals(5000, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'octets without gigawords is just the octets');

// THE case this function exists for: past 4GB the router splits the value.
// Ignoring gigawords would report 1000 bytes for a 4GB transfer.
$attrs = [
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 1),
];
assert_equals(4294968296, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'gigawords are folded in as 2^32 each');

$attrs = [
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 0),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 3),
];
assert_equals(12884901888, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'three gigawords is 12GB');

// Nothing present at all.
assert_equals(0, radius_octets_64([], R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'missing counters read as zero');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/radius_protocol_test.php`
Expected: FAIL with `Call to undefined function radius_uint32()`

- [ ] **Step 3: Add the constants and functions**

In `lib/radius_protocol.php`, add these constants alongside the existing attribute constants:

```php
// Accounting attributes (RFC 2866).
const R_ATTR_ACCT_STATUS_TYPE      = 40;
const R_ATTR_ACCT_INPUT_OCTETS     = 42;
const R_ATTR_ACCT_OUTPUT_OCTETS    = 43;
const R_ATTR_ACCT_SESSION_ID       = 44;
// Counters are 32-bit and wrap at 4GB; these carry the overflow.
const R_ATTR_ACCT_INPUT_GIGAWORDS  = 52;
const R_ATTR_ACCT_OUTPUT_GIGAWORDS = 53;

// Acct-Status-Type values.
const ACCT_START   = 1;
const ACCT_STOP    = 2;
const ACCT_INTERIM = 3;
```

and alongside the existing Mikrotik VSA constants:

```php
const MT_TOTAL_LIMIT           = 17; // combined byte limit for the session
const MT_TOTAL_LIMIT_GIGAWORDS = 15; // its 2^32 multiplier, for limits over 4GB
```

Then append these functions:

```php
/**
 * Decode a 4-byte big-endian RADIUS integer.
 *
 * Returns 0 for anything malformed: these values come off the wire, and a
 * short attribute must not throw inside the daemon's packet loop.
 */
function radius_uint32(string $value): int
{
    if (strlen($value) !== 4) {
        return 0;
    }
    return (int) unpack('N', $value)[1];
}

/**
 * Fold a 32-bit octet counter together with its gigawords companion.
 *
 * RADIUS octet counters are 32-bit and wrap at 4GB; the router reports how
 * many times they wrapped in a separate attribute. The true total is
 * gigawords * 2^32 + octets. Reading the octets alone makes a 5GB transfer
 * look like 1GB — so the quota would never fire for the heaviest users, which
 * are exactly the ones it exists to stop.
 */
function radius_octets_64(array $attrs, int $octetsAttr, int $gigawordsAttr): int
{
    $octets = radius_uint32($attrs[$octetsAttr] ?? '');
    $gigawords = radius_uint32($attrs[$gigawordsAttr] ?? '');
    return ($gigawords * 4294967296) + $octets;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/radius_protocol_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/radius_protocol.php tests/radius_protocol_test.php
git commit -m "feat: decode RADIUS accounting counters including gigawords"
```

---

## Task 3: The quota setting

**Files:**
- Modify: `lib/settings.php`
- Modify: `admin/radius.php`
- Test: `tests/settings_test.php`

**Interfaces:**
- Produces: settings key `data_quota_mb`, default `'0'` (unlimited).

- [ ] **Step 1: Add the key to `SETTINGS_DEFAULTS`**

In `lib/settings.php`, after the `silent_login_enabled` entry:

```php
    // Megabytes a code may transfer before the router cuts it off. '0' means
    // unlimited — the safe default, so the feature is opt-in rather than
    // silently capping an event that never asked for it.
    'data_quota_mb' => '0',
```

- [ ] **Step 2: Write the failing test**

Append to `tests/settings_test.php`, before its final `test_summary();`:

```php
// The data quota defaults to unlimited so it is opt-in.
assert_equals('0', get_settings($db)['data_quota_mb'], 'the data quota defaults to unlimited');
save_settings($db, ['data_quota_mb' => '500']);
assert_equals('500', get_settings($db)['data_quota_mb'], 'the data quota can be set');
save_settings($db, ['data_quota_mb' => '0']);
assert_equals('0', get_settings($db)['data_quota_mb'], 'the data quota can be cleared back to unlimited');
```

- [ ] **Step 3: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: FAIL on `the data quota defaults to unlimited` — undefined index, before Step 1's edit.

(If Step 1 is already applied, confirm it passes and say so rather than claiming a red run you did not see.)

- [ ] **Step 4: Add the field to the admin form**

In `admin/radius.php`, in the POST handler where `$newSettings` is built, add:

```php
        'data_quota_mb' => (string) max(0, (int) ($_POST['data_quota_mb'] ?? 0)),
```

Then add this field immediately after the Speed cap field:

```php
    <div class="field">
      <label for="data_quota_mb">Data limit per code (MB)</label>
      <input type="text" id="data_quota_mb" name="data_quota_mb" inputmode="numeric"
             value="<?= htmlspecialchars($settings['data_quota_mb']) ?>">
      <p class="field-hint">How much a single code may download and upload in total before it is cut off. <code>0</code> means unlimited. The router enforces this, so a device is disconnected the moment it hits the limit — it does not wait for the next login. Usage is shown per code in Raffle Entries.</p>
    </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: `ALL PASSED`

- [ ] **Step 6: Verify the field saves**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
COOKIE=$(mktemp)
curl -s -c "$COOKIE" -X POST http://localhost:8010/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
curl -s -b "$COOKIE" -X POST "http://localhost:8010/admin/radius.php" \
  -d "radius_auth_port=1812" -d "radius_nas_ip=10.0.0.1" -d "session_minutes=720" \
  -d "rate_limit=" -d "silent_login_enabled=1" -d "data_quota_mb=500" -o /dev/null
mysql -u root wifi_portal -e "SELECT setting_value FROM settings WHERE setting_key='data_quota_mb';"
kill %1
```

Expected: `500`.

- [ ] **Step 7: Commit**

```bash
git add lib/settings.php admin/radius.php tests/settings_test.php
git commit -m "feat: add a per-code data quota setting"
```

---

## Task 4: The daemon listens for accounting

**Files:**
- Modify: `radius_server.php`
- Test: `tests/radius_accounting_test.php`

**Interfaces:**
- Consumes: `record_session_usage()`, `radius_octets_64()`, the accounting constants.

The daemon currently binds one socket. It must bind two — auth and accounting — and service both. `socket_select()` waits on both at once without busy-looping.

- [ ] **Step 1: Write the failing test**

Create `tests/radius_accounting_test.php`:

```php
<?php
/**
 * Integration test: starts the real daemon and sends it real accounting
 * packets, then asserts the usage landed in the database.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$authPort = 18140;
$acctPort = 18141;

$db = get_db();
$db->query('DELETE FROM radius_sessions');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $authPort,
    'radius_acct_port' => (string) $acctPort,
    'radius_nas_ip' => '127.0.0.1',
]);

$root = dirname(__DIR__);
$daemonLog = $root . '/logs/test-acct-daemon.log';
@unlink($daemonLog);
$logStart = is_file($root . '/logs/radius.log') ? filesize($root . '/logs/radius.log') : 0;

$descriptors = [1 => ['file', $daemonLog, 'a'], 2 => ['file', $daemonLog, 'a']];
$proc = proc_open('php ' . escapeshellarg($root . '/radius_server.php'), $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
assert_true(is_resource($proc), 'the daemon process started');

// Wait for BOTH sockets to be announced before probing.
$ready = false;
for ($i = 0; $i < 80; $i++) {
    usleep(250000);
    $tail = @file_get_contents($root . '/logs/radius.log', false, null, $logStart);
    if ($tail !== false && strpos($tail, 'Accounting on UDP') !== false) {
        $ready = true;
        break;
    }
}
assert_true($ready, 'the daemon bound its accounting socket');

/** Send one Accounting-Request and return true if it was acknowledged. */
function acct_probe(int $port, string $secret, array $attrs): bool
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $body = '';
    foreach ($attrs as $type => $value) {
        $body .= radius_encode_attr($type, $value);
    }
    // An Accounting-Request authenticator is MD5 over the packet with the
    // authenticator field zeroed, plus the secret.
    $header = chr(R_ACCOUNTING_REQUEST) . chr($id) . pack('n', 20 + strlen($body));
    $auth = md5($header . str_repeat("\x00", 16) . $body . $secret, true);
    $packet = $header . $auth . $body;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);
    return $got !== false && strlen($buf) >= 20 && ord($buf[0]) === R_ACCOUNTING_RESPONSE;
}

// Interim update: 1000 in, 2000 out.
$ok = acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-one',
    R_ATTR_USER_NAME => '55556666',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 2000),
]);
assert_true($ok, 'the daemon acknowledges an Accounting-Request');
usleep(400000);
assert_equals(3000, usage_bytes_for_code($db, '55556666'), 'the reported usage was recorded');

// Same session reports again with higher absolute counters — must overwrite.
acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_INTERIM),
    R_ATTR_ACCT_SESSION_ID => 'session-one',
    R_ATTR_USER_NAME => '55556666',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 4000),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 5000),
]);
usleep(400000);
assert_equals(9000, usage_bytes_for_code($db, '55556666'), 'a later report for the same session overwrites rather than accumulating');

// Over 4GB: gigawords must be folded in.
acct_probe($acctPort, $secret, [
    R_ATTR_ACCT_STATUS_TYPE => pack('N', ACCT_STOP),
    R_ATTR_ACCT_SESSION_ID => 'session-big',
    R_ATTR_USER_NAME => '44443333',
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 1),
    R_ATTR_ACCT_OUTPUT_OCTETS => pack('N', 0),
]);
usleep(400000);
assert_equals(4294968296, usage_bytes_for_code($db, '44443333'), 'gigawords are folded into the recorded usage');

// The auth socket must still work — adding accounting must not break login.
$authSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_set_option($authSock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
$id = random_int(0, 255);
$reqAuth = random_bytes(16);
$body = radius_encode_attr(R_ATTR_USER_NAME, '__healthcheck__')
      . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password('probe', $reqAuth, $secret));
$packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($body)) . $reqAuth . $body;
socket_sendto($authSock, $packet, strlen($packet), 0, '127.0.0.1', $authPort);
$buf = '';
$f = '';
$fp = 0;
$gotAuth = @socket_recvfrom($authSock, $buf, 4096, 0, $f, $fp);
socket_close($authSock);
assert_true($gotAuth !== false, 'the auth socket still answers after accounting was added');

proc_terminate($proc);
proc_close($proc);

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_accounting_test.php`
Expected: FAIL on `the daemon bound its accounting socket` — the daemon does not bind one yet.

- [ ] **Step 3: Add the accounting port setting**

In `lib/settings.php`, after `radius_auth_port`:

```php
    'radius_acct_port' => '1813',
```

- [ ] **Step 4: Bind the second socket in `radius_server.php`**

Replace the single-socket setup with two. After the existing auth socket is created and bound, add:

```php
$acctPort = (int) $settings['radius_acct_port'];
if ($acctPort < 1 || $acctPort > 65535) {
    fwrite(STDERR, "[RADIUS] radius_acct_port is not a valid port ({$acctPort}).\n");
    exit(1);
}

$acctSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($acctSock === false) {
    fwrite(STDERR, '[RADIUS] accounting socket_create failed: ' . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}
if (!socket_bind($acctSock, '0.0.0.0', $acctPort)) {
    fwrite(STDERR, "[RADIUS] cannot bind UDP {$acctPort}: " . socket_strerror(socket_last_error($acctSock)) . "\n");
    exit(1);
}
```

and log it next to the existing listening line:

```php
radius_log("Accounting on UDP 0.0.0.0:{$acctPort}");
```

- [ ] **Step 5: Service both sockets in the loop**

Replace the single `socket_recvfrom` at the top of the loop body with a `socket_select` over both, so neither socket starves and the loop still wakes once a second for the restart flag:

```php
    // Wait on both sockets at once. The 1-second timeout keeps the restart-flag
    // and settings-reload checks above running on schedule when the network is
    // quiet.
    $read = [$sock, $acctSock];
    $write = null;
    $except = null;
    $ready = @socket_select($read, $write, $except, 1);
    if ($ready === false || $ready === 0) {
        continue;
    }

    foreach ($read as $active) {
        $isAccounting = ($active === $acctSock);
        $buf = '';
        $from = '';
        $fromPort = 0;
        $received = @socket_recvfrom($active, $buf, 4096, 0, $from, $fromPort);
        if ($received === false || $received < 20) {
            continue;
        }
        // ... existing per-packet handling, using $active for the reply ...
    }
```

Move the existing per-packet body inside this `foreach`, and change every `socket_sendto($sock, ...)` in that body to `socket_sendto($active, ...)` so a reply goes back out of the socket the request arrived on.

- [ ] **Step 6: Handle the accounting packet**

Replace the existing stub accounting branch with a real handler:

```php
        if ($code === R_ACCOUNTING_REQUEST) {
            $sessionId = $attrs[R_ATTR_ACCT_SESSION_ID] ?? '';
            $acctUser = $attrs[R_ATTR_USER_NAME] ?? '';
            $statusType = radius_uint32($attrs[R_ATTR_ACCT_STATUS_TYPE] ?? '');

            // Acknowledge first, unconditionally: the router retries until it
            // is acknowledged, and a DB problem on our side must not turn into
            // a retry storm.
            $reply = radius_build_reply(R_ACCOUNTING_RESPONSE, $identifier, $requestAuth, $secret, '');
            socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);

            if ($sessionId !== '' && $acctUser !== '') {
                // Counters are 32-bit; radius_octets_64() folds in the
                // gigawords companion so a transfer over 4GB is not truncated.
                $in = radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS);
                $out = radius_octets_64($attrs, R_ATTR_ACCT_OUTPUT_OCTETS, R_ATTR_ACCT_OUTPUT_GIGAWORDS);
                try {
                    db_run(fn(mysqli $d) => record_session_usage($d, $sessionId, $acctUser, $in, $out));
                    radius_log('ACCT ' . radius_log_safe($acctUser)
                        . ' type=' . $statusType
                        . ' in=' . $in . ' out=' . $out);
                } catch (Throwable $e) {
                    radius_log('Could not record usage: ' . $e->getMessage());
                }
            }
            continue;
        }
```

Add `require_once __DIR__ . '/lib/usage.php';` to the daemon's requires.

- [ ] **Step 7: Run the accounting test**

Run: `DB_NAME=wifi_portal_test php tests/radius_accounting_test.php`
Expected: `ALL PASSED`

- [ ] **Step 8: Run the existing daemon test to confirm auth still works**

Run: `DB_NAME=wifi_portal_test php tests/radius_daemon_test.php`
Expected: `ALL PASSED` — all 11 assertions. Adding a second socket must not have broken login.

- [ ] **Step 9: Commit**

```bash
git add radius_server.php lib/settings.php tests/radius_accounting_test.php
git commit -m "feat: listen for RADIUS accounting and record usage"
```

---

## Task 5: Enforce the quota

**Files:**
- Modify: `radius_server.php`
- Test: `tests/radius_quota_test.php`

Enforcement is in two places. Rejecting an over-quota code at Access-Request stops it coming back. Sending `Mikrotik-Total-Limit` with the *remaining* allowance is what actually cuts an active session at the right byte — the router enforces it without waiting for us.

- [ ] **Step 1: Write the failing test**

Create `tests/radius_quota_test.php`:

```php
<?php
/**
 * Integration test: a code over its quota is rejected, and a code under it is
 * accepted with a Mikrotik-Total-Limit carrying what is left.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$authPort = 18150;

$db = get_db();
$db->query('DELETE FROM radius_sessions');
$db->query('DELETE FROM wifi_credentials');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $authPort,
    'radius_acct_port' => '18151',
    'radius_nas_ip' => '127.0.0.1',
    'data_quota_mb' => '100',            // 100 MB = 104857600 bytes
]);

issue_credential($db, '10001000', 600);  // under quota
issue_credential($db, '20002000', 600);  // will be over quota
record_session_usage($db, 'over-1', '20002000', 104857600, 1);

$root = dirname(__DIR__);
$daemonLog = $root . '/logs/test-quota-daemon.log';
@unlink($daemonLog);
$logStart = is_file($root . '/logs/radius.log') ? filesize($root . '/logs/radius.log') : 0;
$descriptors = [1 => ['file', $daemonLog, 'a'], 2 => ['file', $daemonLog, 'a']];
$proc = proc_open('php ' . escapeshellarg($root . '/radius_server.php'), $descriptors, $pipes, $root, null, ['bypass_shell' => true]);

$ready = false;
for ($i = 0; $i < 80; $i++) {
    usleep(250000);
    $tail = @file_get_contents($root . '/logs/radius.log', false, null, $logStart);
    if ($tail !== false && strpos($tail, 'Listening on UDP') !== false) { $ready = true; break; }
}
assert_true($ready, 'the daemon started');

function auth_probe(int $port, string $secret, string $user): ?array
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $body = radius_encode_attr(R_ATTR_USER_NAME, $user)
          . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password($user, $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($body)) . $auth . $body;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $f = '';
    $fp = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $f, $fp);
    socket_close($sock);
    if ($got === false || strlen($buf) < 20) {
        return null;
    }
    return [ord($buf[0]), radius_parse_attributes(substr($buf, 20))];
}

/** Pull the Mikrotik-Total-Limit out of a reply's vendor attributes. */
function total_limit_from(array $parsed): ?int
{
    if (!isset($parsed[R_ATTR_VENDOR_SPECIFIC])) {
        return null;
    }
    $inner = $parsed[R_ATTR_VENDOR_SPECIFIC];
    if (ord($inner[4]) !== MT_TOTAL_LIMIT) {
        return null;
    }
    return radius_uint32(substr($inner, 6, 4));
}

$under = auth_probe($authPort, $secret, '10001000');
assert_equals(R_ACCESS_ACCEPT, $under[0], 'a code under its quota is accepted');

$over = auth_probe($authPort, $secret, '20002000');
assert_equals(R_ACCESS_REJECT, $over[0], 'a code over its quota is rejected');

// With no usage recorded, the full quota should be offered as the limit.
$limit = total_limit_from($under[1]);
assert_true($limit !== null, 'the Accept carries a Mikrotik-Total-Limit');
assert_equals(104857600, $limit, 'the limit is the full quota when nothing has been used');

// After using half, the limit offered should be the remainder.
record_session_usage($db, 'half-1', '10001000', 52428800, 0);
$half = auth_probe($authPort, $secret, '10001000');
assert_equals(R_ACCESS_ACCEPT, $half[0], 'a half-used code is still accepted');
assert_equals(52428800, total_limit_from($half[1]), 'the limit offered is what is left, not the full quota');

// Quota off: no limit attribute should be sent at all.
save_settings($db, ['data_quota_mb' => '0']);
sleep(11);   // the daemon re-reads settings every 10 seconds
$noQuota = auth_probe($authPort, $secret, '20002000');
assert_equals(R_ACCESS_ACCEPT, $noQuota[0], 'with the quota off, a previously over-quota code is accepted again');

proc_terminate($proc);
proc_close($proc);

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_quota_test.php`
Expected: FAIL on `a code over its quota is rejected` — no quota logic exists yet.

- [ ] **Step 3: Add the quota check and the limit attribute**

In `radius_server.php`, after the password check succeeds and before the reply attributes are built, add:

```php
    // Data quota. 0 means unlimited.
    $quotaMb = (int) ($settings['data_quota_mb'] ?? 0);
    $quotaBytes = $quotaMb * 1048576;
    $remaining = 0;

    if ($quotaBytes > 0) {
        try {
            $used = db_run(fn(mysqli $d) => usage_bytes_for_code($d, $username));
        } catch (Throwable $e) {
            // A usage lookup failure must not lock a paying attendee out, so
            // fail open on the quota specifically — the credential itself has
            // already been verified above.
            radius_log('Could not read usage, allowing: ' . $e->getMessage());
            $used = 0;
        }
        $remaining = $quotaBytes - $used;
        if ($remaining <= 0) {
            radius_log('REJECT ' . radius_log_safe($username) . ': data quota exhausted');
            $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
                radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Data limit reached'));
            socket_sendto($active, $reply, strlen($reply), 0, $from, $fromPort);
            continue;
        }
    }
```

Then, where the reply attributes are assembled, after the rate-limit attribute, add:

```php
    if ($quotaBytes > 0) {
        // Hand the router the REMAINING allowance so it disconnects the device
        // itself the moment the limit is hit, rather than the session running
        // on until the next login attempt.
        $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_TOTAL_LIMIT, pack('N', $remaining % 4294967296));
        if ($remaining >= 4294967296) {
            // Over 4GB the limit needs its own gigawords companion, for the
            // same reason the counters do.
            $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_TOTAL_LIMIT_GIGAWORDS,
                pack('N', intdiv($remaining, 4294967296)));
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/radius_quota_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Run the other daemon tests**

```bash
DB_NAME=wifi_portal_test php tests/radius_daemon_test.php
DB_NAME=wifi_portal_test php tests/radius_accounting_test.php
```

Expected: `ALL PASSED` from both.

- [ ] **Step 6: Commit**

```bash
git add radius_server.php tests/radius_quota_test.php
git commit -m "feat: reject over-quota codes and cap sessions at the remaining allowance"
```

---

## Task 6: Turn accounting back on at the router

**Files:**
- Modify: `deploy/mikrotik-setup.rsc`
- Modify: `admin/radius.php`

Stage 1 set `radius-accounting=no` because nothing processed accounting. It does now.

- [ ] **Step 1: Flip the flag**

In `deploy/mikrotik-setup.rsc`, on the `/ip hotspot profile set` line, change `radius-accounting=no` to `radius-accounting=yes`, and replace the explanatory comment above it with:

```
# Accounting is ON: the daemon listens on the accounting port and records what
# each code transfers, which is what makes the data limit work. If you turn it
# off, usage stays at zero and the data limit silently never fires.
```

- [ ] **Step 2: Substitute the accounting port from settings**

The template hardcodes `accounting-port=1813`. Make it a token so it follows the setting. In `deploy/mikrotik-setup.rsc`, replace both occurrences of `accounting-port=1813` with `accounting-port=__ACCT_PORT__`.

In `admin/radius.php`, add to the token substitution array:

```php
        '__ACCT_PORT__' => (string) $settings['radius_acct_port'],
```

- [ ] **Step 3: Verify the generated config**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
COOKIE=$(mktemp)
curl -s -c "$COOKIE" -X POST http://localhost:8010/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
curl -s -b "$COOKIE" "http://localhost:8010/admin/radius.php?download=rsc" | grep -E "accounting-port|radius-accounting"
kill %1
```

Expected: `accounting-port=1813` substituted (no `__ACCT_PORT__` left) and `radius-accounting=yes`.

- [ ] **Step 4: Commit**

```bash
git add deploy/mikrotik-setup.rsc admin/radius.php
git commit -m "feat: enable RADIUS accounting on the router now the daemon processes it"
```

---

## Task 7: Show usage to staff

**Files:**
- Modify: `admin/entries.php`
- Modify: `admin/index.php`

- [ ] **Step 1: Add a usage column to the entries table**

In `admin/entries.php`, add the require:

```php
require_once __DIR__ . '/../lib/usage.php';
```

Add usage to the query by joining the session totals. Replace the SELECT with:

```php
$result = $db->query(
    'SELECT e.name, e.phone, e.email, e.code, e.created_at,
            c.expires_at,
            c.mac,
            TIMESTAMPDIFF(SECOND, NOW(), c.expires_at) AS seconds_remaining,
            COALESCE(u.used, 0) AS used_bytes
       FROM entries e
       LEFT JOIN wifi_credentials c ON c.username = e.code
       LEFT JOIN (
            SELECT username, SUM(input_octets + output_octets) AS used
              FROM radius_sessions GROUP BY username
       ) u ON u.username = e.code
      ORDER BY e.created_at DESC'
);
```

Add a `Data` header cell after `Wi-Fi`:

```php
            <th>Data</th>
```

and the matching cell after the Wi-Fi cell:

```php
            <td class="num-cell"><?= htmlspecialchars(format_bytes((int) $row['used_bytes']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
```

- [ ] **Step 2: Add a reset-usage action**

In the POST handler block at the top of `admin/entries.php`, alongside the revoke branch, add:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_usage') {
    $code = trim((string) ($_POST['code'] ?? ''));
    if (preg_match('/^[0-9]{8}$/', $code) === 1) {
        // Clears recorded usage only — the raffle entry and the credential are
        // both untouched, so this gives someone their full allowance back
        // without giving them a new code.
        reset_usage_for_code($db, $code);
        header('Location: entries.php?usagereset=' . urlencode($code));
    } else {
        header('Location: entries.php?error=badcode');
    }
    exit;
}
```

and the notice for it, alongside the existing `$revoked` handling:

```php
$usageReset = trim((string) ($_GET['usagereset'] ?? ''));
if (preg_match('/^[0-9]{8}$/', $usageReset) === 1) {
    $notice = "Data usage reset for code {$usageReset}. Their allowance starts again.";
}
```

Then add the button in the actions cell, after the revoke form:

```php
              <?php if ((int) $row['used_bytes'] > 0): ?>
                <form method="post" style="margin-top:var(--space-1)"
                      onsubmit="return confirm('Reset data usage for code <?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?\n\nThey get their full allowance again. Their code and raffle entry do not change.')">
                  <input type="hidden" name="action" value="reset_usage">
                  <input type="hidden" name="code" value="<?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <button type="submit" class="btn-inline secondary">Reset data</button>
                </form>
              <?php endif; ?>
```

- [ ] **Step 3: Add the tabular-number style**

Append to `assets/style.css`:

```css
/* Byte counts line up column-wise when the digits are tabular. */
.num-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
```

- [ ] **Step 4: Add a dashboard stat**

In `admin/index.php`, add the require:

```php
require_once __DIR__ . '/../lib/usage.php';
```

after `$activeCredentials`, add:

```php
$dataServed = total_usage_bytes($db);
```

and a fourth stat card after the Active Wi-Fi codes card:

```php
  <div class="stat">
    <span class="stat-label">Data served</span>
    <span class="stat-value"><?= htmlspecialchars(format_bytes($dataServed)) ?></span>
  </div>
```

- [ ] **Step 5: Verify both pages**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
COOKIE=$(mktemp)
curl -s -c "$COOKIE" -X POST http://localhost:8010/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
echo "--- dashboard shows the stat ---"
curl -s -b "$COOKIE" "http://localhost:8010/admin/index.php" | grep -o "Data served"
echo "--- entries page has the Data column ---"
curl -s -b "$COOKIE" "http://localhost:8010/admin/entries.php" | grep -o "<th>Data</th>"
kill %1
```

Expected: `Data served` and `<th>Data</th>`.

- [ ] **Step 6: Commit**

```bash
git add admin/entries.php admin/index.php assets/style.css
git commit -m "feat: show data usage per code and in total"
```

---

## Task 8: Document quotas

**Files:**
- Modify: `deploy/setup.md`
- Modify: `deploy/GO-LIVE.md`

- [ ] **Step 1: Add a section to `deploy/setup.md`**

Insert before the Troubleshooting section:

```markdown
## Data limits

**Data limit per code (MB)** on the Wi-Fi & RADIUS page caps how much a single
code may transfer in total. `0` — the default — means unlimited.

How it works: the router reports each session's transfer to the daemon, which
records it per code. On every login the daemon hands the router the *remaining*
allowance, so the router disconnects the device itself the instant the limit is
hit rather than waiting for the next login. A code that is already over its
limit is refused with "Data limit reached".

Two things to know:

- **Accounting must be on at the router.** The generated config sets
  `radius-accounting=yes`. If it is off, usage stays at zero and the limit
  silently never fires — which looks exactly like the feature not working.
- **Usage accumulates across sessions and across days.** A code that used its
  whole allowance on day 1 has none left on day 2. Staff can give an allowance
  back with **Reset data** on the Raffle Entries page; that changes neither the
  code nor the raffle entry.

Per-code usage is shown in Raffle Entries, and the event total on the Dashboard.
```

Add a troubleshooting row:

```markdown
| Data usage shows 0 for everyone | Accounting is off at the router, or UDP 1813 is blocked | Check `radius-accounting=yes` on the hotspot profile; the daemon logs `Accounting on UDP` at startup |
```

- [ ] **Step 2: Add the quota to the go-live runbook**

In `deploy/GO-LIVE.md`, in the Phase 5 settings table, add a row after Speed cap:

```markdown
| Data limit per code | `0` for unlimited, or e.g. `500` MB |
```

In Phase 7 (Firewall), add accounting to the RADIUS rule:

```bash
ufw allow from <ROUTER-PUBLIC-IP> to any port 1812,1813 proto udp
```

In Phase 9 (Smoke test), add a check:

```markdown
- [ ] **10.** After browsing for a minute, **Raffle Entries** shows a non-zero **Data** figure for that code.
      *Fails →* accounting is off at the router, or UDP 1813 is blocked. The daemon logs `Accounting on UDP` at startup if it is listening.
```

In the Known limits section, replace the "No bandwidth quota yet" bullet with:

```markdown
- **Data limits are enforced by the router**, using the remaining allowance we send at login. Usage is only as current as the router's interim-update interval, so a device can overshoot slightly before it is cut off.
```

- [ ] **Step 3: Run the whole suite**

```bash
for t in settings settings_secret entries credentials usage radius radius_protocol radius_daemon radius_accounting radius_quota; do
  echo -n "$t: "; DB_NAME=wifi_portal_test php tests/${t}_test.php | tail -1
done
for t in csv uploads mailer sms admin_auth radius_log_safe; do
  echo -n "$t: "; php tests/${t}_test.php | tail -1
done
```

Expected: `ALL PASSED` on all sixteen.

- [ ] **Step 4: Commit**

```bash
git add deploy/setup.md deploy/GO-LIVE.md
git commit -m "docs: document data limits and add them to the go-live checks"
```

---

## Self-Review Notes

**Spec coverage:** per-session usage recording, idempotent (Task 1); gigawords folding so transfers over 4GB are not truncated (Tasks 1, 2); accounting socket and handler (Task 4); enforcement both at login and mid-session via `Mikrotik-Total-Limit` (Task 5); the router-side flag Stage 1 deliberately disabled (Task 6); staff visibility and a reset control (Task 7); documentation including the "usage shows zero" failure mode (Task 8).

**Type consistency:** `record_session_usage(mysqli, string, string, int, int): void` is used identically in Tasks 1, 4 and both integration tests. `usage_bytes_for_code(mysqli, string): int` in Tasks 1, 5, 7. `format_bytes(int): string` in Tasks 1, 7. `radius_octets_64(array, int, int): int` in Tasks 2, 4. The new settings keys `radius_acct_port` and `data_quota_mb` are read with those exact strings in Tasks 3, 4, 5, 6.

**Design decisions worth restating:** absolute counters keyed on `Acct-Session-Id` rather than delta accumulation, because retransmissions and duplicates are normal on UDP and delta maths would inflate usage; acknowledging the accounting packet *before* the database write, so a DB problem cannot cause a router retry storm; failing *open* on a usage-lookup error, because a database hiccup should not lock out an attendee whose credential already verified.

**Deliberately out of scope:** no RADIUS CoA/Disconnect-Message, so revoking still blocks the next re-auth rather than cutting a live session — the quota gets instant cutoff only because the router enforces the limit it was handed. No per-device quota; the quota is per code, consistent with everything else in the system.
