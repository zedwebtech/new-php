<?php
// Live deliverability check for the checkout email field.
// On blur, the checkout form POSTs the typed address here; we run the
// same DNS MX/typo dictionary check used by send_email() and return a
// "did you mean?" hint so the customer can fix the typo BEFORE paying.
// Customer can always override with "use anyway" client-side.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$in    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$email = strtolower(trim((string)($in['email'] ?? '')));

if ($email === '') { echo json_encode(['ok' => true]); exit; }

$r = email_address_deliverable($email);
if ($r['ok']) {
    echo json_encode(['ok' => true]);
    exit;
}

// Try to extract a "did you mean" suggestion from the detail string.
$suggest = '';
if (preg_match('/Did the customer mean ([\w\.\-]+@?[\w\.\-]*)\?/i', (string)$r['detail'], $m)) {
    $local = strstr($email, '@', true);
    $suggest = $local . '@' . trim($m[1], '?.');
} elseif (preg_match('/mean ([\w\.\-]+)\?/i', (string)$r['detail'], $m)) {
    $local = strstr($email, '@', true);
    $suggest = $local . '@' . trim($m[1], '?.');
}

echo json_encode([
    'ok'        => false,
    'reason'    => $r['reason'],
    'detail'    => $r['detail'] ?: 'This email address looks undeliverable.',
    'suggest'   => $suggest,
]);
