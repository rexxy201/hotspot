<?php
const ALLOWED_LOGO_MIME_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
const MAX_LOGO_BYTES = 2 * 1024 * 1024;

function validate_logo_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload failed.', null];
    }
    if ($file['size'] > MAX_LOGO_BYTES) {
        return [false, 'Logo must be under 2MB.', null];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!array_key_exists($mime, ALLOWED_LOGO_MIME_TYPES)) {
        return [false, 'Logo must be a PNG or JPG image.', null];
    }
    return [true, null, ALLOWED_LOGO_MIME_TYPES[$mime]];
}

function store_logo_upload(array $file, string $extension, string $uploadsDir): string {
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = rtrim($uploadsDir, '/') . '/' . $filename;
    move_uploaded_file($file['tmp_name'], $destination);
    return 'uploads/logos/' . $filename;
}
