<?php
/**
 * Test-mode order preview — returns JSON describing exactly what
 * (a) the customer's email, (b) the customer's success page, and
 * (c) the admin notification will look like for the current cart,
 * WITHOUT placing a real order. Used by the "Preview test order"
 * button on /checkout.php when the gateway is in TEST mode.
 *
 * Inputs (POST form-data):
 *   first_name, last_name, email, phone, country (optional)
 * If any are missing we fall back to safe demo placeholders so the
 * admin can preview even before filling the form.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';

header('Content-Type: application/json');

$items = cart_items();
if (!$items) {
    echo json_encode(['ok' => false, 'error' => 'Cart is empty.']);
    exit;
}

$proAssist  = (($_POST['pro'] ?? $_GET['pro'] ?? '') === '1');
$subtotal   = cart_subtotal();
$couponCode = $_SESSION['coupon'] ?? null;
$couponPct  = $couponCode ? (int)($_SESSION['coupon_pct'] ?? (coupons()[$couponCode] ?? 20)) : 0;
$discount   = $couponCode ? round($subtotal * $couponPct / 100, 2) : 0.0;
$total      = $subtotal - $discount + ($proAssist ? PRO_ASSIST_PRICE : 0);

$first = trim($_POST['first_name'] ?? '') ?: 'Sample';
$last  = trim($_POST['last_name'] ?? '')  ?: 'Customer';
$email = trim($_POST['email'] ?? '')      ?: 'customer@example.com';
$phone = trim($_POST['phone'] ?? '')      ?: '+1-555-000-0123';

// Build an order-shaped array compatible with build_order_email_html().
$preview = [
    'order_number' => 'TEST-' . date('YmdHis'),
    'email'        => $email,
    'first_name'   => $first,
    'last_name'    => $last,
    'phone'        => $phone,
    'subtotal'     => $subtotal,
    'total'        => $total,
    'currency'     => current_currency()['code'],
    'pro_assist'   => $proAssist ? 1 : 0,
    'created_at'   => date('Y-m-d H:i:s'),
    'payment_method'     => 'card',
    'card_statement_name'=> '',
    'card_brand'   => 'visa',
    'card_last4'   => '4242',
    'transaction_id'=> 'pi_test_' . bin2hex(random_bytes(8)),
];

// Synthesise fake "assignments" (license keys) so the email body has
// realistic-looking dummy key blocks instead of "no key found".
$assignments = [];
foreach ($items as $i) {
    for ($q = 0; $q < (int)$i['qty']; $q++) {
        $assignments[] = [
            'product_slug' => $i['slug'],
            'product_name' => $i['name'],
            'license_key'  => 'XXXXX-XXXXX-XXXXX-XXXXX-' . strtoupper(substr(md5($i['slug'].$q), 0, 5)) . '  (DEMO KEY)',
            'activation_url'    => '',
            'install_guide_url' => '',
        ];
    }
}

$trackToken = 'demo' . bin2hex(random_bytes(6));
$demoReviewUrl = rtrim((trim((string)setting_get('site_domain_url','')) ?: site_url()), '/') . '/review.php?t=demo' . bin2hex(random_bytes(6));
$emailHtml  = build_order_email_html($preview, $items, $assignments, $trackToken, $demoReviewUrl);
$emailSubj  = setting_get('email_template_subject', 'Your Microsoft product key — Order #{{order_number}}');
$emailSubj  = str_replace(['{{order_number}}', '{{first_name}}', '{{customer_email}}'], [$preview['order_number'], $first, $email], $emailSubj);

// Admin alert preview — mirrors checkout.php lines 124-130.
$adminTitle = 'New order ' . $preview['order_number'];
$adminBody  = $first . ' ' . $last
            . ' · ' . current_currency()['code'] . ' ' . number_format($total, 2)
            . ' · ' . count($items) . ' item' . (count($items) > 1 ? 's' : '');
$adminUrl   = '/admin.php?tab=orders&q=' . urlencode($preview['order_number']);

// Customer success-page URL (demo). order-success.php already accepts
// a `demo=1` query to render with safe placeholder data.
$successUrl = '/order-success.php?demo=1&order=' . urlencode($preview['order_number']);

// Where these alerts/emails will be delivered in production:
$adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (company_info()['email'] ?? 'admin@maventechsoftware.com');

echo json_encode([
    'ok'          => true,
    'gw_mode'     => setting_get('gw_mode', 'test'),
    'email_to'    => $email,
    'email_subj'  => $emailSubj,
    'email_html'  => $emailHtml,
    'admin_to'    => $adminEmail,
    'admin_title' => $adminTitle,
    'admin_body'  => $adminBody,
    'admin_url'   => $adminUrl,
    'success_url' => $successUrl,
    'order'       => $preview,
], JSON_UNESCAPED_SLASHES);
