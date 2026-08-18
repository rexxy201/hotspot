<?php
/**
 * A general application error log, mirroring radius_server.php's own
 * radius_log(). The RADIUS daemon has always had a persistent, admin-
 * readable log (Admin -> RADIUS Log); everything else in the app (mail/SMS
 * delivery failures, an uncaught exception in connect.php) previously only
 * ever reached PHP's own error_log() — which, on this host, lands in a
 * root-owned OpenLiteSpeed system log nobody but a developer with SSH
 * access can read. This gives the rest of the app the same "write here,
 * read it from the admin panel" path radius.log already had.
 */

const APP_LOG_MAX_BYTES = 8388608; // 8MB, same cap as radius.log

function app_log_path(): string
{
    return __DIR__ . '/../logs/app.log';
}

function app_log(string $message): void
{
    $dir = dirname(app_log_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $path = app_log_path();
    // Rotate BEFORE writing, not after — so a single generation is always
    // under the cap rather than briefly exceeding it. This is a live-tail
    // diagnostic log, not an audit trail, so one backup generation is enough.
    if (is_file($path) && filesize($path) > APP_LOG_MAX_BYTES) {
        @rename($path, $path . '.1');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Funnels an uncaught exception or a fatal error anywhere in the request
 * into app.log automatically, on top of the explicit app_log() calls at
 * known failure points (mailer, SMS, connect.php's own catch). Safe to
 * call more than once per request — PHP only keeps the last-registered
 * handler either way, so requiring this from multiple included files
 * cannot double-log a single error.
 */
function app_log_register_handlers(): void
{
    set_exception_handler(function (\Throwable $e): void {
        $summary = 'UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        app_log($summary);

        // Registering a handler REPLACES PHP's default uncaught-exception
        // behaviour (print the fatal to stderr, exit non-zero). Logging alone
        // therefore made crashes *quieter* than before this file existed: a
        // failing CLI script printed nothing and exited 0, so `php test.php`
        // looked like it passed when it had actually died, and a
        // radius_server.php crash would have vanished from journalctl. Restore
        // both behaviours explicitly.
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $summary . "\n" . $e->getTraceAsString() . "\n");
            exit(255);
        }
        // Web: deliberately NOT echoing the message — it can contain paths,
        // query values and DB errors that must not reach a visitor. The log
        // above is where the detail belongs; the visitor just gets a 500.
        if (!headers_sent()) {
            http_response_code(500);
        }
        exit(1);
    });
    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_log('FATAL ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
    });
}
