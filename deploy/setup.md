# VPS Deployment Guide — EYIF 2026 Wi-Fi Portal + RADIUS

Target: a single Ubuntu 22.04 droplet (DigitalOcean or equivalent), 1GB RAM is enough
for a one-off event.

## 1. Provision the VPS

- Create an Ubuntu 22.04 droplet. Note its public IP (`<VPS-IP>`).
- SSH in, apply updates: `apt update && apt upgrade -y`
- Create a non-root sudo user and disable root SSH login (standard hardening).

## 2. Install MySQL and create the app database

```bash
apt install -y mysql-server
mysql -u root -e "CREATE DATABASE wifi_portal;"
mysql -u root -e "CREATE USER 'wifi_portal_user'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'wifi_portal_user'@'localhost';"
```

Clone this repo to `/var/www/wifi-portal`, then:

```bash
mysql -u root wifi_portal < schema.sql
```

## 3. Install FreeRADIUS with MySQL support

```bash
apt install -y freeradius freeradius-mysql
```

FreeRADIUS uses the SAME `wifi_portal` database as the PHP app (not a separate
`radius` database) — `connect.php` inserts into `radcheck` right alongside
`entries`, using the app's single DB connection, so both must live in one
place. `schema.sql` already creates a minimal `radcheck` table; importing
FreeRADIUS's own bundled schema on top of it is still worthwhile because it
also creates `radreply`, `radacct`, and `nas`, and its `radcheck` definition
uses `CREATE TABLE IF NOT EXISTS`, so it will safely no-op on the table that
already exists rather than conflict with it:

```bash
mysql -u root -e "CREATE USER 'radius'@'localhost' IDENTIFIED BY '<RADIUS-DB-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'radius'@'localhost';"
mysql -u root wifi_portal < /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql
```

(A dedicated `radius` MySQL user is created here so FreeRADIUS has its own
credentials, but it's granted against the `wifi_portal` database — the
database name itself must not be `radius`.)

Edit `/etc/freeradius/3.0/mods-available/sql` with the values in
`deploy/freeradius/sql.conf.snippet`, then enable the module and the inner-tunnel
reference:

```bash
ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
```

In `/etc/freeradius/3.0/sites-available/default` and `sites-available/inner-tunnel`,
uncomment the `sql` line in both the `authorize` and `accounting` sections.

## 4. Register the Mikrotik as a RADIUS client

Append `deploy/freeradius/clients.conf.snippet` (filled in with the Mikrotik's real
public IP and a freshly generated long random secret) to
`/etc/freeradius/3.0/clients.conf`.

Generate a secret:

```bash
openssl rand -base64 24
```

Restart FreeRADIUS:

```bash
systemctl restart freeradius
systemctl enable freeradius
```

## 5. Smoke-test FreeRADIUS before touching the Mikrotik

Insert a throwaway test user directly, isolate RADIUS problems from router problems:

```bash
mysql -u root wifi_portal -e "INSERT INTO radcheck (username, attribute, op, value) VALUES ('12345678', 'Cleartext-Password', ':=', '12345678');"
radtest 12345678 12345678 localhost 0 <SHARED-SECRET-FROM-CLIENTS.CONF>
```

Expected: `Received Access-Accept`. Then:

```bash
radtest wrongcode wrongcode localhost 0 <SHARED-SECRET-FROM-CLIENTS.CONF>
```

Expected: `Received Access-Reject`. Remove the throwaway row:

```bash
mysql -u root wifi_portal -e "DELETE FROM radcheck WHERE username = '12345678';"
```

## 6. Session expiry (end of each event day)

Add a `radreply` row per new user setting `Session-Timeout` to the number of
seconds remaining until that day's cutoff. Since this depends on wall-clock time at
signup, `lib/radius.php`'s `radius_add_user()` (in the app repo) computes this value
at insert time — confirm the event's daily cutoff time with the event team before
the event and set it in `config.php` if a `SESSION_CUTOFF_HOUR` constant is added, or
adjust `radius_add_user()` directly if the app repo doesn't yet compute it. (If this
step wasn't wired into `connect.php` before deployment, sessions simply won't expire
automatically — cut off Wi-Fi manually at the end of each day instead, which is an
acceptable fallback for a one-time event.)

## 7. Install PHP and a web server for the portal

```bash
apt install -y php php-mysqli php-curl nginx composer
```

Verify the `fileinfo` extension is enabled — `lib/uploads.php`'s
`mime_content_type()` needs it, and logo uploads will silently fail
validation without it:

```bash
php -m | grep fileinfo
```

In the production `php.ini` / PHP-FPM pool config, set `display_errors = Off`
and `log_errors = On` so an unhandled exception (even after the try/catch in
`connect.php`) never leaks a stack trace to attendees' browsers.

Point nginx at the repo's root (`/var/www/wifi-portal`) with PHP-FPM configured
normally. Copy `config.example.php` to `config.php`, fill in the real DB, SMTP, and
Twilio credentials, then generate the admin password hash:

```bash
cd /var/www/wifi-portal
composer install --no-dev
php hash_password.php "<a-long-random-admin-password>"
```

Paste the printed hash into `config.php`'s `ADMIN_PASSWORD_HASH`, then delete any
shell history containing the plaintext password.

## 8. Firewall

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow from <MIKROTIK-PUBLIC-IP> to any port 1812,1813 proto udp
ufw enable
```

## 9. Mikrotik-side configuration (run on the router itself, e.g. via Winbox/SSH)

```
/radius add service=hotspot address=<VPS-IP> secret=<SHARED-SECRET> \
    authentication-port=1812 accounting-port=1813
/ip hotspot profile set [find] use-radius=yes

# Allow attendees to reach the portal before they're authenticated:
/ip hotspot walled-garden add dst-host=<your-domain-or-VPS-IP> action=allow
```

In the hotspot server profile's HTML login page settings, point the login page at
`http://<your-domain>/index.php`, so Mikrotik's redirect (with its `mac`, `ip`,
`link-login-only`, `link-orig` query parameters) lands on this app instead of
Mikrotik's built-in login form.

## 10. End-to-end check

1. Connect a test device to the event Wi-Fi.
2. Confirm it's redirected to the portal page (not Mikrotik's default login page).
3. Submit the form with real test contact info.
4. Confirm the code arrives by email and SMS.
5. Confirm the device is online immediately after submitting, without seeing
   Mikrotik's own login screen.
6. From another device, try to use the same code — confirm it's rejected
   (`Simultaneous-Use := 1`) while the first device stays connected.
