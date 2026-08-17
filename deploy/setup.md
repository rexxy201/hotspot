# VPS Deployment Guide — EYIF 2026 Wi-Fi Portal

Target: one Ubuntu 22.04 droplet. 1GB RAM is plenty for a single event.

There is **no FreeRADIUS to install**. The portal ships its own RADIUS daemon
(`radius_server.php`), which reads the same database as the web app.

## 1. Provision the server

- Create an Ubuntu 22.04 droplet and note its public IP (`<VPS-IP>`).
- `apt update && apt upgrade -y`
- Create a non-root sudo user and disable root SSH login.

## 2. Install PHP, MySQL and nginx

```bash
apt install -y nginx mysql-server composer \
  php php-fpm php-mysqli php-curl php-sockets php-fileinfo php-mbstring openssl
```

`php-sockets` is required — the RADIUS daemon cannot run without it. `openssl`
is required too: it is what `setting_encrypt()` uses to encrypt the RADIUS
shared secret at rest. Confirm:

```bash
php -m | grep -E 'sockets|fileinfo|mysqli|openssl'
```

All four must be listed.

## 3. Create the database

```bash
mysql -u root -e "CREATE DATABASE wifi_portal;"
mysql -u root -e "CREATE USER 'wifi_portal_user'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'wifi_portal_user'@'localhost';"
```

Clone the repo to `/var/www/wifi-portal`, then load the schema:

```bash
cd /var/www/wifi-portal
mysql -u root wifi_portal < schema.sql
composer install --no-dev
```

## 4. Configure the app

```bash
cp config.example.php config.php
php -r "echo bin2hex(random_bytes(32)) . \"\n\";"   # APP_KEY
php hash_password.php "<a-long-random-admin-password>"
```

Edit `config.php` and set:

- `DB_*` to the database user created above
- `APP_KEY` to the 64-character hex string just generated
- `ADMIN_PASSWORD_HASH` to the printed bcrypt hash
- `SMTP_*` and `TWILIO_*` to your real provider credentials
- `MIKROTIK_GATEWAY_HOST` to the hotspot gateway IP (e.g. `10.5.50.1`)

**`APP_KEY` is unrecoverable if lost.** It is the key that encrypts the RADIUS
shared secret at rest. It lives *only* in `config.php` — it is never stored in
the database, so a database backup alone will not bring it back. If the file is
lost, or if the CLI and the web server somehow run with different values, the
stored secret cannot be decrypted and you must re-enter it in the admin UI.
Back `config.php` up somewhere safe and keep it out of git (it already is).
The app will also refuse to encrypt anything while `APP_KEY` is still the
committed placeholder (`change-me-to-a-64-char-random-hex-string`) or empty —
saving RADIUS settings throws until you set a real one.

Then clear the shell history that contains the plaintext admin password:

```bash
history -c
```

Set permissions so the web server can write uploads and the daemon can write logs:

```bash
mkdir -p logs uploads/logos
chown -R www-data:www-data logs uploads
```

## 5. Point nginx at the app

Serve `/var/www/wifi-portal` with PHP-FPM as usual. Two rules matter:

```nginx
# Never serve the config or the logs.
location ~ ^/(config\.php|logs/) { deny all; }
```

## 6. Configure RADIUS in the admin UI

Open `https://<your-domain>/admin/`, log in, then go to **Wi-Fi & RADIUS**:

- **Shared secret** — generate with `openssl rand -base64 24`. Must match the router.
  Strip the trailing newline before pasting it: the secret is written into a
  RouterOS config line, so spaces, tabs, line breaks and other control
  characters are rejected by the form outright.
- **Authentication port** — `1812`
- **Router public IP** — **required.** The router's public IP. The daemon
  ignores packets from anywhere else, and it *refuses to start* while this is
  blank. That is deliberate: CHAP authentication does not involve the shared
  secret at all, so any device that can reach the daemon's UDP port could
  brute-force attendee codes and get a definitive Accept/Reject without knowing
  the secret.
- **Session length** — minutes a code stays valid. `720` = 12 hours, one event day.
- **Speed cap** — e.g. `5M/5M`, or blank for uncapped.

Save.

## 7. Start the daemon

