# Wi-Fi Hotspot Captive Portal — Design

Date: 2026-08-16

## Purpose

A captive portal splash page for the Wi-Fi hotspot at the **Edo Youth Impact Forum
(EYIF) 2026** — Tuesday 18th & Wednesday 19th August 2026, Victor Uwaifo Creative Hub,
Benin City, Edo State. Attendees connect to the Wi-Fi, land on this page, and must
submit their name, phone number, and email to "connect." On submission they receive a
unique 8-digit code by email and SMS. The same code is recorded server-side so event
staff can use it later to run a raffle draw among attendees. The hotspot is provided by
MangoNet, credited as "Powered by MangoNet" on the page.

## Branding & event content

The portal page shows:
- Event logo (EYIF) and the "Powered by MangoNet" logo/wordmark.
- Event name/heading, short tagline, dates, and venue (defaults: "Edo Youth Impact
  Forum 2026", "Empowered Youth, Transformed Future", "Tuesday 18th & Wednesday 19th
  August 2026", "Victor Uwaifo Creative Hub, Benin City, Edo State").
- A brand accent color applied to buttons/highlights (default: EYIF's brand color).

All of the above are **editable from the admin settings page**, not hardcoded — so the
same portal can be re-branded for a different event/sponsor later without touching code.
This is the same admin area used for viewing raffle entries, gated by the same login.

## Scope

- Single one-time event. **Full RADIUS integration (confirmed):** the 8-digit code is
  both the raffle entry code AND the attendee's actual Wi-Fi login credential. Mikrotik
  authenticates hotspot users against a FreeRADIUS server backed by the same MySQL
  database this app uses.
- Because FreeRADIUS needs a persistent daemon, root access, and open UDP ports (1812
  auth / 1813 accounting), this can't run on shared/cPanel hosting — **everything is
  deployed on a single VPS** (e.g. DigitalOcean/Linode/Vultr, a $5-6/mo droplet is
  sufficient for one event): FreeRADIUS, MySQL, and the PHP portal all on one box,
  so RADIUS and the DB talk over localhost.
- This build covers: the portal page, data capture, code generation/delivery, the
  FreeRADIUS configuration (schema, NAS client entry, attribute mapping) that makes the
  code a working Wi-Fi credential, and an auto-login step that submits the code straight
  to Mikrotik's hotspot login endpoint so the attendee never sees Mikrotik's own login
  form. Physically configuring the Mikrotik router itself (adding the RADIUS client,
  pointing the hotspot profile at the VPS) is done by whoever manages the router,
  following the "Mikrotik integration notes" below — I can't reach or configure your
  physical router from here.
- Not building: multi-event/session support, user accounts for attendees, live-updating
  dashboard, automated "pick a winner" feature (admin can do the draw manually from the
  exported list), a captive-portal UI for expiring/revoking individual codes mid-event
  (see Open questions).

## Architecture

Two services on one VPS:

- **FreeRADIUS**, configured with its `rlm_sql` module pointed at MySQL, using
  FreeRADIUS's standard SQL schema (`radcheck`, `radreply`, `radacct`, `nas`) — not
  reinvented, just the schema FreeRADIUS ships with, so upgrades/tooling behave
  normally. `connect.php` inserts into `radcheck` (and optionally `radreply`) right
  alongside inserting into `entries`, using the same DB connection.
- **PHP portal** (plain PHP, vanilla HTML/CSS/JS, no framework) — the attendee-facing
  form, code generation, delivery, admin pages, and the FreeRADIUS-feeding inserts.

```
/
├── index.php            Attendee-facing portal form (reads branding from DB settings)
├── connect.php          Handles form submission; writes entries + radcheck row
├── db.php               MySQL connection (mysqli, prepared statements)
├── config.php           DB credentials, admin password, SMTP + Twilio credentials
├── config.example.php   Template for config.php (committed; config.php is gitignored)
├── schema.sql           entries + settings tables (radius schema imported separately —
│                          see Deployment notes)
├── lib/
│   ├── mailer.php        PHPMailer wrapper — send code by email
│   ├── sms.php           Twilio HTTP API wrapper (cURL) — send code by SMS
│   ├── settings.php      Load/save branding settings (with sane defaults)
│   └── radius.php        Insert/remove a radcheck row for a given code
├── admin/
│   ├── login.php          Password form → sets session on success
│   ├── index.php          Password-gated entries table + CSV export
│   └── settings.php       Password-gated branding form (logos, text, color)
├── assets/
│   └── style.css          Base styles; brand color is injected as a CSS variable
├── uploads/
│   └── logos/             Uploaded logo images (event logo, powered-by logo)
└── deploy/
    ├── freeradius/         Config file templates: sites-available/hotspot, mods-available/sql,
    │                        clients.conf snippet — copied into place during setup, not executed by PHP
    └── setup.md             Step-by-step VPS provisioning + FreeRADIUS install/config guide
```

### Data model

Single MySQL table:

```sql
CREATE TABLE entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  code CHAR(8) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

`phone` and `email` are both unique — either one matching an existing row counts as a
duplicate.

A second table holds branding settings as key/value pairs, so new fields can be added
later without a schema change:

```sql
CREATE TABLE settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT
);
```

Keys used: `event_name`, `event_tagline`, `event_dates`, `event_venue`,
`brand_color`, `event_logo_path`, `powered_by_logo_path`. `schema.sql` seeds these with
the EYIF 2026 defaults from the Branding section above, so the portal works out of the
box before anyone touches admin settings. `lib/settings.php` provides `get_settings()`
(returns all keys with defaults merged in) and `save_settings()`.

### RADIUS schema and credential mapping

FreeRADIUS's standard `rlm_sql` schema (imported as-is from FreeRADIUS's own
`schema.sql` during setup — see Deployment notes) provides:

- `radcheck` — per-user auth attributes. For each new entry, `lib/radius.php` inserts:
  `(username = code, attribute = 'Cleartext-Password', op = ':=', value = code)` and
  `(username = code, attribute = 'Simultaneous-Use', op = ':=', value = '1')`. **The
  code is both the RADIUS username and password** — so it's a single credential the
  attendee only has to enter once (see Flow below for how it's submitted without them
  typing it twice).
- `radreply` — optional per-user reply attributes; used to set `Session-Timeout` (e.g.
  end-of-day cutoff) so access doesn't outlive the event, if you want that (see Open
  questions).
- `nas` — the Mikrotik router registered as a RADIUS client, with its IP/hostname and a
  shared secret. This one row is added manually during VPS setup (`deploy/setup.md`),
  not by the PHP app.
- `radacct` — accounting records (session start/stop, data used) populated
  automatically by FreeRADIUS when Mikrotik sends accounting packets; not written to by
  the PHP app, but available in the DB if the admin wants to query who's currently
  online (`radacct WHERE acctstoptime IS NULL`).

When an attendee resubmits with an existing email/phone, `connect.php` does **not**
touch `radcheck` again — the row already exists from their first submission, so their
same code keeps working.

### Flow

1. Attendee connects to the MangoNet Wi-Fi; Mikrotik's hotspot intercepts the first
   HTTP request and redirects to `index.php`, passing its standard hotspot query
   parameters (`mac`, `ip`, `link-login-only`, `link-orig`, etc. — see Mikrotik
   integration notes). `index.php` captures and preserves these (hidden form fields) so
   they survive the round trip to `connect.php`. Page shows branding loaded from
   `settings` plus the form: Name, Phone, Email, "Connect" button. Client-side
   validation for required fields and basic email/phone format.
2. On submit, `connect.php`:
   - Validates and sanitizes input server-side (never trust client-side checks alone).
   - Checks whether `email` or `phone` already exists in `entries`.
     - **If found:** do not create a new entry or a new `radcheck` row. Look up their
       existing code and re-send/display it (same delivery path as a new signup, so
       they always leave the page having received their code).
     - **If not found:** generate a random 8-digit numeric code (`00000000`–`99999999`,
       zero-padded), check it doesn't already exist (retry on collision), insert the
       new row into `entries`, and insert the matching `radcheck` row via
       `lib/radius.php` so the code becomes valid as a RADIUS credential immediately.
   - Sends the code by email (PHPMailer via SMTP) and by SMS (Twilio HTTP API).
   - Renders a success screen showing the code and confirming delivery, which
     **auto-submits a hidden form** to Mikrotik's `link-login-only` URL (captured in
     step 1) with `username` and `password` both set to the code. This logs the
     attendee into the RADIUS-backed hotspot without them ever seeing Mikrotik's own
     login page or typing the code a second time. A visible "Continue to internet"
     button is also shown in case auto-submit is blocked (e.g. JS disabled).
3. Admin visits `admin/login.php`, enters the shared admin password (stored hashed in
   `config.php`, checked with `password_verify`). On success, a PHP session is set and
   they're redirected to `admin/index.php`.
4. `admin/index.php` lists all entries (name, phone, email, code, timestamp) in a table,
   with a "Download CSV" button that streams the same data as `entries.csv`. Every
   request to this page re-checks the session; expired/missing session redirects to
   login.
5. `admin/settings.php` (linked from `admin/index.php`, same session gate) shows a form
   for the two logo uploads, event name/tagline/dates/venue text fields, and a brand
   color picker, pre-filled with current `settings` values. Saving validates/resizes
   uploaded images, stores them under `uploads/logos/`, and updates the `settings` table.

### Mikrotik integration notes

This app doesn't configure the router — that's done directly on the Mikrotik device by
whoever manages it — but here's exactly what needs to be set up there. `deploy/setup.md`
will include this as a checklist with literal RouterOS commands.

**RADIUS client:**
```
/radius add service=hotspot address=<VPS-IP> secret=<shared-secret> \
    authentication-port=1812 accounting-port=1813
/ip hotspot profile set [find] use-radius=yes
```
The `<shared-secret>` must match the `nas` table entry on the VPS exactly, and should be
long/random — this secret authenticates the *router* to RADIUS, separate from the
per-attendee code.

**Login page redirect:** in the hotspot server profile's HTML login page settings,
replace Mikrotik's built-in `login.html` with one that immediately redirects (or is
itself just a redirect stub) to this app's `index.php`, passing along Mikrotik's
variables (`$(mac)`, `$(ip)`, `$(link-login-only)`, `$(link-orig)`) as query parameters
— `index.php` needs these to build the auto-submit form back to Mikrotik after
signup (see Flow step 2).

**Walled garden:** while unauthenticated, attendees must still be able to reach this
app's domain (DNS + HTTP/HTTPS) to load the form and submit it — add the VPS's
domain/IP to the hotspot's walled-garden allow list so it isn't itself blocked pre-auth:
```
/ip hotspot walled-garden add dst-host=<your-domain> action=allow
```

**Network reachability:** the Mikrotik needs a working internet uplink so it can reach
the VPS both for the walled-garden HTTP traffic and for RADIUS UDP traffic (ports
1812/1813) — confirm this before the event. On the VPS side, the firewall should only
accept RADIUS traffic from the Mikrotik's public IP (see Security notes).

**Session length:** by default RADIUS sessions don't expire until the router or
FreeRADIUS says so — decide with the event team whether attendees should be able to
stay connected past the event's end time (see Open questions on `Session-Timeout`).

### Deployment notes (VPS setup, one-time)

`deploy/setup.md` walks through this in order; summarized here for the design record:

1. Provision a small VPS (1GB RAM is plenty), Ubuntu/Debian.
2. Install MySQL, create the app database, run `schema.sql` (entries + settings).
3. Install FreeRADIUS + `freeradius-mysql`; import FreeRADIUS's own bundled SQL schema
   (`radcheck`/`radreply`/`radacct`/`nas`) into the same or a separate database; point
   `mods-available/sql` at it; enable the `sql` module in `sites-available/default` and
   `inner-tunnel`.
4. Add the Mikrotik as a `nas` row (IP + shared secret) — matches the
   `/radius add ...` command run on the router.
5. Configure the firewall: allow HTTP/HTTPS (for the portal), UDP 1812/1813 only from
   the Mikrotik's IP, SSH from the admin's IP only.
6. Install PHP + a web server (nginx/Apache) for the portal, point it at this repo,
   copy `config.example.php` to `config.php` and fill in real credentials, run
   `hash_password.php` once to set the admin password, then delete it.
7. Smoke-test FreeRADIUS locally with `radtest <code> <code> localhost 0 <secret>`
   before involving the Mikrotik at all — isolates RADIUS problems from router-config
   problems.

### Error handling

- Duplicate email/phone → handled as "resend existing code," not an error shown to the
  user (see above) — from the attendee's perspective it just works.
- Email or SMS delivery failure: the entry is still saved (attendee isn't blocked from
  "connecting" by a third-party outage); the success page notes if a channel failed and
  still shows the code on-screen as a fallback. Failures are logged server-side
  (`error_log`) for the admin to notice.
- DB connection or insert failure: show a generic "something went wrong, please try
  again" message; log the real error server-side, never expose DB errors to the
  attendee.
- Admin login: generic "invalid password" message on failure, no distinction that would
  help an attacker; basic rate limiting is out of scope for a single-event tool but the
  password should be long/random since this page will be internet-reachable.

### Security notes

- All DB queries use prepared statements (mysqli) — no string-concatenated SQL.
- `config.php` (real credentials) is gitignored; `config.example.php` is committed as a
  template.
- Admin password stored as a bcrypt hash (`password_hash`/`password_verify`), not
  plaintext, even though it's a single shared password.
- Form inputs are HTML-escaped on output (admin table, success page) to prevent stored
  XSS via name field.
- CSV export escapes fields properly to avoid CSV injection.
- Logo uploads in `admin/settings.php` are restricted to image MIME types/extensions
  (PNG/JPG/SVG rejected unless sanitized — SVG can carry script content, so either strip
  it or disallow SVG uploads), size-capped, renamed to a generated filename (never the
  original filename) and stored outside of any web-executable path assumptions — served
  as static files only, never `include`d or executed.
- **RADIUS-specific:** the VPS firewall (`ufw`/`iptables`) allows UDP 1812/1813 only
  from the Mikrotik's known public IP, not the open internet. The RADIUS shared secret
  (`nas` table / `clients.conf`) is long and random, generated during setup, never
  reused from anything else.
- **Code-as-password trade-off (accepted, flagging explicitly):** an 8-digit numeric
  code is a much weaker credential than a typical Wi-Fi password (10^8 possibilities,
  and it's shown in plaintext to the attendee and stored in plaintext in `radcheck`
  since RADIUS `Cleartext-Password` needs the value to check against, not a hash). This
  is an accepted trade-off for a single-event guest network, not a corporate/production
  Wi-Fi — `Simultaneous-Use := 1` limits each code to one active session, which mitigates
  casual sharing/guessing turning into multiple free riders, and FreeRADIUS's default
  behavior throttles repeated failed auth attempts somewhat. If this were a
  higher-stakes deployment, a longer/alphanumeric code or MAC-based auth would be
  worth reconsidering — noted here so it's a conscious choice, not an oversight.

## Testing

- Manual test plan (no PHP test framework needed for a project this size):
  1. Submit form with valid data → confirm entry appears in DB, code is 8 digits,
     email arrives, SMS arrives, success page shows the code.
  2. Resubmit with the same email (different phone) → confirm no duplicate row is
     created, existing code is re-sent/shown.
  3. Submit with missing/invalid fields → confirm client and server validation both
     reject it with a clear message.
  4. Admin login with wrong password → rejected. Correct password → see entries table,
     download CSV, confirm contents match DB.
  5. Simulate email/SMS provider failure (bad API key) → confirm entry still saves and
     success page still shows the code with a note that delivery failed.
  6. In admin settings, upload new logos, change event text and brand color, save →
     confirm `index.php` reflects the changes immediately (name, tagline, dates, venue,
     both logos, accent color). Upload a non-image file → confirm it's rejected.
  7. After a form submission, run `radtest <code> <code> localhost 0 <secret>` on the
     VPS → confirm `Access-Accept`. Confirm a `radcheck` row exists with the code as
     both username and Cleartext-Password.
  8. With the Mikrotik configured per the integration notes: connect a test device to
     the Wi-Fi, submit the form, confirm the device is auto-logged-in and gets internet
     access without seeing Mikrotik's own login page. Confirm a second simultaneous
     login attempt with the same code from a different device is rejected
     (`Simultaneous-Use := 1`).
  9. Submit an invalid/expired code directly against RADIUS (`radtest` with a
     made-up code) → confirm `Access-Reject`.

## Decisions (confirmed)

1. **Email sending:** PHPMailer over SMTP. `config.php` holds SMTP host/port/username/
   password (e.g. Gmail app password or the hosting provider's mail server) — you supply
   these after picking a mailbox to send from.
2. **Admin password:** left as a placeholder in `config.example.php` with clear
   instructions; you set a real bcrypt-hashed password in `config.php` yourself before
   going live. A small `hash_password.php` helper script (run once from the CLI/browser,
   then deleted) will be included to generate the hash from a plaintext password you
   choose.
3. **Code format:** 8 digits, numeric only, e.g. `04829371`.
4. **RADIUS integration:** confirmed — full FreeRADIUS setup, code doubles as the
   RADIUS Wi-Fi credential, everything deployed on a single VPS (recommended default,
   since you didn't specify existing infrastructure — flag now if you already have a
   VPS or provider preference).
5. **VPS provider:** no existing VPS specified, so `deploy/setup.md` will write exact
   provisioning steps for a DigitalOcean Ubuntu droplet as the default path — you create
   the account/droplet, then either follow the guide yourself or share SSH access.
   Swappable for any other provider since the steps are standard Ubuntu/apt commands.
6. **Session-Timeout:** defaulting to expiring access at the end of each event day
   (RADIUS `Session-Timeout` computed relative to a configurable daily cutoff), so
   attendees reconnect with the same code on day two rather than staying on
   indefinitely after the event ends.
7. **Mikrotik router config:** `deploy/setup.md`'s Mikrotik section is written as a
   precise, literal handoff checklist (exact RouterOS commands) so it works whether you
   apply it yourself or hand it to whoever manages the hotspot hardware on-site.
