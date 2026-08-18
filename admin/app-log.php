<?php
/**
 * Admin-readable view of logs/app.log — see lib/app_log.php. Mirrors
 * radius-log.php's tail/poll/clear pattern so the two logs behave the
 * same way from an admin's point of view; this one just covers everything
 * that ISN'T the RADIUS daemon (mail/SMS delivery failures, an uncaught
 * exception anywhere in the app) — previously only reachable via SSH into
 * a root-owned system log.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/log_view.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

$logFile = dirname(__DIR__) . '/logs/app.log';

// Plain-text endpoint the page polls, so the log refreshes without a reload.
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    // The log can hold values submitted by unauthenticated visitors (e.g. an
    // exception message built from form input). nosniff stops a browser
    // being talked into content-sniffing this response as HTML.
    header('X-Content-Type-Options: nosniff');
    echo tail_log($logFile);
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $error = 'That form had expired — nothing was changed. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    if (!is_file($logFile)) {
        $error = 'There is no log file to clear.';
    } elseif (@file_put_contents($logFile, '') !== false) {
        $notice = 'Log cleared.';
    } else {
        $error = 'Could not clear the log file.';
    }
}

$log = tail_log($logFile);

admin_layout_start('app-log.php', 'Error Log', $settings);
?>
<div class="page-header">
  <div>
    <h1>Error Log</h1>
    <p class="page-sub">Mail/SMS delivery failures and uncaught application errors — no SSH needed. RADIUS-specific issues are on the separate RADIUS Log page.</p>
  </div>
  <div class="page-actions">
    <form method="POST" style="display:inline"
          onsubmit="return confirm('Clear the log file? This cannot be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn-inline secondary">Clear log</button>
    </form>
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

<section class="panel">
  <div class="panel-header"><h2>Last 200 lines</h2></div>
  <pre id="log" class="log-view"><?= htmlspecialchars($log !== '' ? $log : 'No errors logged yet.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
</section>

<script>
// Poll the raw endpoint so the log stays current while staff watch it during
// the event. Pinned to the bottom unless the reader has scrolled up.
setInterval(async function () {
  try {
    const res = await fetch('?raw=1', { cache: 'no-store' });
    const type = res.headers.get('content-type') || '';
    if (!res.ok || !type.startsWith('text/plain')) { return; }
    const text = await res.text();
    const el = document.getElementById('log');
    const pinned = el.scrollTop + el.clientHeight >= el.scrollHeight - 20;
    el.textContent = text || 'No errors logged yet.';
    if (pinned) { el.scrollTop = el.scrollHeight; }
  } catch (e) { /* transient fetch failure — keep the last content */ }
}, 3000);
document.getElementById('log').scrollTop = document.getElementById('log').scrollHeight;
</script>
<?php admin_layout_end(); ?>
