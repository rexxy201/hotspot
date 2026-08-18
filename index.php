<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/credentials.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/edo_lga.php';

$db = get_db();
$settings = get_settings($db);

// Preserve Mikrotik's hotspot redirect parameters across the form submission
// so connect.php can hand the attendee back to Mikrotik's own login-only URL.
$mikrotikParams = [
    // Cast: a crafted ?mac[]= would otherwise reach normalize_mac(string) and
    // raise a TypeError, blanking the portal for that visitor.
    'mac' => (string) ($_GET['mac'] ?? ''),
    'ip' => (string) ($_GET['ip'] ?? ''),
    'link-login-only' => (string) ($_GET['link-login-only'] ?? ''),
    'link-orig' => (string) ($_GET['link-orig'] ?? ''),
];

// --- Silent login ---------------------------------------------------------
// If this device already holds a valid credential, connect it straight through
// instead of asking for details it has already given. This is the phone that
// dropped off the Wi-Fi and came back, not a new attendee.
//
// The MAC arrives in the query string, so it is client-supplied and unverifiable
// from here (the portal sits behind the router's NAT). Two rules contain that:
//   1. We only ever REUSE a still-valid credential. A forged MAC cannot create
//      or renew one, so at worst it rides a session that already exists.
//   2. The code is not RENDERED as visible text here, but it is unavoidably
//      present in the hidden fields below. To log a browser into the router the
//      credential has to pass through that browser, so anyone who can craft this
//      request can read the code from the page source. Hiding it visually is
//      cosmetic, not a control. The residual risk is therefore: someone who
//      already knows a device's MAC can learn that attendee's code. That buys
//      Wi-Fi which is free anyway, and does not let them claim the prize (the
//      draw is run from the admin's name/phone/email list, not from the code) —
//      but it does let them consume the victim's single allowed session.
//      Closing it properly needs a device credential separate from the raffle
//      code; see the Stage 2 plan's note.
$silentCode = '';
$silentLoginUrl = '';

// Populated by Mikrotik itself (not by this app) when a login attempt it
// just processed was rejected and it has sent the device back to the
// hotspot login page to try again — see deploy/mikrotik-login.html. A real
// reject usually means an expired/revoked code, a RADIUS secret mismatch,
// or the daemon being down, none of which a retry fixes on its own; the
// attendee deserves to see that rather than an unexplained loop.
$mikrotikError = trim((string) ($_GET['error'] ?? ''));

// An explicit "not you?" click always wins — a borrowed or handed-on phone must
// be able to reach the form.
$forget = ($_GET['forget'] ?? '') === '1';

