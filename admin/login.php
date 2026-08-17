<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Root-level pages (setup.php, almightypush.php) send staff here with
// ?next=/that-page.php when they need an authenticated session. Only an
// exact match against this whitelist is ever honoured — never an arbitrary
// query string — so this can't become an open redirect.
$nextWhitelist = ['/setup.php' => '../setup.php', '/almightypush.php' => '../almightypush.php'];
$requestedNext = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
$next = $nextWhitelist[$requestedNext] ?? 'index.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['is_admin'] = true;
        header('Location: ' . $next);
        exit;
    }
    $error = 'Invalid password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="portal">
  <div class="portal-card login-card">
    <h1>Admin Login</h1>
    <p class="intro">Sign in to view raffle entries and event branding.</p>
    <form method="POST">
      <?php if ($error): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <?php if (isset($nextWhitelist[$_GET['next'] ?? ''])): ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($_GET['next']) ?>">
        <p class="hint" style="margin:0 0 var(--space-3)">Log in to continue.</p>
      <?php endif; ?>
      <div class="field">
        <label for="password">Admin password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
      </div>
      <button type="submit">Log in</button>
    </form>
  </div>
</div>
</body>
</html>
