<?php
// AJAX endpoint: upload an image for an email template.
// Admin-only. Returns a public URL the admin can insert into the template HTML.
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded']);
    exit;
}

$f = $_FILES['image'];
if ($f['size'] > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Image must be under 5 MB']);
    exit;
}

$mime = mime_content_type($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
if (!isset($allowed[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, GIF, WEBP and SVG are allowed']);
    exit;
}

$dir = __DIR__ . '/../uploads/templates';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Upload directory unavailable']);
    exit;
}

$name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
$dest = $dir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

$relUrl = 'uploads/templates/' . $name;
$absUrl = rtrim(SITE_URL, '/') . '/' . $relUrl;

echo json_encode([
    'ok'  => true,
    'url' => $absUrl,
    'rel' => $relUrl,
    'name' => $name,
]);
