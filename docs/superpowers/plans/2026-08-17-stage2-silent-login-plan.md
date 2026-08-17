# Stage 2: Silent Login by MAC — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop making an attendee re-type their details every time their phone drops off the Wi-Fi. When a device returns to the portal and that device still holds a valid credential, connect it straight through with no form.

**Architecture:** Mikrotik already passes the client MAC on its captive redirect (`?mac=…`), and `index.php` already captures it into a hidden field that `connect.php` currently discards. Stage 2 finally uses it: `connect.php` records the MAC on the credential it issues, and `index.php` looks up that MAC on load. A hit — and only a hit that is *still valid* — renders an auto-submitting login form instead of the sign-up form. An expired credential falls back to the form, so a device is never resurrected by MAC alone. The whole path is behind an admin toggle.

**Tech Stack:** PHP 8.4, MySQL 8 via mysqli, the project's custom test harness (`tests/bootstrap.php`).

## Global Constraints

- All DB queries use `mysqli` prepared statements — no string-concatenated SQL, anywhere.
- No PHP test framework — tests use `tests/bootstrap.php`'s `assert_equals($expected, $actual, string $message)`, `assert_true($condition, string $message)`, `test_summary()`.
- Codes are 8 digits, numeric only, zero-padded. The code is both the RADIUS username and password.
- Revoking Wi-Fi must never delete the attendee's `entries` row — it is their prize-draw entry.
- **Never do date arithmetic in PHP on a MySQL timestamp.** PHP and MySQL run in different timezones on this deployment (measured 1–2 hours apart). Use the SQL-computed `seconds_remaining` that `find_valid_credential()` already returns.
- Secrets in `settings` are encrypted at rest via `SETTINGS_SECRET_KEYS`; `APP_KEY` lives only in `config.php`.
- Admin pages are gated by `require_admin_session()` before any output and use `admin_layout_start()` / `admin_layout_end()`.

## The security model — read before implementing

**The MAC is attacker-controlled.** It arrives as `$_GET['mac']` on a URL the attendee's browser follows. Any device on the event SSID can hand-craft `index.php?mac=<someone else's MAC>&link-login-only=…`. Nothing in the portal can verify it: the portal sits behind the router's NAT, so it sees the router's address, not the device.

Three decisions follow, and every task must preserve them:

1. **Silent login never issues or renews a credential.** It only *reuses* one that is already valid. A forged MAC therefore cannot resurrect an expired credential or create a new one — it can only ride a session that already exists. This is why the "expired → show the form" behaviour is a security property, not just a UX choice.
2. **The silent path never displays the code.** The normal success page shows the code because the attendee needs it for the prize draw. On the silent path the code is only ever placed in the hidden fields posted to the router. Spoofing a MAC then yields Wi-Fi that was free anyway, not another attendee's raffle entry.
3. **The toggle is a kill switch.** If MAC handling misbehaves on the day, staff turn it off from the admin and every device falls back to the form.

## File Structure

| File | Change |
|---|---|
| `lib/credentials.php` | Add `normalize_mac()` and `find_valid_credential_by_mac()` |
| `lib/radius.php` | `radius_add_user()` gains an optional `$mac` |
| `connect.php` | Pass the submitted MAC through to the credential |
| `index.php` | Silent-login branch before the form |
| `lib/settings.php` | New `silent_login_enabled` key |
| `admin/radius.php` | Toggle field |
| `admin/entries.php` | Show the bound device so staff can see it |
| `deploy/setup.md` | Document the behaviour and the toggle |

---

## Task 1: MAC normalisation and lookup

**Files:**
- Modify: `lib/credentials.php`
- Test: `tests/credentials_test.php`

**Interfaces:**
- Produces: `normalize_mac(string $mac): string` — uppercase, colon-separated, or `''` if not a valid MAC.
- Produces: `find_valid_credential_by_mac(mysqli $db, string $mac): ?array` — same row shape as `find_valid_credential()`, including `seconds_remaining`; `null` if no valid credential is bound to that MAC.

- [ ] **Step 1: Write the failing test**

Append to `tests/credentials_test.php`, immediately before the final `test_summary();`:

```php
// --- MAC normalisation ---------------------------------------------------
// Mikrotik sends AA:BB:CC:DD:EE:FF, but routers and vendors vary in case and
// separator. Normalising on the way in means a lookup cannot miss simply
// because the same device was recorded in a different format.
assert_equals('AA:BB:CC:DD:EE:FF', normalize_mac('aa:bb:cc:dd:ee:ff'), 'normalize_mac uppercases');
assert_equals('AA:BB:CC:DD:EE:FF', normalize_mac('AA-BB-CC-DD-EE-FF'), 'normalize_mac accepts dash separators');
assert_equals('AA:BB:CC:DD:EE:FF', normalize_mac('aabbccddeeff'), 'normalize_mac accepts bare hex');
assert_equals('AA:BB:CC:DD:EE:FF', normalize_mac('  AA:BB:CC:DD:EE:FF  '), 'normalize_mac trims');
assert_equals('', normalize_mac(''), 'an empty MAC normalises to empty');
assert_equals('', normalize_mac('not-a-mac'), 'a non-MAC normalises to empty');
assert_equals('', normalize_mac('AA:BB:CC:DD:EE'), 'a short MAC normalises to empty');
assert_equals('', normalize_mac('AA:BB:CC:DD:EE:FF:00'), 'an over-long MAC normalises to empty');

