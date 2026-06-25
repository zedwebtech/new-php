<?php
// AJAX endpoint: toggle a region active/inactive instantly.
// Admin-only. Used by admin.php?tab=regions.
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$code   = strtoupper(preg_replace('/[^A-Z]/i', '', $in['code'] ?? ''));
$active = !empty($in['active']) ? 1 : 0;

if ($code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing region code']);
    exit;
}

$pdo = db();
$exists = $pdo->prepare('SELECT code FROM regions WHERE code = ?');
$exists->execute([$code]);
if (!$exists->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Region not found']);
    exit;
}

$pdo->prepare('UPDATE regions SET active = ? WHERE code = ?')->execute([$active, $code]);

// Live counts (so the UI can refresh without a page reload)
$prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE region=" . $pdo->quote($code))->fetchColumn();
$keysAv    = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE region=" . $pdo->quote($code) . " AND status='available'")->fetchColumn();

echo json_encode([
    'ok'          => true,
    'code'        => $code,
    'active'      => $active,
    'product_count' => $prodCount,
    'keys_available' => $keysAv,
]);
