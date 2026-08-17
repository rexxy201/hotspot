<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/radius_protocol.php';

// --- attribute encoding -------------------------------------------------
$attr = radius_encode_attr(R_ATTR_USER_NAME, 'alice');
assert_equals(chr(1) . chr(7) . 'alice', $attr, 'radius_encode_attr writes type, length and value');

// --- round trip ---------------------------------------------------------
$packed = radius_encode_attr(R_ATTR_USER_NAME, '04829371')
        . radius_encode_attr(R_ATTR_CALLING_STATION, 'AA:BB:CC:DD:EE:FF');
$parsed = radius_parse_attributes($packed);
assert_equals('04829371', $parsed[R_ATTR_USER_NAME], 'parse recovers the username');
assert_equals('AA:BB:CC:DD:EE:FF', $parsed[R_ATTR_CALLING_STATION], 'parse recovers the calling station');

// --- malformed input must not loop or throw -----------------------------
assert_equals([], radius_parse_attributes(''), 'empty input parses to an empty array');
// Declared length 200 but only a few bytes present: must stop, not overrun.
assert_equals([], radius_parse_attributes(chr(1) . chr(200) . 'xx'), 'over-long declared length is discarded');
// Length byte below the 2-byte minimum would mean zero forward progress.
assert_equals([], radius_parse_attributes(chr(1) . chr(0) . 'xx'), 'under-length attribute is discarded');

// --- vendor-specific attributes ----------------------------------------
$vsa = radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, '5M/5M');
$outer = radius_parse_attributes($vsa);
assert_true(isset($outer[R_ATTR_VENDOR_SPECIFIC]), 'a VSA is wrapped in attribute 26');
$inner = $outer[R_ATTR_VENDOR_SPECIFIC];
assert_equals(VENDOR_MIKROTIK, unpack('N', substr($inner, 0, 4))[1], 'the VSA carries the Mikrotik vendor id');
assert_equals(MT_RATE_LIMIT, ord($inner[4]), 'the VSA carries the rate-limit sub-type');
assert_equals('5M/5M', substr($inner, 6), 'the VSA carries the rate-limit value');

// --- PAP password obfuscation round trip --------------------------------
$secret = 'testing123';
$auth   = str_repeat("\x2a", 16);
$cipher = radius_encrypt_password('04829371', $auth, $secret);
assert_equals('04829371', radius_decrypt_password($cipher, $auth, $secret), 'PAP password survives an encrypt/decrypt round trip');
// Longer than one 16-byte block, to exercise the chaining.
$long = 'password-longer-than-sixteen-bytes';
assert_equals($long, radius_decrypt_password(radius_encrypt_password($long, $auth, $secret), $auth, $secret), 'multi-block PAP passwords round trip');
// A wrong secret must not recover the password.
assert_true(radius_decrypt_password($cipher, $auth, 'wrong-secret') !== '04829371', 'a wrong shared secret does not recover the password');

// --- CHAP ---------------------------------------------------------------
$chapId    = "\x07";
$challenge = str_repeat("\x11", 16);
$good      = $chapId . md5($chapId . '04829371' . $challenge, true);
assert_true(radius_check_chap($good, $challenge, '04829371'), 'radius_check_chap accepts a correct response');
assert_true(!radius_check_chap($good, $challenge, '99999999'), 'radius_check_chap rejects a wrong password');
assert_true(!radius_check_chap('', $challenge, '04829371'), 'radius_check_chap rejects an empty response');

// --- reply framing ------------------------------------------------------
$reply = radius_build_reply(R_ACCESS_ACCEPT, 42, $auth, $secret, radius_encode_attr(R_ATTR_SESSION_TIMEOUT, pack('N', 3600)));
assert_equals(R_ACCESS_ACCEPT, ord($reply[0]), 'the reply carries the Access-Accept code');
assert_equals(42, ord($reply[1]), 'the reply echoes the request identifier');
assert_equals(strlen($reply), unpack('n', substr($reply, 2, 2))[1], 'the declared length matches the real packet length');
$expectedAuth = md5(substr($reply, 0, 4) . $auth . substr($reply, 20) . $secret, true);
assert_equals($expectedAuth, substr($reply, 4, 16), 'the response authenticator is MD5(code+id+len+reqauth+attrs+secret)');

// --- oversized values must be capped, not silently wrapped ----------------
// A single-byte length field caps an attribute value at 253 bytes; chr() would
// wrap modulo 256 and emit a corrupt length, so encoding must truncate.
$huge = str_repeat('A', 400);
$capped = radius_encode_attr(R_ATTR_REPLY_MESSAGE, $huge);
assert_equals(255, strlen($capped), 'an oversized attribute is capped at the 255-byte maximum');
assert_equals(255, ord($capped[1]), 'the capped attribute declares a valid length byte');
$reparsed = radius_parse_attributes($capped);
assert_equals(253, strlen($reparsed[R_ATTR_REPLY_MESSAGE]), 'the capped attribute round-trips through the parser');

$hugeVsa = radius_encode_vsa(VENDOR_MIKROTIK, MT_RATE_LIMIT, $huge);
assert_equals(255, strlen($hugeVsa), 'an oversized VSA is capped at the 255-byte maximum');
assert_equals(255, ord($hugeVsa[1]), 'the capped VSA declares a valid outer length byte');
$vsaInner = radius_parse_attributes($hugeVsa)[R_ATTR_VENDOR_SPECIFIC];
assert_equals(247, strlen(substr($vsaInner, 6)), 'the capped VSA inner value is truncated to 247 bytes');

test_summary();
