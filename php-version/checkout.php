<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/stripe.php';
$pageTitle = 'Secure Checkout | ' . SITE_BRAND;

/* Region-aware checkout address formats. The visible address form (country
   default, phone dial code, state/province/county label + options, postal
   label + placeholder + validation) adapts to the shopper's selected region
   so an Australian buyer sees "State/Territory" + "Postcode", a Canadian sees
   "Province" + "Postal Code", etc. — instead of the US ZIP/State layout for
   everyone. Single source of truth, shared by the form render, the server-side
   validation and the client-side JS (json_encoded below). */
$REGION_FORMS = [
    'US' => ['country'=>'US','dial'=>'+1','flag'=>'🇺🇸','region_label'=>'State','region_required'=>true,
        'regions'=>['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC','Other'],
        'postal_label'=>'ZIP Code','postal_ph'=>'90210','postal_re'=>'^\\d{5}(-\\d{4})?$','postal_err'=>'US ZIP must be 5 digits (e.g. 90210).'],
    'CA' => ['country'=>'CA','dial'=>'+1','flag'=>'🇨🇦','region_label'=>'Province','region_required'=>true,
        'regions'=>['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'],
        'postal_label'=>'Postal Code','postal_ph'=>'K1A 0B1','postal_re'=>'^[A-Za-z]\\d[A-Za-z] ?\\d[A-Za-z]\\d$','postal_err'=>'Enter a valid Canadian postal code (e.g. K1A 0B1).'],
    'UK' => ['country'=>'UK','dial'=>'+44','flag'=>'🇬🇧','region_label'=>'County','region_required'=>false,
        'regions'=>[],
        'postal_label'=>'Postcode','postal_ph'=>'SW1A 1AA','postal_re'=>'^[A-Za-z]{1,2}\\d[A-Za-z\\d]? ?\\d[A-Za-z]{2}$','postal_err'=>'Enter a valid UK postcode (e.g. SW1A 1AA).'],
    'AU' => ['country'=>'AU','dial'=>'+61','flag'=>'🇦🇺','region_label'=>'State/Territory','region_required'=>true,
        'regions'=>['ACT','NSW','NT','QLD','SA','TAS','VIC','WA'],
        'postal_label'=>'Postcode','postal_ph'=>'2000','postal_re'=>'^\\d{4}$','postal_err'=>'Australian postcode must be 4 digits (e.g. 2000).'],
    'EU' => ['country'=>'EU','dial'=>'+49','flag'=>'🇪🇺','region_label'=>'Region / State','region_required'=>false,
        'regions'=>[],
        'postal_label'=>'Postal Code','postal_ph'=>'10115','postal_re'=>'^.{3,}$','postal_err'=>'Postal code is too short.'],
];
/* Map the active storefront currency back to the address region so the form
   defaults to the country the shopper is buying from. */
$__curToRegion = ['USD'=>'US','CAD'=>'CA','GBP'=>'UK','AUD'=>'AU','EUR'=>'EU'];
$__activeCur   = current_currency()['code'];
$curRegionCC   = $__curToRegion[$__activeCur] ?? current_country_code();
if (!isset($REGION_FORMS[$curRegionCC])) $curRegionCC = 'US';


// Subscription checkout — when a visitor arrives via /subscribe.php the
// session carries the chosen plan.  We synthesise a single line item from the
// plan and bypass the product cart / coupons / ProAssist entirely.
$subPlan = !empty($_SESSION['sub_plan']) ? sub_plan_get((string)$_SESSION['sub_plan']) : null;
$isSub   = $subPlan && (float)$subPlan['price'] > 0 && (int)$subPlan['active'] === 1;

