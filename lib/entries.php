<?php
function find_entry_by_email_or_phone(mysqli $db, string $email, string $phone): ?array {
    $stmt = $db->prepare('SELECT id, name, phone, email, code FROM entries WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->bind_param('ss', $email, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function code_exists(mysqli $db, string $code): bool {
    $stmt = $db->prepare('SELECT 1 FROM entries WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function generate_unique_code(mysqli $db): string {
    do {
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    } while (code_exists($db, $code));
    return $code;
}

function create_entry(mysqli $db, string $name, string $phone, string $email, string $code): void {
    $stmt = $db->prepare('INSERT INTO entries (name, phone, email, code) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $name, $phone, $email, $code);
    $stmt->execute();
    $stmt->close();
}
