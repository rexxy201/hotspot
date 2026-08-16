# Stage 1: UDP RADIUS Daemon, Time Limits & Rate Caps — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the FreeRADIUS dependency with a self-contained PHP UDP RADIUS daemon that authenticates attendee codes, enforces a per-session time limit and a bandwidth rate cap, and is fully operable from the admin UI (encrypted settings, live log, diagnostics, router-config generator) without SSH.

**Architecture:** A long-running PHP CLI process (`radius_server.php`) binds UDP 1812 and answers Mikrotik's Access-Requests directly from our own `wifi_credentials` table — no FreeRADIUS, no `radcheck`/`radreply` schema, no `mods-enabled` symlinks. RADIUS packet encoding/decoding lives in a pure-function library (`lib/radius_protocol.php`) so it is unit-testable without sockets; the daemon is a thin socket loop over it. Time limits ride on `Session-Timeout` (attr 27) plus `Mikrotik-Uptime-Limit` (VSA 7) computed from each credential's `expires_at`; the rate cap rides on `Mikrotik-Rate-Limit` (VSA 9). Operator-facing secrets are stored AES-256-CBC encrypted at rest, keyed off an `APP_KEY` that lives in the gitignored `config.php` rather than the database.

**Tech Stack:** PHP 8.4 CLI with `ext-sockets` and `ext-openssl`, MySQL 8 via mysqli, the project's existing custom test harness (`tests/bootstrap.php`), systemd (production) / `start_radius.sh` (any host).

## Global Constraints

- All DB queries use `mysqli` prepared statements — no string-concatenated SQL, anywhere.
- No PHP test framework (no PHPUnit) — tests use the custom assertion harness in `tests/bootstrap.php`, which provides `assert_equals($expected, $actual, string $message)`, `assert_true($condition, string $message)` and `test_summary()`.
- Codes are 8 digits, numeric only, zero-padded (`00000000`–`99999999`). The code is both the RADIUS username and the RADIUS password.
- `config.php` holds real credentials and is gitignored; `config.example.php` is the committed template. Config values are read via `getenv() ?: 'default'`.
- Revoking Wi-Fi access must NEVER delete the attendee's `entries` row — the code is also their prize-draw entry. Only `wifi_credentials` rows may be deleted.
- Secrets stored in the `settings` table (`radius_secret`, `smtp_password`, `twilio_auth_token`) are encrypted at rest with AES-256-CBC using `APP_KEY`. `APP_KEY` itself lives only in `config.php`, never in the database.
- The daemon only answers packets from the configured NAS (router) IP, except from loopback (`127.0.0.1` / `::1`), which is reserved for the admin health-check probe.
- The daemon must never crash on a malformed packet or a dropped DB connection — both are logged and skipped.
- Admin pages are gated by `require_admin_session()` before any output, and use the sidebar shell via `admin_layout_start($activeFile, $title, $settings)` / `admin_layout_end()` from `admin/layout.php`.
- New admin sections must be registered in `ADMIN_NAV` in `admin/layout.php` so the sidebar stays consistent across pages.

## Existing Code This Plan Replaces

- `lib/radius.php` — currently writes FreeRADIUS `radcheck` rows via `radius_add_user(mysqli $db, string $code): void` and `radius_user_exists(mysqli $db, string $code): bool`. Task 6 rewrites both against the new table.
- `schema.sql` — currently creates a `radcheck` table. Task 1 replaces it with `wifi_credentials`.
- `tests/radius_test.php` and `tests/fixtures/radius_schema.sql` — Task 1 and Task 6 update these.
- `deploy/setup.md` — currently documents installing FreeRADIUS. Task 11 rewrites it.

## File Structure

| File | Responsibility |
|---|---|
| `schema.sql` (modify) | Adds `wifi_credentials`, drops `radcheck` |
| `lib/credentials.php` (new) | CRUD for `wifi_credentials` — issue, look up, revoke |
| `lib/radius_protocol.php` (new) | Pure RADIUS encode/decode. No sockets, no DB — fully unit-testable |
| `lib/settings.php` (modify) | Adds encrypted-at-rest secret handling |
| `lib/radius.php` (modify) | Re-pointed from `radcheck` to `wifi_credentials` |
| `radius_server.php` (new) | The UDP daemon: socket loop over the protocol library |
| `start_radius.sh` (new) | start/stop/restart/status wrapper writing `logs/radius.pid` |
| `deploy/mangonet-radius.service` (new) | systemd unit with `Restart=always` |
| `deploy/mikrotik-setup.rsc` (modify) | Template with `__PLACEHOLDER__` tokens |
| `admin/radius.php` (new) | RADIUS settings form + diagnostics + router-config download |
| `admin/radius-log.php` (new) | Live log tail, "trust this IP", restart daemon |
| `admin/layout.php` (modify) | Registers the two new nav entries |
| `connect.php` (modify) | Issues a time-limited credential instead of a `radcheck` row |
| `deploy/setup.md` (modify) | Rewritten for the daemon |

---

## Task 1: `wifi_credentials` schema

**Files:**
- Modify: `schema.sql`
- Modify: `tests/fixtures/radius_schema.sql`

**Interfaces:**
- Produces: the `wifi_credentials` table, consumed by every later task.

The `mac` column is nullable and unused in Stage 1. It is included now because Stage 2 (silent login by MAC) needs it, and adding it here costs one line versus a schema migration against a live event database later.

- [ ] **Step 1: Replace the `radcheck` table in `schema.sql`**

Open `schema.sql`. Delete the entire `CREATE TABLE IF NOT EXISTS radcheck (...);` block (including its preceding comment about mirroring FreeRADIUS) and put this in its place:

```sql
-- Wi-Fi credentials consumed by radius_server.php (our own UDP RADIUS daemon).
-- `username` and `password` both hold the attendee's 8-digit code. Deleting a
-- row revokes Wi-Fi access WITHOUT touching the attendee's `entries` row, which
-- remains their prize-draw entry.
CREATE TABLE IF NOT EXISTS wifi_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(64) NOT NULL,
  -- Reserved for Stage 2 (silent login keyed on device MAC).
  mac VARCHAR(20) DEFAULT NULL,
  rate_limit VARCHAR(60) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wifi_username (username),
  KEY idx_wifi_mac (mac),
  KEY idx_wifi_expires (expires_at)
);
```

- [ ] **Step 2: Replace the test fixture**

Overwrite `tests/fixtures/radius_schema.sql` with exactly:

```sql
CREATE TABLE IF NOT EXISTS wifi_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(64) NOT NULL,
  mac VARCHAR(20) DEFAULT NULL,
  rate_limit VARCHAR(60) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wifi_username (username),
  KEY idx_wifi_mac (mac),
  KEY idx_wifi_expires (expires_at)
);
```

- [ ] **Step 3: Apply to both databases and drop the old table**

```bash
mysql -u root wifi_portal -e "DROP TABLE IF EXISTS radcheck;"
mysql -u root wifi_portal_test -e "DROP TABLE IF EXISTS radcheck;"
mysql -u root wifi_portal < schema.sql
mysql -u root wifi_portal_test < schema.sql
```

- [ ] **Step 4: Verify both databases have the new table**

```bash
mysql -u root wifi_portal -e "DESCRIBE wifi_credentials;"
mysql -u root wifi_portal_test -e "SHOW TABLES;"
```

Expected: `wifi_credentials` describes with columns `id, username, password, mac, rate_limit, expires_at, last_used_at, created_at`; `wifi_portal_test` lists `entries`, `settings`, `wifi_credentials` and no `radcheck`.

- [ ] **Step 5: Commit**

```bash
git add schema.sql tests/fixtures/radius_schema.sql
git commit -m "feat: replace FreeRADIUS radcheck table with wifi_credentials"
```

---

## Task 2: `lib/credentials.php` — credential storage

**Files:**
- Create: `lib/credentials.php`
- Test: `tests/credentials_test.php`

**Interfaces:**
- Consumes: `get_db(): mysqli` from `db.php`.
- Produces:
  - `issue_credential(mysqli $db, string $code, int $minutes, ?string $rateLimit = null, ?string $mac = null): void`
  - `find_valid_credential(mysqli $db, string $username): ?array` — returns the row only if `expires_at > NOW()`, else `null`
  - `revoke_credential(mysqli $db, string $username): void`
  - `touch_credential(mysqli $db, string $username): void`
  - `count_active_credentials(mysqli $db): int`

- [ ] **Step 1: Write the failing test**

Create `tests/credentials_test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/credentials.php';

$db = get_db();
$db->query('DELETE FROM wifi_credentials');

assert_equals(null, find_valid_credential($db, '00000000'), 'find_valid_credential returns null when nothing is issued');

issue_credential($db, '04829371', 60, '5M/5M');
$row = find_valid_credential($db, '04829371');
assert_true($row !== null, 'find_valid_credential finds a freshly issued credential');
assert_equals('04829371', $row['password'], 'the password is the code itself');
assert_equals('5M/5M', $row['rate_limit'], 'the rate limit is stored');

// Re-issuing the same code must replace, not duplicate (UNIQUE on username).
issue_credential($db, '04829371', 120, '10M/10M');
$count = $db->query("SELECT COUNT(*) c FROM wifi_credentials WHERE username = '04829371'")->fetch_assoc()['c'];
assert_equals('1', $count, 're-issuing the same code replaces the existing row');
assert_equals('10M/10M', find_valid_credential($db, '04829371')['rate_limit'], 're-issuing updates the rate limit');

// An expired credential must not be returned.
issue_credential($db, '11112222', -5);
assert_equals(null, find_valid_credential($db, '11112222'), 'an expired credential is not returned');

assert_equals(1, count_active_credentials($db), 'count_active_credentials ignores expired rows');

revoke_credential($db, '04829371');
assert_equals(null, find_valid_credential($db, '04829371'), 'revoke_credential removes the credential');

touch_credential($db, '11112222');
$touched = $db->query("SELECT last_used_at FROM wifi_credentials WHERE username = '11112222'")->fetch_assoc();
assert_true($touched['last_used_at'] !== null, 'touch_credential records last_used_at');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/credentials_test.php`
Expected: FAIL with `Failed opening required '.../lib/credentials.php'`

