<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/csv.php';
require_admin_session();

$db = get_db();
$result = $db->query('SELECT name, phone, email, code, created_at FROM entries ORDER BY created_at DESC');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="entries.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Phone', 'Email', 'Code', 'Submitted At'], ',', '"', '\\');
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            csv_safe_field($row['name']),
            csv_safe_field($row['phone']),
            csv_safe_field($row['email']),
            csv_safe_field($row['code']),
            $row['created_at'],
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Raffle Entries</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin">
  <div class="admin-header">
    <h1>Raffle Entries</h1>
    <div class="admin-actions">
      <a class="btn-link secondary" href="settings.php">Branding Settings</a>
      <a class="btn-link" href="?export=csv">Download CSV</a>
    </div>
  </div>

  <div class="card">
    <p class="count"><?= (int) $result->num_rows ?> <?= $result->num_rows === 1 ? 'entry' : 'entries' ?> so far</p>
    <?php if ($result->num_rows === 0): ?>
      <p class="empty">No entries yet. They'll appear here as attendees connect to the Wi-Fi.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Phone</th><th>Email</th><th>Code</th><th>Submitted</th></tr>
          </thead>
          <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['name']) ?></td>
              <td><?= htmlspecialchars($row['phone']) ?></td>
              <td><?= htmlspecialchars($row['email']) ?></td>
              <td class="code-cell"><?= htmlspecialchars($row['code']) ?></td>
              <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
