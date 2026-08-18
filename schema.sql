CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  -- One of the 18 Edo State LGAs — see lib/edo_lga.php, the single source
  -- of truth both the form dropdown and connect.php's validation read from.
  lga VARCHAR(64) NOT NULL DEFAULT '',
  -- Free-text answer to the sign-up form's raffle/survey question ("What
  -- is the biggest technology problem Edo should solve?"). NULL, not '',
  -- for rows from before this column existed.
  tech_question TEXT NULL,
  code CHAR(8) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT
);

-- Wi-Fi credentials consumed by radius_server.php (our own UDP RADIUS daemon).
-- `username` and `password` both hold the attendee's 8-digit code. Deleting a
-- row revokes Wi-Fi access WITHOUT touching the attendee's `entries` row, which
-- remains their prize-draw entry.
CREATE TABLE IF NOT EXISTS wifi_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(64) NOT NULL,
  -- Reserved for Stage 2 (silent login keyed on device MAC).
  mac VARCHAR(20) DEFAULT NULL,
  rate_limit VARCHAR(60) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wifi_username (username),
  KEY idx_wifi_mac (mac),
  KEY idx_wifi_expires (expires_at)
);

-- One row per RADIUS accounting session.
--
-- The router reports ABSOLUTE counters for a session on every interim update,
-- not deltas, so we upsert on session_id and overwrite. A retransmitted or
-- duplicated packet is then harmless — it writes the same numbers again
-- instead of double-counting, and no delta arithmetic is needed anywhere.
--
-- Deleting rows here resets a code's usage. It never touches `entries`.
CREATE TABLE IF NOT EXISTS radius_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  username VARCHAR(64) NOT NULL,
  -- BIGINT: the 32-bit wire counters are combined with their gigawords
  -- companion before they get here, so these hold the true total.
  input_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY idx_session_user (username)
);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('event_name', 'Edo Youth Impact Forum 2026'),
  ('event_tagline', 'Empowered Youth, Transformed Future'),
  ('event_dates', 'Tuesday 18th & Wednesday 19th August 2026'),
  ('event_venue', 'Victor Uwaifo Creative Hub, Benin City, Edo State'),
  ('brand_color', '#1B7BB8'),
  ('event_logo_path', ''),
  ('powered_by_logo_path', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
