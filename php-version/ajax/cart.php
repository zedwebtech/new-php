<?php
// Cart AJAX API: add / update / remove / count
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Accept payload from any of: JSON body (main.js), form-encoded body ($_POST),
// or query string ($_GET). The frontend uses JSON; the other shapes keep the
// endpoint tolerant for curl tests, native apps, and any future caller that
// posts form data — previously a non-JSON POST silently returned ok:true
// count:0 because $action/$slug were never populated.
$raw  = (string)file_get_contents('php://input');
$body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$in   = $body + $_POST + $_GET;
$action = (string)($in['action'] ?? '');
$slug   = (string)($in['slug']   ?? '');

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

if ($action === 'add' && $slug && get_product($slug)) {
    $qty = max(1, (int)($in['qty'] ?? 1));
    $cur = (int)($_SESSION['cart'][$slug] ?? 0);
    // Products are always purchasable — no stock gate. If inventory is empty the
    // order is delivered as a backorder (license key emailed within the hour).
    $stock = available_keys_count($slug);
    // Multi-seat semantics: one license key covers N PCs/devices. `qty` is the
    // seat count the customer wants on a single key — it never consumes more
    // than ONE key from stock no matter how large. So we no longer cap qty by
    // stock; only enforce a sane upper bound (100 seats) to block accidental
    // 9999 typos. Once the order is fulfilled, fulfill_order() flips exactly
    // ONE license_key.status='sold' regardless of qty.
    $SEAT_CAP   = 100;
    $newQty     = min($SEAT_CAP, $cur + $qty);
    $cappedAt   = $newQty < ($cur + $qty);
    $_SESSION['cart'][$slug] = $newQty;
    if ($cappedAt) {
        echo json_encode([
            'ok'      => true,
            'capped'  => true,
            'qty'     => $newQty,
            'stock'   => $stock,
            'message' => "Capped at {$SEAT_CAP} seats per license.",
            'count'   => cart_count(),
        ]);
        exit;
    }
} elseif ($action === 'set' && $slug && get_product($slug)) {
    // "Buy Now" semantics: set the line to EXACTLY the selected seat count.
    $qty = max(1, (int)($in['qty'] ?? 1));
    $stock = available_keys_count($slug);
    $SEAT_CAP = 100;
    $capped   = $qty > $SEAT_CAP;
    $_SESSION['cart'][$slug] = min($SEAT_CAP, $qty);
    if ($capped) {
        echo json_encode([
            'ok'      => true,
            'capped'  => true,
            'qty'     => $_SESSION['cart'][$slug],
            'stock'   => $stock,
            'message' => "Capped at {$SEAT_CAP} seats per license.",
            'count'   => cart_count(),
        ]);
        exit;
    }
} elseif ($action === 'update' && $slug && isset($_SESSION['cart'][$slug])) {
    $qty = (int)($in['qty'] ?? 1);
    if ($qty <= 0) {
        unset($_SESSION['cart'][$slug]);
    } else {
        $stock = available_keys_count($slug);
        $SEAT_CAP = 100;
        $capped   = $qty > $SEAT_CAP;
        $_SESSION['cart'][$slug] = min($SEAT_CAP, $qty);
        if ($capped) {
            echo json_encode([
                'ok'      => true,
                'capped'  => true,
                'qty'     => $_SESSION['cart'][$slug],
                'stock'   => $stock,
                'message' => "Capped at {$SEAT_CAP} seats per license.",
                'count'   => cart_count(),
            ]);
            exit;
        }
    }
} elseif ($action === 'remove' && $slug) {
    unset($_SESSION['cart'][$slug]);
} elseif ($action === 'coupon') {
    $code = strtoupper(trim($in['code'] ?? ''));
    if ($code === '') {
        unset($_SESSION['coupon'], $_SESSION['coupon_pct']);
        echo json_encode(['ok' => true, 'coupon' => null, 'count' => cart_count()]);
        exit;
    }
    $valid = coupons();
    if (isset($valid[$code])) {
        $_SESSION['coupon'] = $code;
        $_SESSION['coupon_pct'] = $valid[$code];
        echo json_encode(['ok' => true, 'coupon' => $code, 'pct' => $valid[$code], 'count' => cart_count()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid coupon code', 'count' => cart_count()]);
    }
    exit;
}

echo json_encode(['ok' => true, 'count' => cart_count()]);
