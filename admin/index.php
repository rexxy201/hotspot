<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/credentials.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/layout.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);

$totalEntries = (int) $db->query('SELECT COUNT(*) AS c FROM entries')->fetch_assoc()['c'];
$entriesToday = (int) $db->query(
    'SELECT COUNT(*) AS c FROM entries WHERE DATE(created_at) = CURDATE()'
)->fetch_assoc()['c'];
$activeCredentials = count_active_credentials($db);
$dataServed = total_usage_bytes($db);

$recent = $db->query(
    'SELECT name, email, code, created_at FROM entries ORDER BY created_at DESC LIMIT 8'
);

admin_layout_start('index.php', 'Dashboard', $settings);
?>
<div class="page-header">
  <h1>Dashboard</h1>
  <p class="page-sub"><?= htmlspecialchars($settings['event_name']) ?></p>
</div>

<div class="stat-grid">
  <div class="stat">
    <span class="stat-label">Total entries</span>
    <span class="stat-value"><?= number_format($totalEntries) ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Entries today</span>
    <span class="stat-value"><?= number_format($entriesToday) ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Active Wi-Fi codes</span>
    <span class="stat-value"><?= number_format($activeCredentials) ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Data served</span>
    <span class="stat-value"><?= htmlspecialchars(format_bytes($dataServed)) ?></span>
  </div>
</div>

<section class="panel">
  <div class="panel-header">
    <h2>Recent entries</h2>
    <a class="btn-link secondary" href="entries.php">View all</a>
  </div>
  <?php if ($recent->num_rows === 0): ?>
    <p class="empty">No entries yet. They'll appear here as attendees connect to the Wi-Fi.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Email</th><th>Code</th><th>Submitted</th></tr>
        </thead>
        <tbody>
        <?php while ($row = $recent->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['email']) ?></td>
            <td class="code-cell"><?= htmlspecialchars($row['code']) ?></td>
            <td><?= htmlspecialchars($row['created_at']) ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
