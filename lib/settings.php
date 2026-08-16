<?php
const SETTINGS_DEFAULTS = [
    'event_name' => 'Edo Youth Impact Forum 2026',
    'event_tagline' => 'Empowered Youth, Transformed Future',
    'event_dates' => 'Tuesday 18th & Wednesday 19th August 2026',
    'event_venue' => 'Victor Uwaifo Creative Hub, Benin City, Edo State',
    'brand_color' => '#1a7a4c',
    'event_logo_path' => '',
    'powered_by_logo_path' => '',
];

function get_settings(mysqli $db): array {
    $settings = SETTINGS_DEFAULTS;
    $result = $db->query('SELECT setting_key, setting_value FROM settings');
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['setting_key'], $settings)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function save_settings(mysqli $db, array $settings): void {
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach (SETTINGS_DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $value = $settings[$key];
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}
