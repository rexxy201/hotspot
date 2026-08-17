<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';

$db = get_db();
$db->query('DELETE FROM settings');

$defaults = get_settings($db);
assert_equals('Edo Youth Impact Forum 2026', $defaults['event_name'], 'get_settings returns default event_name when table is empty');

save_settings($db, ['event_name' => 'Test Event', 'brand_color' => '#ff0000']);
$updated = get_settings($db);
assert_equals('Test Event', $updated['event_name'], 'save_settings persists event_name');
assert_equals('#ff0000', $updated['brand_color'], 'save_settings persists brand_color');
assert_equals('Empowered Youth, Transformed Future', $updated['event_tagline'], 'save_settings leaves untouched keys at default');

// Silent login defaults to on, and round-trips as a plain (unencrypted) value.
$fresh = get_settings($db);
assert_equals('1', $fresh['silent_login_enabled'], 'silent login is on by default');
save_settings($db, ['silent_login_enabled' => '0']);
assert_equals('0', get_settings($db)['silent_login_enabled'], 'silent login can be turned off');
save_settings($db, ['silent_login_enabled' => '1']);
assert_equals('1', get_settings($db)['silent_login_enabled'], 'silent login can be turned back on');

test_summary();
