<?php
/**
 * Shared "read the last N lines of a log file without loading the whole
 * thing" used by both admin/radius-log.php and admin/app-log.php.
 */
function tail_log(string $path, int $maxLines = 200): string
{
    if (!is_file($path)) {
        return '';
    }
    $size = filesize($path);
    // 64KB comfortably covers 200 lines of either log's format.
    $readFrom = max(0, $size - 65536);
    $chunk = (string) @file_get_contents($path, false, null, $readFrom);
    $lines = explode("\n", trim($chunk));
    return implode("\n", array_slice($lines, -$maxLines));
}