- [ ] **Step 3: Write `lib/credentials.php`**

```php
<?php

/**
 * Issue (or replace) a Wi-Fi credential for a code.
 *
 * `$minutes` may be negative, which produces an already-expired row — used by
 * the tests to exercise the expiry path.
 */
function issue_credential(mysqli $db, string $code, int $minutes, ?string $rateLimit = null, ?string $mac = null): void
{
    $expires = date('Y-m-d H:i:s', time() + ($minutes * 60));
    $stmt = $db->prepare(
        'INSERT INTO wifi_credentials (username, password, mac, rate_limit, expires_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            mac = VALUES(mac),
            rate_limit = VALUES(rate_limit),
            expires_at = VALUES(expires_at)'
    );
    // The code is both the username and the password.
    $stmt->bind_param('sssss', $code, $code, $mac, $rateLimit, $expires);
    $stmt->execute();
    $stmt->close();
}

/** The credential for $username, or null if missing or expired. */
function find_valid_credential(mysqli $db, string $username): ?array
{
    $stmt = $db->prepare(
        'SELECT id, username, password, mac, rate_limit, expires_at
           FROM wifi_credentials
          WHERE username = ? AND expires_at > NOW()
          LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Revoke Wi-Fi access for a code.
 *
 * This deletes ONLY the credential. The attendee's `entries` row is their
 * prize-draw entry and must survive revocation.
 */
function revoke_credential(mysqli $db, string $username): void
{
    $stmt = $db->prepare('DELETE FROM wifi_credentials WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Record that a credential just authenticated successfully. */
function touch_credential(mysqli $db, string $username): void
{
    $stmt = $db->prepare('UPDATE wifi_credentials SET last_used_at = NOW() WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** How many credentials are currently valid. */
function count_active_credentials(mysqli $db): int
{
    $row = $db->query('SELECT COUNT(*) AS c FROM wifi_credentials WHERE expires_at > NOW()')->fetch_assoc();
    return (int) $row['c'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/credentials_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/credentials.php tests/credentials_test.php
git commit -m "feat: add wifi_credentials storage layer"
```

---

## Task 3: `lib/radius_protocol.php` — RADIUS packet encoding

**Files:**
- Create: `lib/radius_protocol.php`
- Test: `tests/radius_protocol_test.php`

**Interfaces:**
- Produces (constants): `R_ACCESS_REQUEST` (1), `R_ACCESS_ACCEPT` (2), `R_ACCESS_REJECT` (3), `R_ACCOUNTING_REQUEST` (4), `R_ACCOUNTING_RESPONSE` (5), `R_ATTR_USER_NAME` (1), `R_ATTR_USER_PASSWORD` (2), `R_ATTR_CHAP_PASSWORD` (3), `R_ATTR_REPLY_MESSAGE` (18), `R_ATTR_VENDOR_SPECIFIC` (26), `R_ATTR_SESSION_TIMEOUT` (27), `R_ATTR_CALLING_STATION` (31), `R_ATTR_CHAP_CHALLENGE` (60), `VENDOR_MIKROTIK` (14988), `MT_GROUP` (5), `MT_UPTIME_LIMIT` (7), `MT_RATE_LIMIT` (9).
- Produces (functions):
  - `radius_parse_attributes(string $data): array` — `[int $type => string $value]`
  - `radius_encode_attr(int $type, string $value): string`
  - `radius_encode_vsa(int $vendor, int $subType, string $value): string`
  - `radius_build_reply(int $code, int $id, string $requestAuthenticator, string $secret, string $attrs): string`
  - `radius_decrypt_password(string $encrypted, string $requestAuthenticator, string $secret): string`
  - `radius_encrypt_password(string $plain, string $requestAuthenticator, string $secret): string` — the inverse, used only by tests and the health-check probe
  - `radius_check_chap(string $chapPassword, string $challenge, string $plainPassword): bool`

This file has no `require`s, touches no sockets and no database, so every function is directly testable.

- [ ] **Step 1: Write the failing test**

Create `tests/radius_protocol_test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

// --- attribute encoding -------------------------------------------------
$attr = radius_encode_attr(R_ATTR_USER_NAME, 'alice');
assert_equals(chr(1) . chr(7) . 'alice', $attr, 'radius_encode_attr writes type, length and value');

// --- round trip ---------------------------------------------------------
$packed = radius_encode_attr(R_ATTR_USER_NAME, '04829371')
        . radius_encode_attr(R_ATTR_CALLING_STATION, 'AA:BB:CC:DD:EE:FF');
$parsed = radius_parse_attributes($packed);
assert_equals('04829371', $parsed[R_ATTR_USER_NAME], 'parse recovers the username');
assert_equals('AA:BB:CC:DD:EE:FF', $parsed[R_ATTR_CALLING_STATION], 'parse recovers the calling station');

// --- malformed input must not loop or throw -----------------------------
assert_equals([], radius_parse_attributes(''), 'empty input parses to an empty array');
// Declared length 200 but only a few bytes present: must stop, not overrun.
assert_equals([], radius_parse_attributes(chr(1) . chr(200) . 'xx'), 'over-long declared length is discarded');
// Length byte below the 2-byte minimum would mean zero forward progress.
assert_equals([], radius_parse_attributes(chr(1) . chr(0) . 'xx'), 'under-length attribute is discarded');

// --- vendor-specific attributes ----------------------------------------
$vsa = radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, '5M/5M');
$outer = radius_parse_attributes($vsa);
assert_true(isset($outer[R_ATTR_VENDOR_SPECIFIC]), 'a VSA is wrapped in attribute 26');
$inner = $outer[R_ATTR_VENDOR_SPECIFIC];
assert_equals(VENDOR_MIKROTIK, unpack('N', substr($inner, 0, 4))[1], 'the VSA carries the Mikrotik vendor id');
assert_equals(MT_RATE_LIMIT, ord($inner[4]), 'the VSA carries the rate-limit sub-type');
assert_equals('5M/5M', substr($inner, 6), 'the VSA carries the rate-limit value');

// --- PAP password obfuscation round trip --------------------------------
$secret = 'testing123';
$auth   = str_repeat("\x2a", 16);
$cipher = radius_encrypt_password('04829371', $auth, $secret);
assert_equals('04829371', radius_decrypt_password($cipher, $auth, $secret), 'PAP password survives an encrypt/decrypt round trip');
// Longer than one 16-byte block, to exercise the chaining.
$long = 'password-longer-than-sixteen-bytes';
assert_equals($long, radius_decrypt_password(radius_encrypt_password($long, $auth, $secret), $auth, $secret), 'multi-block PAP passwords round trip');
// A wrong secret must not recover the password.
assert_true(radius_decrypt_password($cipher, $auth, 'wrong-secret') !== '04829371', 'a wrong shared secret does not recover the password');

// --- CHAP ---------------------------------------------------------------
$chapId    = "\x07";
$challenge = str_repeat("\x11", 16);
$good      = $chapId . md5($chapId . '04829371' . $challenge, true);
assert_true(radius_check_chap($good, $challenge, '04829371'), 'radius_check_chap accepts a correct response');
assert_true(!radius_check_chap($good, $challenge, '99999999'), 'radius_check_chap rejects a wrong password');
assert_true(!radius_check_chap('', $challenge, '04829371'), 'radius_check_chap rejects an empty response');

// --- reply framing ------------------------------------------------------
$reply = radius_build_reply(R_ACCESS_ACCEPT, 42, $auth, $secret, radius_encode_attr(R_ATTR_SESSION_TIMEOUT, pack('N', 3600)));
assert_equals(R_ACCESS_ACCEPT, ord($reply[0]), 'the reply carries the Access-Accept code');
assert_equals(42, ord($reply[1]), 'the reply echoes the request identifier');
assert_equals(strlen($reply), unpack('n', substr($reply, 2, 2))[1], 'the declared length matches the real packet length');
$expectedAuth = md5(substr($reply, 0, 4) . $auth . substr($reply, 20) . $secret, true);
assert_equals($expectedAuth, substr($reply, 4, 16), 'the response authenticator is MD5(code+id+len+reqauth+attrs+secret)');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/radius_protocol_test.php`
Expected: FAIL with `Failed opening required '.../lib/radius_protocol.php'`

- [ ] **Step 3: Write `lib/radius_protocol.php`**

