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
