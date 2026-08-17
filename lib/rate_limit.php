<?php
/**
 * Simple file-based rate limiter — no Redis/cache needed for this app's
 * traffic. One JSON file per purpose under logs/ (writable, gitignored,
 * excluded from the deploy rsync — exactly the runtime-state directory
 * this belongs in), keyed by client IP.
 */

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function rate_limit_path(string $purpose): string
{
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($purpose));
    return dirname(__DIR__) . '/logs/ratelimit_' . $safe . '.json';
}

function rate_limit_read(string $purpose): array
{
    $path = rate_limit_path($purpose);
    if (!is_file($path)) {
        return [];
    }
    $json = @file_get_contents($path);
    return $json !== false ? (json_decode($json, true) ?: []) : [];
}

function rate_limit_write(string $purpose, array $data): void
{
    @file_put_contents(rate_limit_path($purpose), json_encode($data), LOCK_EX);
}

/** True if $identifier is still allowed to attempt (has not hit the cap). */
function rate_limit_check(string $purpose, string $identifier, int $maxAttempts, int $windowSeconds): bool
{
    $data = rate_limit_read($purpose);
    $now = time();
    $recent = array_filter($data[$identifier] ?? [], fn($ts) => $ts > $now - $windowSeconds);
    return count($recent) < $maxAttempts;
}

/** Record a failed attempt. Call only on failure — success should reset instead. */
function rate_limit_record(string $purpose, string $identifier): void
{
    $data = rate_limit_read($purpose);
    $now = time();
    // Keep at most the last hour regardless of window, so the file can't
    // grow unbounded from a sustained attack.
    $kept = array_filter($data[$identifier] ?? [], fn($ts) => $ts > $now - 3600);
    $kept[] = $now;
    $data[$identifier] = array_values($kept);
    rate_limit_write($purpose, $data);
}

function rate_limit_reset(string $purpose, string $identifier): void
{
    $data = rate_limit_read($purpose);
    unset($data[$identifier]);
    rate_limit_write($purpose, $data);
}

function rate_limit_seconds_until_retry(string $purpose, string $identifier, int $windowSeconds): int
{
    $data = rate_limit_read($purpose);
    $attempts = $data[$identifier] ?? [];
    if (empty($attempts)) {
        return 0;
    }
    $oldest = min($attempts);
    return max(0, $windowSeconds - (time() - $oldest));
}
