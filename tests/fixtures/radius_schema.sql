CREATE TABLE IF NOT EXISTS wifi_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(64) NOT NULL,
  mac VARCHAR(20) DEFAULT NULL,
  rate_limit VARCHAR(60) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wifi_username (username),
  KEY idx_wifi_mac (mac),
  KEY idx_wifi_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS radius_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  username VARCHAR(64) NOT NULL,
  input_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY idx_session_user (username)
);
