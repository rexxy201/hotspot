<?php
/**
 * Single source of truth for "this portal's real hostname", used whenever
 * the app generates a router-facing file (the .rsc config, the login.html
 * bridge) that gets pasted or uploaded onto hardware outside this app's
 * control.
 *
 * Before this existed, both downloads derived the host from the CURRENT
 * request's Host header at download-time. That is client-supplied and can
 * be wrong in ways that matter here: an admin troubleshooting over the
 * server's bare IP, a www/non-www mismatch, a staging alias, or a domain
 * migration the admin hasn't happened to visit /admin/ under yet — any of
 * those silently bakes the wrong host into a file that then quietly fails
 * once it's on the router, with nothing here to say why.
 *
 * PORTAL_HOST (set once via setup.php's Network stage, same as
 * MIKROTIK_GATEWAY_HOST) fixes that at one place: change it there and
 * every future download — .rsc and login.html alike — picks it up
 * immediately, with no redeploy needed. Leaving it blank preserves the
 * old auto-detect-from-request behaviour for installs that haven't set it.
 */
function resolve_portal_host(): string
{
    $configured = trim((string) (defined('PORTAL_HOST') ? PORTAL_HOST : ''));
    if ($configured !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $configured) === 1) {
        return $configured;
    }
    // Fallback: the current request's Host header, same validation the
    // downloads always applied — a crafted Host header lands inside a
    // quoted RouterOS string or an HTML attribute, so anything but a
    // plain host[:port] is rejected outright rather than escaped.
    $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $rawHost) === 1 ? $rawHost : 'your-portal-domain';
}

/**
 * True when resolve_portal_host() is returning the admin-configured
 * PORTAL_HOST rather than guessing from the current request — lets the
 * admin UI tell the difference between "this is authoritative" and
 * "this is a best guess, go set PORTAL_HOST".
 */
function portal_host_is_configured(): bool
{
    $configured = trim((string) (defined('PORTAL_HOST') ? PORTAL_HOST : ''));
    return $configured !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $configured) === 1;
}
