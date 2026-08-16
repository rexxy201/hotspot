<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/uploads.php';
require_admin_session();

$db = get_db();
$settings = get_settings($db);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'event_name' => trim($_POST['event_name'] ?? ''),
        'event_tagline' => trim($_POST['event_tagline'] ?? ''),
        'event_dates' => trim($_POST['event_dates'] ?? ''),
        'event_venue' => trim($_POST['event_venue'] ?? ''),
        'brand_color' => trim($_POST['brand_color'] ?? ''),
    ];

    [$eventLogoOk, $eventLogoError, $eventLogoExt] = validate_logo_upload($_FILES['event_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    [$poweredByOk, $poweredByError, $poweredByExt] = validate_logo_upload($_FILES['powered_by_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

    if (!$eventLogoOk) {
        $error = $eventLogoError;
    } elseif (!$poweredByOk) {
        $error = $poweredByError;
    } else {
        $uploadsDir = __DIR__ . '/../uploads/logos';
        $uploadFailed = false;
        if ($eventLogoExt) {
            $eventLogoPath = store_logo_upload($_FILES['event_logo'], $eventLogoExt, $uploadsDir);
            if ($eventLogoPath === null) {
                $uploadFailed = true;
            } else {
                $newSettings['event_logo_path'] = $eventLogoPath;
            }
        }
        if (!$uploadFailed && $poweredByExt) {
            $poweredByLogoPath = store_logo_upload($_FILES['powered_by_logo'], $poweredByExt, $uploadsDir);
            if ($poweredByLogoPath === null) {
                $uploadFailed = true;
            } else {
                $newSettings['powered_by_logo_path'] = $poweredByLogoPath;
            }
        }

        if ($uploadFailed) {
            $error = 'Failed to save the uploaded logo. Please try again.';
        } else {
            save_settings($db, $newSettings);
            $settings = get_settings($db);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Branding Settings</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin">
  <div class="admin-header">
    <h1>Branding Settings</h1>
    <div class="admin-actions">
      <a class="btn-link secondary" href="index.php">Back to entries</a>
    </div>
  </div>

  <div class="card">
    <?php if ($error): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="settings-form">
      <div class="field">
        <label for="event_name">Event Name</label>
        <input type="text" id="event_name" name="event_name" value="<?= htmlspecialchars($settings['event_name']) ?>">
      </div>
      <div class="field">
        <label for="event_tagline">Tagline</label>
        <input type="text" id="event_tagline" name="event_tagline" value="<?= htmlspecialchars($settings['event_tagline']) ?>">
      </div>
      <div class="field">
        <label for="event_dates">Dates</label>
        <input type="text" id="event_dates" name="event_dates" value="<?= htmlspecialchars($settings['event_dates']) ?>">
      </div>
      <div class="field">
        <label for="event_venue">Venue</label>
        <input type="text" id="event_venue" name="event_venue" value="<?= htmlspecialchars($settings['event_venue']) ?>">
      </div>
      <div class="field">
        <label for="brand_color">Brand Color</label>
        <input type="color" id="brand_color" name="brand_color" value="<?= htmlspecialchars($settings['brand_color']) ?>">
        <p class="field-hint">Used for buttons and links. Pick a colour dark enough that white text stays readable on it.</p>
      </div>
      <div class="field">
        <label for="event_logo">Event Logo</label>
        <input type="file" id="event_logo" name="event_logo" accept="image/png,image/jpeg">
        <p class="field-hint">PNG or JPG, max 2MB. Leave empty to keep the current logo.</p>
      </div>
      <div class="field">
        <label for="powered_by_logo">Powered-By Logo</label>
        <input type="file" id="powered_by_logo" name="powered_by_logo" accept="image/png,image/jpeg">
        <p class="field-hint">PNG or JPG, max 2MB. Shown small, at the foot of the portal.</p>
      </div>
      <button type="submit">Save changes</button>
    </form>
  </div>
</div>
</body>
</html>