```php
<?php

/**
 * RADIUS wire-protocol helpers (RFC 2865).
 *
 * Deliberately free of sockets, database access and application state so the
 * whole protocol layer can be unit-tested; radius_server.php is a thin socket
 * loop on top of this.
 */

// Packet codes.
const R_ACCESS_REQUEST      = 1;
const R_ACCESS_ACCEPT       = 2;
const R_ACCESS_REJECT       = 3;
const R_ACCOUNTING_REQUEST  = 4;
const R_ACCOUNTING_RESPONSE = 5;

// Attribute types.
const R_ATTR_USER_NAME       = 1;
const R_ATTR_USER_PASSWORD   = 2;   // PAP — obfuscated with the shared secret
const R_ATTR_CHAP_PASSWORD   = 3;   // 1-byte id + 16-byte MD5 response
const R_ATTR_REPLY_MESSAGE   = 18;
const R_ATTR_VENDOR_SPECIFIC = 26;
const R_ATTR_SESSION_TIMEOUT = 27;
const R_ATTR_CALLING_STATION = 31;  // the client MAC
const R_ATTR_CHAP_CHALLENGE  = 60;

// Mikrotik vendor-specific attributes.
const VENDOR_MIKROTIK = 14988;
const MT_GROUP        = 5;  // hotspot user profile / group
const MT_UPTIME_LIMIT = 7;  // session seconds
const MT_RATE_LIMIT   = 9;  // e.g. "5M/5M"

/**
 * Split a RADIUS attribute blob into [type => value].
 *
 * Malformed input is discarded rather than throwing: this parses packets from
 * the network, and the daemon must not die on a bad one. Duplicate types keep
 * the last occurrence, which is all we need for the attributes we read.
 */
function radius_parse_attributes(string $data): array
{
    $attrs = [];
    $i = 0;
    $len = strlen($data);
    while ($i + 2 <= $len) {
        $type = ord($data[$i]);
        $length = ord($data[$i + 1]);
        // length < 2 would mean no forward progress (infinite loop); a length
        // past the end of the buffer means a truncated or hostile packet.
        if ($length < 2 || $i + $length > $len) {
            break;
        }
        $attrs[$type] = substr($data, $i + 2, $length - 2);
        $i += $length;
    }
    return $attrs;
}

/** Encode one attribute: type, total length, value. */
function radius_encode_attr(int $type, string $value): string
{
    return chr($type) . chr(2 + strlen($value)) . $value;
}

/** Encode a vendor-specific attribute, wrapped in attribute 26. */
function radius_encode_vsa(int $vendor, int $subType, string $value): string
{
    $inner = pack('N', $vendor) . chr($subType) . chr(2 + strlen($value)) . $value;
    return radius_encode_attr(R_ATTR_VENDOR_SPECIFIC, $inner);
}

/**
 * Build a complete reply packet including its Response Authenticator, which is
 * MD5(code + id + length + request authenticator + attributes + secret).
 */
function radius_build_reply(int $code, int $id, string $requestAuthenticator, string $secret, string $attrs): string
{
    $length = 20 + strlen($attrs);
    $header = chr($code) . chr($id) . pack('n', $length);
    $responseAuth = md5($header . $requestAuthenticator . $attrs . $secret, true);
    return $header . $responseAuth . $attrs;
}

/**
 * Reverse RFC 2865's PAP obfuscation.
 *
 * b1 = MD5(secret + request authenticator); p1 = c1 XOR b1
 * bN = MD5(secret + c(N-1));               pN = cN XOR bN
 */
function radius_decrypt_password(string $encrypted, string $requestAuthenticator, string $secret): string
{
    $result = '';
    $last = $requestAuthenticator;
    for ($i = 0; $i < strlen($encrypted); $i += 16) {
        $chunk = substr($encrypted, $i, 16);
        $result .= $chunk ^ md5($secret . $last, true);
        $last = $chunk;
    }
    return rtrim($result, "\x00");
}

/** The inverse of radius_decrypt_password (tests and the loopback probe). */
function radius_encrypt_password(string $plain, string $requestAuthenticator, string $secret): string
{
    // RFC 2865 pads the password to a multiple of 16 bytes with NULs.
    $padded = $plain;
    $remainder = strlen($padded) % 16;
    if ($remainder !== 0 || $padded === '') {
        $padded .= str_repeat("\x00", 16 - $remainder);
    }
    $result = '';
    $last = $requestAuthenticator;
    for ($i = 0; $i < strlen($padded); $i += 16) {
        $chunk = substr($padded, $i, 16);
        $cipher = $chunk ^ md5($secret . $last, true);
        $result .= $cipher;
        $last = $cipher;
    }
    return $result;
}

/**
 * Verify a CHAP-Password attribute.
 *
 * The response is MD5(chap id + plaintext password + challenge). The shared
 * secret is not involved, so CHAP works purely off the stored password.
 */
function radius_check_chap(string $chapPassword, string $challenge, string $plainPassword): bool
{
    if (strlen($chapPassword) !== 17) {
        return false;
    }
    $chapId = substr($chapPassword, 0, 1);
    $response = substr($chapPassword, 1, 16);
    return hash_equals(md5($chapId . $plainPassword . $challenge, true), $response);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/radius_protocol_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Commit**

```bash
git add lib/radius_protocol.php tests/radius_protocol_test.php
git commit -m "feat: add unit-tested RADIUS wire protocol library"
```

---

## Task 4: Encrypted settings

**Files:**
- Modify: `lib/settings.php`
- Modify: `config.example.php`
- Test: `tests/settings_secret_test.php`

**Interfaces:**
- Consumes: `SETTINGS_DEFAULTS`, `get_settings(mysqli $db): array`, `save_settings(mysqli $db, array $settings): void` (existing).
- Produces:
  - `SETTINGS_SECRET_KEYS` (array constant) — the keys encrypted at rest
  - `setting_encrypt(string $plain): string`
  - `setting_decrypt(string $value): string`
  - New settings keys: `radius_secret`, `radius_auth_port`, `radius_nas_ip`, `session_minutes`, `rate_limit`
- Requires `APP_KEY` to be defined in `config.php`.

- [ ] **Step 1: Add `APP_KEY` to `config.example.php`**

Add these lines to `config.example.php`, immediately after the `// Database` block:

```php
// Encryption key for secrets stored in the settings table (RADIUS shared
// secret, SMTP password, Twilio token). Generate a fresh one per install:
//   php -r "echo bin2hex(random_bytes(32));"
// Keep this in this file only — never in the database, or encrypting the
// database values would be pointless.
define('APP_KEY', getenv('APP_KEY') ?: 'change-me-to-a-64-char-random-hex-string');
```

- [ ] **Step 2: Add the same line to your local `config.php`**

```bash
php -r "echo \"define('APP_KEY', getenv('APP_KEY') ?: '\" . bin2hex(random_bytes(32)) . \"');\n\";" >> config.php
```

Then open `config.php` and move that generated line up so it sits with the other `define()` calls rather than after the closing of the file. Verify it loads:

```bash
php -r "require 'config.php'; echo strlen(APP_KEY) . \" char key loaded\n\";"
```

Expected: `64 char key loaded`

- [ ] **Step 3: Write the failing test**

Create `tests/settings_secret_test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';

$db = get_db();
$db->query('DELETE FROM settings');

// Round trip through the encryption helpers directly.
$cipher = setting_encrypt('super-secret-value');
assert_true(strpos($cipher, 'enc:') === 0, 'encrypted values are tagged with an enc: prefix');
assert_true(strpos($cipher, 'super-secret-value') === false, 'the plaintext does not appear in the ciphertext');
assert_equals('super-secret-value', setting_decrypt($cipher), 'setting_decrypt reverses setting_encrypt');

// Two encryptions of the same plaintext must differ (random IV).
assert_true(setting_encrypt('same') !== setting_encrypt('same'), 'each encryption uses a fresh IV');

// Plain (unencrypted, legacy) values pass through untouched.
assert_equals('plain-value', setting_decrypt('plain-value'), 'non-prefixed values are returned as-is');

// Secret keys are transparently encrypted on save and decrypted on read.
save_settings($db, ['radius_secret' => 'my-radius-secret', 'event_name' => 'Test Event']);

$stored = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'radius_secret'")->fetch_assoc();
assert_true(strpos($stored['setting_value'], 'enc:') === 0, 'radius_secret is stored encrypted in the database');
assert_true(strpos($stored['setting_value'], 'my-radius-secret') === false, 'the raw secret is not present in the database');

$plainStored = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'event_name'")->fetch_assoc();
assert_equals('Test Event', $plainStored['setting_value'], 'non-secret settings are stored in the clear');

$settings = get_settings($db);
assert_equals('my-radius-secret', $settings['radius_secret'], 'get_settings decrypts secrets transparently');
assert_equals('Test Event', $settings['event_name'], 'get_settings returns non-secrets unchanged');

test_summary();
```

- [ ] **Step 4: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/settings_secret_test.php`
Expected: FAIL with `Call to undefined function setting_encrypt()`

- [ ] **Step 5: Update `lib/settings.php`**

Replace the `SETTINGS_DEFAULTS` constant with this version (five new keys appended), and add the encryption helpers plus the encrypt/decrypt hooks in `get_settings()` and `save_settings()`. The full new file contents:

```php
<?php
const SETTINGS_DEFAULTS = [
    'event_name' => 'Edo Youth Impact Forum 2026',
    'event_tagline' => 'Empowered Youth, Transformed Future',
    'event_dates' => 'Tuesday 18th & Wednesday 19th August 2026',
    'event_venue' => 'Victor Uwaifo Creative Hub, Benin City, Edo State',
    // EYIF wordmark blue, darkened from the logo's #2088C8 so white button
    // text clears WCAG AA contrast (4.6:1 vs the logo blue's 3.9:1).
    'brand_color' => '#1B7BB8',
    'event_logo_path' => '',
    'powered_by_logo_path' => '',
    // --- RADIUS / Wi-Fi ---
    'radius_secret' => '',
    'radius_auth_port' => '1812',
    'radius_nas_ip' => '',
    // How long a code stays valid for, in minutes. 720 = 12 hours, enough to
    // cover one event day.
    'session_minutes' => '720',
    // Mikrotik rate-limit string (upload/download). Empty means uncapped.
    'rate_limit' => '',
];

/**
 * Settings whose values are encrypted at rest. APP_KEY lives in config.php,
 * never in the database, so a database leak alone does not expose them.
 */
const SETTINGS_SECRET_KEYS = ['radius_secret'];