```bash
sudo cp deploy/mangonet-radius.service /etc/systemd/system/
sudo nano /etc/systemd/system/mangonet-radius.service   # check paths and User
sudo systemctl daemon-reload
sudo systemctl enable --now mangonet-radius
sudo systemctl status mangonet-radius
```

**Do not repoint the unit's output at `logs/radius.log`.** The daemon opens,
writes and rotates that file itself at 8MB. A supervisor appending to the same
path would double every line, and after a rotation it would keep writing to the
renamed inode — silently defeating the size cap, so a packet flood from any
device on the event SSID could fill the disk and take MySQL down with it. That
is why the unit ships with `StandardOutput=journal` (read it with
`journalctl -u mangonet-radius`) and why `start_radius.sh` writes startup
failures to a separate `logs/radius-startup.log`. Leave both as they are.

Now open the **Wi-Fi & RADIUS** page — the daemon status is checked each time it
loads. It should report the daemon is up and answering. If not, the message
names the exact fault.

Without systemd, use the wrapper instead:

```bash
bash start_radius.sh start
```

## 8. Firewall

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow from <ROUTER-PUBLIC-IP> to any port 1812,1813 proto udp
ufw enable
```

RADIUS is deliberately **not** open to the internet — only to the router.

## 9. Configure the Mikrotik

On the **Wi-Fi & RADIUS** page click **Download router config**. It produces a
`.rsc` with your secret, server IP, port and portal host already filled in.

**Confirm BOTH profile names before importing.** The config touches two
different RouterOS objects in two different namespaces, and they have two
different default names:

```
/ip hotspot profile print        # server profile — the `use-radius` line, default "hsprof1"
/ip hotspot user profile print   # user profile   — the `shared-users` line, default "default"
```

If your **user** profile is not named `default`, edit that line in the `.rsc`
before importing. `[find name=...]` matching nothing makes RouterOS `set`
silently do nothing, so the one-device-per-code limit would simply not apply
and you would get no error saying so.

Upload it to the router and run:

```
/import file=eyif-radius.rsc
```

The generated config includes
`/ip hotspot user profile set [find name=default] shared-users=1`. That line is
what stops one code being used on several devices at once — it replaces the
`Simultaneous-Use := 1` check the removed FreeRADIUS setup provided, and the
daemon cannot enforce it itself until RADIUS accounting lands in a later stage.
If it is not set, one code works on unlimited devices simultaneously.

Then point the hotspot login page at the portal so Mikrotik's redirect
(carrying `mac`, `ip`, `link-login-only`, `link-orig`) lands on `index.php`.

## 10. End-to-end check

1. Connect a test phone to the event Wi-Fi.
2. Confirm it lands on the portal, not Mikrotik's default login page.
3. Submit the form with real contact details.
4. Confirm the code arrives by email and SMS.
5. Confirm the device reaches the internet immediately.
6. Watch **Admin → RADIUS Log** — an `ACCEPT` line should appear with the
   seconds remaining.
7. Confirm the speed cap applies (run a speed test if you set one).
8. **Try the same code on a SECOND device while the first is still connected.**
   It must be refused. That is the only proof `shared-users=1` actually took
   effect — if the second device gets online, the `set` matched no user profile
   (see the profile-name check above) and one leaked code will work on
   unlimited devices for the whole event.

## Troubleshooting

Everything is visible from the browser — **Admin → RADIUS Log** shows the live
daemon log, and **Wi-Fi & RADIUS** diagnoses connectivity.

| Symptom | Cause |
|---|---|
| "no logs/radius.pid" | Daemon never started — `systemctl start mangonet-radius` |
| "Ignored packet from x.x.x.x" | Router IP differs from the trusted IP. The log page offers a one-click "Trust this IP". |
| `REJECT: unknown or expired` | The code expired (past its session length) or was revoked. |
| `REJECT: wrong password` | Router and portal shared secrets differ. |
| Daemon alive, nothing answers | Another process holds UDP 1812 — `ss -lunp \| grep 1812` |
| Admin → RADIUS Log shows nothing | The daemon has not started, or `logs/` is not writable by the web server user — check `bash start_radius.sh status` and the directory ownership set in the permissions step. |
