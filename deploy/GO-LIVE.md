# EYIF 2026 — Go-Live Runbook

**Event:** Tuesday 18 & Wednesday 19 August 2026 · Victor Uwaifo Creative Hub, Benin City

Work top to bottom. Each phase ends with a check that proves it worked — if a
check fails, stop and fix it there. A later phase built on a broken earlier one
is how you end up debugging the router when the problem is a typo in a config
file.

Two things to have ready before you start:

- The **router's public IP** (the address the Mikrotik uses to reach the internet). The daemon refuses to start without it.
- **Real SMTP and Twilio credentials.** Without them attendees still get their code on screen, but not by email or SMS.

---

## Phase 1 — Server (30 min)

- [ ] Create an Ubuntu 22.04 droplet. Note its public IP as `<VPS-IP>`.
- [ ] `apt update && apt upgrade -y`
- [ ] Create a non-root sudo user; disable root SSH login.

```bash
apt install -y nginx mysql-server composer \
  php php-fpm php-mysqli php-curl php-sockets php-fileinfo php-mbstring
```

**Check — all five must print:**

```bash
php -m | grep -E '^(sockets|fileinfo|mysqli|openssl|curl)$'
```

> `sockets` missing is the one that bites. The RADIUS daemon exits immediately
> without it, and the admin diagnostics will tell you so in those words.

---

## Phase 2 — Database (10 min)

```bash
mysql -u root -e "CREATE DATABASE wifi_portal;"
mysql -u root -e "CREATE USER 'wifi_portal_user'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';"
mysql -u root -e "GRANT ALL PRIVILEGES ON wifi_portal.* TO 'wifi_portal_user'@'localhost';"
```

Clone to `/var/www/wifi-portal`, then:

```bash
cd /var/www/wifi-portal
mysql -u root wifi_portal < schema.sql
composer install --no-dev
mkdir -p logs uploads/logos
chown -R www-data:www-data logs uploads
```

**Check — three tables:**

```bash
mysql -u root wifi_portal -e "SHOW TABLES;"
```

Expect `entries`, `settings`, `wifi_credentials`.

> `logs/` must be writable by the web server or the admin's "Restart daemon"
> button and the log viewer both fail.

---

## Phase 3 — Secrets (15 min)

```bash
php -r "echo bin2hex(random_bytes(32)) . \"\n\";"   # APP_KEY
php hash_password.php "<a-long-random-admin-password>"
openssl rand -base64 24 | tr -d '\n'; echo          # RADIUS shared secret
```

Edit `config.php` (copy from `config.example.php`) and set:

| Key | Value |
|---|---|
| `DB_*` | the user from Phase 2 |
| `APP_KEY` | the 64-char hex above |
| `ADMIN_PASSWORD_HASH` | the printed bcrypt hash |
| `SMTP_*` | real mail credentials |
| `TWILIO_*` | real SMS credentials |
| `MIKROTIK_GATEWAY_HOST` | hotspot gateway IP, e.g. `10.5.50.1` |

Then `history -c` to clear the plaintext password from your shell history.

> **`APP_KEY` cannot be recovered.** It encrypts the RADIUS secret at rest and
> lives only in this file, never in the database. Lose it and you re-enter the
> RADIUS secret. The app refuses to start encrypting under the placeholder value,
> so a skipped step fails loudly rather than shipping guessable ciphertext.
>
> **The RADIUS secret must not contain spaces** — the admin form rejects them,
> because a space would truncate the router's config line and every login would
> fail with no visible cause. Strip the trailing newline from `openssl`.

**Check:**

```bash
php -r "require 'config.php'; echo strlen(APP_KEY) . \" char key\n\";"
```

Expect `64 char key`.

---

## Phase 4 — Web server (15 min)

Serve `/var/www/wifi-portal` with PHP-FPM. Two rules matter:

```nginx
location ~ ^/(config\.php|logs/|\.git|docs/|tests/|schema\.sql|composer\.|start_radius\.sh|hash_password\.php) {
    deny all;
}
```

Set `display_errors = Off` and `log_errors = On` in the production `php.ini`, so
an unexpected error never prints a stack trace to an attendee.

**Check:** the portal loads and `config.php` does not.

```bash
curl -s -o /dev/null -w "portal:%{http_code}\n" https://<your-domain>/index.php
curl -s -o /dev/null -w "config:%{http_code}\n" https://<your-domain>/config.php
```

Expect `portal:200` and `config:403`.

---

## Phase 5 — Admin settings (10 min)

Open `https://<your-domain>/admin/`, log in, go to **Wi-Fi & RADIUS**:

| Field | Value |
|---|---|
| Shared secret | the `openssl` value from Phase 3 |
| Authentication port | `1812` |
| **Router public IP** | **the router's public IP — required** |
| Session length | `720` (12 h — one event day) |
| Speed cap | `5M/5M`, or blank for uncapped |
| Reconnect known devices | leave ticked |

Save. Then **Branding Settings** → upload the EYIF and MangoNet logos, confirm
the event name, tagline and dates.

> **Router public IP is not optional.** The daemon refuses to start without it,
> deliberately: CHAP authentication does not involve the shared secret, so a
> daemon that accepts packets from anywhere is a code-guessing oracle for anyone
> on the SSID.

---

## Phase 6 — Start the daemon (10 min)

```bash
sudo cp deploy/mangonet-radius.service /etc/systemd/system/
sudo nano /etc/systemd/system/mangonet-radius.service   # check paths and User
sudo systemctl daemon-reload
sudo systemctl enable --now mangonet-radius
sudo systemctl status mangonet-radius
```

**Check:** reload **Wi-Fi & RADIUS**. The status panel should read *"The daemon
is UP and answering on UDP 1812."*

