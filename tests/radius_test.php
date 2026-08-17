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

// Use MySQL's own `seconds_remaining` rather than `strtotime($row['expires_at']) - time()`:
// PHP runs on UTC and MySQL on UTC+1 here, and `expires_at` comes back as a bare datetime
// with no offset, so PHP-side arithmetic silently adds an hour to every session. See the
// comment on find_valid_credential() in lib/credentials.php.
$expiresIn = (int) $row['seconds_remaining'];
assert_true($expiresIn > 5000 && $expiresIn <= 5400, 'the credential expires after session_minutes (90m = 5400s)');

assert_true(radius_user_exists($db, '04829371'), 'radius_user_exists finds the issued code');
assert_true(!radius_user_exists($db, '99999999'), 'radius_user_exists is false for an unknown code');

// A returning attendee whose credential expired must get a fresh one. This is
// the day-2 case: the entries row still exists, so connect.php takes the
// duplicate path — which must still renew the credential.
issue_credential($db, '77778888', -60);            // expired an hour ago
assert_equals(null, find_valid_credential($db, '77778888'), 'the credential starts out expired');
radius_add_user($db, '77778888', $settings);       // what connect.php now does on every submission
$renewed = find_valid_credential($db, '77778888');
assert_true($renewed !== null, 'radius_add_user renews an expired credential rather than leaving it dead');
assert_true((int) $renewed['seconds_remaining'] > 5000, 'the renewed credential gets a full session again');

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

// '' is what connect.php actually sends when the router omitted the MAC — the
// single most common value this parameter takes in production, so pin it.
radius_add_user($db, '60606060', $settings, '');
assert_true(find_valid_credential($db, '60606060') !== null, 'an empty MAC still issues a working credential');

test_summary();
