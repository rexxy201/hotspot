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

// seconds_remaining is computed by MySQL so PHP's differing timezone can never
// skew it — the daemon consumes this instead of parsing expires_at.
assert_true(isset($row['seconds_remaining']), 'find_valid_credential returns seconds_remaining');
assert_true((int) $row['seconds_remaining'] > 3500 && (int) $row['seconds_remaining'] <= 3600,
    'seconds_remaining reflects the 60-minute credential, not skewed by an hour');

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

// Revoking Wi-Fi must NEVER cost the attendee their prize-draw entry. The code
// is both the RADIUS credential and the raffle ticket, so this is the one
// invariant the admin revoke button must not break.
require_once __DIR__ . '/../lib/entries.php';
$db->query("DELETE FROM entries WHERE email = 'revoke.test@example.com'");
create_entry($db, 'Revoke Test', '08099998888', 'revoke.test@example.com', '31313131');
issue_credential($db, '31313131', 60);
assert_true(find_valid_credential($db, '31313131') !== null, 'the credential exists before revoking');

revoke_credential($db, '31313131');

assert_equals(null, find_valid_credential($db, '31313131'), 'revoke_credential removes the wifi credential');
$stillThere = $db->query("SELECT code FROM entries WHERE email = 'revoke.test@example.com'")->fetch_assoc();
assert_true($stillThere !== null, 'revoking leaves the raffle entry in place');
assert_equals('31313131', $stillThere['code'], 'the raffle entry keeps its original code after revoking');
$db->query("DELETE FROM entries WHERE email = 'revoke.test@example.com'");

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