If it does not, the message names the exact fault — secret blank, `ext-sockets`
missing, process dead, or nothing bound. Fix that, don't guess.

---

## Phase 7 — Firewall (5 min)

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow from <ROUTER-PUBLIC-IP> to any port 1812 proto udp
ufw enable
```

RADIUS is deliberately **not** open to the internet — only to your router.

---

## Phase 8 — Mikrotik (20 min) — the fiddly one

First, find your two profile names. **They are different objects and usually have
different names.**

```
/ip hotspot profile print
/ip hotspot user profile print
```

The **server** profile is typically `hsprof1`; the **user** profile is typically
`default`. The generated config assumes exactly that.

- [ ] Admin → **Wi-Fi & RADIUS** → **Download router config**
- [ ] Open the `.rsc` and check the two profile names match yours. **Edit them if not.**
- [ ] Upload to the router and run `/import file=eyif-radius.rsc`
- [ ] Point the hotspot login page at `https://<your-domain>/index.php`
- [ ] Confirm the walled garden allows your portal host

> The `shared-users=1` line is what stops one code working on unlimited devices.
> It targets the **user** profile. If that name is wrong, the line silently does
> nothing and you will not find out until someone shares their code.

**Check:**

```
/radius print
/ip hotspot profile print
/ip hotspot user profile print
```

Confirm the RADIUS server points at `<VPS-IP>:1812`, `use-radius=yes` on the
server profile, and `shared-users=1` on the user profile.

---

## Phase 9 — Smoke test (20 min) — do this before doors open

Do this on a **real phone**, on the **event SSID**, with **Admin → RADIUS Log**
open on a laptop beside you.

- [ ] **1.** Connect the phone to the Wi-Fi. It should land on the EYIF portal, not Mikrotik's default login page.
      *Fails →* login page isn't pointed at the portal, or the walled garden blocks it.
- [ ] **2.** Submit real details. A code appears on screen.
      *Fails →* check `logs/radius.log` and the PHP error log.
- [ ] **3.** The code arrives by **email** and by **SMS**.
      *Fails →* SMTP/Twilio credentials. Attendees still get the code on screen, so this is not a stopper.
- [ ] **4.** The phone reaches the internet immediately.
      *Fails →* watch the RADIUS Log; see the table below.
- [ ] **5.** An `ACCEPT` line appears in the RADIUS Log with the seconds remaining.
- [ ] **6.** **Second device, same code → refused.** This is the one people skip.
      *Fails →* `shared-users=1` landed on the wrong profile. Phase 8.
- [ ] **7.** Turn the phone's Wi-Fi off and on. It should reconnect **without** the form.
      *Fails →* the router isn't sending `mac`. Check Raffle Entries: a blank device column means no MAC was recorded. Harmless — everyone just sees the form.
- [ ] **8.** Admin → **Raffle Entries** shows the entry, its device, and a **Revoke** button.
- [ ] **9.** **Download CSV** and open it. This is your prize-draw list.

---

## Phase 10 — Clear the decks (5 min)

Test entries must not be in the draw.

```bash
mysql -u root wifi_portal -e "SELECT COUNT(*) FROM entries;"     # look first
mysql -u root wifi_portal -e "DELETE FROM entries; DELETE FROM wifi_credentials;"
mysql -u root wifi_portal -e "SELECT COUNT(*) FROM entries;"     # expect 0
```

- [ ] Confirm `0` before doors open.

---

## During the event

| Need | Where |
|---|---|
| Is anyone connecting? | Admin → **RADIUS Log** (live, no SSH) |
| How many entries so far | Admin → **Dashboard** |
| Cut off an abused code | Admin → **Raffle Entries** → Revoke |
| Someone can't connect | RADIUS Log — the reason is almost always there |
| Run the draw | Raffle Entries → **Download CSV** |

### When something breaks

| What you see | What it means | Fix |
|---|---|---|
| `Ignored packet from x.x.x.x` | Router IP differs from the trusted one | RADIUS Log offers a one-click **Trust this IP** |
| `REJECT … unknown or expired` | Code expired, or was revoked | They re-submit the form; a new code is issued |
| `REJECT … wrong password` | Router and portal secrets differ | Re-download the `.rsc` and re-import |
| Nothing in the log at all | Router isn't reaching the daemon | Firewall (Phase 7), or `systemctl status mangonet-radius` |
| `no logs/radius.pid` | Daemon never started | `systemctl start mangonet-radius` |
| Daemon alive, nothing answers | Something else holds UDP 1812 | `ss -lunp \| grep 1812` |
| Portal shows a generic error | DB problem | PHP error log; the real cause is logged, not shown to attendees |
| One code works on many phones | `shared-users=1` on the wrong profile | Phase 8 |

### Panic switches

| Situation | Action |
|---|---|
| Silent reconnect misbehaving | Untick **Reconnect known devices** — everyone falls back to the form |
| Daemon wedged | Admin → RADIUS Log → **Restart daemon** (no SSH needed) |
| Wi-Fi too slow | Raise or clear the **Speed cap** |
| Sessions expiring too soon | Raise **Session length** — existing codes keep the length they were issued with |

---

## Known limits — so nobody is surprised on the day

- **Revoking is not instant.** It blocks the next re-authentication; the current session runs to the router's timeout.
- **A revoked attendee can re-register.** Filling the form again issues a fresh code. There is no permanent block.
- **Silent reconnect can be spoofed** by someone who already knows a device's MAC. They gain Wi-Fi that is free anyway and can consume that attendee's one session; they cannot claim the prize, which is drawn from the name/phone/email list.
- **No bandwidth quota yet.** The speed cap limits how fast, not how much.
- **Codes do not expire at midnight** — they expire `session_length` minutes after issue. A code issued at 4pm on day 1 is valid until 4am.