// A pending error means the LAST auto-submit (silent or otherwise) just
// failed at the router. Retrying the same auto-submit would only recreate
// the same failure — and since Mikrotik sends the device back through
// login.html on every reject, that failure mode is a real infinite loop,
// not a hypothetical one. Force the manual form instead so the attendee
// (or, per session on a shared laptop) isn't stuck watching "Reconnecting…"
// bounce forever.
if (!$forget && $mikrotikError === '' && $settings['silent_login_enabled'] === '1' && $mikrotikParams['mac'] !== '') {
    $known = find_valid_credential_by_mac($db, $mikrotikParams['mac']);
    if ($known !== null) {
        // Same validation the success page applies: only auto-post to the
        // configured gateway, never to a host named in the query string.
        $candidate = $mikrotikParams['link-login-only'];
        $isGateway = filter_var($candidate, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($candidate, PHP_URL_SCHEME), ['http', 'https'], true)
            && parse_url($candidate, PHP_URL_HOST) === MIKROTIK_GATEWAY_HOST;
        if ($isGateway) {
            $silentCode = (string) $known['username'];
            $silentLoginUrl = $candidate;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($settings['event_name']) ?> Wi-Fi</title>
<link rel="stylesheet" href="<?= asset_url(__DIR__, 'assets/style.css') ?>">
<style>:root { --brand-color: <?= htmlspecialchars($settings['brand_color']) ?>; }</style>
</head>
<body>
<?php // Ambient glow + film-grain, fixed behind everything. Pure canvas/CSS —
      // no external image or library — so it still renders before the
      // attendee has internet access. See the grain script at the bottom. ?>
<div class="portal-bg" aria-hidden="true"><canvas id="grain-canvas" class="grain"></canvas></div>
<div class="portal">
  <?php if ($silentLoginUrl !== ''): ?>
  <div class="portal-card">
      <?php if ($settings['event_logo_path']): ?>
        <img class="logo" src="<?= htmlspecialchars($settings['event_logo_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?> logo">
      <?php endif; ?>
      <h1>Welcome back</h1>
      <p class="intro">Reconnecting you to <?= htmlspecialchars($settings['event_name']) ?> Wi-Fi…</p>
      <?php // Not rendered as visible text, but present in the hidden fields
            // below — it has to be, to reach the router. See the note above. ?>
      <form id="silent-login" method="POST" action="<?= htmlspecialchars($silentLoginUrl) ?>">
        <input type="hidden" name="username" value="<?= htmlspecialchars($silentCode) ?>">
        <input type="hidden" name="password" value="<?= htmlspecialchars($silentCode) ?>">
        <button type="submit">Continue</button>
      </form>
      <?php // Submits immediately: a reconnect that pauses defeats the purpose.
            // This means the "Not you?" link below is only reachable when
            // JavaScript is disabled or blocked. For the handoff case, staff
            // hand out the ?forget=1 URL — see deploy/setup.md. ?>
      <script>document.getElementById('silent-login').submit();</script>
      <?php // Carry the router's parameters through, or the form this reaches
            // cannot complete a login (no link-login-only means no auto-post),
            // and the borrower's submission would not rebind the device. ?>
      <p class="hint"><a href="index.php?<?= htmlspecialchars(http_build_query(['forget' => '1'] + $mikrotikParams), ENT_QUOTES) ?>">Not you? Sign in with your own details</a></p>
  </div>
  <?php else: ?>
  <?php // $(error) from Mikrotik itself — see the $mikrotikError comment
        // above. Shown once, right where the attendee lands after a
        // rejected login, before anything else on the page. ?>
  <?php if ($mikrotikError !== ''): ?>
    <p class="error" role="alert" style="max-width:420px;margin-left:auto;margin-right:auto;">
      Wi-Fi login didn't go through (<?= htmlspecialchars($mikrotikError) ?>). Please try again below.
    </p>
  <?php endif; ?>
  <?php // Single designed banner (logo + partner/sponsor logos + headline)
        // — admin-uploadable via Branding Settings, so nothing here is
        // fixed to one event's artwork. Falls back to the bundled EYIF 2.0
        // banner (see SETTINGS_DEFAULTS) until an admin replaces it. ?>
  <?php if ($settings['hero_banner_path']): ?>
    <img class="hero-banner" src="<?= htmlspecialchars($settings['hero_banner_path']) ?>" alt="<?= htmlspecialchars($settings['event_name']) ?>">
  <?php endif; ?>

  <hr class="hero-rule">

  <h2 class="hero-welcome">Welcome to <span class="hero-welcome-accent">EYIF <span class="hero-welcome-accent2">2.0</span>!</span></h2>

  <div class="hero-divider">
    <span class="divider-line"></span><span class="divider-dot"></span><span class="divider-line"></span>
  </div>
      <p class="hero-copy">Connect to the WIFI and stand a chance<br>to win amazing prizes.</p>

  <div class="hero-divider">
    <span class="divider-line"></span>
    <svg class="divider-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>
    </svg>
    <span class="divider-line"></span>
  </div>


  <?php // Button sits in normal document flow now — there's real content
        // both above and below it (the page scrolls), so it no longer
        // needs to flex-grow into the leftover space the way it did when
        // the banner + button were the only things on the page. ?>
  <div class="hero-cta">
    <button type="button" id="open-connect-modal" class="btn-connect-win"><?= htmlspecialchars($settings['cta_button_text']) ?></button>
  </div>

  <div class="hero-divider">
    <span class="divider-line"></span>
    <svg class="divider-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8"/><path d="M16.5 8a2.5 2.5 0 0 0 0-5C13 3 12 8 12 8"/>
    </svg>
    <span class="divider-line"></span>
  </div>

  <p class="hero-shout">CONNECT - ENGAGE - WIN</p>

  <div class="hero-divider">
    <span class="divider-line"></span>
    <svg class="divider-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3a2 2 0 0 1 2 2 4 4 0 0 1-4 4"/><path d="M7 5H4a2 2 0 0 0-2 2 4 4 0 0 0 4 4"/>
    </svg>
    <span class="divider-line"></span>
  </div>
  <?php // The sign-up form lives here, hidden until "Connect to Win" opens it.
        // Submitting still posts straight to connect.php — the success/
        // "you're connected" flow after that is unchanged. ?>
  <div class="modal-overlay" id="connect-modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="connect-modal-title">
      <button type="button" class="modal-close" id="close-connect-modal" aria-label="Close">&times;</button>
      <h2 id="connect-modal-title" class="visually-hidden"><?= htmlspecialchars($settings['event_name']) ?></h2>
      <p class="tagline"><?= htmlspecialchars($settings['event_tagline']) ?></p>
      <p class="details"><?= htmlspecialchars($settings['event_dates']) ?></p>

      <form method="POST" action="connect.php" id="connect-form">
        <div class="field">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" autocomplete="name" required>
        </div>
        <div class="field">
          <label for="phone">Phone Number</label>
          <input type="tel" id="phone" name="phone" autocomplete="tel" inputmode="tel" required>
        </div>
        <div class="field">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" autocomplete="email" inputmode="email" required>
        </div>
        <div class="field">
          <label for="lga">LGA</label>
          <select id="lga" name="lga" required>
            <option value="" disabled selected>Select your LGA</option>
            <?php foreach (EDO_LGAS as $lgaOption): ?>
              <option value="<?= htmlspecialchars($lgaOption) ?>"><?= htmlspecialchars($lgaOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tech_question">What is the biggest technology problem Edo should solve?</label>
          <textarea id="tech_question" name="tech_question" rows="3" maxlength="1000" required></textarea>
        </div>
        <?php foreach ($mikrotikParams as $key => $value): ?>
          <input type="hidden" name="mikrotik_<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
        <?php endforeach; ?>
        <button type="submit" id="connect-submit">Connect to Wi-Fi</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php // Always shown now (the mockup this matches has it as a fixed
        // footer line): falls back to plain text until an admin uploads
        // an actual logo via Branding Settings, then swaps to the image
        // automatically. ?>
  <p class="powered-by">Powered by
    <?php if ($settings['powered_by_logo_path']): ?>
      <img src="<?= htmlspecialchars($settings['powered_by_logo_path']) ?>" alt="<?= htmlspecialchars(COMPANY_NAME) ?>">
    <?php else: ?>
      <strong><?= htmlspecialchars(COMPANY_NAME) ?></strong>
    <?php endif; ?>
  </p>
</div>
<script>
(function () {
  // Film-grain background texture. Deliberately cheap: a small canvas
  // (180x180) stretched over the viewport with pixelated rendering, redrawn
  // a few times a second rather than every frame, paused while the tab is
  // hidden, and skipped entirely for prefers-reduced-motion — this renders
  // on whatever phone an attendee showed up with, before they have Wi-Fi,
  // so it can't be the thing that makes the portal feel slow.
  var canvas = document.getElementById('grain-canvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d', { alpha: true });
  if (!ctx) return;

  var SIZE = 180;
  canvas.width = SIZE;
  canvas.height = SIZE;

  var draw = function () {
    var image = ctx.createImageData(SIZE, SIZE);
    var data = image.data;
    for (var i = 0; i < data.length; i += 4) {
      var value = Math.random() * 255;
      data[i] = value;
      data[i + 1] = value;
      data[i + 2] = value;
      data[i + 3] = 20; // low alpha — a texture, not TV static
    }
    ctx.putImageData(image, 0, 0);
  };

  draw();

  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var frame = 0;
    var raf = 0;
    var loop = function () {
      if (frame % 4 === 0) draw();
      frame++;
      raf = window.requestAnimationFrame(loop);
    };
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        window.cancelAnimationFrame(raf);
      } else {
        raf = window.requestAnimationFrame(loop);
      }
    });
    raf = window.requestAnimationFrame(loop);
  }
})();

