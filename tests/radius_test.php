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
