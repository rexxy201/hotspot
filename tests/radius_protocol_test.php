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

// --- accounting counters -------------------------------------------------
assert_equals(0, radius_uint32(''), 'an empty value decodes to zero');
assert_equals(0, radius_uint32('abc'), 'a short value decodes to zero');
assert_equals(1000, radius_uint32(pack('N', 1000)), 'a 4-byte value decodes');
assert_equals(4294967295, radius_uint32(pack('N', 4294967295)), 'the full 32-bit range decodes unsigned');

// Octets alone, no gigawords attribute present.
$attrs = [R_ATTR_ACCT_INPUT_OCTETS => pack('N', 5000)];
assert_equals(5000, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'octets without gigawords is just the octets');

// THE case this function exists for: past 4GB the router splits the value.
// Ignoring gigawords would report 1000 bytes for a 4GB transfer.
$attrs = [
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 1000),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 1),
];
assert_equals(4294968296, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'gigawords are folded in as 2^32 each');

$attrs = [
    R_ATTR_ACCT_INPUT_OCTETS => pack('N', 0),
    R_ATTR_ACCT_INPUT_GIGAWORDS => pack('N', 3),
];
assert_equals(12884901888, radius_octets_64($attrs, R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'three gigawords is 12GB');

// Nothing present at all.
assert_equals(0, radius_octets_64([], R_ATTR_ACCT_INPUT_OCTETS, R_ATTR_ACCT_INPUT_GIGAWORDS), 'missing counters read as zero');

// --- Mikrotik vendor attribute numbers -----------------------------------
// Pinned against FreeRADIUS dictionary.mikrotik (vendor 14988). A wrong
// sub-type is not rejected by the router — it is silently ignored or applied
// as a different attribute entirely, so nothing surfaces at runtime. These
// assertions are the only thing that catches a regression.
assert_equals(14988, VENDOR_MIKROTIK, 'the Mikrotik vendor id is 14988');
assert_equals(3, MT_GROUP, 'Mikrotik-Group is 3 (5 is Wireless-Skip-Dot1x)');
assert_equals(8, MT_RATE_LIMIT, 'Mikrotik-Rate-Limit is 8 (9 is Realm)');
assert_equals(17, MT_TOTAL_LIMIT, 'Mikrotik-Total-Limit is 17');
assert_equals(18, MT_TOTAL_LIMIT_GIGAWORDS, 'Mikrotik-Total-Limit-Gigawords is 18 (15 is Xmit-Limit-Gigawords)');
assert_true(!defined('MT_UPTIME_LIMIT'), 'there is no Mikrotik uptime-limit attribute; session length uses the standard Session-Timeout');

// --- accounting authenticator -------------------------------------------
$acctSecret = 'shared-secret-here';
$acctBody = radius_encode_attr(R_ATTR_ACCT_SESSION_ID, 'sess-1')
          . radius_encode_attr(R_ATTR_USER_NAME, '12345678');
$acctHeader = chr(R_ACCOUNTING_REQUEST) . chr(7) . pack('n', 20 + strlen($acctBody));
$acctAuth = md5($acctHeader . str_repeat("\x00", 16) . $acctBody . $acctSecret, true);
$goodPacket = $acctHeader . $acctAuth . $acctBody;

assert_true(radius_verify_accounting($goodPacket, $acctSecret), 'a correctly signed Accounting-Request verifies');
assert_true(!radius_verify_accounting($goodPacket, 'wrong-secret'), 'a wrong shared secret fails verification');
assert_true(!radius_verify_accounting(substr($goodPacket, 0, 19), $acctSecret), 'a runt packet fails verification rather than throwing');
// Tamper with a counter byte: the signature must no longer match.
$tampered = $goodPacket;
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
assert_true(!radius_verify_accounting($tampered, $acctSecret), 'a tampered packet fails verification');

test_summary();
