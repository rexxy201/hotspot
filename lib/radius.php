<?php
function radius_add_user(mysqli $db, string $code): void {
    $stmt = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)');

    $attr = 'Cleartext-Password';
    $op = ':=';
    $stmt->bind_param('ssss', $code, $attr, $op, $code);
    $stmt->execute();

    $attr = 'Simultaneous-Use';
    $limit = '1';
    $stmt->bind_param('ssss', $code, $attr, $op, $limit);
    $stmt->execute();

    $stmt->close();
}

function radius_user_exists(mysqli $db, string $code): bool {
    $stmt = $db->prepare('SELECT 1 FROM radcheck WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}
