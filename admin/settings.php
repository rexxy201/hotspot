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
<head><meta charset="UTF-8"><title>Branding Settings</title><link rel="stylesheet" href="../assets/style.css"></head>
<body>
<h1>Branding Settings</h1>
<p><a href="index.php">Back to entries</a></p>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="POST" enctype="multipart/form-data">
  <label>Event Name <input type="text" name="event_name" value="<?= htmlspecialchars($settings['event_name']) ?>"></label>
  <label>Tagline <input type="text" name="event_tagline" value="<?= htmlspecialchars($settings['event_tagline']) ?>"></label>
  <label>Dates <input type="text" name="event_dates" value="<?= htmlspecialchars($settings['event_dates']) ?>"></label>
  <label>Venue <input type="text" name="event_venue" value="<?= htmlspecialchars($settings['event_venue']) ?>"></label>
  <label>Brand Color <input type="color" name="brand_color" value="<?= htmlspecialchars($settings['brand_color']) ?>"></label>
  <label>Event Logo (PNG/JPG, max 2MB) <input type="file" name="event_logo" accept="image/png,image/jpeg"></label>
  <label>Powered-By Logo (PNG/JPG, max 2MB) <input type="file" name="powered_by_logo" accept="image/png,image/jpeg"></label>
  <button type="submit">Save</button>
</form>
</body>
</html>
