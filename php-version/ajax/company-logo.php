<?php
// AJAX endpoint: upload the company logo (used in transactional emails).
// Admin-only. Stores under /uploads/company/ and returns its public URL.
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No logo uploaded']);
    exit;
}

$f = $_FILES['logo'];
if ($f['size'] > 3 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Logo must be under 3 MB']);
    exit;
}

$mime = mime_content_type($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
if (!isset($allowed[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, GIF, WEBP and SVG are allowed']);
    exit;
}

$dir = __DIR__ . '/../uploads/company';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Upload directory unavailable']);
    exit;
}

$name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
$dest = $dir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

$relUrl = 'uploads/company/' . $name;

// Store a HOST-RELATIVE path (not an absolute URL). Absolute URLs baked in the
// preview/staging host break the moment the site is opened on another host
// (preview → production). company_info() + email_absolute_url() resolve this
// to the correct absolute URL against the live request host on the fly.
setting_set('company_logo', $relUrl);

echo json_encode([
    'ok'  => true,
    'url' => $relUrl,
    'rel' => $relUrl,
]);
