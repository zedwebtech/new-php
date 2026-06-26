<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/stripe.php';
$pageTitle = 'Order Confirmed | ' . SITE_BRAND;

$orderNumber = $_GET['order'] ?? '';
$sessionId = $_GET['session_id'] ?? '';
$order = null;
$isDemo = (($_GET['demo'] ?? '') === '1');
if ($isDemo) {
    // Synthesised order for the test-preview iframe on /checkout.php.
    // No DB write — purely a render preview.
    $items = cart_items();
    $subtotal = cart_subtotal();
    $order = [
        'id'           => 0,
        'order_number' => $orderNumber ?: 'TEST-' . date('YmdHis'),
        'email'        => 'customer@example.com',
        'first_name'   => 'Sample',
        'last_name'    => 'Customer',
        'phone'        => '+1-555-000-0123',
        'address'      => '123 Demo Street',
        'city'         => 'San Francisco',
        'state'        => 'CA',
        'zip'          => '94107',
        'country'      => 'US',
        'subtotal'     => $subtotal,
        'total'        => $subtotal,
        'currency'     => current_currency()['code'],
        'payment_method' => 'card',
        'status'       => 'paid',
        'fulfilled'    => 1,
        'pro_assist'   => 0,
        'gw_mode'      => 'test',
        'created_at'   => date('Y-m-d H:i:s'),
    ];
} elseif ($orderNumber) {
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_number = ?');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
}

// Direct download of the Subscription Details PDF straight from the order
// confirmation page (?order=…&dl=subscription) — convenience link next to
// the receipt/invoice for subscription purchases.
if ($order && !$isDemo && ($_GET['dl'] ?? '') === 'subscription' && !empty($order['subscription_plan'])) {
    require_once __DIR__ . '/includes/subscriptions.php';
    require_once __DIR__ . '/includes/pdf.php';
    $cs = db()->prepare("SELECT * FROM customer_subscriptions WHERE order_id=? LIMIT 1");
    $cs->execute([(int)$order['id']]);
    $subRow = $cs->fetch(PDO::FETCH_ASSOC);
    if ($subRow) {
        $plan = sub_plan_get((string)$subRow['plan_slug']) ?: ['name'=>$subRow['plan_name'],'tenure_label'=>'','features'=>[],'devices'=>'','tagline'=>'','duration_months'=>0];
        try {
            $bin = sub_generate_certificate_pdf($order, $subRow, $plan);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Subscription-Details-' . $subRow['customer_id'] . '.pdf"');
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: private, no-store');
            echo $bin; exit;
        } catch (Throwable $e) { error_log('[order-success sub pdf] ' . $e->getMessage()); }
    }
}

// ProAssist auto-chat: if this order has a ProAssist line item, the
// checkout flow already created a chat_lead + seeded an admin "welcome"
// message.  Bind that lead's chat_token to this browser so the chat
// widget auto-opens, skips the lead form, and starts polling for live
// agent replies immediately.
$proChatToken = null;
$proLeadId    = null;
if ($order && !empty($order['pro_assist'])) {
    try {
        $ld = db()->prepare("SELECT id, chat_token FROM chat_leads
                             WHERE email=? AND requested_product='ProAssist Premium Installation'
                             ORDER BY id DESC LIMIT 1");
        $ld->execute([$order['email']]);
        if ($row = $ld->fetch()) {
            $proLeadId    = (int)$row['id'];
            $proChatToken = (string)$row['chat_token'];
            // Re-bind to the current session in case the browser tab
            // switched after Stripe redirect (Stripe rotates session_id).
            $_SESSION['lead_id']    = $proLeadId;
            $_SESSION['chat_token'] = $proChatToken;
        }
    } catch (Throwable $e) { /* best-effort */ }
}

// Returning from Stripe: verify payment, capture admin-safe card details
// (brand, last4, expiry, funding, issuing country, risk score), then fulfill
// (idempotent — fulfill_order is a no-op on already-fulfilled rows).
if ($order && $sessionId && stripe_enabled() && $order['status'] !== 'paid') {
    try {
        $session = stripe_get_session($sessionId);
        if (($session['payment_status'] ?? '') === 'paid' && $order['stripe_session_id'] === $sessionId) {
            // Pull PCI-allowed card details from Stripe + Radar risk data.
            $cd = stripe_extract_card_details($session);
            $upd = db()->prepare("UPDATE orders SET
                status='paid',
                card_brand=?,    card_last4=?,    card_exp=?,
                card_funding=?,  card_country=?,  card_type=?,
                risk_score=?,    risk_level=?,
                payment_intent_id=?, transaction_id=?,
                billing_country=COALESCE(NULLIF(?, ''), billing_country)
                WHERE id=?");
            $upd->execute([
                $cd['card_brand']        ?: $order['card_brand'],
                $cd['card_last4']        ?: $order['card_last4'],
                $cd['card_exp']          ?: $order['card_exp'],
                $cd['card_funding']      ?: $order['card_funding'],
                $cd['card_country']      ?: $order['card_country'],
                $cd['card_type']         ?: $order['card_type'],
                $cd['risk_score'],
                $cd['risk_level'],
                $cd['payment_intent_id'],
                $cd['transaction_id'],
                $cd['billing_country'],
                $order['id'],
            ]);
            fulfill_order((int)$order['id']);
            $order['status'] = 'paid';
            // Refresh the local row so the post-paid template renders the
            // newly-captured card details if needed.
            $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$order['id']]);
            $order = $stmt->fetch() ?: $order;
        }
    } catch (RuntimeException $e) {
        // Show the pending state below; log for diagnostics.
        error_log('Stripe session verify failed for order ' . $order['order_number'] . ': ' . $e->getMessage());
    }
}

include __DIR__ . '/includes/header.php';

