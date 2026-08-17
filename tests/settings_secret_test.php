<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';

$db = get_db();
$db->query('DELETE FROM settings');

// Round trip through the encryption helpers directly.
$cipher = setting_encrypt('super-secret-value');
assert_true(strpos($cipher, 'enc:') === 0, 'encrypted values are tagged with an enc: prefix');
assert_true(strpos($cipher, 'super-secret-value') === false, 'the plaintext does not appear in the ciphertext');
assert_equals('super-secret-value', setting_decrypt($cipher), 'setting_decrypt reverses setting_encrypt');

// Two encryptions of the same plaintext must differ (random IV).
assert_true(setting_encrypt('same') !== setting_encrypt('same'), 'each encryption uses a fresh IV');

// Plain (unencrypted, legacy) values pass through untouched.
assert_equals('plain-value', setting_decrypt('plain-value'), 'non-prefixed values are returned as-is');

// Secret keys are transparently encrypted on save and decrypted on read.
save_settings($db, ['radius_secret' => 'my-radius-secret', 'event_name' => 'Test Event']);

$stored = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'radius_secret'")->fetch_assoc();
assert_true(strpos($stored['setting_value'], 'enc:') === 0, 'radius_secret is stored encrypted in the database');
assert_true(strpos($stored['setting_value'], 'my-radius-secret') === false, 'the raw secret is not present in the database');

$plainStored = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'event_name'")->fetch_assoc();
assert_equals('Test Event', $plainStored['setting_value'], 'non-secret settings are stored in the clear');

$settings = get_settings($db);
assert_equals('my-radius-secret', $settings['radius_secret'], 'get_settings decrypts secrets transparently');
assert_equals('Test Event', $settings['event_name'], 'get_settings returns non-secrets unchanged');

// --- failure paths -------------------------------------------------------
// Corrupt and truncated ciphertexts must degrade to '' rather than warn or
// return half-decoded bytes.
assert_equals('', setting_decrypt('enc:!!!not-valid-base64!!!'), 'a non-base64 payload decrypts to an empty string');
assert_equals('', setting_decrypt('enc:' . base64_encode('too-short')), 'a truncated payload decrypts to an empty string');

// A blank secret must NEVER overwrite a stored one — this is the guard against
// a failed decrypt (which reads as '') destroying the real secret on save.
save_settings($db, ['radius_secret' => 'original-secret']);
save_settings($db, ['radius_secret' => '']);
assert_equals('original-secret', get_settings($db)['radius_secret'], 'saving a blank secret leaves the stored secret intact');

// A non-blank secret still replaces it.
save_settings($db, ['radius_secret' => 'replacement-secret']);
assert_equals('replacement-secret', get_settings($db)['radius_secret'], 'a non-blank secret replaces the stored one');

// Non-secret settings are still allowed to be blanked.
save_settings($db, ['event_logo_path' => '']);
assert_equals('', get_settings($db)['event_logo_path'], 'non-secret settings can still be cleared');

test_summary();
