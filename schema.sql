CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  code CHAR(8) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT
);

-- Mirrors the radcheck table from FreeRADIUS's own bundled schema
-- (imported for real during production deployment — see deploy/setup.md).
-- Defined here so the app's own database has it for local dev without
-- requiring a full FreeRADIUS install.
CREATE TABLE IF NOT EXISTS radcheck (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '==',
  value VARCHAR(253) NOT NULL DEFAULT ''
);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('event_name', 'Edo Youth Impact Forum 2026'),
  ('event_tagline', 'Empowered Youth, Transformed Future'),
  ('event_dates', 'Tuesday 18th & Wednesday 19th August 2026'),
  ('event_venue', 'Victor Uwaifo Creative Hub, Benin City, Edo State'),
  ('brand_color', '#1a7a4c'),
  ('event_logo_path', ''),
  ('powered_by_logo_path', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