(function () {
  // "Magnetize" particles around the Connect to Win button: a dozen dots
  // scattered around it that snap in on hover/touch, spring back out on
  // release. Purely decorative (pointer-events: none), so it can't get in
  // the way of the actual tap.
  var btn = document.getElementById('open-connect-modal');
  if (!btn) return;
  var COUNT = 12;
  var RADIUS = 46;
  for (var i = 0; i < COUNT; i++) {
    var particle = document.createElement('span');
    particle.className = 'magnet-particle';
    var angle = Math.random() * Math.PI * 2;
    var dist = RADIUS * (0.5 + Math.random() * 0.5);
    particle.style.setProperty('--px', (Math.cos(angle) * dist).toFixed(1) + 'px');
    particle.style.setProperty('--py', (Math.sin(angle) * dist).toFixed(1) + 'px');
    btn.appendChild(particle);
  }
  var attract = function () { btn.classList.add('is-attracting'); };
  var release = function () { btn.classList.remove('is-attracting'); };
  btn.addEventListener('mouseenter', attract);
  btn.addEventListener('mouseleave', release);
  btn.addEventListener('touchstart', attract, { passive: true });
  btn.addEventListener('touchend', release);
  btn.addEventListener('touchcancel', release);
})();

(function () {
  var openBtn = document.getElementById('open-connect-modal');
  var modal = document.getElementById('connect-modal');
  var closeBtn = document.getElementById('close-connect-modal');
  if (!openBtn || !modal) return;

  var openModal = function () {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var firstField = modal.querySelector('input');
    if (firstField) firstField.focus();
  };
  var closeModal = function () {
    modal.hidden = true;
    document.body.style.overflow = '';
    openBtn.focus();
  };

  openBtn.addEventListener('click', openModal);
  closeBtn?.addEventListener('click', closeModal);
  // Clicking the dimmed backdrop (not the card itself) closes it too.
  modal.addEventListener('click', function (event) {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
})();

// Give immediate feedback on submit: issuing a code involves email + SMS
// delivery, so the response is not instant and an unchanged button invites
// double-taps (which the duplicate-entry handling absorbs, but which look
// broken to the attendee).
// The reconnect path renders no sign-up form, so this element is absent there.
document.getElementById('connect-form')?.addEventListener('submit', function () {
  var btn = document.getElementById('connect-submit');
  btn.setAttribute('aria-busy', 'true');
  btn.textContent = 'Connecting…';
});
</script>
</body>
</html>