function setting_encrypt(string $plain): string
{
    $key = hash('sha256', APP_KEY, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return 'enc:' . base64_encode($iv . $cipher);
}

function setting_decrypt(string $value): string
{
    // Values without the marker are legacy plaintext — return them untouched.
    if (strpos($value, 'enc:') !== 0) {
        return $value;
    }
    $raw = base64_decode(substr($value, 4), true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $key = hash('sha256', APP_KEY, true);
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function get_settings(mysqli $db): array
{
    $settings = SETTINGS_DEFAULTS;
    $result = $db->query('SELECT setting_key, setting_value FROM settings');
    while ($row = $result->fetch_assoc()) {
        if (!array_key_exists($row['setting_key'], $settings)) {
            continue;
        }
        $value = $row['setting_value'];
        if (in_array($row['setting_key'], SETTINGS_SECRET_KEYS, true)) {
            $value = setting_decrypt($value);
        }
        $settings[$row['setting_key']] = $value;
    }
    return $settings;
}

function save_settings(mysqli $db, array $settings): void
{
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach (SETTINGS_DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $value = (string) $settings[$key];
        if (in_array($key, SETTINGS_SECRET_KEYS, true)) {
            $value = setting_encrypt($value);
        }
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}
```

- [ ] **Step 6: Run both settings test files to verify they pass**

```bash
DB_NAME=wifi_portal_test php tests/settings_secret_test.php
DB_NAME=wifi_portal_test php tests/settings_test.php
```

Expected: `ALL PASSED` from both. (The pre-existing `settings_test.php` must still pass — it covers the non-secret path.)

- [ ] **Step 7: Commit**

```bash
git add lib/settings.php config.example.php tests/settings_secret_test.php
git commit -m "feat: encrypt sensitive settings at rest with APP_KEY"
```

---

## Task 5: The RADIUS daemon

**Files:**
- Create: `radius_server.php`
- Create: `logs/.gitkeep`
- Modify: `.gitignore`
- Test: `tests/radius_daemon_test.php`

**Interfaces:**
- Consumes: `get_db()`, `get_settings()`, `find_valid_credential()`, `touch_credential()`, the whole of `lib/radius_protocol.php`.
- Produces: a daemon binding UDP `radius_auth_port`, and `logs/radius.pid`, `logs/radius.log`, `logs/radius.restart` conventions used by Tasks 8 and 9.

The test is an integration test: it starts the daemon as a background process, sends real UDP packets at it, and asserts on the replies. That is the only way to prove the socket loop and the DB lookup work together.

- [ ] **Step 1: Create the log directory and ignore its contents**

```bash
mkdir -p logs
touch logs/.gitkeep
```

Append to `.gitignore`:

```
logs/*
!logs/.gitkeep
```

- [ ] **Step 2: Write the failing test**

Create `tests/radius_daemon_test.php`:

```php
<?php
/**
 * Integration test: starts the real daemon, speaks real RADIUS to it over UDP.
 *
 * Uses port 18120 and the test database so it never collides with a running
 * production daemon on 1812.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

$secret = 'test-shared-secret';
$port = 18120;

$db = get_db();
$db->query('DELETE FROM wifi_credentials');
save_settings($db, [
    'radius_secret' => $secret,
    'radius_auth_port' => (string) $port,
    'radius_nas_ip' => '',           // empty = accept any source
    'rate_limit' => '5M/5M',
]);
issue_credential($db, '04829371', 60, '5M/5M');
issue_credential($db, '55556666', -5); // already expired

// Start the daemon against the test database.
$root = dirname(__DIR__);
$descriptors = [1 => ['file', $root . '/logs/test-daemon.log', 'a'], 2 => ['file', $root . '/logs/test-daemon.log', 'a']];
// env = null means "inherit this process's environment". The test itself is
// launched with DB_NAME=wifi_portal_test, so the daemon picks up the test
// database automatically. Passing an explicit env array would replace the whole
// environment (losing PATH, and $_ENV is not always populated).
$proc = proc_open('php ' . escapeshellarg($root . '/radius_server.php'), $descriptors, $pipes, $root, null);
assert_true(is_resource($proc), 'the daemon process started');

// Give it a moment to bind the socket.
usleep(900000);

/** Send one Access-Request and return [code, attributes] or null on timeout. */
function radius_probe(int $port, string $secret, string $username, string $password): ?array
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $attrs = radius_encode_attr(R_ATTR_USER_NAME, $username)
           . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password($password, $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($attrs)) . $auth . $attrs;
    socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $received = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);
    if ($received === false || strlen($buf) < 20) {
        return null;
    }
    return [ord($buf[0]), radius_parse_attributes(substr($buf, 20))];
}

$accept = radius_probe($port, $secret, '04829371', '04829371');
assert_true($accept !== null, 'the daemon replied to a valid Access-Request');
assert_equals(R_ACCESS_ACCEPT, $accept[0], 'a valid code is accepted');
assert_true(isset($accept[1][R_ATTR_SESSION_TIMEOUT]), 'the Accept carries a Session-Timeout');
$timeout = unpack('N', $accept[1][R_ATTR_SESSION_TIMEOUT])[1];
assert_true($timeout > 0 && $timeout <= 3600, 'Session-Timeout reflects the remaining time');
assert_true(isset($accept[1][R_ATTR_VENDOR_SPECIFIC]), 'the Accept carries Mikrotik vendor attributes');

$wrong = radius_probe($port, $secret, '04829371', '99999999');
assert_equals(R_ACCESS_REJECT, $wrong[0], 'a wrong password is rejected');

$expired = radius_probe($port, $secret, '55556666', '55556666');
assert_equals(R_ACCESS_REJECT, $expired[0], 'an expired credential is rejected');

$unknown = radius_probe($port, $secret, '00000000', '00000000');
assert_equals(R_ACCESS_REJECT, $unknown[0], 'an unknown code is rejected');

// A successful auth must be recorded.
$touched = $db->query("SELECT last_used_at FROM wifi_credentials WHERE username = '04829371'")->fetch_assoc();
assert_true($touched['last_used_at'] !== null, 'a successful auth updates last_used_at');

// Shut the daemon down.
$status = proc_get_status($proc);
if ($status['running']) {
    // proc_terminate sends SIGTERM; the daemon cleans up its PID file.
    proc_terminate($proc);
}
proc_close($proc);

test_summary();
```

- [ ] **Step 3: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_daemon_test.php`
Expected: FAIL — the daemon does not exist yet, so every probe times out and `the daemon replied to a valid Access-Request` fails.

- [ ] **Step 4: Write `radius_server.php`**

```php
<?php
/**
 * radius_server.php — UDP RADIUS daemon for the EYIF Wi-Fi portal.
 *
 * Mikrotik sends Access-Requests here; we answer from wifi_credentials. This
 * replaces FreeRADIUS: nothing to install, one codebase, and the credential
 * table is the app's own.
 *
 * Run it as a CLI process, never over HTTP:
 *   bash start_radius.sh start
 * or under systemd via deploy/mangonet-radius.service.
 *
 * Requires ext-sockets. Reads its configuration from the settings table, so
 * changing the shared secret or the trusted router IP in the admin UI takes
 * effect without editing files.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("radius_server.php must be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/credentials.php';
require_once __DIR__ . '/lib/radius_protocol.php';

const LOG_DIR = __DIR__ . '/logs';

function radius_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    if (is_dir(LOG_DIR)) {
        file_put_contents(LOG_DIR . '/radius.log', $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Run a database closure, reconnecting once if the daemon's idle connection
 * was dropped by MySQL. Without this a long-quiet night would leave the daemon
 * alive but rejecting everyone.
 */
function db_run(callable $fn)
{
    try {
        return $fn(get_db());
    } catch (mysqli_sql_exception $e) {
        radius_log('DB error, reconnecting once: ' . $e->getMessage());
        reset_db();
        return $fn(get_db());
    }
}

if (!extension_loaded('sockets')) {
    fwrite(STDERR, "[RADIUS] ext-sockets is not enabled for this PHP binary. Cannot start.\n");
    exit(1);
}

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0775, true);
}

$db = get_db();
$settings = get_settings($db);
$secret = (string) $settings['radius_secret'];
$bindPort = (int) $settings['radius_auth_port'];
$allowedNasIp = (string) $settings['radius_nas_ip'];

if ($secret === '') {
    fwrite(STDERR, "[RADIUS] radius_secret is not set. Configure it in Admin -> RADIUS, then start the daemon.\n");
    exit(1);
}

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
    fwrite(STDERR, '[RADIUS] socket_create failed: ' . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}
if (!socket_bind($sock, '0.0.0.0', $bindPort)) {
    fwrite(STDERR, "[RADIUS] cannot bind UDP {$bindPort}: " . socket_strerror(socket_last_error($sock)) . "\n");
    exit(1);
}
// A 1-second receive timeout keeps the loop responsive to the restart flag.
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

file_put_contents(LOG_DIR . '/radius.pid', (string) getmypid());

$restartFlag = LOG_DIR . '/radius.restart';
@unlink($restartFlag); // clear a stale flag from a previous run

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $cleanup = function () {
        @unlink(LOG_DIR . '/radius.pid');
        radius_log('Daemon stopping.');
        exit(0);
    };
    pcntl_signal(SIGTERM, $cleanup);
    pcntl_signal(SIGINT, $cleanup);
}

radius_log("Listening on UDP 0.0.0.0:{$bindPort}");
radius_log('Trusted router IP: ' . ($allowedNasIp !== '' ? $allowedNasIp : 'any (not restricted)'));

$lastSettingsReload = time();

while (true) {
    // The admin RADIUS Log page drops this flag to request a restart. Only
    // exit if we actually removed it, otherwise an undeletable flag would trap
    // us in a respawn loop.
    if (is_file($restartFlag)) {
        if (@unlink($restartFlag)) {
            radius_log('Restart requested from the admin UI — exiting for the supervisor to respawn.');
            exit(0);
        }
        radius_log('Restart flag present but could not be deleted (permissions?) — ignoring.');
    }

    // Re-read the trusted router IP and secret every 10s so admin changes take
    // effect without a restart.
    if (time() - $lastSettingsReload >= 10) {
        $lastSettingsReload = time();
        try {
            $fresh = db_run(fn(mysqli $d) => get_settings($d));
            if ((string) $fresh['radius_secret'] !== '') {
                $secret = (string) $fresh['radius_secret'];
            }
            if ((string) $fresh['radius_nas_ip'] !== $allowedNasIp) {
                radius_log("Trusted router IP changed to: " . ($fresh['radius_nas_ip'] ?: 'any'));
                $allowedNasIp = (string) $fresh['radius_nas_ip'];
            }
            $settings = $fresh;
        } catch (Throwable $e) {
            radius_log('Could not reload settings: ' . $e->getMessage());
        }
    }

    $buf = '';
    $from = '';
    $fromPort = 0;
    $received = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    if ($received === false || $received < 20) {
        continue; // receive timeout, or a runt packet
    }

    // Loopback is always allowed: the admin "Test connectivity" probe comes
    // from 127.0.0.1, not from the router.
    $isLocal = ($from === '127.0.0.1' || $from === '::1');
    if ($allowedNasIp !== '' && !$isLocal && $from !== $allowedNasIp) {
        radius_log("Ignored packet from {$from} (trusted router is {$allowedNasIp})");
        continue;
    }

    $code = ord($buf[0]);
    $identifier = ord($buf[1]);
    $declaredLength = unpack('n', substr($buf, 2, 2))[1];
    if ($declaredLength < 20 || $declaredLength > $received) {
        radius_log("Malformed packet from {$from} (declared length {$declaredLength}, got {$received})");
        continue;
    }
    $requestAuth = substr($buf, 4, 16);
    $attrs = radius_parse_attributes(substr($buf, 20, $declaredLength - 20));

    if ($code === R_ACCOUNTING_REQUEST) {
        // Stage 1 acknowledges accounting so the router does not retry; Stage 3
        // will parse the octet counters here for bandwidth quotas.
        $reply = radius_build_reply(R_ACCOUNTING_RESPONSE, $identifier, $requestAuth, $secret, '');
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    if ($code !== R_ACCESS_REQUEST) {
        continue;
    }

    $username = $attrs[R_ATTR_USER_NAME] ?? '';
    $mac = $attrs[R_ATTR_CALLING_STATION] ?? '';

    // The admin health-check probes with this reserved username purely to prove
    // the daemon is listening; answer without touching the database.
    if ($username === '__healthcheck__') {
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'health-check ok'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    radius_log("Access-Request user={$username} mac={$mac} from={$from}");

    try {
        $row = db_run(fn(mysqli $d) => find_valid_credential($d, $username));
    } catch (Throwable $e) {
        radius_log('DB lookup failed: ' . $e->getMessage());
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Database error'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    if ($row === null) {
        radius_log("REJECT {$username}: unknown or expired");
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid or expired code'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    // Verify the password with whichever method the router used.
    if (isset($attrs[R_ATTR_CHAP_PASSWORD])) {
        $challenge = $attrs[R_ATTR_CHAP_CHALLENGE] ?? $requestAuth;
        $authOk = radius_check_chap($attrs[R_ATTR_CHAP_PASSWORD], $challenge, (string) $row['password']);
    } else {
        $supplied = radius_decrypt_password($attrs[R_ATTR_USER_PASSWORD] ?? '', $requestAuth, $secret);
        $authOk = hash_equals((string) $row['password'], $supplied);
    }

    if (!$authOk) {
        radius_log("REJECT {$username}: wrong password");
        $reply = radius_build_reply(R_ACCESS_REJECT, $identifier, $requestAuth, $secret,
            radius_encode_attr(R_ATTR_REPLY_MESSAGE, 'Invalid credentials'));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
        continue;
    }

    // Seconds left on this credential. Floored at 60 so a code that is seconds
    // from expiry does not hand the router a zero/negative timeout.
    $remaining = max(60, strtotime((string) $row['expires_at']) - time());

    $replyAttrs = radius_encode_attr(R_ATTR_SESSION_TIMEOUT, pack('N', $remaining))
                . radius_encode_vsa(VENDOR_MIKROTIK, MT_UPTIME_LIMIT, pack('N', $remaining));

    $rate = (string) ($row['rate_limit'] ?? '');
    if ($rate === '') {
        $rate = (string) $settings['rate_limit'];
    }
    if ($rate !== '') {
        $replyAttrs .= radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, $rate);
    }

    $reply = radius_build_reply(R_ACCESS_ACCEPT, $identifier, $requestAuth, $secret, $replyAttrs);
    socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
    radius_log("ACCEPT {$username}: {$remaining}s remaining" . ($rate !== '' ? ", rate {$rate}" : ''));

    try {
        db_run(fn(mysqli $d) => touch_credential($d, $username));
    } catch (Throwable $e) {
        radius_log('Could not update last_used_at: ' . $e->getMessage()); // non-fatal
    }
}
```

- [ ] **Step 5: Add `reset_db()` to `db.php`**

The daemon's `db_run()` needs a way to drop a dead connection. PHP cannot
reassign another function's `static`, so the connection moves into a small
holder function that both `get_db()` and `reset_db()` can reach.

Replace the entire contents of `db.php` with:

```php
<?php
require_once __DIR__ . '/config.php';

/**
 * Holds the single mysqli connection.
 *
 * A plain `static` inside get_db() could never be cleared from outside, and the
 * long-running RADIUS daemon must be able to drop a dead handle — hence this
 * small holder.
 */
function db_holder(?mysqli $set = null, bool $clear = false): ?mysqli {
    static $db = null;
    if ($clear) {
        $db = null;
        return null;
    }
    if ($set !== null) {
        $db = $set;
    }
    return $db;
}

function get_db(): mysqli {
    $db = db_holder();
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
        db_holder($db);
    }
    return $db;
}

/**
 * Drop the cached connection so the next get_db() dials a fresh one.
 *
 * Only the long-running RADIUS daemon needs this: MySQL closes idle
 * connections, and without a reconnect the daemon would stay up while
 * rejecting every attendee.
 */
function reset_db(): void {
    $db = db_holder();
    if ($db instanceof mysqli) {
        @$db->close();
    }
    db_holder(null, true);
}
```

- [ ] **Step 6: Run the daemon test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/radius_daemon_test.php`
Expected: `ALL PASSED`

If the probes time out, read `logs/test-daemon.log` — the daemon prints its bind errors there.

- [ ] **Step 7: Run the whole suite to confirm the `db.php` change broke nothing**

```bash
for t in settings settings_secret entries credentials radius_protocol; do DB_NAME=wifi_portal_test php tests/${t}_test.php | tail -1; done
for t in csv uploads mailer sms admin_auth; do php tests/${t}_test.php | tail -1; done
```

Expected: `ALL PASSED` from every line.

- [ ] **Step 8: Commit**

```bash
git add radius_server.php db.php logs/.gitkeep .gitignore tests/radius_daemon_test.php
git commit -m "feat: add UDP RADIUS daemon with time limits and rate caps"
```

---

## Task 6: Point the portal at the new credentials

**Files:**
- Modify: `lib/radius.php`
- Modify: `connect.php`
- Modify: `tests/radius_test.php`

**Interfaces:**
- Consumes: `issue_credential()`, `find_valid_credential()` (Task 2); `get_settings()` (Task 4).
- Produces: `radius_add_user(mysqli $db, string $code, array $settings): void` — note the added third parameter — and `radius_user_exists(mysqli $db, string $code): bool`.

- [ ] **Step 1: Rewrite `tests/radius_test.php`**

Replace the entire file with:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius.php';

$db = get_db();
$db->query('DELETE FROM wifi_credentials');

$settings = SETTINGS_DEFAULTS;
$settings['session_minutes'] = '90';
$settings['rate_limit'] = '5M/5M';

radius_add_user($db, '04829371', $settings);

$row = find_valid_credential($db, '04829371');
assert_true($row !== null, 'radius_add_user issues a usable credential');
assert_equals('04829371', $row['password'], 'the code is also the password');
assert_equals('5M/5M', $row['rate_limit'], 'the configured rate limit is applied');

$expiresIn = strtotime($row['expires_at']) - time();
assert_true($expiresIn > 5000 && $expiresIn <= 5400, 'the credential expires after session_minutes (90m = 5400s)');

assert_true(radius_user_exists($db, '04829371'), 'radius_user_exists finds the issued code');
assert_true(!radius_user_exists($db, '99999999'), 'radius_user_exists is false for an unknown code');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: FAIL — `radius_add_user()` currently takes two arguments and writes to `radcheck`, which no longer exists.

- [ ] **Step 3: Rewrite `lib/radius.php`**

Replace the entire file with:

```php
<?php
require_once __DIR__ . '/credentials.php';

/**
 * Issue the Wi-Fi credential for a freshly created entry.
 *
 * The 8-digit code is both the RADIUS username and password. How long it stays
 * valid, and what speed cap applies, come from the admin settings.
 */
function radius_add_user(mysqli $db, string $code, array $settings): void
{
    $minutes = max(1, (int) ($settings['session_minutes'] ?? 720));
    $rate = trim((string) ($settings['rate_limit'] ?? ''));
    issue_credential($db, $code, $minutes, $rate !== '' ? $rate : null);
}

/** Whether a code currently has a valid (unexpired) Wi-Fi credential. */
function radius_user_exists(mysqli $db, string $code): bool
{
    return find_valid_credential($db, $code) !== null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Update the call site in `connect.php`**

In `connect.php`, find this line inside the new-entry branch:

```php
            radius_add_user($db, $code);
```

Replace it with:

```php
            radius_add_user($db, $code, $settings);
```

- [ ] **Step 6: Verify the portal still issues a working code end to end**

```bash
DB_NAME=wifi_portal php -S localhost:8000 > /dev/null 2>&1 &
sleep 1
curl -s -X POST http://localhost:8000/connect.php \
  -d "name=Radius Test" -d "phone=08012349999" -d "email=radius.test@example.com" \
  | grep -o 'id="code">[0-9]*'
kill %1
mysql -u root wifi_portal -e "SELECT username, rate_limit, expires_at FROM wifi_credentials ORDER BY id DESC LIMIT 1;"
```

Expected: the curl output shows an 8-digit code, and the MySQL row shows that same code with an `expires_at` roughly `session_minutes` in the future.

- [ ] **Step 7: Commit**

```bash
git add lib/radius.php connect.php tests/radius_test.php
git commit -m "feat: issue time-limited wifi credentials from the portal"
```

---

## Task 7: Daemon lifecycle scripts

**Files:**
- Create: `start_radius.sh`
- Create: `deploy/mangonet-radius.service`

**Interfaces:**
- Consumes: `radius_server.php`, `logs/radius.pid` (Task 5).
- Produces: `bash start_radius.sh {start|stop|restart|status}`.

- [ ] **Step 1: Write `start_radius.sh`**

```bash
#!/usr/bin/env bash
# start_radius.sh — start/stop/restart the RADIUS daemon without systemd.
#
# Useful on hosts where you cannot install a unit file. On a VPS prefer
# deploy/mangonet-radius.service, which restarts the daemon automatically.
#
#   bash start_radius.sh start|stop|restart|status
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
RADIUS_SCRIPT="${SCRIPT_DIR}/radius_server.php"
LOG_FILE="${SCRIPT_DIR}/logs/radius.log"
PID_FILE="${SCRIPT_DIR}/logs/radius.pid"

mkdir -p "${SCRIPT_DIR}/logs"

is_running() {
    [ -f "$PID_FILE" ] || return 1
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null)"
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

start() {
    if is_running; then
        echo "RADIUS daemon already running (PID $(cat "$PID_FILE"))"
        return 0
    fi
    nohup "$PHP_BIN" "$RADIUS_SCRIPT" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    sleep 1
    if is_running; then
        echo "RADIUS daemon started (PID $(cat "$PID_FILE"))"
    else
        echo "RADIUS daemon failed to start. Last log lines:"
        tail -n 15 "$LOG_FILE"
        return 1
    fi
}

stop() {
    if ! is_running; then
        echo "RADIUS daemon is not running"
        rm -f "$PID_FILE"
        return 0
    fi
    local pid
    pid="$(cat "$PID_FILE")"
    kill "$pid"
    rm -f "$PID_FILE"
    echo "RADIUS daemon stopped (PID $pid)"
}

status() {
    if is_running; then
        echo "RADIUS daemon is running (PID $(cat "$PID_FILE"))"
    else
        echo "RADIUS daemon is NOT running"
        return 1
    fi
}

case "${1:-start}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    *) echo "Usage: bash start_radius.sh {start|stop|restart|status}"; exit 1 ;;
esac
```

- [ ] **Step 2: Write `deploy/mangonet-radius.service`**

```ini
# systemd unit for the EYIF Wi-Fi RADIUS daemon.
#
# Install on the VPS:
#   sudo cp deploy/mangonet-radius.service /etc/systemd/system/
#   sudo nano /etc/systemd/system/mangonet-radius.service   # fix paths + User
#   sudo systemctl daemon-reload
#   sudo systemctl enable --now mangonet-radius
#
# Restart=always is what makes the admin UI's "Restart daemon" button work: the
# daemon exits when it sees the restart flag, and systemd brings it back with
# the current code.

[Unit]
Description=EYIF Wi-Fi Portal RADIUS daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/wifi-portal
ExecStart=/usr/bin/php /var/www/wifi-portal/radius_server.php
Restart=always
RestartSec=3
StandardOutput=append:/var/www/wifi-portal/logs/radius.log
StandardError=append:/var/www/wifi-portal/logs/radius.log

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 3: Verify the wrapper starts, reports, and stops the daemon**

```bash
bash start_radius.sh status || true
bash start_radius.sh start
bash start_radius.sh status
bash start_radius.sh stop
bash start_radius.sh status || echo "(correctly reports stopped)"
```

Expected: the first `status` reports not running; `start` prints a PID; the second `status` reports running with that PID; after `stop`, `status` reports not running.

- [ ] **Step 4: Commit**

```bash
git add start_radius.sh deploy/mangonet-radius.service
git commit -m "feat: add RADIUS daemon lifecycle wrapper and systemd unit"
```

---

## Task 8: Admin — RADIUS settings, diagnostics and router config

**Files:**
- Create: `admin/radius.php`
- Modify: `deploy/mikrotik-setup.rsc`
- Modify: `admin/layout.php`

**Interfaces:**
- Consumes: `require_admin_session()`, `admin_layout_start()`, `admin_layout_end()`, `get_settings()`, `save_settings()`, `radius_encrypt_password()`, `radius_parse_attributes()`, `radius_build_reply()`.
- Produces: `radius_diagnose(array $settings): array` returning `[bool $ok, string $message]`.

- [ ] **Step 1: Write `deploy/mikrotik-setup.rsc` as a token template**

Replace the whole file with:

```
# EYIF Wi-Fi Portal — MikroTik RADIUS setup
#
# Generated by Admin -> RADIUS -> "Download router config".
# Paste into the router terminal, or upload and run: /import file=eyif-radius.rsc
#
# Verify your hotspot profile name first with: /ip hotspot profile print

:put "EYIF: configuring RADIUS -> __VPS_IP__"

# 1) Point the router at our RADIUS daemon.
:if ([:len [/radius find where address=__VPS_IP__]] = 0) do={
  /radius add service=hotspot address=__VPS_IP__ secret=__RADIUS_SECRET__ \
    authentication-port=__AUTH_PORT__ accounting-port=1813 timeout=3s
  :put "  + RADIUS server added"
} else={
  /radius set [find where address=__VPS_IP__] service=hotspot \
    secret=__RADIUS_SECRET__ authentication-port=__AUTH_PORT__ \
    accounting-port=1813 timeout=3s
  :put "  ~ RADIUS server updated"
}

# 2) Tell the hotspot profile to authenticate via RADIUS.
/ip hotspot profile set [find name=__HS_PROFILE__] use-radius=yes \
  login-by=http-chap,http-pap
:put "  ~ hotspot profile __HS_PROFILE__ set to use RADIUS"

# 3) Walled garden: let unauthenticated devices reach the portal only.
:if ([:len [/ip hotspot walled-garden find where dst-host="__PORTAL_HOST__"]] = 0) do={
  /ip hotspot walled-garden add dst-host="__PORTAL_HOST__" action=allow
  :put "  + walled-garden entry added for __PORTAL_HOST__"
}

:put "EYIF: done. Check /radius print and /ip hotspot profile print"
```

- [ ] **Step 2: Register the new nav entries in `admin/layout.php`**

Replace the `ADMIN_NAV` constant with:

```php
const ADMIN_NAV = [
    ['file' => 'index.php',      'label' => 'Dashboard',        'icon' => 'grid'],
    ['file' => 'entries.php',    'label' => 'Raffle Entries',   'icon' => 'list'],
    ['file' => 'radius.php',     'label' => 'Wi-Fi & RADIUS',   'icon' => 'wifi'],
    ['file' => 'radius-log.php', 'label' => 'RADIUS Log',       'icon' => 'terminal'],
    ['file' => 'settings.php',   'label' => 'Branding Settings','icon' => 'sliders'],
];
```

Then add these two icons to the `$paths` array inside `admin_nav_icon()`:

```php
        'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
```

- [ ] **Step 3: Write `admin/radius.php`**

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/radius_protocol.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

/**
 * Probe the daemon over loopback and explain precisely what is wrong.
 *
 * Any reply — Accept or Reject — proves the daemon is listening; only a
 * timeout means nothing is there. The daemon answers the reserved
 * "__healthcheck__" username without touching the database, and accepts
 * loopback packets regardless of the trusted-router setting.
 *
 * @return array [bool $ok, string $message]
 */
function radius_diagnose(array $settings): array
{
    $port = (int) $settings['radius_auth_port'];
    $secret = (string) $settings['radius_secret'];

    if ($secret === '') {
        return [false, 'No shared secret is set. Enter one below and save it — the daemon exits immediately without a secret.'];
    }
    if (!extension_loaded('sockets')) {
        return [false, 'PHP ext-sockets is not enabled for the web server. The daemon also needs it in the CLI PHP binary.'];
    }

    $pidFile = dirname(__DIR__) . '/logs/radius.pid';
    $pidAlive = false;
    if (is_file($pidFile)) {
        $pid = (int) trim((string) @file_get_contents($pidFile));
        $pidAlive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
    $id = random_int(0, 255);
    $auth = random_bytes(16);
    $attrs = radius_encode_attr(R_ATTR_USER_NAME, '__healthcheck__')
           . radius_encode_attr(R_ATTR_USER_PASSWORD, radius_encrypt_password('probe', $auth, $secret));
    $packet = chr(R_ACCESS_REQUEST) . chr($id) . pack('n', 20 + strlen($attrs)) . $auth . $attrs;
    @socket_sendto($sock, $packet, strlen($packet), 0, '127.0.0.1', $port);
    $buf = '';
    $from = '';
    $fromPort = 0;
    $got = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    socket_close($sock);

    if ($got !== false && strlen($buf) >= 20) {
        return [true, "The daemon is UP and answering on UDP {$port}. For live logins also confirm: (1) UDP {$port} is open inbound on the server firewall, and (2) the router's public IP below matches the router — the daemon ignores packets from anywhere else."];
    }

    if (!is_file($pidFile)) {
        return [false, "Nothing answered on UDP {$port} and there is no logs/radius.pid — the daemon has never been started. Run: bash start_radius.sh start"];
    }
    if (!$pidAlive) {
        return [false, "Nothing answered on UDP {$port} and the process in logs/radius.pid is gone — it crashed or was killed. Check the RADIUS Log page, then run: bash start_radius.sh restart"];
    }
    return [false, "The daemon process is alive but nothing answered on UDP {$port}. It probably could not bind the port (another process using it?). Check the RADIUS Log page."];
}

$error = '';
$notice = '';

// Download the router config as a .rsc file.
if (($_GET['download'] ?? '') === 'rsc') {
    $template = (string) file_get_contents(dirname(__DIR__) . '/deploy/mikrotik-setup.rsc');
    $portalHost = $_SERVER['HTTP_HOST'] ?? 'your-portal-domain';
    $out = strtr($template, [
        '__RADIUS_SECRET__' => (string) $settings['radius_secret'],
        '__VPS_IP__' => (string) ($_SERVER['SERVER_ADDR'] ?? 'YOUR_SERVER_IP'),
        '__AUTH_PORT__' => (string) $settings['radius_auth_port'],
        '__HS_PROFILE__' => 'hsprof1',
        '__PORTAL_HOST__' => $portalHost,
    ]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="eyif-radius.rsc"');
    echo $out;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'radius_auth_port' => (string) max(1, (int) ($_POST['radius_auth_port'] ?? 1812)),
        'radius_nas_ip' => trim((string) ($_POST['radius_nas_ip'] ?? '')),
        'session_minutes' => (string) max(1, (int) ($_POST['session_minutes'] ?? 720)),
        'rate_limit' => trim((string) ($_POST['rate_limit'] ?? '')),
    ];
    // Only overwrite the secret when a new one was actually typed, so saving
    // the form does not wipe it.
    $typedSecret = trim((string) ($_POST['radius_secret'] ?? ''));
    if ($typedSecret !== '') {
        $newSettings['radius_secret'] = $typedSecret;
    }
    save_settings($db, $newSettings);
    $settings = get_settings($db);
    $notice = 'RADIUS settings saved. Restart the daemon from the RADIUS Log page for the new port to take effect.';
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
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<section class="panel" style="margin-bottom:var(--space-4)">
  <div class="panel-header"><h2>Daemon status</h2></div>
  <p class="<?= $diagOk ? 'diag-ok' : 'error' ?>"><?= htmlspecialchars($diagMessage) ?></p>
</section>

<section class="panel">
  <div class="panel-header"><h2>Settings</h2></div>
  <form method="POST" class="settings-form">
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
    <button type="submit">Save RADIUS settings</button>
  </form>
</section>
<?php admin_layout_end(); ?>
```

- [ ] **Step 4: Add the diagnostic "ok" style**

Append to `assets/style.css`:

```css
.diag-ok {
  margin: 0;
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 14px;
  color: #B7F7D4;
  background: #10321F;
}
```

- [ ] **Step 5: Verify the page, the diagnostics and the download**

```bash
DB_NAME=wifi_portal php -S localhost:8000 > /dev/null 2>&1 &
sleep 1
rm -f /tmp/rc.txt
curl -s -c /tmp/rc.txt -X POST http://localhost:8000/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
echo "--- daemon stopped: diagnostics should explain why ---"
curl -s -b /tmp/rc.txt "http://localhost:8000/admin/radius.php" | grep -o "class=\"error\">[^<]*" | head -2
echo "--- save a secret ---"
curl -s -b /tmp/rc.txt -X POST "http://localhost:8000/admin/radius.php" \
  -d "radius_secret=demo-secret-123" -d "radius_auth_port=1812" -d "radius_nas_ip=" \
  -d "session_minutes=720" -d "rate_limit=5M/5M" -o /dev/null
mysql -u root wifi_portal -e "SELECT LEFT(setting_value,4) AS prefix FROM settings WHERE setting_key='radius_secret';"
echo "--- router config download ---"
curl -s -b /tmp/rc.txt "http://localhost:8000/admin/radius.php?download=rsc" | head -12
kill %1
```

Expected: the diagnostics line explains the daemon is not started; the MySQL query shows the prefix `enc:` (the secret is encrypted); the downloaded `.rsc` shows the real secret and port substituted into the template, with no `__TOKEN__` placeholders left.

- [ ] **Step 6: Commit**

```bash
git add admin/radius.php admin/layout.php deploy/mikrotik-setup.rsc assets/style.css
git commit -m "feat: add admin RADIUS settings, diagnostics and router config generator"
```

---

## Task 9: Admin — live RADIUS log

**Files:**
- Create: `admin/radius-log.php`

**Interfaces:**
- Consumes: `require_admin_session()`, `admin_layout_start()`, `admin_layout_end()`, `get_settings()`, `save_settings()`; the `logs/radius.log`, `logs/radius.pid` and `logs/radius.restart` conventions from Task 5.

- [ ] **Step 1: Write `admin/radius-log.php`**

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

$logFile = dirname(__DIR__) . '/logs/radius.log';
$restartFlag = dirname(__DIR__) . '/logs/radius.restart';

/** The last $maxLines lines of a file, without reading the whole thing. */
function tail_log(string $path, int $maxLines = 200): string
{
    if (!is_file($path)) {
        return '';
    }
    $size = filesize($path);
    // 64KB comfortably covers 200 lines of this log format.
    $readFrom = max(0, $size - 65536);
    $chunk = (string) @file_get_contents($path, false, null, $readFrom);
    $lines = explode("\n", trim($chunk));
    return implode("\n", array_slice($lines, -$maxLines));
}

// Plain-text endpoint the page polls, so the log refreshes without a reload.
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo tail_log($logFile);
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restart') {
        // The daemon watches for this flag, deletes it, and exits; systemd (or
        // start_radius.sh) brings it back. Lets an admin restart without shell
        // access.
        if (@file_put_contents($restartFlag, (string) time()) !== false) {
            $notice = 'Restart requested. The daemon exits within a second and its supervisor restarts it.';
        } else {
            $error = 'Could not write ' . $restartFlag . ' — the logs/ directory is not writable by the web server.';
        }
    }

    if ($action === 'trust_ip') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $error = 'That is not a valid IP address.';
        } else {
            save_settings($db, ['radius_nas_ip' => $ip]);
            $settings = get_settings($db);
            $notice = "Now trusting RADIUS packets from {$ip}. The daemon picks this up within 10 seconds.";
        }
    }

    if ($action === 'clear') {
        if (@file_put_contents($logFile, '') !== false) {
            $notice = 'Log cleared.';
        } else {
            $error = 'Could not clear the log file.';
        }
    }
}

