<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/csv.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();

// Revoke a code's Wi-Fi access.
//
// This deletes ONLY the wifi_credentials row. The attendee's entries row is
// their prize-draw entry and must survive — cutting someone's Wi-Fi must never
// cost them the draw.
//
// Handled before any output so the redirect below can send headers. The
// redirect is a POST/redirect/GET: without it, refreshing the page after a
// revoke would silently re-submit it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    if (!csrf_verify()) {
        header('Location: entries.php?error=csrf');
        exit;
    }
    $code = trim((string) ($_POST['code'] ?? ''));
    // Codes are 8 numeric digits. revoke_credential() uses a prepared
    // statement, so this is not an injection guard — it stops a malformed
    // request deleting something that was never a code.
    if (preg_match('/^[0-9]{8}$/', $code) === 1) {
        revoke_credential($db, $code);
        header('Location: entries.php?revoked=' . urlencode($code));
    } else {
        header('Location: entries.php?error=badcode');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_usage') {
    if (!csrf_verify()) {
        header('Location: entries.php?error=csrf');
        exit;
    }
    $code = trim((string) ($_POST['code'] ?? ''));
    if (preg_match('/^[0-9]{8}$/', $code) === 1) {
        // Clears recorded usage only — the raffle entry and the credential are
        // both untouched, so this gives someone their full allowance back
        // without giving them a new code.
        reset_usage_for_code($db, $code);
        header('Location: entries.php?usagereset=' . urlencode($code));
    } else {
        header('Location: entries.php?error=badcode');
    }
    exit;
}

// `seconds_remaining` is computed by MySQL, never in PHP: this deployment runs
// PHP and MySQL in different timezones, so date arithmetic on expires_at here
// would be wrong by the offset.
$result = $db->query(
    'SELECT e.name, e.phone, e.email, e.lga, e.tech_question, e.code, e.created_at,
            c.expires_at,
            c.mac,
            TIMESTAMPDIFF(SECOND, NOW(), c.expires_at) AS seconds_remaining,
            COALESCE(u.used, 0) AS used_bytes
       FROM entries e
       LEFT JOIN wifi_credentials c ON c.username = e.code
       LEFT JOIN (
            SELECT username, SUM(input_octets + output_octets) AS used
              FROM radius_sessions GROUP BY username
       ) u ON u.username = e.code
      ORDER BY e.created_at DESC'
);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="entries.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Phone', 'Email', 'LGA', 'Biggest Tech Problem', 'Code', 'Submitted At'], ',', '"', '\\');
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            csv_safe_field($row['name']),
            csv_safe_field($row['phone']),
            csv_safe_field($row['email']),
            csv_safe_field($row['lga']),
            csv_safe_field((string) ($row['tech_question'] ?? '')),
            csv_safe_field($row['code']),
            $row['created_at'],
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

$notice = '';
$error = '';
$revoked = trim((string) ($_GET['revoked'] ?? ''));
if (preg_match('/^[0-9]{8}$/', $revoked) === 1) {
    $notice = "Wi-Fi access revoked for code {$revoked}. Their raffle entry is untouched.";
}
$usageReset = trim((string) ($_GET['usagereset'] ?? ''));
if (preg_match('/^[0-9]{8}$/', $usageReset) === 1) {
    $notice = "Data usage reset for code {$usageReset}. Their allowance starts again.";
}
if (($_GET['error'] ?? '') === 'badcode') {
    // Both the revoke and the reset_usage path redirect here, so the wording has
    // to be true of either — saying "nothing was revoked" after a failed reset
    // would describe an action the request never asked for.
    $error = 'That was not a valid code, so nothing was changed.';
}
if (($_GET['error'] ?? '') === 'csrf') {
    $error = 'That form had expired — nothing was changed. Please try again.';
}

/** How long is left on a credential, in words. */
function format_remaining(int $seconds): string
{
    if ($seconds >= 3600) {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return $minutes > 0 ? "{$hours}h {$minutes}m left" : "{$hours}h left";
    }
    return max(1, intdiv($seconds, 60)) . 'm left';
}

$settings = get_settings($db);
admin_layout_start('entries.php', 'Raffle Entries', $settings);
?>
<div class="page-header">
  <div>
    <h1>Raffle Entries</h1>
    <p class="page-sub"><?= (int) $result->num_rows ?> <?= $result->num_rows === 1 ? 'entry' : 'entries' ?> collected</p>
  </div>
  <div class="page-actions">
    <a class="btn-link" href="?export=csv">Download CSV</a>
  </div>
</div>

<?php if ($notice !== ''): ?><p class="warning"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

<section class="panel">
  <?php if ($result->num_rows === 0): ?>
    <p class="empty">No entries yet. They'll appear here as attendees connect to the Wi-Fi.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th><th>Phone</th><th>Email</th><th>LGA</th>
            <th>Biggest tech problem</th><th>Code</th>
            <th>Submitted</th><th>Wi-Fi</th>
            <th>Data</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
          <?php
            $remaining = $row['seconds_remaining'] === null ? null : (int) $row['seconds_remaining'];
            $isActive = $remaining !== null && $remaining > 0;
          ?>
          <tr>
            <td><?= htmlspecialchars($row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['lga'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td class="answer-cell" title="<?= htmlspecialchars((string) ($row['tech_question'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($row['tech_question'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td class="code-cell"><?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if ($isActive): ?>
                <span class="pill pill-active">Active</span>
                <span class="pill-note"><?= htmlspecialchars(format_remaining($remaining), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php elseif ($remaining !== null): ?>
                <span class="pill">Expired</span>
              <?php else: ?>
                <span class="pill">None</span>
              <?php endif; ?>
              <?php if (!empty($row['mac'])): ?>
                <span class="pill-note" title="Device bound for silent reconnect"><?= htmlspecialchars($row['mac'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
            </td>
            <td class="num-cell"><?= htmlspecialchars(format_bytes((int) $row['used_bytes']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if ($isActive): ?>
                <form method="post"
                      onsubmit="return confirm('Revoke Wi-Fi for code <?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?\n\nTheir raffle entry is kept. They can reconnect by filling in the portal form again.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="code" value="<?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <button type="submit" class="btn-inline btn-danger">Revoke</button>
                </form>
              <?php endif; ?>
              <?php if ((int) $row['used_bytes'] > 0): ?>
                <form method="post" style="margin-top:var(--space-1)"
                      onsubmit="return confirm('Reset data usage for code <?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?\n\nThey get their full allowance again. Their code and raffle entry do not change.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_usage">
                  <input type="hidden" name="code" value="<?= htmlspecialchars($row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <button type="submit" class="btn-inline secondary">Reset data</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <p class="table-note">
      Revoking deletes only the Wi-Fi credential — the raffle entry is always kept.
      The device drops at the router's session timeout rather than instantly, and the
      attendee can get a new code by filling in the portal form again.
    </p>
  <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
