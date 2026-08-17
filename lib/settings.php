<?php
const SETTINGS_DEFAULTS = [
    'event_name' => 'Edo Youth Impact Forum 2026',
    'event_tagline' => 'Empowered Youth, Transformed Future',
    'event_dates' => 'Tuesday 18th & Wednesday 19th August 2026',
    'event_venue' => 'Victor Uwaifo Creative Hub, Benin City, Edo State',
    // EYIF wordmark blue, darkened from the logo's #2088C8 so white button
    // text clears WCAG AA contrast (4.6:1 vs the logo blue's 3.9:1).
    'brand_color' => '#1B7BB8',
    'event_logo_path' => '',
    'powered_by_logo_path' => '',
    // --- RADIUS / Wi-Fi ---
    'radius_secret' => '',
    'radius_auth_port' => '1812',
    'radius_nas_ip' => '',
    // How long a code stays valid for, in minutes. 720 = 12 hours, enough to
    // cover one event day.
    'session_minutes' => '720',
    // Mikrotik rate-limit string (upload/download). Empty means uncapped.
    'rate_limit' => '',
];

/**
 * Settings whose values are encrypted at rest. APP_KEY lives in config.php,
 * never in the database, so a database leak alone does not expose them.
 */
const SETTINGS_SECRET_KEYS = ['radius_secret'];

function setting_encrypt(string $plain): string
{
    $key = hash('sha256', APP_KEY, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return 'enc:' . base64_encode($iv . $cipher);
}

function setting_decrypt(string $value): string
{
    // Values without the marker are legacy plaintext — return them untouched.
    if (strpos($value, 'enc:') !== 0) {
        return $value;
    }
    $raw = base64_decode(substr($value, 4), true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $key = hash('sha256', APP_KEY, true);
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function get_settings(mysqli $db): array
{
    $settings = SETTINGS_DEFAULTS;
    $result = $db->query('SELECT setting_key, setting_value FROM settings');
    while ($row = $result->fetch_assoc()) {
        if (!array_key_exists($row['setting_key'], $settings)) {
            continue;
        }
        $value = $row['setting_value'];
        if (in_array($row['setting_key'], SETTINGS_SECRET_KEYS, true)) {
            $value = setting_decrypt($value);
        }
        $settings[$row['setting_key']] = $value;
    }
    return $settings;
}

function save_settings(mysqli $db, array $settings): void
{
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach (SETTINGS_DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $value = (string) $settings[$key];
        if (in_array($key, SETTINGS_SECRET_KEYS, true)) {
            $value = setting_encrypt($value);
        }
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}
