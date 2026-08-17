<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

$logFile = dirname(__DIR__) . '/logs/radius.log';
$restartFlag = dirname(__DIR__) . '/logs/radius.restart';

/** The last $maxLines lines of a file, without reading the whole thing. */
function tail_log(string $path, int $maxLines = 200): string
{
    if (!is_file($path)) {
        return '';
    }
    $size = filesize($path);
    // 64KB comfortably covers 200 lines of this log format.
    $readFrom = max(0, $size - 65536);
    $chunk = (string) @file_get_contents($path, false, null, $readFrom);
    $lines = explode("\n", trim($chunk));
    return implode("\n", array_slice($lines, -$maxLines));
}

// Plain-text endpoint the page polls, so the log refreshes without a reload.
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    // The log holds usernames and MAC addresses that arrived over the network
    // from unauthenticated devices. The daemon sanitises them, but defence in
    // depth: nosniff stops a browser being talked into content-sniffing this
    // response as HTML and executing anything embedded in a log line.
    header('X-Content-Type-Options: nosniff');
    echo tail_log($logFile);
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restart') {
        // The daemon watches for this flag, deletes it, and exits; systemd (or
        // start_radius.sh) brings it back. Lets an admin restart without shell
        // access.
        if (@file_put_contents($restartFlag, (string) time()) !== false) {
            $notice = 'Restart requested. The daemon exits within a second and its supervisor restarts it.';
        } else {
            $error = 'Could not write ' . $restartFlag . ' — the logs/ directory is not writable by the web server.';
        }
    }

    if ($action === 'trust_ip') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $error = 'That is not a valid IP address.';
        } else {
            save_settings($db, ['radius_nas_ip' => $ip]);
            $settings = get_settings($db);
            $notice = "Now trusting RADIUS packets from {$ip}. The daemon picks this up within 10 seconds.";
        }
    }

    if ($action === 'clear') {
        // Never create the file here. The daemon runs as a different user, so a
        // file created by the web server could leave the daemon unable to write
        // and silently kill all logging.
        if (!is_file($logFile)) {
            $error = 'There is no log file to clear.';
        } elseif (@file_put_contents($logFile, '') !== false) {
            $notice = 'Log cleared.';
        } else {
            $error = 'Could not clear the log file.';
        }
    }
}

$log = tail_log($logFile);

// Surface any source IP the daemon rejected, so a one-click "trust this" is
// possible when the router's public IP is not what was configured.
// Anchored to a whole daemon-emitted line, NOT a substring match. The log
// contains RADIUS usernames copied from unauthenticated devices, so an
// attendee can put the text "Ignored packet from <their ip>" inside a
// username field. Matching mid-line would let that forged text drive the
// one-click Trust button below and add an attacker's host to the daemon's
// allowlist. The timestamp prefix, and the trailing space before the
// literal "(trusted router is ...)" the daemon always appends, mean only a
// line the daemon itself wrote can match.
$suggestIp = '';
if (preg_match_all('/^\[[^\]]+\] Ignored packet from (\d{1,3}(?:\.\d{1,3}){3}) \(trusted router is /m', $log, $m)) {
    $candidate = end($m[1]);
    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
        $suggestIp = $candidate;
    }
}

admin_layout_start('radius-log.php', 'RADIUS Log', $settings);
?>
<div class="page-header">
  <div>
    <h1>RADIUS Log</h1>
    <p class="page-sub">Live authentication log from the daemon — no SSH needed.</p>
  </div>
  <div class="page-actions">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="restart">
      <button type="submit" class="btn-inline">Restart daemon</button>
    </form>
    <form method="POST" style="display:inline"
          onsubmit="return confirm('Clear the log file? This cannot be undone.')">
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn-inline secondary">Clear log</button>
    </form>
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

<?php if ($suggestIp !== '' && $suggestIp !== $settings['radius_nas_ip']): ?>
  <section class="panel" style="margin-bottom:var(--space-4)">
    <div class="panel-header"><h2>Unrecognised router</h2></div>
    <p class="page-sub" style="margin-bottom:var(--space-3)">
      The daemon is ignoring packets from <strong><?= htmlspecialchars($suggestIp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>,
      which is not the trusted router IP. If that is your router, trust it:
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="trust_ip">
      <input type="hidden" name="ip" value="<?= htmlspecialchars($suggestIp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <button type="submit" class="btn-inline">Trust <?= htmlspecialchars($suggestIp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <div class="panel-header"><h2>Last 200 lines</h2></div>
  <pre id="log" class="log-view"><?= htmlspecialchars($log !== '' ? $log : 'No log yet. Start the daemon with: bash start_radius.sh start', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
</section>

<script>
// Poll the raw endpoint so the log stays current while staff watch it during
// the event. Pinned to the bottom unless the reader has scrolled up.
setInterval(async function () {
  try {
    const res = await fetch('?raw=1', { cache: 'no-store' });
    // A redirect to the login page would otherwise be rendered as log content.
    const type = res.headers.get('content-type') || '';
    if (!res.ok || !type.startsWith('text/plain')) { return; }
    const text = await res.text();
    const el = document.getElementById('log');
    const pinned = el.scrollTop + el.clientHeight >= el.scrollHeight - 20;
    el.textContent = text || 'No log yet.';
    if (pinned) { el.scrollTop = el.scrollHeight; }
  } catch (e) { /* transient fetch failure — keep the last content */ }
}, 3000);
document.getElementById('log').scrollTop = document.getElementById('log').scrollHeight;
</script>
<?php admin_layout_end(); ?>
