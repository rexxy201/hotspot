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

test_summary();