$log = tail_log($logFile);

// Surface any source IP the daemon rejected, so a one-click "trust this" is
// possible when the router's public IP is not what was configured.
$suggestIp = '';
if (preg_match_all('/Ignored packet from ([0-9.]+)/', $log, $m)) {
    $suggestIp = end($m[1]);
}

admin_layout_start('radius-log.php', 'RADIUS Log', $settings);
?>
<div class="page-header">
  <div>
    <h1>RADIUS Log</h1>
    <p class="page-sub">Live authentication log from the daemon — no SSH needed.</p>
  </div>
  <div class="page-actions">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="restart">
      <button type="submit" class="btn-inline">Restart daemon</button>
    </form>
    <form method="POST" style="display:inline"
          onsubmit="return confirm('Clear the log file? This cannot be undone.')">
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn-inline secondary">Clear log</button>
    </form>
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<?php if ($suggestIp !== '' && $suggestIp !== $settings['radius_nas_ip']): ?>
  <section class="panel" style="margin-bottom:var(--space-4)">
    <div class="panel-header"><h2>Unrecognised router</h2></div>
    <p class="page-sub" style="margin-bottom:var(--space-3)">
      The daemon is ignoring packets from <strong><?= htmlspecialchars($suggestIp) ?></strong>,
      which is not the trusted router IP. If that is your router, trust it:
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="trust_ip">
      <input type="hidden" name="ip" value="<?= htmlspecialchars($suggestIp) ?>">
      <button type="submit" class="btn-inline">Trust <?= htmlspecialchars($suggestIp) ?></button>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <div class="panel-header"><h2>Last 200 lines</h2></div>
  <pre id="log" class="log-view"><?= htmlspecialchars($log !== '' ? $log : 'No log yet. Start the daemon with: bash start_radius.sh start') ?></pre>