if ($isSub) {
    $planAmt   = round((float)$subPlan['price'], 2);
    $items     = [[
        'slug'           => 'sub-' . $subPlan['slug'],
        'name'           => $subPlan['name'] . ' Subscription (' . $subPlan['tenure_label'] . ')',
        'price'          => $planAmt,
        'original_price' => null,
        'qty'            => 1,
        'image'          => (string)($subPlan['icon_image'] ?? ''),
    ]];
    $proAssist = false;
    $subtotal  = $planAmt;
    $savings   = 0;
    $couponCode = null; $couponPct = 0; $discount = 0.0;
    $total     = $planAmt;
    $errors    = [];
} else {
    if (!empty($_SESSION['sub_plan'])) unset($_SESSION['sub_plan']);
    $items = cart_items();
    if (!$items) {
        header('Location: cart.php');
        exit;
    }
    $proAssist = ($_GET['pro'] ?? ($_POST['pro'] ?? '')) === '1';
    $subtotal = cart_subtotal();
    // Savings from list prices
    $savings = 0;
    foreach ($items as $i) {
        if ($i['original_price'] && $i['original_price'] > $i['price']) {
            $savings += ($i['original_price'] - $i['price']) * $i['qty'];
        }
    }
    // Coupon (set via ajax/cart.php action=coupon): percent comes from the coupons() map
    $couponCode = $_SESSION['coupon'] ?? null;
    $couponPct = $couponCode ? (int)($_SESSION['coupon_pct'] ?? (coupons()[$couponCode] ?? 20)) : 0;
    $discount = $couponCode ? round($subtotal * $couponPct / 100, 2) : 0.0;
    $total = $subtotal - $discount + ($proAssist ? PRO_ASSIST_PRICE : 0);
    $errors = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCC = strtoupper(trim($_POST['country'] ?? 'US'));
    if (!isset($REGION_FORMS[$postCC])) $postCC = 'US';
    $required = ['email', 'first_name', 'last_name', 'phone', 'address', 'city', 'zip'];
    if (!empty($REGION_FORMS[$postCC]['region_required'])) $required[] = 'state';
    foreach ($required as $f) {
        if (trim($_POST[$f] ?? '') === '') $errors[] = ucwords(str_replace('_', ' ', $f)) . ' is required.';
    }
    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    $method = ($_POST['payment_method'] ?? 'card') === 'paypal' ? 'paypal' : 'card';
    // Reject methods the admin has disabled (defence-in-depth — hides UI AND blocks API spoofing)
    if ($method === 'card'   && !card_enabled())   $errors[] = 'Card payments are currently unavailable. Please choose another method.';
    if ($method === 'paypal' && !paypal_enabled()) $errors[] = 'PayPal is currently unavailable. Please choose another method.';

    // Server-side mirror of the JS guards in checkout.php — defence-in-depth
    // against API spoofing or JS-disabled browsers.  We can only validate
    // the fields that are actually POSTed; card details (number/exp/cvv) are
    // intentionally not posted (no `name` attr) — they go straight to the
    // gateway's PCI-compliant page, so card validation lives there.
    if (!$errors || count($errors) < 5) {
        // Email — RFC validity already checked, now check deliverability via
        // DNS MX + typo dictionary (same helper the JS hint uses).
        $emailVal = trim($_POST['email'] ?? '');
        if ($emailVal !== '' && filter_var($emailVal, FILTER_VALIDATE_EMAIL)
            && trim($_POST['email_override'] ?? '') !== '1') {
            $deliv = email_address_deliverable($emailVal);
            if (!$deliv['ok'] && in_array($deliv['reason'], ['no_mx','invalid_syntax'], true)) {
                $errors[] = 'Email looks undeliverable: ' . ($deliv['detail'] ?: $deliv['reason']);
            }
        }
        // Billing address sanity — needs a street number (or P.O. Box), not
        // be too short, not just letters.  Matches the JS rules.
        $addrVal = trim($_POST['address'] ?? '');
        if ($addrVal !== '' && trim($_POST['address_override'] ?? '') !== '1') {
            $issues = [];
            if (!preg_match('/\d/', $addrVal) && !preg_match('/p\.?\s*o\.?\s*box/i', $addrVal)) {
                $issues[] = 'no street number detected';
            }
            if (strlen($addrVal) < 6)              $issues[] = 'address looks too short';
            if (preg_match('/^[a-z\s]+$/i', $addrVal)) $issues[] = 'just letters — missing street/number?';
            if ($issues) $errors[] = 'Billing address looks incomplete: ' . implode(', ', $issues) . '.';
        }
        // Postal / ZIP shape — validated against the submitted country's format
        // (US 5-digit, CA A1A 1A1, UK postcode, AU 4-digit, EU 3+ chars).
        $zipVal     = trim($_POST['zip'] ?? '');
        $countryVal = strtoupper(trim($_POST['country'] ?? 'US'));
        if (!isset($REGION_FORMS[$countryVal])) $countryVal = 'US';
        if ($zipVal !== '') {
            $rfPost = $REGION_FORMS[$countryVal];
            if (!preg_match('/' . $rfPost['postal_re'] . '/', $zipVal)) {
                $errors[] = $rfPost['postal_err'];
            }
        }
        // Phone — at least 7 digits in the local part (international numbers
        // can be longer; we only enforce a minimum).
        $phoneDigits = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        if ($phoneDigits !== '' && strlen($phoneDigits) < 7) {
            $errors[] = 'Phone number is too short — please enter at least 7 digits.';
        }
        // State / province / county — when the country has a fixed list (US/CA/AU)
        // the value must be one of them; free-text regions (UK/EU) accept anything.
        $stateVal = trim($_POST['state'] ?? '');
        $validStates = $REGION_FORMS[$countryVal]['regions'];
        if ($stateVal !== '' && !empty($validStates) && !in_array($stateVal, $validStates, true)) {
            $errors[] = 'Please select a valid ' . strtolower($REGION_FORMS[$countryVal]['region_label']) . '.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $orderNumber = generate_order_number();
        $user = current_user();
        $phoneFull = trim(($_POST['phone_code'] ?? '+1') . ' ' . trim($_POST['phone']));
        $activeMode = stripe_active_mode(); // 'test' or 'live' — captured at order creation
        $stmt = $pdo->prepare('INSERT INTO orders (order_number, email, first_name, last_name, phone, company_name, address, address2, country, city, state, zip, payment_method, currency, subtotal, total, pro_assist, user_id, gw_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $orderNumber, trim($_POST['email']), trim($_POST['first_name']), trim($_POST['last_name']),
            $phoneFull, '', trim($_POST['address']), trim($_POST['address2'] ?? ''),
            substr(trim($_POST['country'] ?? 'US'), 0, 5), trim($_POST['city']), trim($_POST['state']), trim($_POST['zip']),
            $method, current_currency()['code'], $subtotal, $total, $proAssist ? 1 : 0, $user['id'] ?? null, $activeMode,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        // Mark this order as a subscription purchase so fulfilment runs the
        // subscription path (record + customer ID + certificate) instead of
        // the license-key delivery flow.
        if ($isSub && $subPlan) {
            try { $pdo->prepare('UPDATE orders SET subscription_plan=? WHERE id=?')->execute([$subPlan['slug'], $orderId]); }
            catch (Throwable $e) { /* column self-heals via sub_migrate */ }
        }
        // Capture session metadata for the Sales Detail view (IP, user-agent → device)
        try {
            $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400);
            $pdo->prepare("UPDATE orders SET ip_address = ?, timeline = ?, region = ? WHERE id = ?")
                ->execute([$clientIp, json_encode(['user_agent' => $ua, 'placed_at' => date('c')]), active_region_code(), $orderId]);
        } catch (Throwable $e) { /* metadata is best-effort */ }
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_slug, name, price, qty) VALUES (?,?,?,?,?)');
        foreach ($items as $i) {
            $itemStmt->execute([$orderId, $i['slug'], $i['name'], $i['price'], $i['qty']]);
        }

        // Notification: new order placed → bubbles up to admin's PWA bell.
        // We fire TWO rows (one per channel) so the user can filter the
        // bell by "Orders" vs "Sales Detail" later if they want.
        $orderTitle = 'New order ' . $orderNumber;
        $orderBody  = trim($_POST['first_name']) . ' ' . trim($_POST['last_name'])
                    . ' · ' . current_currency()['code'] . ' ' . number_format($total, 2)
                    . ' · ' . count($items) . ' item' . (count($items) > 1 ? 's' : '');
        admin_notify('order', $orderTitle, $orderBody, '/admin.php?tab=orders&q=' . urlencode($orderNumber));
        admin_notify('sale',  '$' . number_format($total, 2) . ' sale — ' . $orderNumber,
                     $orderBody, '/admin.php?tab=sales');
        if ($proAssist) {
            $itemStmt->execute([$orderId, 'proassist-premium', 'ProAssist Premium Installation', PRO_ASSIST_PRICE, 1]);
            // Surface this customer in Lead Management so the support team
            // can proactively reach out and schedule the installation call.
            // We treat ProAssist as an inbound "callback requested" lead
            // keyed off the order number + email, so admins can search/
            // chat / assign right from the existing Leads tab.
            try {
                $proName  = trim(trim($_POST['first_name']) . ' ' . trim($_POST['last_name']));
                $proCty   = substr(trim($_POST['country'] ?? 'US'), 0, 5);
                $proMsg   = 'ProAssist Premium Installation requested — Order #' . $orderNumber
                          . ' · Total: ' . current_currency()['code'] . ' ' . number_format($total, 2)
                          . ' · Schedule the install call within one business day.';
                $proToken = bin2hex(random_bytes(20));
                $pdo->prepare('INSERT INTO chat_leads
                    (session_id, name, email, phone, callback_requested, message, requested_product, country, chat_token)
                    VALUES (?,?,?,?,1,?,?,?,?)')
                    ->execute([
                        session_id(),
                        $proName ?: 'ProAssist customer',
                        trim($_POST['email']),
                        $phoneFull,
                        $proMsg,
                        'ProAssist Premium Installation',
                        $proCty,
                        $proToken,
                    ]);
                // Pre-fill the chat thread with an automated welcome so the
                // customer lands in an "active conversation" the moment they
                // open the widget on order-success.php.  Inserted as
                // sender='admin' so the customer-side poller picks it up and
                // appends it as a bot/agent bubble.
                $proLeadId = (int)$pdo->lastInsertId();
                $proFirst  = trim(explode(' ', $proName)[0] ?? '');
                $proWelcome = 'Hi' . ($proFirst ? ' ' . $proFirst : '')
                            . "! Thanks for choosing ProAssist Premium Installation on Order #" . $orderNumber . '. '
                            . "A specialist has been notified and will reach out within one business hour to schedule your install call. "
                            . "Feel free to drop any questions here in the meantime — we're online.";
                $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
                    ->execute([$proLeadId, 'admin', $proWelcome]);
                // Tell the admin PWA bell: a new ProAssist install must be scheduled.
                admin_notify(
                    'install',
                    'New install to schedule — Order ' . $orderNumber,
                    ($proName ?: 'A customer') . ' purchased ProAssist Premium Installation. Reach out within 1 business hour.',
                    '/admin.php?tab=leads&id=' . $proLeadId
                );
                // Bind the chat token to this browser session so the chat
                // widget on order-success.php auto-connects (no lead form
                // shown — we already have name/email/phone).
                $_SESSION['lead_id']   = $proLeadId;
                $_SESSION['chat_token'] = $proToken;
            } catch (Throwable $e) { /* lead-creation is best-effort */ }

            // Email the company's registered address the moment a customer
            // OPTS IN to ProAssist at checkout (separate from the later
            // "install scheduled" alert).  Recipient resolves from Company
            // Info → support/company email, then ADMIN_EMAIL fallback.
            try {
                $toEmail = trim((string)setting_get('company_support_email', ''));
                if ($toEmail === '') $toEmail = trim((string)setting_get('company_email', ''));
                if ($toEmail === '' && defined('ADMIN_EMAIL')) $toEmail = (string)ADMIN_EMAIL;
                if ($toEmail !== '') {
                    $brand    = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software';
                    $siteUrl  = function_exists('site_url') ? rtrim(site_url(), '/') : '';
                    $cName    = htmlspecialchars($proName ?: 'Customer', ENT_QUOTES, 'UTF-8');
                    $cEmail   = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $cPhone   = htmlspecialchars($phoneFull ?? '', ENT_QUOTES, 'UTF-8') ?: '(no phone)';
                    $ordTxt   = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');
                    $totTxt   = htmlspecialchars(current_currency()['code'] . ' ' . number_format($total, 2), ENT_QUOTES, 'UTF-8');
                    $adminLnk = $siteUrl . '/admin.php?tab=leads&id=' . (int)($proLeadId ?? 0);
                    $subject  = '[ProAssist] New install request — Order ' . $orderNumber . ' — ' . ($proName ?: $cEmail);
                    $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="background:#0f172a;padding:18px 22px;border-radius:10px 10px 0 0;">
    <div style="font-size:11px;letter-spacing:.12em;font-weight:800;text-transform:uppercase;color:#fcd34d;">{$brand} — ProAssist</div>
    <div style="font-size:20px;font-weight:800;color:#fff;margin-top:4px;">New ProAssist install request — action needed</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;padding:24px;line-height:1.55;">
    <p style="margin:0 0 14px 0;font-size:14px;">A customer just <strong>opted into ProAssist Premium Installation</strong> at checkout. Reach out within one business hour to schedule the install call.</p>
    <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;margin:6px 0 18px 0;">
      <tr><td style="padding:7px 0;color:#64748b;width:140px;">Order</td><td style="padding:7px 0;font-weight:700;">#{$ordTxt}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Order total</td><td style="padding:7px 0;">{$totTxt}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Customer</td><td style="padding:7px 0;">{$cName}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Email</td><td style="padding:7px 0;"><a href="mailto:{$cEmail}" style="color:#2563eb;text-decoration:none;">{$cEmail}</a></td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Phone</td><td style="padding:7px 0;"><a href="tel:{$cPhone}" style="color:#2563eb;text-decoration:none;">{$cPhone}</a></td></tr>
    </table>
    <a href="{$adminLnk}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:700;font-size:14px;">Open in admin &rsaquo;</a>
    <p style="margin:22px 0 0 0;font-size:12px;color:#64748b;">Automated notification from {$brand}. Update the recipient in Admin → Company Info.</p>
  </div>
</div>
HTML;
                    send_email($toEmail, $subject, $html, null, 'proassist_optin', 0);
                }
            } catch (Throwable $e) { @error_log('[checkout proassist optin email] ' . $e->getMessage()); }
        }
        $_SESSION['cart'] = [];
        unset($_SESSION['coupon']);
        unset($_SESSION['sub_plan']);

        if (stripe_enabled()) {
            // Real payment path. The key in use is mode-aware:
            //  · gw_mode='test'  ⇒ sk_test_*  ⇒ Stripe test/sandbox (no real charge)
            //  · gw_mode='live'  ⇒ sk_live_*  ⇒ real funds move
            // We additionally guard against the misconfiguration where the
            // admin flipped to LIVE but only test keys are configured — that
            // would silently send real customers to a sandbox checkout.
            $secretInUse = stripe_active_secret();
            if ($activeMode === 'live' && (str_starts_with($secretInUse, 'sk_test_') || str_contains($secretInUse, 'emergent'))) {
                $errors[] = 'Payment gateway is set to LIVE mode but no live API key is configured. An admin must paste a Stripe sk_live_* key under Admin → API / Payment Gateway → Card → Live keys before real payments can be processed.';
            } else {
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
                try {
                    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                    $orderStmt->execute([$orderId]);
                    $orderRow = $orderStmt->fetch();
                    $session = stripe_create_session($orderRow, $baseUrl);
                    $pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $orderId]);
                    header('Location: ' . $session['url']);
                    exit;
                } catch (RuntimeException $e) {
                    $errors[] = 'Payment error: ' . $e->getMessage();
                }
            }
        }
        if ($errors === [] && !stripe_enabled()) {
            // DEMO MODE (no Stripe key for the active gateway mode): mark paid + fulfill immediately
            $pdo->prepare('UPDATE orders SET status = "paid" WHERE id = ?')->execute([$orderId]);
            // Log a "test charge" row in transaction_logs so the admin's
            // Recent Transaction Logs table reflects the dry-run.
            try {
                $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $orderId,
                        $method === 'paypal' ? 'paypal' : 'card',
                        'TEST_' . strtoupper(bin2hex(random_bytes(6))),
                        $total,
                        current_currency()['code'],
                        $activeMode === 'test' ? 'test' : 'paid',
                    ]);
            } catch (Throwable $e) { /* logging is best-effort */ }
            fulfill_order($orderId);
            header('Location: order-success.php?order=' . urlencode($orderNumber));
            exit;
        }
    }
}