// Build the QR-code URL.  When the customer scans the QR on their phone,
// the receipt page (order-history.php) auto-looks up the order via the
// ?email=X&order=Y query string and renders the full delivery view —
// license keys, Sign-in-to-activate, Installation Guide, PDF download.
// This is the exact same data the customer got in their email.
//
// IMPORTANT: we deliberately use site_url() here (which derives the host
// from the current HTTP request) instead of any cached `site_domain_url`
// setting. That way the QR — and the "Or copy the link" button under it —
// always points at the SAME domain the customer is currently viewing the
// receipt on. Otherwise, a stored preview URL in the DB (from when the
// site was built on Emergent) would leak the dev hostname into production
// receipts on maventechsoftware.com.
$qrUrl = '';
$orderItems = [];
if ($order && $order['status'] === 'paid') {
    $qrUrl = rtrim(site_url(), '/')
           . '/order-history.php?email=' . urlencode((string)$order['email'])
           . '&order=' . urlencode((string)$order['order_number']);

    // Pull this order's products + their already-assigned license keys so we
    // can render the same "what you bought + here's your license key + Sign
    // in to activate + View installation guide" card that the email shows.
    // This is the "show more focus on the product" iteration 20 request.
    try {
        if ($isDemo) {
            // Demo preview — use the live cart items + synthetic license keys
            // so the iframe in checkout's test-preview modal looks realistic.
            $orderItems = array_map(function($i) {
                return [
                    'product_slug' => $i['slug'],
                    'name'         => $i['name'],
                    'qty'          => (int)$i['qty'],
                    'price'        => $i['price'],
                    'image'        => $i['image'] ?? '',
                    'brand'        => $i['brand'] ?? '',
                    'activation_url'    => '',
                    'install_guide_url' => '',
                    'license_keys' => array_map(
                        fn($n) => 'XXXXX-XXXXX-XXXXX-' . strtoupper(substr(md5($i['slug'].$n), 0, 5)) . '-DEMO',
                        range(1, (int)$i['qty'])
                    ),
                ];
            }, cart_items());
        } else {
            $stIt = db()->prepare(
                'SELECT oi.product_slug, oi.name, oi.qty, oi.price,
                        p.image, p.brand, p.gtin, p.activation_url, p.install_guide_url, p.installer_url
                 FROM order_items oi
                 LEFT JOIN products p ON p.slug = oi.product_slug
                 WHERE oi.order_id = ?'
            );
            $stIt->execute([(int)$order['id']]);
            $orderItems = $stIt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Attach assigned license keys per item (in insertion order)
            $stKeys = db()->prepare(
                'SELECT product_slug, license_key
                 FROM license_keys
                 WHERE order_id = ? AND status = "sold"
                 ORDER BY id ASC'
            );
            $stKeys->execute([(int)$order['id']]);
            $keysByProduct = [];
            foreach ($stKeys->fetchAll(PDO::FETCH_ASSOC) as $kr) {
                $keysByProduct[$kr['product_slug']][] = $kr['license_key'];
            }
            foreach ($orderItems as &$it) {
                $slug = $it['product_slug'];
                $it['license_keys'] = $keysByProduct[$slug] ?? [];
                if (function_exists('activation_url_for_product')) {
                    $it['activation_url'] = activation_url_for_product(
                        (string)$it['name'],
                        (string)($it['brand'] ?? ''),
                        (string)($it['activation_url'] ?? '')
                    );
                }
            }
            unset($it);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Has any item on this order still not been assigned a license key? If so it's
// a backorder — surface the "delivered within 30 min to 1 hour" reassurance.
// (Subscriptions are not key-based, so they never show this.)
$isSubscriptionOrder = !$isDemo && $order && !empty($order['subscription_plan']);
$hasPendingKey = false;
if (!$isSubscriptionOrder) {
    foreach ($orderItems as $oiChk) {
        if (($oiChk['product_slug'] ?? '') === 'proassist-premium') continue;
        if (empty($oiChk['license_keys'])) { $hasPendingKey = true; break; }
    }
    if (!$isDemo && $order && ($order['delivery_status'] ?? '') === 'pending') $hasPendingKey = true;
}

// Subscription details for the thank-you card (when this is a plan purchase).
$subRow = null; $subPlan = null;
if (!$isDemo && $order && !empty($order['subscription_plan'])) {
    try {
        require_once __DIR__ . '/includes/subscriptions.php';
        $cs = db()->prepare("SELECT * FROM customer_subscriptions WHERE order_id=? LIMIT 1");
        $cs->execute([(int)$order['id']]);
        $subRow = $cs->fetch() ?: null;
        if ($subRow) $subPlan = sub_plan_get((string)$subRow['plan_slug']) ?: ['name' => $subRow['plan_name'], 'tenure_label' => '', 'features' => [], 'devices' => '', 'duration_months' => 0];
    } catch (Throwable $e) { /* non-fatal */ }
}

// Has the customer already reviewed this order? (avoid showing the prompt twice)
$reviewAlreadyDone = false;
$reviewProductName = '';
if (!$isDemo && $order && $order['status'] === 'paid') {
    try {
        $rv = db()->prepare("SELECT cr.submitted_at, cr.product_slug, p.name
                             FROM customer_reviews cr LEFT JOIN products p ON p.slug=cr.product_slug
                             WHERE cr.order_id=? ORDER BY cr.id ASC LIMIT 1");
        $rv->execute([(int)$order['id']]);
        if ($rrow = $rv->fetch()) {
            $reviewAlreadyDone = !empty($rrow['submitted_at']);
            $reviewProductName = (string)($rrow['name'] ?? '');
        }
    } catch (Throwable $e) { /* non-fatal */ }
    if ($reviewProductName === '') {
        foreach ($orderItems as $oiN) {
            if (($oiN['product_slug'] ?? '') === 'proassist-premium') continue;
            $reviewProductName = (string)($oiN['name'] ?? ''); break;
        }
    }
}
?>
<div class="container py-4" style="max-width: 1100px;">
  <?php if ($order && $order['status'] === 'paid'): ?>
  <div class="row g-4 align-items-start" data-testid="order-success-grid">

    <!-- ===== Main content — kept first in source so the review card it
         contains can be captured (ob) and re-rendered inside the left rail
         below.  Placed visually on the RIGHT via Bootstrap order utilities. ===== -->
    <div class="col-12 col-md-8 order-md-2 text-center">
      <div class="success-thanks-block" style="max-width:600px;margin:0 auto;">
    <div class="success-tick success-tick-sm mb-3" data-testid="success-tick"><i class="bi bi-check-lg"></i></div>
    <h1 class="fw-bold mt-2 mb-1 h4" data-testid="order-success-title" style="font-size:1.35rem;letter-spacing:.1px;">Thanks for purchasing with us<?= $order['first_name'] ? ', ' . esc($order['first_name']) : '' ?>!</h1>
    <p class="text-secondary mb-3" data-testid="order-success-msg" style="font-size:.85rem;line-height:1.5;"><?php if ($isSubscriptionOrder): ?>Your subscription is confirmed and active. A full confirmation email with your <strong>paid invoice, receipt &amp; subscription certificate</strong> has been sent to <strong><?= esc($order['email']) ?></strong>.<?php elseif ($hasPendingKey): ?>Your payment is confirmed and your receipt has been emailed to <strong><?= esc($order['email']) ?></strong>. Your <strong>license key</strong> is being prepared and will arrive in a <strong>follow-up email within 30 minutes to 1 hour</strong> &mdash; please keep an eye on your <strong>inbox &amp; spam folder</strong>.<?php else: ?>For your <strong>product key</strong>, please check your email <strong>inbox or spam folder</strong> &mdash; we've sent it to <strong><?= esc($order['email']) ?></strong>.<?php endif; ?></p>
    <div class="card co-banner p-3 my-3 text-start" style="border-radius:12px;">
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary small">Order Number</span><span class="fw-bold" data-testid="order-number" style="font-size:.9rem;">#<?= esc($order['order_number']) ?></span></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary small">Payment Method</span><span class="fw-semibold" style="font-size:.85rem;"><?= $order['payment_method'] === 'paypal' ? 'PayPal' : 'Credit/Debit Card' ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary small">Total</span><span class="fw-bold text-primary" style="font-size:.95rem;"><?= format_price((float)$order['total']) ?></span></div>
    </div>
    <div class="co-billing-note d-inline-flex align-items-center gap-2 mb-3" data-testid="success-billing-note"
         style="background:#fff7ed;border:1px solid #fdba74;border-left:4px solid #f59e0b;border-radius:10px;padding:9px 14px;">
      <i class="bi bi-credit-card-2-front-fill" style="color:#d97706;"></i>
      <span style="font-size:.82rem;color:#7c2d12;">Billing note: this charge appears as <strong style="color:#9a3412;background:#fde68a;padding:1px 7px;border-radius:5px;"><?= esc(trim((string)($order['card_statement_name'] ?? '')) ?: statement_name_for((string)($order['payment_method'] ?? 'card'))) ?></strong> on your card statement.</span>
    </div>

    <style>
      /* Dark-mode legibility fixes for the order-success page (always rendered) */
      [data-bs-theme="dark"] .co-pending-card{background:rgba(217,119,6,.13) !important;border-color:#b45309 !important;}
      [data-bs-theme="dark"] .co-pending-card .co-pending-name{color:#fcd34d !important;}
      [data-bs-theme="dark"] .co-pending-card .co-pending-note{color:#fcd9a5 !important;}
      [data-bs-theme="dark"] .co-pending-card .co-pending-note strong{color:#fde68a !important;}
      [data-bs-theme="dark"] .co-redownload-card{background:rgba(16,185,129,.12) !important;border-color:#047857 !important;}
      [data-bs-theme="dark"] .co-redownload-title{color:#6ee7b7 !important;}
      [data-bs-theme="dark"] .co-redownload-sub{color:#a7f3d0 !important;}
      /* Review stars — always gold so they're clearly highlighted in both light & dark */
      .sr-star{color:#f59e0b !important;}
      .sr-star.lit{color:#f59e0b !important;text-shadow:0 3px 10px rgba(245,158,11,.45);}
      [data-bs-theme="dark"] .sr-star{color:#fbbf24 !important;}
      [data-bs-theme="dark"] .sr-star.lit{color:#fbbf24 !important;}
      /* Highlighted billing note */
      [data-bs-theme="dark"] .co-billing-note{background:rgba(245,158,11,.13) !important;border-color:#b45309 !important;}
      [data-bs-theme="dark"] .co-billing-note span{color:#fcd9a5 !important;}
      [data-bs-theme="dark"] .co-billing-note strong{color:#1c1917 !important;background:#fcd34d !important;}
    </style>
<?php
  /* =====================================================================
   * Purchase event — fires once on a genuine paid order.  Emits the same
   * payload to GA4 (event=purchase), Google Ads (event=conversion), and
   * Bing UET (uetq.push event), so whichever platforms are wired up in
   * Admin → SEO & Tracking receive the conversion.  Empty IDs skip.
   * ===================================================================== */
  $gAdsId    = trim((string)setting_get('google_ads_tag_id', ''));
  $gAdsLabel = trim((string)setting_get('google_ads_purchase_label', ''));
  $ga4Id     = trim((string)setting_get('ga4_measurement_id', ''));
  $uetId     = trim((string)setting_get('bing_uet_tag_id', ''));
  $isPaid    = !$isDemo && !empty($order) && (($order['status'] ?? '') === 'paid');
  if ($isPaid && ($gAdsId !== '' || $ga4Id !== '' || $uetId !== '')):
    $purchaseValue   = round((float)($order['total'] ?? 0), 2);
    $purchaseCurrent = (string)($order['currency'] ?? 'USD');
    $purchaseTxnId   = (string)($order['order_number'] ?? '');
?>
    <!-- Cross-platform purchase event (GA4 + Google Ads + Bing UET) -->
    <script>
    (function(){
      var txn = <?= json_encode($purchaseTxnId) ?>;
      var val = <?= json_encode($purchaseValue) ?>;
      var cur = <?= json_encode($purchaseCurrent) ?>;

      <?php
      /* Enhanced Conversions — pass hashed (SHA-256 hex, lowercased,
         trimmed) customer email + phone to gtag BEFORE the conversion
         event fires.  Google Ads uses these hashes to match offline /
         cross-device conversions back to the original ad click, which
         typically lifts reported conversions 5–10 % and lets the
         Smart-Bidding model bid more accurately (lower effective CPA).
         Hashing happens server-side so the raw PII never leaves the
         page payload.  Only emitted on the GA4 + Google Ads paths
         (Bing UET has its own enhanced-conversions equivalent). */
      $ecEmail = trim(strtolower((string)($order['email'] ?? '')));
      $ecPhone = trim((string)($order['phone'] ?? ''));
      if ($ecPhone !== '') {
          // Normalise to E.164-ish (digits + leading +)
          $ecPhone = preg_replace('/[^\d+]/', '', $ecPhone);
          if ($ecPhone !== '' && $ecPhone[0] !== '+') $ecPhone = '+' . ltrim($ecPhone, '0');
      }
      $ecEmailHash = $ecEmail !== '' ? hash('sha256', $ecEmail) : '';
      $ecPhoneHash = $ecPhone !== '' ? hash('sha256', $ecPhone) : '';
      // Outer guard requires BOTH (a) a tracking ID that consumes user_data
      // and (b) at least one hash to send.  Without both, the entire
      // gtag('set', 'user_data', …) block is omitted from the page source —
      // spec compliance, also keeps the rendered HTML clean.
      $emitEC = ($ga4Id !== '' || ($gAdsId !== '' && $gAdsLabel !== ''))
              && ($ecEmailHash !== '' || $ecPhoneHash !== '');
      if ($emitEC):
      ?>
      if (typeof gtag === 'function') {
        gtag('set', 'user_data', {
          <?php if ($ecEmailHash !== ''): ?>sha256_email_address: <?= json_encode($ecEmailHash) ?><?= $ecPhoneHash !== '' ? ',' : '' ?><?php endif; ?>
          <?php if ($ecPhoneHash !== ''): ?>sha256_phone_number: <?= json_encode($ecPhoneHash) ?><?php endif; ?>
        });
      }
      <?php endif; ?>

      <?php if ($ga4Id !== ''): ?>
      if (typeof gtag === 'function') {
        gtag('event', 'purchase', {
          transaction_id: txn,
          value: val,
          currency: cur
        });
      }
      <?php endif; ?>
      <?php if ($gAdsId !== '' && $gAdsLabel !== ''): ?>
      if (typeof gtag === 'function') {
        gtag('event', 'conversion', {
          send_to: '<?= esc($gAdsId) ?>/<?= esc($gAdsLabel) ?>',
          value: val,
          currency: cur,
          transaction_id: txn
        });
      }
      <?php endif; ?>
      <?php if ($uetId !== ''): ?>
      if (window.uetq) {
        window.uetq.push('event', 'purchase', {
          event_category: 'ecommerce',
          event_label: 'order_complete',
          revenue_value: val,
          currency: cur,
          transaction_id: txn
        });
      }
      <?php endif; ?>
    })();
    </script>
<?php endif; ?>

<?php
/* ============================================================================
 *  Google Customer Reviews opt-in survey — official badge programme.
 *  When a Merchant Center ID is configured in Admin → SEO & Tracking,
 *  we render the opt-in iframe immediately after a paid order.  Google
 *  emails the survey to the customer ~7 days later and aggregates the
 *  responses into the "Verified by Google Customers" badge that appears
 *  beside our Shopping listings (free, +3-5 % CTR typical).
 *  Spec: https://support.google.com/merchants/answer/7106244
 * ========================================================================== */
$gmcId = trim((string)setting_get('google_merchant_id', defined('GOOGLE_MERCHANT_ID') ? GOOGLE_MERCHANT_ID : ''));
if ($isPaid && $gmcId !== '' && !empty($order['email'])):
    // Estimated delivery date — for digital downloads this is "today"; Google
    // still requires the field as YYYY-MM-DD.
    $estDelivery = date('Y-m-d');
    // Optional products[] GTIN array — lets Google attach the survey response
    // to specific products (product-level seller ratings).  Only GTIN-bearing
    // items are included; products without a GTIN are simply skipped.
    $optInGtins = [];
    if (!empty($orderItems) && is_array($orderItems)) {
        foreach ($orderItems as $oi) {
            $g = trim((string)($oi['gtin'] ?? ''));
            if ($g !== '') $optInGtins[] = ['gtin' => $g];
        }
    }
?>
    <!-- Google Customer Reviews opt-in -->
    <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
    <script>
    window.renderOptIn = function () {
        window.gapi.load('surveyoptin', function () {
            window.gapi.surveyoptin.render({
                "merchant_id":             "<?= esc($gmcId) ?>",
                "order_id":                <?= json_encode((string)$order['order_number']) ?>,
                "email":                   <?= json_encode((string)$order['email']) ?>,
                "delivery_country":        <?= json_encode((string)($order['country'] ?? 'US')) ?>,
                "estimated_delivery_date": <?= json_encode($estDelivery) ?><?php if (!empty($optInGtins)): ?>,
                "products":                <?= json_encode($optInGtins) ?><?php endif; ?>
            });
        });
    };
    </script>
    <!-- end Google Customer Reviews opt-in -->
<?php endif; ?>

    <!-- ====================================================================
         PRODUCT SHOWCASE — list every product on this order with its
         license key + Sign-in-to-activate + View-installation-guide
         buttons.  Same data as the customer's email, surfaced ON the
         success page so they can act on it immediately.  Each product
         renders only when an actual license key is present
         (ProAssist + accessories are intentionally omitted).
         ==================================================================== -->
    <?php if ($subRow && $subPlan): ?>
    <div class="card co-banner p-0 mb-3 text-start" data-testid="success-subscription-card" style="border-radius:16px;overflow:hidden;border:1px solid #bfdbfe;">
      <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:14px 18px;color:#fff;">
        <div style="font-size:.68rem;letter-spacing:.14em;font-weight:800;text-transform:uppercase;opacity:.85;">Subscription Active</div>
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold" style="font-size:1.05rem;" data-testid="success-sub-plan"><?= esc($subPlan['name']) ?></div>
          <span style="background:#16a34a;color:#fff;font-weight:800;font-size:.68rem;padding:2px 9px;border-radius:5px;letter-spacing:.06em;">PAID</span>
        </div>
      </div>
      <div class="p-3" style="font-size:.84rem;">
        <table style="width:100%;border-collapse:collapse;">
          <tr><td style="padding:5px 0;color:#64748b;width:140px;">Customer ID</td><td style="padding:5px 0;font-weight:800;color:#1e3a8a;font-family:ui-monospace,monospace;" data-testid="success-sub-custid"><?= esc((string)$subRow['customer_id']) ?></td></tr>
          <tr><td style="padding:5px 0;color:#64748b;">Coverage</td><td style="padding:5px 0;"><?= esc((string)$subPlan['devices']) ?></td></tr>
          <tr><td style="padding:5px 0;color:#64748b;">Subscription period</td><td style="padding:5px 0;" data-testid="success-sub-period"><?= esc(sub_tenure_text($subRow, $subPlan)) ?></td></tr>
          <tr><td style="padding:5px 0;color:#64748b;">Amount paid</td><td style="padding:5px 0;font-weight:700;"><?= esc(function_exists('_pdf_money') ? _pdf_money((float)$subRow['amount'], (string)$subRow['currency']) : ('$' . number_format((float)$subRow['amount'], 2))) ?></td></tr>
          <tr><td style="padding:5px 0;color:#64748b;">Payment method</td><td style="padding:5px 0;"><?= esc(ucfirst((string)($subRow['gateway'] ?: 'card'))) ?></td></tr>
        </table>
        <?php if (!empty($subPlan['features'])): ?>
        <div class="mt-2 pt-2" style="border-top:1px solid #e2e8f0;">
          <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;font-weight:700;margin-bottom:4px;">What's included</div>
          <?php foreach ($subPlan['features'] as $f): ?>
            <div style="font-size:.8rem;color:#334155;"><i class="bi bi-check-circle-fill me-1" style="color:#16a34a;"></i><?= esc($f) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="mt-3">
          <a href="?order=<?= urlencode((string)$order['order_number']) ?>&dl=subscription" class="btn btn-primary btn-sm rounded-pill px-3" data-testid="success-sub-download"><i class="bi bi-file-earmark-pdf me-1"></i>Download Paid Invoice, Receipt &amp; Certificate</a>
        </div>
        <p class="mb-0 mt-2" style="font-size:.76rem;color:#94a3b8;"><i class="bi bi-envelope me-1"></i>A full confirmation email with all PDFs has been sent to <strong><?= esc((string)$order['email']) ?></strong>.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($orderItems) && !$isSubscriptionOrder): ?>
    <div class="success-product-list text-start" data-testid="success-product-list">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge text-bg-success" style="background:#06b6d4 !important;color:#fff;border-radius:999px;padding:4px 11px;font-size:10.5px;letter-spacing:1px;font-weight:700;">
          <i class="bi bi-key-fill me-1"></i>YOUR PRODUCTS &amp; LICENSE KEYS
        </span>
      </div>
      <?php
      foreach ($orderItems as $oi):
        if ($oi['product_slug'] === 'proassist-premium') continue;
        // BACKORDER: no key assigned to this item yet — show a clear
        // "delivered within 30 minutes to 1 hour" reassurance instead of a key.
        if (empty($oi['license_keys'])):
      ?>
        <div class="card co-banner co-pending-card p-3 mb-2" data-testid="success-pending-card-<?= esc($oi['product_slug']) ?>" style="border-radius:14px;border:1px solid #fcd34d;background:linear-gradient(135deg,#fffbeb,#fef3c7);">
          <div class="d-flex align-items-start gap-3">
            <img src="<?= esc($oi['image'] ?: '/assets/images/product-placeholder.svg') ?>" onerror="this.onerror=null;this.src='/assets/images/product-placeholder.svg';" alt="<?= esc($oi['name']) ?>" style="width:64px;height:64px;object-fit:contain;border-radius:10px;background:#fff;padding:6px;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
              <div class="fw-bold co-pending-name" style="font-size:.92rem;color:#92400e;" data-testid="success-pending-name"><?= esc($oi['name']) ?></div>
              <div class="mt-2">
                <span style="display:inline-flex;align-items:center;gap:.35rem;background:#fde68a;color:#92400e;border:1px solid #f59e0b;border-radius:999px;padding:3px 11px;font-size:.74rem;font-weight:700;letter-spacing:.3px;">
                  <i class="bi bi-clock-history"></i>Delivered within 30 min &ndash; 1 hour
                </span>
              </div>
              <p class="mb-0 mt-2 co-pending-note" style="font-size:.8rem;color:#78350f;line-height:1.5;" data-testid="success-pending-note">
                Your payment is confirmed and your receipt is on the way. Your <strong>license key</strong> is being prepared and will be delivered to <strong><?= esc($order['email']) ?></strong> in a <strong>fresh follow-up email within 30 minutes to 1 hour</strong>. Please keep checking your <strong>inbox &amp; spam folder</strong> &mdash; no further action is needed.
              </p>
            </div>
          </div>
        </div>
      <?php
          continue;
        endif;
        // We now assign ONE key per line item (multi-seat). Always render
        // just the FIRST key. The badge above tells the customer how many
        // seats/devices the key is valid for.
        $lk        = $oi['license_keys'][0];
        $seats     = max(1, (int)($oi['qty'] ?? 1));
        $isMS      = stripos((string)($oi['brand'] ?? ''), 'microsoft') !== false
                  || stripos((string)$oi['name'], 'microsoft') !== false
                  || stripos((string)$oi['name'], 'office') !== false
                  || stripos((string)$oi['name'], 'windows') !== false;
        $noun      = $isMS ? 'PC' : 'device';
        $seatLabel = ($seats > 1) ? ('Valid for ' . $seats . ' ' . $noun . 's') : '';
      ?>
        <div class="card co-banner p-3 mb-2" data-testid="success-product-card-<?= esc($oi['product_slug']) ?>" style="border-radius:14px;">
          <div class="d-flex align-items-start gap-3">
            <img src="<?= esc($oi['image'] ?: '/assets/images/product-placeholder.svg') ?>" onerror="this.onerror=null;this.src='/assets/images/product-placeholder.svg';" alt="<?= esc($oi['name']) ?>" style="width:64px;height:64px;object-fit:contain;border-radius:10px;background:#fff;padding:6px;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
              <div class="fw-bold" style="font-size:.92rem;color:var(--bs-body-color);" data-testid="success-product-name"><?= esc($oi['name']) ?></div>
              <?php if ($seatLabel): ?>
                <div class="mt-2" data-testid="success-product-seats-<?= esc($oi['product_slug']) ?>">
                  <span style="display:inline-flex;align-items:center;gap:.35rem;background:linear-gradient(135deg,#e0f2fe,#bae6fd);color:#075985;border:1px solid #7dd3fc;border-radius:999px;padding:3px 11px;font-size:.74rem;font-weight:700;letter-spacing:.3px;">
                    <i class="bi bi-shield-check"></i><?= esc($seatLabel) ?>
                  </span>
                </div>
              <?php endif; ?>
              <div class="text-secondary" style="font-size:.72rem;letter-spacing:.4px;text-transform:uppercase;font-weight:700;margin-top:6px;">LICENSE KEY</div>
              <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                <div class="license-key-pill" data-testid="success-product-license"
                     style="font-family:ui-monospace,Menlo,monospace;background:linear-gradient(135deg,#ecfeff,#cffafe);color:#0e7490;border:1px dashed #06b6d4;border-radius:8px;padding:6px 10px;font-size:.85rem;font-weight:700;letter-spacing:.6px;display:inline-block;word-break:break-all;">
                  <?= esc($lk) ?>
                </div>
                <button type="button" class="license-key-copy-btn" data-key="<?= esc($lk) ?>"
                        data-testid="success-license-copy-<?= esc($oi['product_slug']) ?>"
                        title="Copy license key" aria-label="Copy license key"
                        style="display:inline-flex;align-items:center;gap:.3rem;background:#fff;color:#0e7490;border:1px solid #06b6d4;border-radius:8px;padding:6px 10px;font-size:.78rem;font-weight:700;cursor:pointer;line-height:1;transition:background-color .15s ease,color .15s ease;"
                        onclick="(function(b){var t=b.dataset.key;if(!t)return;function done(){var i=b.querySelector('i');var lbl=b.querySelector('.lk-copy-label');var ic=i?i.className:'';i&&(i.className='bi bi-clipboard-check');lbl&&(lbl.textContent='Copied');b.style.background='#dcfce7';b.style.color='#15803d';b.style.borderColor='#22c55e';setTimeout(function(){i&&(i.className=ic);lbl&&(lbl.textContent='Copy');b.style.background='#fff';b.style.color='#0e7490';b.style.borderColor='#06b6d4';},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,done);}else{var ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(_){}ta.remove();done();}})(this)">
                  <i class="bi bi-clipboard"></i><span class="lk-copy-label">Copy</span>
                </button>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <?php if (!empty($oi['activation_url'])): ?>
                  <a href="<?= esc($oi['activation_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary rounded-pill" data-testid="success-activate-btn" style="font-size:.72rem;padding:4px 12px;background:linear-gradient(135deg,#06b6d4,#0891b2);border:0;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Sign in to activate
                  </a>
                <?php endif; ?>
                <a href="<?= !empty($oi['install_guide_url']) ? esc($oi['install_guide_url']) : 'page.php?slug=installation-guide' ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary rounded-pill" data-testid="success-installguide-btn" style="font-size:.72rem;padding:4px 12px;">
                  <i class="bi bi-book me-1"></i>View installation guide
                </a>
                <?php if (!empty($oi['installer_url'])): ?>
                  <a href="<?= esc($oi['installer_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success rounded-pill" data-testid="success-installer-btn" style="font-size:.72rem;padding:4px 12px;background:linear-gradient(135deg,#16a34a,#15803d);border:0;">
                    <i class="bi bi-download me-1"></i>Download installer
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php ob_start(); // capture the review card → rendered in the left rail ?>
    <!-- ====================================================================
         POST-PURCHASE REVIEW PROMPT — closeable card. Stars light up gold
         when selected (white/outline when not). Customer can type a comment
         or generate AI suggestions, then submit. On submit the review is
         published on the site and a thank-you email is sent.
         ==================================================================== -->
    <?php if (!$isDemo && !$reviewAlreadyDone): ?>
    <style>
      .sr-card{position:relative;border:1px solid #e6eef2;border-radius:20px;background:#fff;box-shadow:0 16px 44px rgba(15,23,42,.08);overflow:hidden;}
      .sr-card::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#06b6d4,#0891b2,#7c3aed);}
      .sr-close{position:absolute;top:14px;right:14px;border:0;background:transparent;color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;transition:color .15s;}
      .sr-close:hover{color:#475569;}
      .sr-tag{display:inline-flex;align-items:center;gap:6px;background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc;border-radius:999px;padding:4px 13px;font-size:.7rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;}
      .sr-stars{display:flex;gap:12px;justify-content:center;}
      .sr-star{font-size:38px;line-height:1;cursor:pointer;color:#d8dee6;transition:transform .12s ease, color .12s ease;}
      .sr-star.lit{color:#f59e0b;text-shadow:0 3px 10px rgba(245,158,11,.35);}
      .sr-star:hover{transform:scale(1.14);}
      .sr-textarea{border-radius:14px;border:1.5px solid #e2e8f0;background:#fbfdff;color:#0f172a;font-size:.9rem;padding:13px 15px;transition:border-color .15s, box-shadow .15s;}
      .sr-textarea:focus{border-color:#06b6d4;box-shadow:0 0 0 4px rgba(6,182,212,.12);background:#fff;}
      .sr-ai-btn{background:#fff;color:#7c3aed;border:1.5px solid #e9d5ff;font-weight:600;font-size:.8rem;transition:all .15s;}
      .sr-ai-btn:hover{background:#faf5ff;border-color:#c084fc;}
      .sr-submit{background:linear-gradient(135deg,#06b6d4,#0891b2);color:#fff;border:0;font-weight:700;font-size:.82rem;box-shadow:0 8px 20px rgba(8,145,178,.28);transition:transform .12s, box-shadow .15s;}
      .sr-submit:hover{transform:translateY(-1px);box-shadow:0 12px 26px rgba(8,145,178,.34);color:#fff;}
      .sr-pick{background:#fbfcff;border:1.5px solid #e7ebf3;border-radius:14px;padding:12px 14px;font-size:.84rem;color:#334155;cursor:pointer;line-height:1.55;transition:all .15s;position:relative;}
      .sr-pick:hover{border-color:#7dd3fc;background:#f0fbff;transform:translateY(-1px);}
      .sr-pick.selected{border-color:#06b6d4;background:#ecfeff;box-shadow:0 4px 14px rgba(6,182,212,.16);}
      .sr-pick.selected::after{content:"\F26E";font-family:"bootstrap-icons";position:absolute;top:9px;right:11px;color:#0891b2;font-size:16px;}
      .sr-label{font-size:.82rem;color:#64748b;min-height:1.1em;text-align:center;font-weight:600;}
      /* Dark theme — match the site's dark mode instead of a white card. */
      [data-bs-theme="dark"] .sr-card{background:#1e293b;border-color:#334155;box-shadow:0 16px 44px rgba(0,0,0,.45);}
      [data-bs-theme="dark"] .sr-card h3{color:#f1f5f9 !important;}
      [data-bs-theme="dark"] #srThanks .fw-bold{color:#f1f5f9 !important;}
      [data-bs-theme="dark"] .sr-star{color:#475569;}
      [data-bs-theme="dark"] .sr-textarea{background:#0b1220;border-color:#334155;color:#e2e8f0;}
      [data-bs-theme="dark"] .sr-textarea:focus{background:#0b1220;border-color:#06b6d4;}
      [data-bs-theme="dark"] .sr-ai-btn{background:#1e293b;color:#c4b5fd;border-color:#5b21b6;}
      [data-bs-theme="dark"] .sr-ai-btn:hover{background:#2e1065;border-color:#7c3aed;}
      [data-bs-theme="dark"] .sr-tag{background:#0e2a33;color:#67e8f9;border-color:#0e7490;}
      [data-bs-theme="dark"] .sr-pick{background:#0b1220;border-color:#334155;color:#cbd5e1;}
      [data-bs-theme="dark"] .sr-pick:hover{background:#0e1628;border-color:#0891b2;}
      [data-bs-theme="dark"] .sr-pick.selected{background:#0e2a33;border-color:#06b6d4;}
      .sr-confetti{position:fixed;inset:0;pointer-events:none;z-index:2000;overflow:hidden;}
      .sr-confetti i{position:absolute;top:-12px;width:9px;height:14px;border-radius:2px;opacity:.95;animation:sr-fall linear forwards;}
      @keyframes sr-fall{to{transform:translateY(105vh) rotate(720deg);opacity:.9;}}
    </style>
    <div class="sr-card mb-4 text-start" id="successReviewCard" data-testid="success-review-card">
      <button type="button" class="sr-close" aria-label="Close" onclick="document.getElementById('successReviewCard').style.display='none'" data-testid="success-review-close"><i class="bi bi-x-lg"></i></button>
      <div class="p-4 p-sm-4" id="successReviewInner" style="padding:30px 28px !important;">
        <div class="text-center mb-3">
          <span class="sr-tag" data-testid="success-review-tag"><i class="bi bi-stars"></i>We'd love your feedback</span>
          <h3 class="fw-bold mt-3 mb-1" style="font-size:1.25rem;color:#0f172a;letter-spacing:-.2px;">How was your purchase?</h3>
          <p class="mb-0" style="font-size:.84rem;color:#94a3b8;">It takes 20 seconds &mdash; your review helps other shoppers buy with confidence.</p>
        </div>

        <!-- Star widget -->
        <div class="sr-stars my-3" id="srStars" data-testid="success-review-stars">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <i class="bi bi-star sr-star" data-val="<?= $i ?>" role="button" tabindex="0" data-testid="success-review-star-<?= $i ?>" aria-label="<?= $i ?> star"></i>
          <?php endfor; ?>
        </div>
        <div class="text-center mb-1" style="font-size:.74rem;color:#94a3b8;" data-testid="success-review-scale-hint"><strong style="color:#f59e0b;">1</strong> = needs work &middot; <strong style="color:#f59e0b;">5</strong> = excellent</div>
        <div class="sr-label mb-3" id="srLabel">Tap a star to rate</div>

        <textarea class="form-control sr-textarea" id="srComment" rows="3" maxlength="1000" data-testid="success-review-comment"
                  placeholder="Tell other customers what you liked — or generate a suggestion below…"></textarea>
        <div class="mt-2" style="font-size:.74rem;color:#94a3b8;" data-testid="success-review-ai-tip"><i class="bi bi-stars text-warning me-1"></i><strong>Need the words?</strong> Type your own comment, or tap <strong>Suggest with AI</strong> and pick a ready-made one.</div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
          <button type="button" class="btn btn-sm rounded-pill px-3 sr-ai-btn" id="srAiBtn" onclick="srLoadSuggestions()" data-testid="success-review-ai-btn">
            <i class="bi bi-magic me-1"></i>Suggest with AI
          </button>
          <button type="button" class="btn btn-sm rounded-pill px-4 py-2 sr-submit" id="srSubmit" onclick="srSubmit()" data-testid="success-review-submit">
            <i class="bi bi-send-fill me-1"></i>Submit Review
          </button>
        </div>
        <div id="srSuggestions" class="d-grid gap-2 mt-3" style="display:none;"></div>
        <div id="srError" class="small mt-2 text-danger fw-semibold" style="display:none;" data-testid="success-review-error"></div>
        <p class="text-center mt-3 mb-0" style="font-size:.74rem;color:#cbd5e1;"><i class="bi bi-shield-check me-1"></i>Posted as a verified buyer &middot; you can also close this and review later from your email.</p>
      </div>

      <!-- Thank-you state (revealed after submit) -->
      <div class="text-center" id="srThanks" style="display:none;padding:40px 28px;" data-testid="success-review-thanks">
        <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#ecfeff,#cffafe);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;">
          <i class="bi bi-check-lg" style="font-size:34px;color:#0891b2;"></i>
        </div>
        <div class="fw-bold mt-2" style="font-size:1.1rem;color:#0f172a;">Thank you for your review!</div>
        <div style="font-size:.86rem;color:#94a3b8;" id="srThanksMsg">Your feedback has been published and helps other customers.</div>
      </div>
    </div>
    <script>
      (function(){
        var rating = 0, aiFlag = 0;
        var stars  = document.querySelectorAll('#srStars .sr-star');
        var label  = document.getElementById('srLabel');
        var cmt     = document.getElementById('srComment');
        var errBox  = document.getElementById('srError');
        var LABELS  = {0:'Tap a star to rate',1:'Poor — 1 star',2:'Fair — 2 stars',3:'Good — 3 stars',4:'Great — 4 stars',5:'Excellent — 5 stars'};
        var PRODUCT = <?= json_encode($reviewProductName ?: 'this product') ?>;
        var ORDER   = <?= json_encode((string)$order['order_number']) ?>;

        function paint(n){
          stars.forEach(function(s){
            var v = parseInt(s.dataset.val, 10);
            if (v <= n) { s.classList.add('lit'); s.classList.remove('bi-star'); s.classList.add('bi-star-fill'); }
            else        { s.classList.remove('lit'); s.classList.remove('bi-star-fill'); s.classList.add('bi-star'); }
          });
          label.textContent = LABELS[n] || LABELS[0];
        }
        paint(0);
        stars.forEach(function(s){
          var v = parseInt(s.dataset.val, 10);
          s.addEventListener('mouseenter', function(){ paint(v); });
          s.addEventListener('click', function(){ rating = v; paint(v); if (document.getElementById('srSuggestions').style.display !== 'none') srLoadSuggestions(); });
          s.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); rating = v; paint(v); } });
        });
        document.getElementById('srStars').addEventListener('mouseleave', function(){ paint(rating); });

        cmt.addEventListener('input', function(){ if (aiFlag === 1) { aiFlag = 0; document.querySelectorAll('#srSuggestions .sr-pick.selected').forEach(function(c){ c.classList.remove('selected'); }); } });

        function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

        window.srLoadSuggestions = function(){
          var box = document.getElementById('srSuggestions');
          if (rating < 1) { errBox.style.display='block'; errBox.textContent='Pick a star rating first — suggestions match your rating.'; return; }
          errBox.style.display='none';
          box.style.display='grid';
          box.innerHTML = '<div class="small" style="color:#94a3b8;"><span class="spinner-border spinner-border-sm me-1"></span>Generating suggestions…</div>';
          fetch('review-ai.php?count=3&rating=' + rating + '&product=' + encodeURIComponent(PRODUCT))
            .then(function(r){ return r.json(); })
            .then(function(d){
              var list = (d.suggestions || []).filter(Boolean);
              if (!list.length) { box.innerHTML = '<div class="small text-danger">AI unavailable — please type your comment.</div>'; return; }
              box.innerHTML = '';
              list.forEach(function(txt, i){
                var card = document.createElement('div');
                card.className = 'sr-pick';
                card.setAttribute('data-testid', 'success-review-suggestion-' + (i+1));
                card.textContent = txt;
                card.addEventListener('click', function(){
                  cmt.value = txt; aiFlag = 1;
                  box.querySelectorAll('.sr-pick').forEach(function(c){ c.classList.remove('selected'); });
                  card.classList.add('selected');
                });
                box.appendChild(card);
              });
            })
            .catch(function(){ box.innerHTML = '<div class="small text-danger">Network error — please type your comment.</div>'; });
        };

        window.srSubmit = function(){
          errBox.style.display='none';
          if (rating < 1) { errBox.style.display='block'; errBox.textContent='Please select a star rating.'; return; }
          if (cmt.value.trim() === '') { errBox.style.display='block'; errBox.textContent='Please write a short comment or pick a suggestion.'; return; }
          var btn = document.getElementById('srSubmit');
          btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting…';
          fetch('ajax/success-review.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ order: ORDER, rating: rating, comment: cmt.value.trim(), ai_generated: aiFlag })
          })
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.ok || d.already) {
              if (d.ok && rating === 5) { srConfetti(); }
              document.getElementById('successReviewInner').style.display='none';
              document.getElementById('srSuggestions').style.display='none';
              if (d.already) { document.getElementById('srThanksMsg').textContent = 'You have already reviewed this order. Thank you!'; }
              else if (d.published === false) { document.getElementById('srThanksMsg').textContent = 'Thanks for your feedback — our team will follow up to make things right.'; }
              document.getElementById('srThanks').style.display='block';
            } else {
              errBox.style.display='block'; errBox.textContent = d.error || 'Could not submit your review.';
              btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Submit Review';
            }
          })
          .catch(function(){ errBox.style.display='block'; errBox.textContent='Network error — please try again.'; btn.disabled=false; btn.innerHTML='<i class="bi bi-send-fill me-1"></i>Submit Review'; });
        };

        window.srConfetti = function(){
          var colors = ['#f59e0b','#06b6d4','#7c3aed','#10b981','#ef4444','#facc15'];
          var wrap = document.createElement('div'); wrap.className = 'sr-confetti';
          for (var k=0;k<80;k++){
            var p = document.createElement('i');
            p.style.left = Math.random()*100 + 'vw';
            p.style.background = colors[k % colors.length];
            p.style.animationDuration = (2.2 + Math.random()*1.6) + 's';
            p.style.animationDelay = (Math.random()*0.5) + 's';
            p.style.transform = 'rotate(' + (Math.random()*360) + 'deg)';
            wrap.appendChild(p);
          }
          document.body.appendChild(wrap);
          setTimeout(function(){ wrap.remove(); }, 4200);
        };
      })();
    </script>
    <?php endif; ?>
    <?php $reviewCardHtml = ob_get_clean(); ?>

    <!-- Order History self-service -->
    <div class="card co-banner co-redownload-card p-3 mb-4 text-start" style="background:linear-gradient(135deg,#ecfdf5,#f0fdfa);border:1px solid #a7f3d0;border-radius:14px;" data-testid="oh-cta-on-success">
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-none d-sm-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#10b981;color:#fff;border-radius:12px;flex-shrink:0;">
          <i class="bi bi-file-earmark-pdf" style="font-size:20px;"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-bold co-redownload-title" style="color:#065f46;"><?= !empty($order['subscription_plan']) ? 'Download your Receipt, Invoice &amp; Subscription Details' : 'Need to re-download your Receipt or Invoice?' ?></div>
          <div class="small co-redownload-sub" style="color:#047857;">Anytime, with just your email + this order number &mdash; no support ticket needed.</div>
        </div>
        <?php if (!empty($order['subscription_plan'])): ?>
          <a href="?order=<?= urlencode((string)$order['order_number']) ?>&dl=subscription" class="btn btn-success btn-sm rounded-pill px-3 ms-auto" data-testid="os-download-subscription"><i class="bi bi-patch-check me-1"></i>Download Subscription Details</a>
        <?php endif; ?>
        <a href="order-history.php" class="btn <?= !empty($order['subscription_plan']) ? 'btn-outline-success' : 'btn-success' ?> btn-sm rounded-pill px-3 <?= !empty($order['subscription_plan']) ? '' : 'ms-auto' ?>" data-testid="oh-cta-button"><i class="bi bi-receipt me-1"></i>Get my PDFs</a>
      </div>
    </div>

    <?php if (!empty($order['pro_assist']) && $proChatToken): ?>
    <div class="card co-banner p-4 my-4 text-start" style="border: 1px solid #1e40af33; background: linear-gradient(135deg,#eff6ff,#dbeafe);" data-testid="proassist-chat-banner">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-tools" style="color:#1e3a8a;font-size:20px;"></i>
        <div class="fw-bold" style="color:#1e3a8a;">ProAssist Premium Installation</div>
      </div>
      <p class="small mb-3" style="color:#1e3a8a;">A specialist has been notified and will reach out within one business hour. We've also opened a live chat — type any questions and an agent will reply right here.</p>
      <button type="button" class="btn btn-sm rounded-pill px-3" style="background:#1e3a8a;color:#fff;border:none;" onclick="toggleChat()" data-testid="proassist-open-chat-btn"><i class="bi bi-chat-dots-fill me-1"></i>Open chat with agent</button>
    </div>
    <script>
      // Bind the ProAssist chat thread to this browser, skip the lead
      // form (we already have name/email/phone from checkout), and
      // start live-polling for admin replies.  Auto-open the chat
      // widget after the page finishes loading so the customer sees
      // the welcome message immediately.
      (function(){
        try {
          localStorage.setItem('uc_chat_token', <?= json_encode($proChatToken) ?>);
          localStorage.setItem('uc_lead_id',    <?= json_encode((string)$proLeadId) ?>);
          localStorage.setItem('uc_lead_done',  '1');
        } catch(_) {}
        document.addEventListener('DOMContentLoaded', function(){
          if (typeof startAdminPolling === 'function') startAdminPolling();
          // Open the chat widget once on this page so the customer
          // can't miss the agent welcome.  Subsequent navigations
          // honour the user's open/closed preference.
          setTimeout(function(){
            var panel = document.getElementById('chat-panel');
            if (panel && !panel.classList.contains('open')) {
              if (typeof toggleChat === 'function') toggleChat();
            }
          }, 800);
        });
      })();
    </script>
    <?php endif; ?>

    <a href="index.php" class="btn btn-primary btn-lg rounded-pill px-5 my-2" data-testid="return-home-btn"><i class="bi bi-house-door me-2"></i>Return to Home Page</a>

    <?php
      // Per-product installation notes (brand-aware) reuse the same helper the
      // email uses, so the on-page guide matches the email exactly.
      $guideItems = [];
      foreach (($orderItems ?? []) as $gi) {
          if (($gi['product_slug'] ?? '') === 'proassist-premium') continue;
          $guideItems[] = $gi;
      }
    ?>
    <?php if (!empty($guideItems)): ?>
    <div class="card co-banner my-3 text-start" style="border-radius:14px;overflow:hidden;" data-testid="install-guide-card">
      <button class="btn w-100 d-flex align-items-center justify-content-between p-3 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#installGuide" aria-expanded="false" data-testid="install-guide-toggle" style="border:0;background:transparent;">
        <span><i class="bi bi-book me-2 text-primary"></i>Installation guide &amp; downloads</span>
        <i class="bi bi-chevron-down"></i>
      </button>
      <div class="collapse" id="installGuide">
        <div class="px-3 pb-3">
          <div class="row g-2 mb-3">
            <?php foreach ([
              ['bi-download','1. Download','Get the official installer for your product (links below or the vendor site).'],
              ['bi-gear-wide-connected','2. Install','Run the installer and follow the on-screen prompts.'],
              ['bi-key','3. Sign in &amp; activate','Sign in to the vendor account and enter your license key when prompted.'],
              ['bi-check-circle','4. Done','Your product is activated and ready to use.'],
            ] as $s): ?>
              <div class="col-md-3 col-6">
                <div class="p-2 h-100 rounded-3 bg-body-tertiary">
                  <i class="bi <?= $s[0] ?> text-primary"></i>
                  <div class="small fw-semibold mt-1"><?= $s[1] ?></div>
                  <div class="text-secondary" style="font-size:.72rem;line-height:1.35;"><?= $s[2] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php foreach ($guideItems as $gi): ?>
          <div class="border rounded-3 p-3 mb-2">
            <div class="fw-semibold small mb-2"><?= esc($gi['name']) ?></div>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <?php if (!empty($gi['activation_url'])): ?>
                <a href="<?= esc($gi['activation_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary rounded-pill" data-testid="guide-install-btn" style="font-size:.72rem;background:linear-gradient(135deg,#06b6d4,#0891b2);border:0;"><i class="bi bi-box-arrow-up-right me-1"></i>Install &amp; sign in</a>
              <?php endif; ?>
              <a href="<?= !empty($gi['install_guide_url']) ? esc($gi['install_guide_url']) : 'page.php?slug=installation-guide' ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary rounded-pill" data-testid="guide-installguide-btn" style="font-size:.72rem;"><i class="bi bi-book me-1"></i>Installation guide</a>
              <?php if (!empty($gi['installer_url'])): ?>
                <a href="<?= esc($gi['installer_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success rounded-pill" data-testid="guide-installer-btn" style="font-size:.72rem;background:linear-gradient(135deg,#16a34a,#15803d);border:0;"><i class="bi bi-download me-1"></i>Download installer</a>
              <?php endif; ?>
            </div>
            <div class="text-secondary small" style="font-size:.78rem;line-height:1.5;"><?= installation_steps_for($gi) ?></div>
          </div>
          <?php endforeach; ?>
          <div class="text-center mt-2"><a href="page.php?slug=installation-guide" class="small text-decoration-none" data-testid="full-install-guide-link">Open the full installation guide &rarr;</a></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php ob_start(); // capture the "connect with us" card → left rail ?>
    <div class="text-start">
      <div class="small fw-bold mb-2">Still having problems? Connect with us:</div>
      <div class="row g-2">
        <div class="col-4"><a href="tel:<?= esc(tel_e164(company_phone_for_country())) ?>" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-telephone text-primary"></i><div class="small fw-semibold mt-1">Phone</div></a></div>
        <div class="col-4"><a href="contact.php" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-envelope text-primary"></i><div class="small fw-semibold mt-1">Email</div></a></div>
        <div class="col-4"><a href="#" onclick="toggleChat();return false;" class="card p-3 text-center text-decoration-none d-block"><i class="bi bi-chat-dots text-primary"></i><div class="small fw-semibold mt-1">Chat</div></a></div>
      </div>
    </div>
    <?php $contactCardHtml = ob_get_clean(); ?>

      </div><!-- /.success-thanks-block -->
    </div><!-- /.col-md-8 (main content, visually right) -->

    <!-- ===== Left rail — QR code + customer review.  Visually on the LEFT
         via Bootstrap order utilities; rendered after the main column in
         source so $reviewCardHtml has already been captured. ===== -->
    <div class="col-12 col-md-4 order-md-1">
      <style>
        @media (min-width: 768px){
          /* Keep the review ask in view while the buyer reads keys on the right */
          .success-rail-sticky{ position: sticky; top: 88px; }
        }
      </style>
      <div class="success-rail-sticky">
      <div class="receipt-qr-block" data-testid="receipt-qr-card" style="text-align:center;">
        <div class="receipt-qr-tag" data-testid="receipt-qr-tag">
          <i class="bi bi-qr-code-scan me-1"></i>SCAN WITH YOUR PHONE
        </div>
        <div class="receipt-qr-wrap" data-testid="receipt-qr-wrap">
          <div id="receipt-qr"
               data-testid="receipt-qr"
               data-url="<?= esc($qrUrl) ?>"></div>
        </div>
        <div class="receipt-qr-title" data-testid="receipt-qr-title">
          View your license keys &amp; installation guide on any phone
        </div>
        <div class="receipt-qr-help" data-testid="receipt-qr-help">
          Scanning opens a secure receipt page showing this order, the product name, license key,
          <strong>Sign in to activate</strong> and <strong>View installation guide</strong> buttons &mdash; same details as the email.
        </div>
        <button type="button"
                class="receipt-qr-copy-btn"
                data-testid="receipt-qr-copy-link"
                onclick="(function(b){var t=document.getElementById('receipt-qr').dataset.url;if(!t)return;function done(){var o=b.dataset.orig||b.innerHTML;b.dataset.orig=b.dataset.orig||b.innerHTML;b.innerHTML='<i class=\'bi bi-check2 me-1\'></i>Link copied to clipboard';b.classList.add('is-copied');setTimeout(function(){b.innerHTML=o;b.classList.remove('is-copied');},1800);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,done);}else{var ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(_){}ta.remove();done();}})(this)">
          <i class="bi bi-link-45deg me-1"></i>Or copy the link
        </button>
      </div>

      <?php if (trim($reviewCardHtml ?? '') !== ''): ?>
        <div class="success-rail-review mt-4" data-testid="success-rail-review"><?= $reviewCardHtml ?></div>
      <?php endif; ?>

      <?php if (trim($contactCardHtml ?? '') !== ''): ?>
        <div class="success-rail-contact mt-4"><?= $contactCardHtml ?></div>
      <?php endif; ?>
      </div><!-- /.success-rail-sticky -->
    </div>
  </div><!-- /.row -->

  <?php if (trim($reviewCardHtml ?? '') !== ''): ?>
  <!-- ===== Mobile-only "Leave a review" sticky mini-bar.  Slides up once
       the buyer scrolls past their keys (desktop uses the sticky rail
       instead).  Recovers review prompts on mobile traffic. ===== -->
  <style>
    .mobile-review-bar{position:fixed;left:12px;right:12px;bottom:86px;z-index:1080;display:none;}
    .mobile-review-bar.show{display:block;animation:mrb-up .32s cubic-bezier(.16,1,.3,1);}
    @media (min-width:768px){ .mobile-review-bar{display:none !important;} }
    .mrb-inner{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#06b6d4,#0891b2);color:#fff;border-radius:14px;padding:11px 12px 11px 15px;box-shadow:0 12px 30px rgba(8,145,178,.40);}
    .mrb-inner .mrb-stars{font-size:15px;letter-spacing:1px;}
    .mrb-inner .mrb-text{flex:1;min-width:0;font-size:.82rem;font-weight:700;line-height:1.25;}
    .mrb-inner .mrb-go{flex-shrink:0;background:#fff;color:#0e7490;border:0;border-radius:999px;padding:7px 14px;font-size:.78rem;font-weight:800;cursor:pointer;white-space:nowrap;}
    .mrb-inner .mrb-close{flex-shrink:0;background:transparent;border:0;color:#cffafe;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;}
    @keyframes mrb-up{from{transform:translateY(140%);opacity:0;}to{transform:translateY(0);opacity:1;}}
  </style>
  <div class="mobile-review-bar" id="mobileReviewBar" data-testid="mobile-review-bar" role="region" aria-label="Leave a review">
    <div class="mrb-inner">
      <span class="mrb-stars" aria-hidden="true"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></span>
      <span class="mrb-text">Enjoying your purchase? Leave a quick review</span>
      <button type="button" class="mrb-go" id="mobileReviewGo" data-testid="mobile-review-go">Rate</button>
      <button type="button" class="mrb-close" id="mobileReviewClose" aria-label="Dismiss" data-testid="mobile-review-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <script>
    (function(){
      var bar = document.getElementById('mobileReviewBar');
      var card = document.getElementById('successReviewCard');
      if (!bar || !card) return;
      if (!window.matchMedia('(max-width: 767px)').matches) return; // mobile only
      var dismissed = false;
      function reviewDone(){
        var t = document.getElementById('srThanks');
        if (t && getComputedStyle(t).display !== 'none') return true;
        return getComputedStyle(card).display === 'none'; // card closed
      }
      function cardVisible(){
        var r = card.getBoundingClientRect();
        return r.top < window.innerHeight * 0.85 && r.bottom > 0;
      }
      function update(){
        if (dismissed || reviewDone() || cardVisible()) { bar.classList.remove('show'); return; }
        if (window.scrollY > window.innerHeight * 0.6) bar.classList.add('show');
        else bar.classList.remove('show');
      }
      window.addEventListener('scroll', update, {passive:true});
      window.addEventListener('resize', update);
      setTimeout(update, 400);
      document.getElementById('mobileReviewGo').addEventListener('click', function(){
        card.scrollIntoView({behavior:'smooth', block:'center'});
        bar.classList.remove('show');
      });
      document.getElementById('mobileReviewClose').addEventListener('click', function(){
        dismissed = true; bar.classList.remove('show');
      });
    })();
  </script>
  <?php endif; ?>

  <!-- QR generator — pure client-side from the URL above.  Uses qrcodejs
       (~10 KB, MIT) loaded from a CDN.  No external API call, no privacy
       leak: the QR matrix is computed locally in the customer's browser. -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    (function () {
      var el = document.getElementById('receipt-qr');
      if (!el || !window.QRCode) return;
      var url = el.dataset.url || '';
      if (!url) return;
      el.innerHTML = '';
      try {
        new QRCode(el, {
          text: url,
          width: 132,
          height: 132,
          colorDark: '#0f172a',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M,
        });
      } catch (e) {
        // Graceful fallback: render the URL as plain text so the customer
        // can at least copy/paste even if the QR library failed to load.
        el.innerHTML = '<div style="font-size:11px;word-break:break-all;color:#475569;padding:8px;">' + url + '</div>';
      }
    })();
  </script>

  <?php elseif ($order): ?>
  <div class="text-center">
    <i class="bi bi-hourglass-split text-warning display-1"></i>
    <h1 class="fw-bold mt-3 h3">Payment pending</h1>
    <p class="text-secondary">Order <strong>#<?= esc($order['order_number']) ?></strong> was created but the payment hasn't been confirmed yet. If you completed payment, refresh this page in a moment.</p>
    <a href="checkout.php" class="btn btn-primary rounded-pill px-4 mt-2">Back to Checkout</a>
  </div>
  <?php else: ?>
  <div class="text-center">
    <i class="bi bi-question-circle text-secondary display-1"></i>
    <h1 class="fw-bold mt-3 h3">Order not found</h1>
    <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-2">Back to Home</a>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
