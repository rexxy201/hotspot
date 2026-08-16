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

test_summary();