// --- lookup by MAC -------------------------------------------------------
$db->query('DELETE FROM wifi_credentials');
assert_equals(null, find_valid_credential_by_mac($db, 'AA:BB:CC:DD:EE:FF'), 'no credential for an unknown MAC');

issue_credential($db, '12341234', 60, null, 'AA:BB:CC:DD:EE:FF');
$byMac = find_valid_credential_by_mac($db, 'AA:BB:CC:DD:EE:FF');
assert_true($byMac !== null, 'a valid credential is found by its MAC');
assert_equals('12341234', $byMac['code'] ?? $byMac['username'], 'the MAC lookup returns the right code');
assert_true((int) $byMac['seconds_remaining'] > 3500, 'the MAC lookup returns SQL-computed seconds_remaining');

// Lookup must normalise its argument too, or a differently-formatted MAC misses.
assert_true(find_valid_credential_by_mac($db, 'aa-bb-cc-dd-ee-ff') !== null, 'the MAC lookup normalises its argument');

// An EXPIRED credential must NOT be found by MAC. This is a security property,
// not just tidiness: silent login must never resurrect a dead credential for a
// MAC, because the MAC is attacker-supplied.
issue_credential($db, '43214321', -60, null, 'BB:BB:BB:BB:BB:BB');
assert_equals(null, find_valid_credential_by_mac($db, 'BB:BB:BB:BB:BB:BB'), 'an expired credential is not found by MAC');

// An empty MAC must never match a row, including rows with a NULL mac.
issue_credential($db, '56785678', 60);   // no MAC bound
assert_equals(null, find_valid_credential_by_mac($db, ''), 'an empty MAC matches nothing');

test_summary();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/credentials_test.php`
Expected: FAIL with `Call to undefined function normalize_mac()`

- [ ] **Step 3: Add both functions to `lib/credentials.php`**

Append to the end of the file:

```php
/**
 * Canonicalise a MAC to uppercase colon-separated form, or '' if it is not one.
 *
 * Routers differ in case and separator, so storing and comparing a canonical
 * form stops a lookup missing purely on formatting. Returning '' for anything
 * that is not a MAC means callers get one obviously-invalid value to check
 * rather than having to validate the shape themselves.
 */
function normalize_mac(string $mac): string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/**
 * The still-valid credential bound to a device, or null.
 *
 * Used only by the silent-login path. The `expires_at > NOW()` filter is load
 * bearing: the MAC is supplied by the client, so matching an expired row here
 * would let a forged MAC revive a dead credential.
 */
