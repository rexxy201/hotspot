<?php
require_once __DIR__ . '/config.php';

function get_db(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
    }
    return $db;
}
