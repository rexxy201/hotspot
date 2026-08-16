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