function find_valid_credential_by_mac(mysqli $db, string $mac): ?array
{
    $normalised = normalize_mac($mac);
    if ($normalised === '') {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, username, password, mac, rate_limit, expires_at,
                TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
           FROM wifi_credentials
          WHERE mac = ? AND expires_at > NOW()
          LIMIT 1'
    );
    $stmt->bind_param('s', $normalised);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
```

- [ ] **Step 4: Normalise on write too**

In `issue_credential()`, the `$mac` parameter is currently stored as given. Normalise it so writes and reads agree. Find the line assigning the bind variables and add, immediately before the `prepare()` call:

```php
    // Store the canonical form so find_valid_credential_by_mac() cannot miss on
    // formatting. A non-MAC becomes NULL rather than a junk string.
    $mac = $mac === null ? null : (normalize_mac($mac) ?: null);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/credentials_test.php`
Expected: `ALL PASSED`

- [ ] **Step 6: Commit**

```bash
git add lib/credentials.php tests/credentials_test.php
git commit -m "feat: add MAC normalisation and lookup for silent login"
```

---

## Task 2: The `silent_login_enabled` setting

**Files:**
- Modify: `lib/settings.php`
- Modify: `admin/radius.php`
- Test: `tests/settings_test.php`

**Interfaces:**
- Produces: settings key `silent_login_enabled`, `'1'` (on) or `'0'` (off), default `'1'`.

- [ ] **Step 1: Add the key to `SETTINGS_DEFAULTS`**

In `lib/settings.php`, inside `SETTINGS_DEFAULTS`, after the `rate_limit` entry:

```php
    // '1' = a device holding a still-valid credential is reconnected without
    // seeing the form. '0' = always show the form. Staff kill switch: the MAC
    // this relies on is supplied by the client, so being able to turn it off
    // mid-event without a redeploy matters.
    'silent_login_enabled' => '1',
```

- [ ] **Step 2: Write the failing test**

Append to `tests/settings_test.php`, before its final `test_summary();`:

```php
// Silent login defaults to on, and round-trips as a plain (unencrypted) value.
$fresh = get_settings($db);
assert_equals('1', $fresh['silent_login_enabled'], 'silent login is on by default');
save_settings($db, ['silent_login_enabled' => '0']);
assert_equals('0', get_settings($db)['silent_login_enabled'], 'silent login can be turned off');
save_settings($db, ['silent_login_enabled' => '1']);
assert_equals('1', get_settings($db)['silent_login_enabled'], 'silent login can be turned back on');
```

- [ ] **Step 3: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: FAIL on `silent login is on by default` — the key does not exist yet, so `$fresh['silent_login_enabled']` is an undefined index.

- [ ] **Step 4: Run test to verify it passes**

After Step 1's edit is in place:

Run: `DB_NAME=wifi_portal_test php tests/settings_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Add the toggle to the admin form**

In `admin/radius.php`, in the POST handler where `$newSettings` is built, add:

```php
        // An unchecked checkbox sends nothing, so absence means off.
        'silent_login_enabled' => isset($_POST['silent_login_enabled']) ? '1' : '0',
```

Then add this field to the form, immediately before the `Save RADIUS settings` button:

```php
    <div class="field">
      <label for="silent_login_enabled" class="checkbox-label">
        <input type="checkbox" id="silent_login_enabled" name="silent_login_enabled" value="1"
               <?= $settings['silent_login_enabled'] === '1' ? 'checked' : '' ?>>
        Reconnect known devices without the form
      </label>
      <p class="field-hint">When a device comes back to the portal still holding a valid code, connect it straight through instead of asking for its details again. Expired codes always get the form. Turn this off if device detection misbehaves.</p>
    </div>
```

- [ ] **Step 6: Add the checkbox style**

Append to `assets/style.css`:

```css
/* Checkbox rows read as one control, not a stray box above a label. */
.checkbox-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-weight: 600;
  cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
  width: 18px;
  height: 18px;
  min-height: 0;
  margin: 0;
  accent-color: var(--brand-color);
  cursor: pointer;
}
```

- [ ] **Step 7: Verify the toggle saves both ways**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
rm -f /tmp/s2.txt
curl -s -c /tmp/s2.txt -X POST http://localhost:8010/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
echo "--- off (checkbox omitted) ---"
curl -s -b /tmp/s2.txt -X POST "http://localhost:8010/admin/radius.php" \
  -d "radius_auth_port=1812" -d "radius_nas_ip=10.0.0.1" -d "session_minutes=720" -d "rate_limit=" -o /dev/null
mysql -u root wifi_portal -e "SELECT setting_value FROM settings WHERE setting_key='silent_login_enabled';"
echo "--- on (checkbox present) ---"
curl -s -b /tmp/s2.txt -X POST "http://localhost:8010/admin/radius.php" \
  -d "radius_auth_port=1812" -d "radius_nas_ip=10.0.0.1" -d "session_minutes=720" -d "rate_limit=" \
  -d "silent_login_enabled=1" -o /dev/null
mysql -u root wifi_portal -e "SELECT setting_value FROM settings WHERE setting_key='silent_login_enabled';"
kill %1
```

Expected: `0` after the first POST, `1` after the second.

- [ ] **Step 8: Commit**

```bash
git add lib/settings.php admin/radius.php assets/style.css tests/settings_test.php
git commit -m "feat: add an admin toggle for silent login"
```

---

## Task 3: Bind the MAC to the credential

**Files:**
- Modify: `lib/radius.php`
- Modify: `connect.php`
- Test: `tests/radius_test.php`

**Interfaces:**
- Produces: `radius_add_user(mysqli $db, string $code, array $settings, ?string $mac = null): void` — the fourth parameter is new and optional, so existing call sites keep working.

This closes a loose end flagged in the original build review: `connect.php` has always received `mikrotik_mac` as a hidden field and thrown it away.

- [ ] **Step 1: Write the failing test**

Append to `tests/radius_test.php`, before its final `test_summary();`:

```php
// The MAC submitted with the form is bound to the credential, so a later visit
// from the same device can be recognised.
$db->query('DELETE FROM wifi_credentials');
radius_add_user($db, '90909090', $settings, 'AA:BB:CC:DD:EE:11');
$bound = find_valid_credential_by_mac($db, 'AA:BB:CC:DD:EE:11');
assert_true($bound !== null, 'radius_add_user binds the MAC it is given');
assert_equals('90909090', $bound['username'], 'the MAC resolves to the right code');

// A submission with no MAC must still issue a working credential.
radius_add_user($db, '80808080', $settings);
assert_true(find_valid_credential($db, '80808080') !== null, 'a credential is still issued when no MAC is supplied');

// Garbage in the MAC field must not block the credential — it is optional data
// from the router, and a malformed value should degrade to "no MAC", not fail.
radius_add_user($db, '70707070', $settings, 'not-a-mac');
assert_true(find_valid_credential($db, '70707070') !== null, 'a malformed MAC does not prevent issuing a credential');
assert_equals(null, find_valid_credential_by_mac($db, 'not-a-mac'), 'a malformed MAC is not stored as a lookup key');
```

Add `require_once __DIR__ . '/../lib/credentials.php';` to the top of the file if it is not already there.

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: FAIL — `radius_add_user()` currently takes three arguments, so the MAC is ignored and `find_valid_credential_by_mac()` returns null.

- [ ] **Step 3: Add the parameter in `lib/radius.php`**

Replace `radius_add_user()` with:

```php
function radius_add_user(mysqli $db, string $code, array $settings, ?string $mac = null): void
{
    $minutes = max(1, (int) ($settings['session_minutes'] ?? 720));
    $rate = trim((string) ($settings['rate_limit'] ?? ''));
    // The MAC is optional: it only enables silent login later. issue_credential()
    // normalises it and stores NULL for anything that is not a MAC, so a junk
    // value from the router degrades to "no device bound" rather than failing.
    issue_credential($db, $code, $minutes, $rate !== '' ? $rate : null, $mac);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_NAME=wifi_portal_test php tests/radius_test.php`
Expected: `ALL PASSED`

- [ ] **Step 5: Pass the submitted MAC through in `connect.php`**

`connect.php` receives the MAC as `$_POST['mikrotik_mac']`. Just above the existing unconditional `radius_add_user($db, $code, $settings);` call, add:

```php
    // The router hands us the client MAC on its redirect, and index.php carries
    // it through the form. Binding it to the credential is what lets a device
    // that drops off the Wi-Fi reconnect later without re-typing its details.
    $submittedMac = (string) ($_POST['mikrotik_mac'] ?? '');
```

and change the call to:

```php
    radius_add_user($db, $code, $settings, $submittedMac);
```

- [ ] **Step 6: Verify end to end that a form submission binds the MAC**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
curl -s -X POST http://localhost:8010/connect.php \
  -d "name=Mac Bind Test" -d "phone=08012340000" -d "email=macbind@example.com" \
  -d "mikrotik_mac=AA:BB:CC:11:22:33" -o /dev/null
kill %1
mysql -u root wifi_portal -e "SELECT c.username, c.mac FROM wifi_credentials c JOIN entries e ON e.code = c.username WHERE e.email='macbind@example.com';"
```

Expected: one row whose `mac` is `AA:BB:CC:11:22:33`.

- [ ] **Step 7: Commit**

```bash
git add lib/radius.php connect.php tests/radius_test.php
git commit -m "feat: bind the client MAC to the credential it issues"
```

---

## Task 4: The silent-login path

**Files:**
- Modify: `index.php`

**Interfaces:**
- Consumes: `get_settings()`, `find_valid_credential_by_mac()`, `MIKROTIK_GATEWAY_HOST`.

- [ ] **Step 1: Add the silent-login branch to `index.php`**

After `$settings = get_settings($db);` and after `$mikrotikParams` is built, insert:

```php
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

if ($settings['silent_login_enabled'] === '1' && $mikrotikParams['mac'] !== '') {
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
```

`index.php` must also require the credentials library — add near the existing requires:

```php
require_once __DIR__ . '/lib/credentials.php';
```

- [ ] **Step 2: Render the silent page instead of the form**

Wrap the existing card contents so the silent path replaces the form. Immediately after the opening `<div class="portal-card">`, add:

```php
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
```

and immediately before the closing `</div>` of the card, add:

```php
    <?php endif; ?>
```

so the existing heading, tagline, details and form all sit in the `else` branch.

- [ ] **Step 3: Honour the escape hatch**

A shared or handed-on device must be able to reach the form. At the top of the silent-login block, before the lookup, add the opt-out:

```php
// An explicit "not you?" click always wins — a borrowed or handed-on phone must
// be able to reach the form.
$forget = ($_GET['forget'] ?? '') === '1';
```

and add `&& !$forget` to the `if` that guards the lookup:

```php
if (!$forget && $settings['silent_login_enabled'] === '1' && $mikrotikParams['mac'] !== '') {
```

- [ ] **Step 4: Verify all five paths**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1

# Bind a known device.
php -r "
require 'config.php'; require 'db.php'; require 'lib/credentials.php';
\$db = get_db();
\$db->query(\"DELETE FROM wifi_credentials WHERE mac='DE:AD:BE:EF:00:01'\");
issue_credential(\$db, '65656565', 720, null, 'DE:AD:BE:EF:00:01');
echo 'bound 65656565 to DE:AD:BE:EF:00:01' . PHP_EOL;
"

GW="http://10.5.50.1/login"
echo "--- 1. known device + real gateway -> silent, and the code is NOT shown ---"
curl -s "http://localhost:8010/index.php?mac=DE:AD:BE:EF:00:01&link-login-only=$GW" > /tmp/silent.html
grep -c "silent-login" /tmp/silent.html
# The code IS present in the hidden fields — it must be, to reach the router.
# Assert only that it is not rendered as visible text.
grep -c 'class="code"' /tmp/silent.html
grep -o "Welcome back" /tmp/silent.html

echo "--- 2. unknown device -> form ---"
curl -s "http://localhost:8010/index.php?mac=00:00:00:00:00:99&link-login-only=$GW" | grep -o 'id="connect-form"'

echo "--- 3. known device but attacker-named gateway -> form, no auto-post ---"
curl -s "http://localhost:8010/index.php?mac=DE:AD:BE:EF:00:01&link-login-only=http://evil.example/login" | grep -o 'id="connect-form"'

echo "--- 4. escape hatch -> form ---"
curl -s "http://localhost:8010/index.php?mac=DE:AD:BE:EF:00:01&link-login-only=$GW&forget=1" | grep -o 'id="connect-form"'

echo "--- 5. toggle off -> form ---"
mysql -u root wifi_portal -e "UPDATE settings SET setting_value='0' WHERE setting_key='silent_login_enabled';"
curl -s "http://localhost:8010/index.php?mac=DE:AD:BE:EF:00:01&link-login-only=$GW" | grep -o 'id="connect-form"'
mysql -u root wifi_portal -e "UPDATE settings SET setting_value='1' WHERE setting_key='silent_login_enabled';"

kill %1
```

Expected: (1) `1` for `silent-login`, `0` for `class="code"` — the code must not be rendered as visible text — and `Welcome back`; (2) through (5) each print `id="connect-form"`.

**Correction to the plan header.** The header originally claimed the silent path never exposes the code. That is wrong: the credential has to travel through the client's browser to reach the router, so it is in the hidden fields and readable from the page source by anyone who can craft the request. Not rendering it visibly is cosmetic. The real, holding protection is rule 1 — silent login never issues or renews, only reuses. Fully closing the exposure requires a device credential distinct from the raffle code, which is a separate decision.

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat: reconnect a known device without showing the form"
```

---

## Task 5: Surface the bound device, and document it

**Files:**
- Modify: `admin/entries.php`
- Modify: `deploy/setup.md`

- [ ] **Step 1: Show the bound device in the entries table**

In `admin/entries.php`, add `c.mac` to the SELECT column list (after `c.expires_at`).

Then in the Wi-Fi cell, under the existing Active/Expired/None pills, show the device when one is bound. Immediately after the `<?php endif; ?>` that closes the pill block, add:

```php
              <?php if (!empty($row['mac'])): ?>
                <span class="pill-note" title="Device bound for silent reconnect"><?= htmlspecialchars($row['mac'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
```

- [ ] **Step 2: Document the behaviour in `deploy/setup.md`**

Add this section immediately before the Troubleshooting section:

```markdown
## Silent reconnect

When a device returns to the portal still holding a valid code, it is connected
straight through instead of being asked for its details again — the common case
being a phone that slept or walked out of range. Turn it off with **Reconnect
known devices without the form** on the Wi-Fi & RADIUS page.

Two limits are deliberate:

- **An expired code always gets the form.** The device MAC arrives in the URL
  and cannot be verified from the portal, so silent reconnect only ever reuses a
  credential that is already valid. It never issues or renews one.
- **The code is not shown on the reconnect screen.** It is posted to the router
  but never displayed, so a spoofed MAC yields Wi-Fi that was free anyway rather
  than another attendee's prize-draw code.

Attendees who lent their phone to someone can use **Not you? Sign in with your
own details** to reach the form.

The bound device is shown next to each code in Raffle Entries, so staff can see
which entries will reconnect silently.
```

Add a troubleshooting row to the existing table:

```markdown
| A device is not reconnecting silently | Its code expired (expected — it gets the form), silent reconnect is switched off, or the router is not sending `mac` on its redirect. Check the Wi-Fi column in Raffle Entries: no device shown means no MAC was ever recorded. |
```

- [ ] **Step 3: Verify the admin page and run the full suite**

```bash
php -S localhost:8010 > /dev/null 2>&1 &
sleep 1
rm -f /tmp/s5.txt
curl -s -c /tmp/s5.txt -X POST http://localhost:8010/admin/login.php -d "password=eyif-preview-2026" -o /dev/null
curl -s -b /tmp/s5.txt "http://localhost:8010/admin/entries.php" | grep -oE "DE:AD:BE:EF:00:01|AA:BB:CC:11:22:33" | head -3
kill %1
```

Expected: at least one bound MAC is listed.

Then the whole suite:

```bash
for t in settings settings_secret entries credentials radius radius_protocol radius_daemon; do
  echo -n "$t: "; DB_NAME=wifi_portal_test php tests/${t}_test.php | tail -1
done
for t in csv uploads mailer sms admin_auth radius_log_safe; do
  echo -n "$t: "; php tests/${t}_test.php | tail -1
done
```

Expected: `ALL PASSED` on all thirteen.

- [ ] **Step 4: Commit**

```bash
git add admin/entries.php deploy/setup.md
git commit -m "docs: surface the bound device and document silent reconnect"
```

---

## Self-Review Notes

**Spec coverage:** MAC normalisation and lookup (Task 1); admin toggle (Task 2); MAC bound at issue time, closing the long-standing unused-`mikrotik_mac` finding (Task 3); the silent path itself with gateway validation and the escape hatch (Task 4); staff visibility and docs (Task 5).

**Type consistency:** `normalize_mac(string): string` and `find_valid_credential_by_mac(mysqli, string): ?array` are used identically in Tasks 1, 3, 4. `radius_add_user()` gains a fourth optional parameter, so the existing three-argument call in `connect.php` stays valid until Task 3 updates it. `issue_credential()`'s signature is unchanged — Task 1 only normalises inside it.

**Security properties, each pinned by a check rather than a comment:** an expired credential is not returned by MAC (Task 1 test); a malformed MAC is not stored as a lookup key (Task 3 test); the code never appears in the silent page's HTML (Task 4 Step 4 check 1); an attacker-named `link-login-only` does not auto-post (Task 4 Step 4 check 3).

**Deliberately out of scope:** no `blocked_at` flag — revoking still lets an attendee re-register, and hard blocking remains a separate decision. Bandwidth quotas remain Stage 3.
