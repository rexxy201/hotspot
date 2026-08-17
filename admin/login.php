<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// setup.php sends attendees here with ?next=/setup.php when it needs a
// re-run authenticated. Only that one exact value is ever honoured — never
// an arbitrary query string — so this can't become an open redirect.
$next = (($_POST['next'] ?? $_GET['next'] ?? '') === '/setup.php') ? '../setup.php' : 'index.php';

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
      <?php if (($_GET['next'] ?? '') === '/setup.php'): ?>
        <input type="hidden" name="next" value="/setup.php">
        <p class="hint" style="margin:0 0 var(--space-3)">Log in to continue re-running setup.</p>
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
