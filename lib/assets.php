<?php
/**
 * Cache-busting for static assets (assets/style.css, and any other
 * versioned file). Deploys land instantly server-side, but browsers cache
 * assets/style.css for a week (Cache-Control: max-age=604800, set by the
 * host) — without a version query string on the <link>, a returning
 * visitor keeps their OLD stylesheet for up to 7 days after every CSS
 * change, silently rendering with stale rules. Confirmed by hand: a fresh
 * curl always got the new file, but a browser tab that had loaded the page
 * before a deploy kept serving the cached CSS until the URL itself changed.
 *
 * Appending the file's own mtime as ?v= means every deploy that touches
 * the file changes its URL, which busts the cache immediately — no need
 * to lower max-age or coordinate a version number by hand.
 */
function asset_url(string $projectRoot, string $relativePath): string
{
    $absolute = rtrim($projectRoot, '/') . '/' . ltrim($relativePath, '/');
    $version = @filemtime($absolute);
    // Root-relative (leading "/"), NOT relative-to-current-page. A bare
    // "assets/style.css" resolves fine from pages living at the site root
    // (index.php, connect.php, setup.php) but silently 404s from anything
    // one directory deeper (admin/login.php, admin/layout.php resolve it
    // to /admin/assets/style.css) — confirmed live: the admin pages were
    // rendering completely unstyled because of exactly this. A leading
    // "/" makes the URL resolve to the same place regardless of which
    // directory the including script lives in.
    return '/' . ltrim($relativePath, '/') . '?v=' . ($version !== false ? $version : '1');
}
