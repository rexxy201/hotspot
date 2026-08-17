<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/usage.php';

$db = get_db();
$db->query('DELETE FROM radius_sessions');

assert_equals(0, usage_bytes_for_code($db, '11112222'), 'a code with no sessions has used nothing');

record_session_usage($db, 'sess-A', '11112222', 1000, 2000);
assert_equals(3000, usage_bytes_for_code($db, '11112222'), 'usage is input plus output');

// THE important one: interim updates carry ABSOLUTE counters, not deltas. The
// same session reporting again must overwrite, not accumulate — otherwise a
// chatty router inflates usage and cuts people off early.
record_session_usage($db, 'sess-A', '11112222', 5000, 6000);
assert_equals(11000, usage_bytes_for_code($db, '11112222'), 're-reporting a session overwrites rather than accumulating');

// A byte-for-byte duplicate (a retransmission) must change nothing.
record_session_usage($db, 'sess-A', '11112222', 5000, 6000);
assert_equals(11000, usage_bytes_for_code($db, '11112222'), 'a duplicated packet does not double-count');

// A second session for the same code DOES add.
record_session_usage($db, 'sess-B', '11112222', 1000, 1000);
assert_equals(13000, usage_bytes_for_code($db, '11112222'), 'a second session adds to the total');

// Another code is unaffected.
record_session_usage($db, 'sess-C', '99998888', 500, 500);
assert_equals(13000, usage_bytes_for_code($db, '11112222'), "another code's usage is separate");
assert_equals(1000, usage_bytes_for_code($db, '99998888'), 'the second code has its own total');

assert_equals(14000, total_usage_bytes($db), 'total_usage_bytes sums every code');

// Values above 32 bits must survive the round trip — this is the 4GB case the
// gigawords handling exists for.
record_session_usage($db, 'sess-BIG', '77776666', 6000000000, 1000000000);
assert_equals(7000000000, usage_bytes_for_code($db, '77776666'), 'usage above 4GB is stored and summed correctly');

// The inviolable one: clearing usage must never reach the attendee's entries
// row, which is their prize-draw entry. The SQL is plainly safe today; this
// pins it so a future join or cascade cannot break it silently.
require_once __DIR__ . '/../lib/entries.php';
$db->query("DELETE FROM entries WHERE email = 'usage.invariant@example.com'");
create_entry($db, 'Usage Invariant', '08011112222', 'usage.invariant@example.com', '11112222');

reset_usage_for_code($db, '11112222');

$entrySurvived = $db->query("SELECT code FROM entries WHERE email = 'usage.invariant@example.com'")->fetch_assoc();
assert_true($entrySurvived !== null, 'resetting usage leaves the raffle entry in place');
assert_equals('11112222', $entrySurvived['code'], 'the raffle entry keeps its code after a usage reset');
$db->query("DELETE FROM entries WHERE email = 'usage.invariant@example.com'");
assert_equals(0, usage_bytes_for_code($db, '11112222'), 'reset clears that code');
assert_equals(1000, usage_bytes_for_code($db, '99998888'), 'reset leaves other codes alone');

// Human-readable sizes.
assert_equals('0 B', format_bytes(0), 'format_bytes handles zero');
assert_equals('512 B', format_bytes(512), 'format_bytes handles bytes');
assert_equals('1.0 KB', format_bytes(1024), 'format_bytes handles kilobytes');
assert_equals('1.5 MB', format_bytes(1572864), 'format_bytes handles megabytes');
assert_equals('2.0 GB', format_bytes(2147483648), 'format_bytes handles gigabytes');

test_summary();
