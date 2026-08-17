<?php
/**
 * Shared admin chrome: sidebar navigation + main content wrapper.
 *
 * Every admin page calls admin_layout_start() after require_admin_session()
 * and admin_layout_end() at the end, so the navigation is defined once and
 * new sections only need adding to ADMIN_NAV below.
 */

const ADMIN_NAV = [
    ['file' => 'index.php',      'label' => 'Dashboard',        'icon' => 'grid'],
    ['file' => 'entries.php',    'label' => 'Raffle Entries',   'icon' => 'list'],
    ['file' => 'radius.php',     'label' => 'Wi-Fi & RADIUS',   'icon' => 'wifi'],
    ['file' => 'radius-log.php', 'label' => 'RADIUS Log',       'icon' => 'terminal'],
    ['file' => 'settings.php',   'label' => 'Branding Settings','icon' => 'sliders'],
];

function admin_nav_icon(string $name): string
{
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'list'    => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    ];
    $d = $paths[$name] ?? '';
    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

function admin_layout_start(string $activeFile, string $title, array $settings = []): void
{
    $eventName = $settings['event_name'] ?? 'Wi-Fi Portal';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Admin</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="sidebar-brand-name">MangoNet</span>
      <span class="sidebar-brand-sub"><?= htmlspecialchars($eventName) ?></span>
    </div>
    <nav class="sidebar-nav" aria-label="Admin sections">
      <?php foreach (ADMIN_NAV as $item): ?>
        <?php $isActive = $item['file'] === $activeFile; ?>
        <a class="nav-item<?= $isActive ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($item['file']) ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
          <?= admin_nav_icon($item['icon']) ?>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <div class="nav-divider"></div>
      <a class="nav-item" href="../index.php" target="_blank" rel="noopener">
        <?= admin_nav_icon('globe') ?><span>View portal</span>
      </a>
      <?php // Sign-out is separated from the section links so it is not hit by accident. ?>
      <a class="nav-item nav-item-signout" href="logout.php">
        <?= admin_nav_icon('logout') ?><span>Log out</span>
      </a>
    </nav>
  </aside>

  <main class="admin-main">
    <?php
}

function admin_layout_end(): void
{
    ?>
  </main>
</div>
</body>
</html>
    <?php
}