</section>

<script>
// Poll the raw endpoint so the log stays current while staff watch it during
// the event. Pinned to the bottom unless the reader has scrolled up.
setInterval(async function () {
  try {
    const res = await fetch('?raw=1', { cache: 'no-store' });
    const text = await res.text();
    const el = document.getElementById('log');
    const pinned = el.scrollTop + el.clientHeight >= el.scrollHeight - 20;
    el.textContent = text || 'No log yet.';
    if (pinned) { el.scrollTop = el.scrollHeight; }
  } catch (e) { /* transient fetch failure — keep the last content */ }
}, 3000);
document.getElementById('log').scrollTop = document.getElementById('log').scrollHeight;
</script>
<?php admin_layout_end(); ?>
```

- [ ] **Step 2: Add the log view and inline button styles**

Append to `assets/style.css`:

```css
.log-view {
  max-height: 460px;
  overflow: auto;
  margin: 0;
  padding: var(--space-3);
  background: var(--admin-bg);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--admin-text-muted);
}

/* Buttons that sit inline in a header row, rather than filling a form. */
.btn-inline {
  width: auto;
  min-height: 44px;
  margin: 0;
  padding: 0 var(--space-4);
  font-size: 14px;
  font-weight: 600;
}

.btn-inline.secondary {
  color: var(--admin-text);
  background: transparent;
  border: 1px solid var(--admin-border);
}
```

- [ ] **Step 3: Verify the log page, restart flag and trust-IP flow**

```bash
DB_NAME=wifi_portal php -S localhost:8000 > /dev/null 2>&1 &
sleep 1
rm -f /tmp/rl.txt
curl -s -c /tmp/rl.txt -X POST http://localhost:8000/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
echo "--- page renders ---"
curl -s -b /tmp/rl.txt "http://localhost:8000/admin/radius-log.php" | grep -o "Last 200 lines"
echo "--- raw endpoint ---"
curl -s -b /tmp/rl.txt "http://localhost:8000/admin/radius-log.php?raw=1" | head -3
echo "--- restart flag is written ---"
curl -s -b /tmp/rl.txt -X POST "http://localhost:8000/admin/radius-log.php" -d "action=restart" -o /dev/null
ls -la logs/radius.restart && rm -f logs/radius.restart
echo "--- trust IP rejects a bad value ---"
curl -s -b /tmp/rl.txt -X POST "http://localhost:8000/admin/radius-log.php" -d "action=trust_ip" -d "ip=not-an-ip" | grep -o "not a valid IP address"
kill %1
```

Expected: the page renders; the raw endpoint returns the log text; `logs/radius.restart` is created by the restart POST; the invalid IP is rejected with the validation message.

- [ ] **Step 4: Commit**

```bash
git add admin/radius-log.php assets/style.css
git commit -m "feat: add live RADIUS log viewer with restart and trust-IP actions"
```

---

## Task 10: Show Wi-Fi status on the dashboard

**Files:**
- Modify: `admin/index.php`

**Interfaces:**
- Consumes: `count_active_credentials(mysqli $db): int` (Task 2).

The dashboard currently counts `radcheck` usernames, a table Task 1 deleted. This task repairs that and surfaces the daemon's state where staff will actually look.

- [ ] **Step 1: Update the stats query in `admin/index.php`**

Add this require near the other requires at the top of the file:

```php
require_once __DIR__ . '/../lib/credentials.php';
```

Then replace this block:

```php
// radcheck holds two rows per code (Cleartext-Password + Simultaneous-Use),
// so count the distinct usernames to get provisioned Wi-Fi logins.
$radiusUsers = (int) $db->query(
    'SELECT COUNT(DISTINCT username) AS c FROM radcheck'
)->fetch_assoc()['c'];
```

with:

```php
$activeCredentials = count_active_credentials($db);
```

- [ ] **Step 2: Update the stat card**

Replace this card:

```php
  <div class="stat">
    <span class="stat-label">Wi-Fi logins issued</span>
    <span class="stat-value"><?= number_format($radiusUsers) ?></span>
  </div>
