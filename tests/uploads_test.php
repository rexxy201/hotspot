<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/uploads.php';

// A real, minimal 1x1 PNG so mime_content_type() reports image/png.
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$tmpPath = tempnam(sys_get_temp_dir(), 'logo');
file_put_contents($tmpPath, $pngBytes);

[$ok, $error, $ext] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($pngBytes),
    'tmp_name' => $tmpPath,
]);
assert_true($ok, 'validate_logo_upload accepts a real PNG');
assert_equals('png', $ext, 'validate_logo_upload identifies the PNG extension');

[$tooBigOk] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => MAX_LOGO_BYTES + 1,
    'tmp_name' => $tmpPath,
]);
assert_true(!$tooBigOk, 'validate_logo_upload rejects files over the size cap');

$textPath = tempnam(sys_get_temp_dir(), 'notimage');
file_put_contents($textPath, '<script>alert(1)</script>');
[$textOk] = validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'size' => 100,
    'tmp_name' => $textPath,
]);
assert_true(!$textOk, 'validate_logo_upload rejects a non-image file even with image bytes claimed');

[$noFileOk] = validate_logo_upload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);
assert_true($noFileOk, 'validate_logo_upload treats "no file uploaded" as valid, since the field is optional');

$fakeUploadResult = store_logo_upload(
    ['tmp_name' => $tmpPath],
    'png',
    sys_get_temp_dir()
);
assert_equals(null, $fakeUploadResult, 'store_logo_upload returns null when move_uploaded_file fails (not a genuine upload)');

unlink($tmpPath);
unlink($textPath);
test_summary();
