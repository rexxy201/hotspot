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