```

with:

```php
  <div class="stat">
    <span class="stat-label">Active Wi-Fi codes</span>
    <span class="stat-value"><?= number_format($activeCredentials) ?></span>
  </div>
```

- [ ] **Step 3: Verify the dashboard loads and counts correctly**

```bash
DB_NAME=wifi_portal php -S localhost:8000 > /dev/null 2>&1 &
sleep 1
rm -f /tmp/rd.txt
curl -s -c /tmp/rd.txt -X POST http://localhost:8000/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
curl -s -b /tmp/rd.txt "http://localhost:8000/admin/index.php" | grep -A1 "Active Wi-Fi codes" | tail -1
kill %1
mysql -u root wifi_portal -e "SELECT COUNT(*) AS should_match FROM wifi_credentials WHERE expires_at > NOW();"
```

Expected: the number rendered in the stat card matches the `should_match` count from MySQL.

- [ ] **Step 4: Commit**

```bash
git add admin/index.php
git commit -m "fix: count active wifi credentials on the dashboard instead of the removed radcheck table"
```

---

## Task 11: Rewrite the deployment guide

**Files:**
- Modify: `deploy/setup.md`
- Delete: `deploy/freeradius/clients.conf.snippet`
- Delete: `deploy/freeradius/sql.conf.snippet`

**Interfaces:**
- Consumes: everything built in Tasks 1–10.

- [ ] **Step 1: Delete the FreeRADIUS config snippets**

```bash
git rm deploy/freeradius/clients.conf.snippet deploy/freeradius/sql.conf.snippet
```

- [ ] **Step 2: Replace `deploy/setup.md` entirely**

```markdown
# VPS Deployment Guide — EYIF 2026 Wi-Fi Portal

