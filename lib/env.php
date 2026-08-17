<?php
/**
 * Minimal .env loader — no external dependency. Adding a whole Composer
 * library just to parse KEY=VALUE lines would be overkill for this app,
 * which already avoids external assets everywhere it can (see the note at
 * the top of assets/style.css).
 *
 * Parses simple KEY=VALUE lines into the process environment via putenv()
 * and $_ENV, so the existing getenv()-based constants in config.php pick
 * them up unchanged. A real environment variable the host has already set
 * is left untouched — .env only fills in what isn't already set, so an
 * operator (or the test suite) can still override a single value without
 * editing the file.
 */
function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Strip one layer of matching quotes, if the value has them.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key === '' || getenv($key) !== false) {
            continue; // don't clobber a real environment variable
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}
