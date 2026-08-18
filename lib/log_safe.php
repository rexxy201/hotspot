<?php
/**
 * Sanitize a value that came from outside the app (a query-string param, a
 * form field, a MAC address handed to us by an unauthenticated device)
 * before it goes into ANY log line. Strips anything non-printable — a
 * newline would let a crafted value forge whole fake log entries — and
 * caps the length so one long value can't flood a log a person is meant
 * to be able to skim.
 *
 * Originally lived only in radius_server.php (as radius_log_safe(), which
 * now just delegates here) — extracted so app_log() call sites elsewhere
 * (index.php's Mikrotik-error logging, for one) don't need their own copy.
 *
 * NOT for use on already-trusted, server-generated text (an exception
 * message, a stack trace) — those can legitimately contain newlines worth
 * keeping, and running them through this would mangle them for no reason.
 * This is specifically for values an unauthenticated visitor supplied.
 */
function log_safe_value(string $value, int $max = 64): string
{
    $clean = preg_replace('/[^\x20-\x7E]/', '.', substr($value, 0, $max));
    return $clean === '' ? '(empty)' : $clean;
}
