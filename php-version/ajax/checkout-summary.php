<?php
/**
 * Returns the rendered checkout-summary HTML. Called by main.js after
 * any cart mutation (coupon apply / qty change / item remove) so we can
 * refresh ONLY the right-column summary in place, leaving the customer's
 * filled-in Contact / Billing / Card details intact on the left.
 */
require_once __DIR__ . '/../includes/functions.php';

// Empty cart → 204 so the client can decide what to do (typically redirect home).
$items = cart_items();
if (!$items) {
    http_response_code(204);
    exit;
}

// Mirror the same totals computation used by /checkout.php so the
// refreshed summary always matches what the server would render on a
// full reload.
$proAssist  = (($_GET['pro'] ?? '') === '1');
$subtotal   = cart_subtotal();
$savings    = 0;
foreach ($items as $i) {
    if ($i['original_price'] && $i['original_price'] > $i['price']) {
        $savings += ($i['original_price'] - $i['price']) * $i['qty'];
    }
}
$couponCode = $_SESSION['coupon'] ?? null;
$couponPct  = $couponCode ? (int)($_SESSION['coupon_pct'] ?? (coupons()[$couponCode] ?? 20)) : 0;
$discount   = $couponCode ? round($subtotal * $couponPct / 100, 2) : 0.0;
$total      = $subtotal - $discount + ($proAssist ? PRO_ASSIST_PRICE : 0);

include __DIR__ . '/../includes/checkout-summary-partial.php';
