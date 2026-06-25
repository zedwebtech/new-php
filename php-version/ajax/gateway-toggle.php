<?php
// AJAX endpoint: toggle a payment gateway active/inactive instantly.
// Admin-only. Used by admin.php?tab=api&gw=toggles.
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$gw  = strtolower(preg_replace('/[^a-z]/i', '', $in['gateway'] ?? ''));
$on  = !empty($in['active']);

if (!in_array($gw, ['card', 'paypal'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown gateway']);
    exit;
}

$status = $on ? 'active' : 'inactive';
$key    = $gw === 'card' ? 'gw_card_status' : 'gw_paypal_status';
setting_set($key, $status);

// Also keep the legacy paypal_enabled flag in sync (back-compat with older code paths).
if ($gw === 'paypal') {
    setting_set('paypal_enabled', $on ? '1' : '0');
}

// Return both states so the UI can refresh both cards in one round-trip
echo json_encode([
    'ok'      => true,
    'gateway' => $gw,
    'active'  => $on,
    'states'  => [
        'card'   => setting_get('gw_card_status',   'active')   === 'active',
        'paypal' => setting_get('gw_paypal_status', 'inactive') === 'active',
    ],
]);
