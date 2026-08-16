<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/csv.php';

assert_equals("'=cmd", csv_safe_field('=cmd'), 'csv_safe_field neutralizes a leading =');
assert_equals("'+2348010000000", csv_safe_field('+2348010000000'), 'csv_safe_field neutralizes a leading +');
assert_equals("'-1+1", csv_safe_field('-1+1'), 'csv_safe_field neutralizes a leading -');
assert_equals("'@SUM(1)", csv_safe_field('@SUM(1)'), 'csv_safe_field neutralizes a leading @');
assert_equals('Jane Doe', csv_safe_field('Jane Doe'), 'csv_safe_field leaves normal text untouched');
assert_equals('', csv_safe_field(''), 'csv_safe_field handles an empty string');

test_summary();
