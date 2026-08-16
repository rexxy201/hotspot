<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}
if ($argc < 2) {
    fwrite(STDERR, "Usage: php hash_password.php <plaintext-password>\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_BCRYPT) . "\n";