Target: one Ubuntu 22.04 droplet. 1GB RAM is plenty for a single event.

There is **no FreeRADIUS to install**. The portal ships its own RADIUS daemon
(`radius_server.php`), which reads the same database as the web app.

## 1. Provision the server

- Create an Ubuntu 22.04 droplet and note its public IP (`<VPS-IP>`).
- `apt update && apt upgrade -y`
- Create a non-root sudo user and disable root SSH login.

## 2. Install PHP, MySQL and nginx

```bash
apt install -y nginx mysql-server composer \
  php php-fpm php-mysqli php-curl php-sockets php-fileinfo php-mbstring
```

`php-sockets` is required — the RADIUS daemon cannot run without it. Confirm:

```bash
php -m | grep -E 'sockets|fileinfo|mysqli'
```

All three must be listed.

## 3. Create the database

```bash
mysql -u root -e "CREATE DATABASE wifi_portal;"
mysql -u root -e "CREATE USER 'wifi_portal_user'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'wifi_portal_user'@'localhost';"
```

Clone the repo to `/var/www/wifi-portal`, then load the schema:

```bash
cd /var/www/wifi-portal
mysql -u root wifi_portal < schema.sql
composer install --no-dev
```

## 4. Configure the app

```bash
cp config.example.php config.php
php -r "echo bin2hex(random_bytes(32)) . \"\n\";"   # APP_KEY
php hash_password.php "<a-long-random-admin-password>"
```

Edit `config.php` and set:

- `DB_*` to the database user created above
- `APP_KEY` to the 64-character hex string just generated — this encrypts the
  RADIUS shared secret at rest. **Losing it makes stored secrets unreadable.**
- `ADMIN_PASSWORD_HASH` to the printed bcrypt hash
- `SMTP_*` and `TWILIO_*` to your real provider credentials
- `MIKROTIK_GATEWAY_HOST` to the hotspot gateway IP (e.g. `10.5.50.1`)

Then clear the shell history that contains the plaintext admin password:

```bash
history -c
```

Set permissions so the web server can write uploads and the daemon can write logs:

```bash
mkdir -p logs uploads/logos
chown -R www-data:www-data logs uploads
```

## 5. Point nginx at the app

Serve `/var/www/wifi-portal` with PHP-FPM as usual. Two rules matter:

```nginx
# Never serve the config or the logs.
location ~ ^/(config\.php|logs/) { deny all; }
```

## 6. Configure RADIUS in the admin UI

Open `https://<your-domain>/admin/`, log in, then go to **Wi-Fi & RADIUS**:

- **Shared secret** — generate with `openssl rand -base64 24`. Must match the router.
- **Authentication port** — `1812`
- **Router public IP** — the router's public IP. The daemon ignores packets
  from anywhere else, so this must be right.
- **Session length** — minutes a code stays valid. `720` = 12 hours, one event day.
- **Speed cap** — e.g. `5M/5M`, or blank for uncapped.

Save.

## 7. Start the daemon

```bash
sudo cp deploy/mangonet-radius.service /etc/systemd/system/
sudo nano /etc/systemd/system/mangonet-radius.service   # check paths and User
sudo systemctl daemon-reload
sudo systemctl enable --now mangonet-radius
sudo systemctl status mangonet-radius
```

Now click **Test** on the Wi-Fi & RADIUS page (reload it). It should report the
daemon is up and answering. If not, the message names the exact fault.

Without systemd, use the wrapper instead:

```bash
bash start_radius.sh start
```

## 8. Firewall

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow from <ROUTER-PUBLIC-IP> to any port 1812,1813 proto udp
ufw enable
```

RADIUS is deliberately **not** open to the internet — only to the router.

## 9. Configure the Mikrotik

On the **Wi-Fi & RADIUS** page click **Download router config**. It produces a
`.rsc` with your secret, server IP, port and portal host already filled in.

Upload it to the router and run:

```
/import file=eyif-radius.rsc
```

Then point the hotspot login page at the portal so Mikrotik's redirect
(carrying `mac`, `ip`, `link-login-only`, `link-orig`) lands on `index.php`.

Check the profile name matches your router first:

```
/ip hotspot profile print
```

## 10. End-to-end check

1. Connect a test phone to the event Wi-Fi.
2. Confirm it lands on the portal, not Mikrotik's default login page.
3. Submit the form with real contact details.
4. Confirm the code arrives by email and SMS.
5. Confirm the device reaches the internet immediately.
6. Watch **Admin → RADIUS Log** — an `ACCEPT` line should appear with the
   seconds remaining.
7. Confirm the speed cap applies (run a speed test if you set one).

## Troubleshooting

Everything is visible from the browser — **Admin → RADIUS Log** shows the live
daemon log, and **Wi-Fi & RADIUS** diagnoses connectivity.

| Symptom | Cause |
|---|---|
| "no logs/radius.pid" | Daemon never started — `systemctl start mangonet-radius` |
| "Ignored packet from x.x.x.x" | Router IP differs from the trusted IP. The log page offers a one-click "Trust this IP". |
| `REJECT: unknown or expired` | The code expired (past its session length) or was revoked. |
| `REJECT: wrong password` | Router and portal shared secrets differ. |
| Daemon alive, nothing answers | Another process holds UDP 1812 — `ss -lunp \| grep 1812` |
```

- [ ] **Step 3: Verify no FreeRADIUS references remain**

```bash
grep -rniE "freeradius|radcheck|radreply|radacct|mods-enabled" --include="*.md" --include="*.php" --include="*.sql" . | grep -v "docs/superpowers/" || echo "CLEAN — no stale FreeRADIUS references"
```

Expected: `CLEAN — no stale FreeRADIUS references`

- [ ] **Step 4: Run the full test suite one final time**

```bash
for t in settings settings_secret entries credentials radius radius_protocol radius_daemon; do
  echo -n "$t: "; DB_NAME=wifi_portal_test php tests/${t}_test.php | tail -1
done
for t in csv uploads mailer sms admin_auth; do
  echo -n "$t: "; php tests/${t}_test.php | tail -1
done
```

Expected: `ALL PASSED` on all twelve lines.

- [ ] **Step 5: Commit**

```bash
git add deploy/setup.md
git commit -m "docs: rewrite deployment guide for the built-in RADIUS daemon"
```

---

## Self-Review Notes (already applied above)

**Spec coverage:** daemon replacing FreeRADIUS (Tasks 1, 5, 6, 11); time limit via `Session-Timeout` + `Mikrotik-Uptime-Limit` (Tasks 3, 5); rate cap via `Mikrotik-Rate-Limit` (Tasks 3, 5, 8); encrypted settings (Task 4); admin settings hub (Task 8); live log viewer (Task 9); actionable diagnostics (Task 8); router config generator (Task 8); daemon lifecycle + browser-triggered restart (Tasks 5, 7, 9); NAS allowlist with loopback bypass (Task 5); dashboard repair (Task 10).

**Type consistency checked:** `issue_credential(mysqli, string, int, ?string, ?string): void` is used identically in Tasks 2, 6; `find_valid_credential(mysqli, string): ?array` in Tasks 2, 5, 6; `count_active_credentials(mysqli): int` in Tasks 2, 8, 10; `radius_add_user(mysqli, string, array): void` — the third parameter is new and is updated at its only call site in Task 6 Step 5; `radius_encrypt_password`/`radius_decrypt_password` share one signature across Tasks 3, 5, 8; `admin_layout_start(string, string, array)` matches the existing `admin/layout.php`.

**Deliberate carry-forward:** `wifi_credentials.mac` is created in Task 1 but unused until Stage 2 — justified inline to avoid migrating a live event database.

**Known follow-ups, explicitly out of scope for Stage 1:** accounting is acknowledged but not parsed (Stage 3 bandwidth quotas); no CoA/Disconnect-Message, so revoking a credential stops the next re-auth rather than cutting a live session at that instant — both are stated in the daemon's comments so the next implementer is not surprised.
