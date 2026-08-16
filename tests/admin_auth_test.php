<?php
require_once __DIR__ . '/bootstrap.php';

$hash = password_hash('correct horse battery staple', PASSWORD_BCRYPT);
assert_true(password_verify('correct horse battery staple', $hash), 'password_verify accepts the correct password');
assert_true(!password_verify('wrong password', $hash), 'password_verify rejects an incorrect password');

test_summary();