$checkoutHeader = true;
include __DIR__ . '/includes/header.php';
?>
<div class="checkout-canvas">
<div class="container py-3 pb-4" style="max-width: 1180px;">
  <!-- Checkout flow stepper -->
  <div class="checkout-steps d-flex align-items-center mb-3 flex-wrap" data-testid="checkout-steps">
    <div class="step done">
      <span class="step-dot"><i class="bi bi-cart3"></i></span><span class="step-label">Cart</span>
    </div>
    <span class="step-line done"></span>
    <div class="step active">
      <span class="step-dot"><i class="bi bi-credit-card"></i></span><span class="step-label">Checkout</span>
    </div>
    <span class="step-line"></span>
    <div class="step">
      <span class="step-dot"><i class="bi bi-check2-circle"></i></span><span class="step-label">Done</span>
    </div>
    <a href="cart.php" class="ms-auto text-decoration-none small back-to-cart"><i class="bi bi-arrow-left me-1"></i>Back to Cart</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if (setting_get('gw_mode', 'test') !== 'live'): ?>
    <!-- Payment gateway is currently in TEST mode — make that crystal clear
         to whoever is on the page (typically the admin doing a dry run). -->
    <div class="alert alert-warning small mb-3 d-flex align-items-start gap-2"
         data-testid="checkout-test-mode-banner"
         style="border-radius:12px;line-height:1.5;font-weight:600;border:1px solid #f59e0b;background:linear-gradient(135deg,rgba(245,158,11,.08),rgba(234,88,12,.08));">
      <i class="bi bi-eyedropper text-warning mt-1" style="font-size:18px;"></i>
      <div class="flex-grow-1">
        <span class="badge bg-warning text-dark me-1" style="font-size:10.5px;letter-spacing:1px;">TEST MODE</span>
        Payments are not charged in this environment.  The order will be created and emails will be delivered, but no real money moves. Switch to <a href="/admin.php?tab=api&gw=toggles" class="alert-link">Live</a> from Admin → API to start processing real payments.
      </div>
      <button type="button" class="btn btn-sm btn-warning ms-2 flex-shrink-0" onclick="openTestPreview()" data-testid="checkout-test-preview-btn" style="font-weight:600;white-space:nowrap;">
        <i class="bi bi-eye me-1"></i>Preview test order
      </button>
    </div>

    <!-- Test-mode preview modal — opened by the button above. Shows the
         3 things the user wants to verify before going to the test
         gateway: customer success-page link, customer email body, and
         admin notification copy. -->
    <div class="modal fade" id="testPreviewModal" tabindex="-1" aria-hidden="true" data-testid="test-preview-modal">
      <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-eye me-2 text-warning"></i>Test-mode order preview</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="testPreviewBody">
            <div class="text-center py-5"><div class="spinner-border text-primary"></div><div class="small text-secondary mt-2">Building preview…</div></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-testid="test-preview-close">Close</button>
            <button type="button" class="btn btn-warning" onclick="document.getElementById('testPreviewModal') && bootstrap.Modal.getInstance(document.getElementById('testPreviewModal')).hide(); setTimeout(()=>document.querySelector('form.row.g-3.align-items-start').requestSubmit(), 200);" data-testid="test-preview-confirm">
              <i class="bi bi-check2-circle me-1"></i>Continue with test payment
            </button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3 align-items-start">
    <input type="hidden" name="pro" value="<?= $proAssist ? '1' : '0' ?>">
    <input type="hidden" name="payment_method" id="payment-method-input" value="card">
    <input type="hidden" name="email_override" id="email-override-input" value="0">
    <input type="hidden" name="address_override" id="address-override-input" value="0">

    <!-- Right column: Order Summary (receipt style) -->
    <div class="col-lg-5 order-lg-2">
    <div class="card co-banner co-summary-sticky p-3 position-relative" data-testid="co-banner-summary">
      <div id="checkout-summary">
      <?php include __DIR__ . '/includes/checkout-summary-partial.php'; ?>
      </div>
    </div>
    </div>

    <!-- Left column: Your Details + Payment -->
    <div class="col-lg-7 order-lg-1 d-grid gap-3">
    <!-- Banner 2: Contact Information -->
    <div class="card co-banner p-3" data-testid="co-banner-contact">
      <div class="co-head d-flex align-items-center gap-3 mb-3">
        <span class="co-num">1</span>
        <div class="lh-sm">
          <h6 class="fw-bold mb-0">Your Details</h6>
          <small class="text-secondary">License key goes to your email · address is for payment verification only</small>
        </div>
        <i class="bi bi-person-vcard co-head-icon ms-auto"></i>
      </div>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Email Address *</label>
          <input type="email" name="email" required class="form-control" value="<?= esc($_POST['email'] ?? '') ?>" data-testid="checkout-email" id="checkout-email">
          <div id="checkout-email-hint" class="checkout-hint" style="display:none;" data-testid="checkout-email-hint"></div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone Number *</label>
          <?php
          $phoneFlags = ['+1' => '🇺🇸', '+44' => '🇬🇧', '+61' => '🇦🇺', '+49' => '🇩🇪', '+33' => '🇫🇷', '+34' => '🇪🇸', '+39' => '🇮🇹', '+31' => '🇳🇱', '+91' => '🇮🇳', '+971' => '🇦🇪', '+64' => '🇳🇿'];
          // Region the form should default to: a re-displayed form (validation
          // error) keeps the POSTed country; a fresh form follows the storefront
          // region derived from the active currency.
          $formCC = strtoupper(trim($_POST['country'] ?? '')) ?: $curRegionCC;
          if (!isset($REGION_FORMS[$formCC])) $formCC = 'US';
          $rf = $REGION_FORMS[$formCC];
          $selCode = $_POST['phone_code'] ?? $rf['dial'];
          ?>
          <div class="input-group phone-group">
            <span class="input-group-text phone-flag" id="phone-flag" data-testid="phone-flag"><?= $phoneFlags[$selCode] ?? '🇺🇸' ?></span>
            <select name="phone_code" id="phone-code" class="form-select phone-code" style="max-width:90px;" onchange="syncPhoneFlag(this)" data-testid="phone-code-select">
              <?php foreach ($phoneFlags as $code => $flag): ?>
                <option value="<?= $code ?>" data-flag="<?= $flag ?>" <?= $selCode === $code ? 'selected' : '' ?>><?= $code ?></option>
              <?php endforeach; ?>
            </select>
            <input name="phone" required class="form-control" value="<?= esc($_POST['phone'] ?? '') ?>" data-testid="phone-number-input">
          </div>
        </div>
        <div class="col-md-6"><label class="form-label">First Name *</label><input name="first_name" required class="form-control" value="<?= esc($_POST['first_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Last Name *</label><input name="last_name" required class="form-control" value="<?= esc($_POST['last_name'] ?? '') ?>"></div>
        <div class="col-md-8"><label class="form-label">Address *</label><input name="address" required class="form-control" value="<?= esc($_POST['address'] ?? '') ?>" id="checkout-address" data-testid="checkout-address">
          <div id="checkout-address-hint" class="checkout-hint" style="display:none;" data-testid="checkout-address-hint"></div>
        </div>
        <div class="col-md-4"><label class="form-label">Address Line 2</label><input name="address2" class="form-control" value="<?= esc($_POST['address2'] ?? '') ?>"></div>
        <div class="col-md-3 col-6">
          <label class="form-label">Country *</label>
          <select name="country" id="co-country" class="form-select" onchange="mvApplyCheckoutRegion(this.value)" data-testid="country-select">
            <?php foreach (['US' => 'United States', 'CA' => 'Canada', 'UK' => 'United Kingdom', 'AU' => 'Australia', 'EU' => 'Europe (Other)'] as $c => $n): ?>
              <option value="<?= $c ?>" <?= $formCC === $c ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-6"><label class="form-label">City *</label><input name="city" required class="form-control" value="<?= esc($_POST['city'] ?? '') ?>"></div>
        <div class="col-md-3 col-6" id="co-region-wrap">
          <label class="form-label" id="co-region-label"><?= esc($rf['region_label']) ?><?= $rf['region_required'] ? ' *' : '' ?></label>
          <?php if (!empty($rf['regions'])): ?>
          <select name="state" <?= $rf['region_required'] ? 'required' : '' ?> class="form-select" data-testid="state-select">
            <option value="">Select</option>
            <?php foreach ($rf['regions'] as $st): ?>
              <option value="<?= $st ?>" <?= ($_POST['state'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input name="state" <?= $rf['region_required'] ? 'required' : '' ?> class="form-control" value="<?= esc($_POST['state'] ?? '') ?>" data-testid="state-select" placeholder="<?= esc($rf['region_label']) ?>">
          <?php endif; ?>
        </div>
        <div class="col-md-3 col-6"><label class="form-label" id="co-postal-label"><?= esc($rf['postal_label']) ?> *</label><input name="zip" required class="form-control" value="<?= esc($_POST['zip'] ?? '') ?>" id="co-postal" placeholder="<?= esc($rf['postal_ph']) ?>" data-testid="zip-input"></div>
        <div class="col-12">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="sms_consent" id="sms-consent" value="1" <?= !empty($_POST['sms_consent']) ? 'checked' : '' ?> data-testid="sms-consent">
            <label class="form-check-label text-secondary" for="sms-consent" style="font-size:.72rem;">I agree to receive SMS order updates &amp; delivery notifications from <?= SITE_BRAND ?>. Msg &amp; data rates may apply. Reply STOP to opt out.</label>
          </div>
        </div>
      </div>
    </div>

    <!-- Banner 4: Payment — short & sweet -->
    <div class="card co-banner p-3" data-testid="co-banner-payment">
      <div class="co-head d-flex align-items-center gap-3 mb-3">
        <span class="co-num">2</span>
        <div class="lh-sm">
          <h6 class="fw-bold mb-0">Payment</h6>
          <small class="text-secondary">All transactions are secure and encrypted</small>
        </div>
        <i class="bi bi-shield-lock co-head-icon ms-auto"></i>
      </div>
      <?php $_cardEnabled = card_enabled(); $_paypalEnabled = paypal_enabled(); ?>
      <?php if (!$_cardEnabled && !$_paypalEnabled): ?>
        <div class="alert alert-warning mb-3" data-testid="checkout-no-methods"><i class="bi bi-exclamation-triangle me-2"></i>No payment methods are currently available. Please contact support.</div>
      <?php endif; ?>
      <div class="row g-2 mb-2">
        <?php if ($_cardEnabled): ?>
        <div class="<?= $_paypalEnabled ? 'col-sm-6' : 'col-12' ?>">
          <div id="pay-card" class="pay-option pay-tile active p-2 h-100" onclick="selectPayMethod('card')" data-testid="pay-method-card">
            <div class="d-flex align-items-center gap-2">
              <input type="radio" class="form-check-input mt-0" name="pm_radio" checked onclick="selectPayMethod('card')">
              <i class="bi bi-credit-card-2-front text-primary fs-5"></i>
              <span class="fw-bold">Card</span>
            </div>
            <div class="d-flex gap-1 mt-2 ps-4">
              <img src="assets/images/payments/visa.svg" alt="Visa" class="pay-icon pay-icon-sm"><img src="assets/images/payments/mastercard.svg" alt="Mastercard" class="pay-icon pay-icon-sm"><img src="assets/images/payments/amex.svg" alt="American Express" class="pay-icon pay-icon-sm"><img src="assets/images/payments/discover.svg" alt="Discover" class="pay-icon pay-icon-sm">
            </div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($_paypalEnabled): ?>
        <div class="<?= $_cardEnabled ? 'col-sm-6' : 'col-12' ?>">
          <div id="pay-paypal" class="pay-option pay-tile paypal p-2 h-100" onclick="selectPayMethod('paypal')" data-testid="pay-method-paypal">
            <div class="d-flex align-items-center gap-2">
              <input type="radio" class="form-check-input mt-0" name="pm_radio" onclick="selectPayMethod('paypal')">
              <img src="assets/images/payments/paypal.svg" alt="PayPal" class="pay-icon pay-icon-sm">
              <span class="fw-bold"><span class="fst-italic" style="color:#003087">Pay</span><span class="fst-italic" style="color:#0070BA">Pal</span></span>
            </div>
            <small class="text-secondary d-block mt-2 ps-4">Checkout with your PayPal account</small>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <!-- Card details drop-down (shown when Card selected). Fields have NO name attrs —
           they are never posted to our server; the charge is confirmed on Stripe's PCI-compliant page. -->
      <div id="card-form" class="card-form-reveal mb-2" data-testid="card-details-form">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Card Number</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-credit-card-2-front text-primary"></i></span>
              <input id="card-number" class="form-control" inputmode="numeric" autocomplete="cc-number" maxlength="19" data-testid="card-number-input">
              <span class="input-group-text card-brands" id="card-brands" data-testid="card-brand-icons">
                <img src="assets/images/payments/visa.svg" alt="Visa" data-brand="visa" class="card-brand-icon">
                <img src="assets/images/payments/mastercard.svg" alt="Mastercard" data-brand="mastercard" class="card-brand-icon">
                <img src="assets/images/payments/amex.svg" alt="American Express" data-brand="amex" class="card-brand-icon">
                <img src="assets/images/payments/discover.svg" alt="Discover" data-brand="discover" class="card-brand-icon">
              </span>
            </div>
          </div>
          <div class="col-7">
            <label class="form-label">Expiry Date</label>
            <input id="card-exp" class="form-control" inputmode="numeric" autocomplete="cc-exp" maxlength="5" data-testid="card-exp-input">
          </div>
          <div class="col-5">
            <label class="form-label">CVV</label>
            <div class="input-group">
              <input id="card-cvv" type="password" class="form-control" inputmode="numeric" autocomplete="cc-csc" maxlength="4" data-testid="card-cvv-input">
              <span class="input-group-text" title="3-4 digit code on the back of your card"><i class="bi bi-question-circle text-secondary"></i></span>
            </div>
          </div>
        </div>
        <div class="small text-secondary mt-2"><i class="bi bi-shield-lock-fill text-success me-1"></i>Your card is verified &amp; charged on Stripe's PCI-compliant secure page — we never store card data.</div>
      </div>
      <?php if ($_cardEnabled): ?>
      <button id="btn-pay-card" type="submit" class="btn btn-primary btn-lg rounded-pill w-100" data-testid="checkout-pay-button">Pay Securely · <?= format_price($total) ?></button>
      <?php endif; ?>
      <?php if ($_paypalEnabled): ?>
      <button id="btn-pay-paypal" type="submit" class="btn btn-paypal btn-lg rounded-pill w-100 <?= $_cardEnabled ? 'd-none' : '' ?>" data-testid="checkout-paypal-button"><span class="fst-italic" style="color:#003087">Pay</span><span class="fst-italic" style="color:#0070BA">Pal</span> · Continue <?= format_price($total) ?></button>
      <?php endif; ?>
      <div class="text-center small text-secondary mt-2"><i class="bi bi-shield-lock me-1"></i>256-bit SSL · Powered by Stripe — card details are entered on the secure payment page</div>
      <div class="text-center mt-1" style="font-size:.72rem;">By placing your order, you agree to our <a href="page.php?slug=terms-of-service">Terms</a> and <a href="page.php?slug=privacy-policy">Privacy Policy</a></div>
    </div>
    </div>
  </form>
</div>
</div>

<style>
/* Checkout entrance + Pay button accent */
@keyframes coFadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
.co-summary-sticky { animation: coFadeUp .5s cubic-bezier(.22,.61,.36,1) both; }
@media (prefers-reduced-motion: reduce) { .co-summary-sticky { animation: none; } }
#btn-pay-card { position: relative; overflow: hidden; }
#btn-pay-card::after {
  content: ""; position: absolute; top: 0; left: -60%; width: 50%; height: 100%;
  background: linear-gradient(100deg, transparent, rgba(255,255,255,.45), transparent);
  transform: skewX(-18deg); animation: coPaySheen 2.6s ease-in-out infinite;
}
@keyframes coPaySheen { 0% { left:-60%; } 55%,100% { left:130%; } }
#btn-pay-card:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(13,110,253,.32); }
#btn-pay-card { transition: transform .15s ease, box-shadow .15s ease; }
@media (prefers-reduced-motion: reduce) { #btn-pay-card::after { animation: none; display:none; } }
.checkout-hint {
  margin-top: 6px; padding: 8px 12px; border-radius: 8px;
  font-size: 12.5px; line-height: 1.45;
  border-left: 3px solid;
  background: #fffbeb; border-color: #f59e0b; color: #92400e;
}
[data-bs-theme="dark"] .checkout-hint { background: #2a1f0c; color: #fde68a; }
.checkout-hint.is-ok { background:#ecfdf5; border-color:#10b981; color:#047857; }
.checkout-hint .hint-btn {
  display:inline-block; margin-left:8px; padding:3px 10px;
  border-radius:999px; font-size:11.5px; font-weight:700;
  border:1px solid currentColor; background:transparent; cursor:pointer;
  text-decoration:none;
}
.checkout-hint .hint-btn:hover { background: currentColor; color:#fff; }
.checkout-hint .hint-btn.is-primary { background: #f59e0b; color:#fff; border-color:#f59e0b; }
.checkout-hint .hint-btn.is-primary:hover { background:#d97706; border-color:#d97706; }
</style>
<script>
/* Region-aware checkout address form. The PHP $REGION_FORMS config is the
   single source of truth; this mirrors it client-side so changing the Country
   select instantly reshapes the State/Province/County field, its label, the
   Postal/ZIP label + placeholder and the phone dial code — without a reload. */
window.MV_REGION_FORMS = <?= json_encode($REGION_FORMS) ?>;
function mvApplyCheckoutRegion(cc) {
  var rf = window.MV_REGION_FORMS[cc] || window.MV_REGION_FORMS['US'];
  if (!rf) return;
  // --- Region (state/province/county) field: rebuild as <select> or <input> ---
  var wrap = document.getElementById('co-region-wrap');
  if (wrap) {
    var prev = '';
    var prevEl = wrap.querySelector('[name="state"]');
    if (prevEl) prev = prevEl.value;
    var reqAttr = rf.region_required ? ' required' : '';
    var html = '<label class="form-label" id="co-region-label">' + rf.region_label + (rf.region_required ? ' *' : '') + '</label>';
    if (rf.regions && rf.regions.length) {
      html += '<select name="state"' + reqAttr + ' class="form-select" data-testid="state-select"><option value="">Select</option>';
      rf.regions.forEach(function (s) {
        html += '<option value="' + s + '"' + (s === prev ? ' selected' : '') + '>' + s + '</option>';
      });
      html += '</select>';
    } else {
      html += '<input name="state"' + reqAttr + ' class="form-control" value="' + (prev || '').replace(/"/g, '&quot;') + '" data-testid="state-select" placeholder="' + rf.region_label + '">';
    }
    wrap.innerHTML = html;
  }
  // --- Postal / ZIP label + placeholder ---
  var pl = document.getElementById('co-postal-label');
  if (pl) pl.textContent = rf.postal_label + ' *';
  var pin = document.getElementById('co-postal');
  if (pin) pin.setAttribute('placeholder', rf.postal_ph || '');
  // --- Phone dial code + flag ---
  var pc = document.getElementById('phone-code');
  if (pc && rf.dial) {
    for (var i = 0; i < pc.options.length; i++) {
      if (pc.options[i].value === rf.dial) { pc.selectedIndex = i; break; }
    }
    if (typeof syncPhoneFlag === 'function') syncPhoneFlag(pc);
  }
}
</script>

<script>
(function(){
  // ===== Email field — DNS MX + typo dictionary check on blur =====
  const $email = document.getElementById('checkout-email');
  const $emailHint = document.getElementById('checkout-email-hint');
  let _emailOverride = false;
  function showEmailHint(html, isOk) {
    if (!$emailHint) return;
    $emailHint.innerHTML = html;
    $emailHint.className = 'checkout-hint' + (isOk ? ' is-ok' : '');
    $emailHint.style.display = 'block';
  }
  function clearEmailHint(){ if($emailHint){ $emailHint.style.display='none'; $emailHint.innerHTML=''; } }
  async function checkEmail() {
    if (!$email) return;
    const val = $email.value.trim();
    if (!val) { clearEmailHint(); return; }
    if (_emailOverride) return; // user explicitly chose to use this address
    try {
      const r = await fetch('ajax/email-check.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ email: val }),
      });
      const j = await r.json();
      if (j.ok) { clearEmailHint(); return; }
      const suggest = (j.suggest && j.suggest !== val) ? j.suggest : '';
      let html = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Heads up:</strong> ' + (j.detail || 'This email looks undeliverable.');
      if (suggest) {
        html += ' <button type="button" class="hint-btn is-primary" data-testid="email-hint-accept">Use <strong>' + suggest + '</strong></button>';
      }
      html += ' <button type="button" class="hint-btn" data-testid="email-hint-override">Use my address anyway</button>';
      showEmailHint(html, false);
      // Hook up the buttons
      const accept = $emailHint.querySelector('[data-testid="email-hint-accept"]');
      if (accept) accept.addEventListener('click', () => { $email.value = suggest; clearEmailHint(); _emailOverride = false; });
      const override = $emailHint.querySelector('[data-testid="email-hint-override"]');
      if (override) override.addEventListener('click', () => {
        _emailOverride = true;
        const flag = document.getElementById('email-override-input');
        if (flag) flag.value = '1';
        showEmailHint("<i class='bi bi-check2-circle me-1'></i>Got it — we'll deliver to <strong>" + val + "</strong>.", true);
      });
    } catch (_) { /* network hiccup, skip silently */ }
  }
  if ($email) {
    $email.addEventListener('blur', checkEmail);
    $email.addEventListener('input', () => {
      _emailOverride = false;
      const flag = document.getElementById('email-override-input');
      if (flag) flag.value = '0';
      clearEmailHint();
    });
  }

  // ===== Address field — basic completeness sanity-check on blur =====
  const $addr = document.getElementById('checkout-address');
  const $addrHint = document.getElementById('checkout-address-hint');
  let _addrOverride = false;
  function clearAddrHint(){ if($addrHint){ $addrHint.style.display='none'; $addrHint.innerHTML=''; } }
  function checkAddress() {
    if (!$addr) return;
    const val = $addr.value.trim();
    if (!val || _addrOverride) { clearAddrHint(); return; }
    const issues = [];
    // Must have a street number — almost every shippable / billing address
    // starts with one.  P.O. Box is allowed.
    if (!/\d/.test(val) && !/p\.?\s*o\.?\s*box/i.test(val)) {
      issues.push('No street number detected');
    }
    if (val.length < 6) issues.push('Address looks too short');
    if (/^[a-z]+$/i.test(val)) issues.push('Just letters — missing street/number?');
    if (!issues.length) { clearAddrHint(); return; }
    $addrHint.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Double-check this address:</strong> ' + issues.join(', ') + '.'
      + ' <button type="button" class="hint-btn" data-testid="addr-hint-override">Use this address anyway</button>';
    $addrHint.className = 'checkout-hint';
    $addrHint.style.display = 'block';
    const o = $addrHint.querySelector('[data-testid="addr-hint-override"]');
    if (o) o.addEventListener('click', () => {
      _addrOverride = true;
      const flag = document.getElementById('address-override-input');
      if (flag) flag.value = '1';
      $addrHint.innerHTML = "<i class='bi bi-check2-circle me-1'></i>Got it — using the address as entered.";
      $addrHint.className = 'checkout-hint is-ok';
    });
  }
  if ($addr) {
    $addr.addEventListener('blur', checkAddress);
    $addr.addEventListener('input', () => {
      _addrOverride = false;
      const flag = document.getElementById('address-override-input');
      if (flag) flag.value = '0';
      clearAddrHint();
    });
  }

  // ===== Strict form-level submit guard (blocks payment on invalid data) =====
  // - Email: RFC valid + not currently flagged undeliverable (unless overridden).
  // - Billing address: street number / length sanity (unless overridden).
  // - Card (only when Card payment method is selected): Luhn pass on number,
  //   MM/YY in the future, 3-4 digit CVV.  Card details are NEVER posted to
  //   our server (no `name` attr); this is purely a UX guard to stop bad
  //   data from being forwarded to the payment processor.
  const form = document.querySelector('form[method="post"]');
  const submitBtns = form ? form.querySelectorAll('button[type="submit"]') : [];

  function setFieldError($input, msg) {
    if (!$input) return;
    $input.classList.add('is-invalid');
    let $hint = $input.parentElement.querySelector('.field-err');
    if (!$hint) {
      $hint = document.createElement('div');
      $hint.className = 'field-err invalid-feedback d-block';
      $input.parentElement.appendChild($hint);
    }
    $hint.textContent = msg;
  }
  function clearFieldError($input) {
    if (!$input) return;
    $input.classList.remove('is-invalid');
    const $hint = $input.parentElement.querySelector('.field-err');
    if ($hint) $hint.remove();
  }
  // Live-clear errors when the user starts editing again.
  document.querySelectorAll('input, select').forEach(el => {
    el.addEventListener('input', () => clearFieldError(el));
    el.addEventListener('change', () => clearFieldError(el));
  });

  function luhnValid(digits) {
    if (!/^\d{13,19}$/.test(digits)) return false;
    let sum = 0, alt = false;
    for (let i = digits.length - 1; i >= 0; i--) {
      let n = parseInt(digits.charAt(i), 10);
      if (alt) { n *= 2; if (n > 9) n -= 9; }
      sum += n; alt = !alt;
    }
    return sum % 10 === 0;
  }
  function expiryValid(val) {
    const m = /^(\d{2})\/(\d{2})$/.exec((val || '').trim());
    if (!m) return false;
    const mm = parseInt(m[1], 10), yy = parseInt(m[2], 10);
    if (mm < 1 || mm > 12) return false;
    const now = new Date();
    const curYY = now.getFullYear() % 100;
    const curMM = now.getMonth() + 1;
    if (yy < curYY) return false;
    if (yy === curYY && mm < curMM) return false;
    return true;
  }

  async function validateBeforeSubmit() {
    let firstBadEl = null;
    const mark = (el, msg) => { setFieldError(el, msg); if (!firstBadEl) firstBadEl = el; };

    // --- Email ---
    const emailVal = ($email && $email.value || '').trim();
    if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(emailVal)) {
      mark($email, 'Please enter a valid email address.');
    } else if ($emailHint && $emailHint.style.display === 'block' && !$emailHint.classList.contains('is-ok')) {
      mark($email, 'This email looks undeliverable. Correct it or click "Use my address anyway".');
    }

    // --- Billing address: street number / length / not just letters ---
    const addrVal = ($addr && $addr.value || '').trim();
    if (!addrVal) {
      mark($addr, 'Billing address is required.');
    } else if (!_addrOverride) {
      const noNum = !/\d/.test(addrVal) && !/p\.?\s*o\.?\s*box/i.test(addrVal);
      const tooShort = addrVal.length < 6;
      const justLetters = /^[a-z\s]+$/i.test(addrVal);
      if (noNum || tooShort || justLetters) {
        mark($addr, 'Address looks incomplete — include a street number, or override the warning.');
      }
    }

    // --- City / State / ZIP / Country / First / Last / Phone ---
    const requiredMap = [
      ['input[name="first_name"]', 'First name is required.'],
      ['input[name="last_name"]',  'Last name is required.'],
      ['input[name="phone"]',      'Phone number is required.'],
      ['input[name="city"]',       'City is required.'],
      ['select[name="country"]',   'Country is required.'],
    ];
    requiredMap.forEach(([sel, msg]) => {
      const el = document.querySelector(sel);
      if (el && !el.value.trim()) mark(el, msg);
    });
    // State / province / county — required only for regions that have a fixed list.
    const stateEl = document.querySelector('[name="state"]');
    const countryEl = document.querySelector('select[name="country"]');
    const rf = (window.MV_REGION_FORMS || {})[countryEl ? countryEl.value : 'US'] || {};
    if (stateEl && rf.region_required && !stateEl.value.trim()) {
      mark(stateEl, 'Please select a ' + (rf.region_label || 'state').toLowerCase() + '.');
    }

    // Postal / ZIP sanity by the selected country's format.
    const zip = document.querySelector('input[name="zip"]');
    if (zip && zip.value.trim()) {
      const z = zip.value.trim();
      if (rf.postal_re) {
        try {
          if (!(new RegExp(rf.postal_re)).test(z)) mark(zip, rf.postal_err || 'Postal code looks invalid.');
        } catch (e) { if (z.length < 3) mark(zip, 'Postal code is too short.'); }
      } else if (z.length < 3) {
        mark(zip, 'Postal code is too short.');
      }
    }
    // Phone — at least 7 digits in the local part.
    const phone = document.querySelector('input[name="phone"]');
    if (phone && phone.value.trim()) {
      const digits = phone.value.replace(/\D/g, '');
      if (digits.length < 7) mark(phone, 'Phone number is too short.');
    }

    // --- Card details (only when Card is the selected method) ---
    const pmInput = document.getElementById('payment-method-input');
    const usingCard = pmInput && pmInput.value === 'card';
    if (usingCard) {
      const $cardNum = document.getElementById('card-number');
      const $cardExp = document.getElementById('card-exp');
      const $cardCvv = document.getElementById('card-cvv');
      if ($cardNum) {
        const digits = ($cardNum.value || '').replace(/\D/g, '');
        if (!digits) mark($cardNum, 'Enter your card number.');
        else if (!luhnValid(digits)) mark($cardNum, 'Card number is invalid — please double-check.');
      }
      if ($cardExp && !expiryValid($cardExp.value)) {
        mark($cardExp, 'Expiry must be MM/YY and in the future.');
      }
      if ($cardCvv) {
        const cvv = ($cardCvv.value || '').trim();
        if (!/^\d{3,4}$/.test(cvv)) mark($cardCvv, 'CVV must be 3 or 4 digits.');
      }
    }

    return firstBadEl;
  }

  if (form) {
    form.addEventListener('submit', async (e) => {
      // Allow Submit to proceed if no errors; else block and focus the first bad field.
      e.preventDefault();
      const bad = await validateBeforeSubmit();
      if (bad) {
        bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
        try { bad.focus({ preventScroll: true }); } catch(_) { bad.focus(); }
        // Show a friendly summary toast (uses existing showToast() helper from main.js).
        if (typeof showToast === 'function') {
          showToast('<i class="bi bi-exclamation-triangle me-1"></i> Please fix the highlighted fields before continuing.');
        }
        return false;
      }
      // No errors — disable the buttons (prevent double-submit) and let the form go.
      submitBtns.forEach(b => { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing…'; });
      form.submit();
    });
  }
})();

/* ---------- Test-mode preview (only shown when gw_mode = test) ---------- */
async function openTestPreview() {
  const modalEl = document.getElementById('testPreviewModal');
  const body    = document.getElementById('testPreviewBody');
  if (!modalEl || !body) return;
  body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><div class="small text-secondary mt-2">Building preview…</div></div>';
  const m = new bootstrap.Modal(modalEl);
  m.show();

  // Collect what the customer has typed so the preview emails/admin
  // alerts reflect their actual contact info (falls back to demo data
  // if a field is still empty).
  const fd = new FormData();
  ['first_name', 'last_name', 'email', 'phone', 'country', 'pro'].forEach(k => {
    const el = document.querySelector(`[name="${k}"]`);
    if (el && el.value) fd.append(k, el.value);
  });

  let data;
  try {
    const r = await fetch('ajax/checkout-test-preview.php' + (window.location.search || ''), { method: 'POST', body: fd, credentials: 'same-origin' });
    data = await r.json();
  } catch (e) {
    body.innerHTML = '<div class="alert alert-danger">Failed to build preview. Try again.</div>';
    return;
  }
  if (!data || !data.ok) {
    body.innerHTML = '<div class="alert alert-danger">' + ((data && data.error) || 'Unable to build preview.') + '</div>';
    return;
  }

  // Render three side-by-side preview panels using vanilla bootstrap markup.
  body.innerHTML = `
    <ul class="nav nav-tabs mb-3" role="tablist" data-testid="test-preview-tabs">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tp-customer" type="button" role="tab"><i class="bi bi-person-check me-1"></i>Customer screen</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tp-email" type="button" role="tab"><i class="bi bi-envelope me-1"></i>Customer email</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tp-admin" type="button" role="tab"><i class="bi bi-shield-lock me-1"></i>Admin alert</button></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tp-customer" role="tabpanel">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
          <div>
            <div class="fw-bold">What the customer sees after paying</div>
            <small class="text-secondary">Order success page rendered with the test-order data</small>
          </div>
          <a href="${data.success_url}" target="_blank" class="btn btn-sm btn-outline-primary" data-testid="test-preview-open-success">Open in new tab <i class="bi bi-box-arrow-up-right ms-1"></i></a>
        </div>
        <div class="border rounded" style="overflow:hidden;background:#f8fafc;">
          <iframe src="${data.success_url}" style="width:100%;height:520px;border:0;display:block;" data-testid="test-preview-success-iframe"></iframe>
        </div>
      </div>
      <div class="tab-pane fade" id="tp-email" role="tabpanel">
        <div class="row g-2 small mb-2">
          <div class="col-md-3 text-secondary">To:</div><div class="col-md-9"><strong data-testid="test-preview-email-to">${data.email_to}</strong></div>
          <div class="col-md-3 text-secondary">Subject:</div><div class="col-md-9"><strong data-testid="test-preview-email-subj">${escapeHtml(data.email_subj)}</strong></div>
        </div>
        <div class="border rounded" style="overflow:hidden;background:#fff;">
          <iframe srcdoc="${data.email_html.replace(/"/g, '&quot;')}" style="width:100%;height:520px;border:0;display:block;background:#fff;" data-testid="test-preview-email-iframe"></iframe>
        </div>
      </div>
      <div class="tab-pane fade" id="tp-admin" role="tabpanel">
        <div class="row g-2 small mb-3">
          <div class="col-md-3 text-secondary">Delivered to:</div><div class="col-md-9"><strong data-testid="test-preview-admin-to">${data.admin_to}</strong></div>
        </div>
        <div class="border rounded p-3 mb-3" style="background:#f8fafc;">
          <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px;height:42px;background:#1565C0;color:#fff;"><i class="bi bi-bell-fill"></i></div>
            <div class="flex-grow-1">
              <div class="fw-bold" data-testid="test-preview-admin-title">${escapeHtml(data.admin_title)}</div>
              <div class="text-secondary small" data-testid="test-preview-admin-body">${escapeHtml(data.admin_body)}</div>
              <a href="${data.admin_url}" class="btn btn-sm btn-link p-0 mt-1" data-testid="test-preview-admin-link">Open in admin panel <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
          </div>
        </div>
        <div class="small text-secondary">
          <i class="bi bi-info-circle me-1"></i>Two alerts are fired per order — one tagged <strong>Orders</strong> (above) and one tagged <strong>Sales Detail</strong> in the admin bell, so you can filter them later.
        </div>
      </div>
    </div>
  `;
}
function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>

<?php /* begin_checkout — fires once when the user lands on the checkout
        page.  Whichever tracker(s) are configured will receive the event. */ ?>
<?php
$tk_ga4_c = trim((string)setting_get('ga4_measurement_id', ''));
$tk_uet_c = trim((string)setting_get('bing_uet_tag_id', ''));
if (($tk_ga4_c !== '' || $tk_uet_c !== '') && !empty(cart())):
    $cartItemsJs = [];
    foreach (cart() as $slug => $line) {
        $cartItemsJs[] = [
            'item_id'   => (string)$slug,
            'item_name' => (string)($line['name'] ?? $slug),
            'price'     => round((float)($line['price'] ?? 0), 2),
            'quantity'  => (int)($line['qty'] ?? 1),
        ];
    }
    $checkoutCurrency = (string)(current_currency()['code'] ?? 'USD');
    $checkoutTotal    = round((float)cart_subtotal(), 2);
?>
<script>
(function(){
  var items = <?= json_encode($cartItemsJs) ?>;
  var total = <?= json_encode($checkoutTotal) ?>;
  var currency = <?= json_encode($checkoutCurrency) ?>;
  <?php if ($tk_ga4_c !== ''): ?>
  if (typeof gtag === 'function') {
    gtag('event', 'begin_checkout', { currency: currency, value: total, items: items });
  }
  <?php endif; ?>
  <?php if ($tk_uet_c !== ''): ?>
  if (window.uetq) {
    window.uetq.push('event', 'begin_checkout', {
      event_category: 'ecommerce', event_label: 'checkout_started',
      revenue_value: total, currency: currency
    });
  }
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
