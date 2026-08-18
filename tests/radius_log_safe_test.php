<?php
/**
 * Covers the log sanitiser in isolation. A crafted RADIUS username can carry
 * newlines, which would let an attacker forge whole entries in the operator's
 * log — the file the admin RADIUS Log page renders.
 *
 * radius_server.php cannot be required here (it binds a socket and loops), so
 * the function is loaded by extracting it from the source. radius_log_safe()
 * is now a thin wrapper around lib/log_safe.php's shared log_safe_value()
 * (the same sanitiser other entrypoints use too) — load that first so the
 * eval'd wrapper has something to call.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/log_safe.php';

$source = file_get_contents(__DIR__ . '/../radius_server.php');
if (!preg_match('/function radius_log_safe\(.*?\n\}/s', $source, $m)) {
    echo "FAIL: could not find radius_log_safe() in radius_server.php\n";
    exit(1);
}
eval($m[0]);

assert_equals('evil.FORGED LINE', radius_log_safe("evil\nFORGED LINE"), 'a newline cannot forge a new log line');
assert_equals('a.b', radius_log_safe("a\rb"), 'a carriage return is neutralised');
assert_equals('a.b', radius_log_safe("a\x00b"), 'a NUL byte is neutralised');
assert_equals('(empty)', radius_log_safe(''), 'an empty value renders as a placeholder');
assert_equals(64, strlen(radius_log_safe(str_repeat('A', 253))), 'an over-long value is capped at 64 characters');
assert_equals('04829371', radius_log_safe('04829371'), 'a normal code passes through untouched');
assert_equals('AA:BB:CC:DD:EE:FF', radius_log_safe('AA:BB:CC:DD:EE:FF'), 'a normal MAC passes through untouched');

test_summary();
