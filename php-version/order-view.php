<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$admin = require_admin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$o = $pdo->prepare('SELECT * FROM orders WHERE id=?');
$o->execute([$id]); $o = $o->fetch();
if (!$o) { http_response_code(404); die('Order not found'); }

// Resend or status update
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (($_POST['action'] ?? '')==='resend_email') {
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([$id]);
        fulfill_order($id);
        header('Location: order-view.php?id='.$id.'&msg=Email+resent'); exit;
    }
    if (($_POST['action'] ?? '')==='update_status') {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'], $id]);
        if ($_POST['status']==='paid') fulfill_order($id);
        header('Location: order-view.php?id='.$id.'&msg=Status+updated'); exit;
    }
    if (($_POST['action'] ?? '')==='deliver_keys') {
        // Admin manually enters / updates the license key for a (backordered)
        // order, optionally changes the customer email, then re-sends the real
        // key-delivery email.
        $newEmail = trim((string)($_POST['cust_email'] ?? ''));
        if ($newEmail !== '' && filter_var($newEmail, FILTER_VALIDATE_EMAIL) && $newEmail !== $o['email']) {
            $pdo->prepare('UPDATE orders SET email=? WHERE id=?')->execute([$newEmail, $id]);
            $o['email'] = $newEmail;
        }
        $oiList = $pdo->prepare('SELECT id, product_slug FROM order_items WHERE order_id=?');
        $oiList->execute([$id]);
        foreach ($oiList->fetchAll() as $oi) {
            $k = trim((string)($_POST['key_'.$oi['id']] ?? ''));
            if ($k === '') continue;
            $ex = $pdo->prepare('SELECT id FROM license_keys WHERE order_id=? AND product_slug=? LIMIT 1');
            $ex->execute([$id, $oi['product_slug']]);
            $exId = $ex->fetchColumn();
            if ($exId) {
                $pdo->prepare('UPDATE license_keys SET license_key=?, status="sold", assigned_at=NOW() WHERE id=?')->execute([$k, $exId]);
            } else {
                $pdo->prepare('INSERT INTO license_keys (product_slug, license_key, status, order_id, region, assigned_at) VALUES (?,?,"sold",?,?,NOW())')
                    ->execute([$oi['product_slug'], $k, $id, $o['region'] ?: 'US']);
            }
        }
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([$id]);
        fulfill_order($id, true);
        header('Location: order-view.php?id='.$id.'&msg=License+key+saved+and+email+sent+to+'.urlencode($o['email'])); exit;
    }
}

$items = $pdo->prepare('SELECT oi.*, p.image, p.category, p.platform
  FROM order_items oi LEFT JOIN products p ON p.slug=oi.product_slug WHERE oi.order_id=?');
$items->execute([$id]); $items = $items->fetchAll();

$keys = $pdo->prepare('SELECT lk.*, oi.product_slug, oi.name AS product_name
  FROM license_keys lk JOIN order_items oi ON oi.product_slug=lk.product_slug AND oi.order_id=lk.order_id
  WHERE lk.order_id=?');
$keys->execute([$id]); $keys = $keys->fetchAll();
$keyMap = []; foreach ($keys as $k) $keyMap[$k['product_slug']][] = $k;

$em = $pdo->prepare('SELECT * FROM email_outbox WHERE order_id=? ORDER BY created_at DESC');
$em->execute([$id]); $emRows = $em->fetchAll();
$lastEmail = $emRows[0] ?? null;

$tl = json_decode($o['timeline'] ?? 'null', true) ?: [];

$pageTitle = 'Order #'.$o['order_number'].' · Admin';
$adminActive = 'orders';
include __DIR__ . '/includes/admin-shell.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <a href="javascript:void(0)" onclick="if(document.referrer && document.referrer.indexOf(location.host) !== -1){history.back();} else {location.href='admin.php?tab=orders';}" class="text-decoration-none small" data-testid="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 fw-bold mb-0 mt-1" data-testid="order-title">Order #<?= esc($o['order_number']) ?>
      <?php $oMode = $o['gw_mode'] ?? 'live'; if ($oMode === 'test'): ?>
        <span class="badge ms-2" data-testid="order-mode-badge" style="background:linear-gradient(135deg,#f59e0b,#ea580c);color:#fff;font-size:11px;letter-spacing:1.2px;vertical-align:middle;"><i class="bi bi-eyedropper me-1"></i>TEST</span>
      <?php else: ?>
        <span class="badge ms-2" data-testid="order-mode-badge" style="background:rgba(16,185,129,.12);color:#10b981;font-size:11px;letter-spacing:1.2px;vertical-align:middle;border:1px solid rgba(16,185,129,.35);"><i class="bi bi-broadcast me-1"></i>LIVE</span>
      <?php endif; ?>
    </h1>
    <small class="text-muted">Placed <?= esc(date('M j, Y H:i', strtotime($o['created_at']))) ?> · Region <?= esc($o['region']) ?></small>
  </div>
  <div class="d-flex gap-2">
    <form method="post" class="d-inline">
      <input type="hidden" name="action" value="resend_email">
      <button class="btn btn-soft-blue btn-sm"><i class="bi bi-envelope me-1"></i> Resend Email</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="action" value="update_status">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:130px;">
        <?php foreach (['pending','paid','delivered','refunded','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $o['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success py-2 small"><?= esc($_GET['msg']) ?></div><?php endif; ?>

<?php if (($o['delivery_status'] ?? 'delivered') === 'pending'): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 py-2" data-testid="order-pending-banner">
    <i class="bi bi-clock-history fs-5"></i>
    <div class="small">
      <strong>Delivery pending.</strong> At least one item had no license key in stock, so the customer's confirmation email shows that item as "delivered within 30 min – 1 hour" (any in-stock keys were sent right away). Enter the missing key below to send the updated key-delivery email to <strong><?= esc($o['email']) ?></strong>.
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <!-- LEFT: Customer + Purchase + Payment -->
  <div class="col-lg-8">
    <div class="card-e p-4 mb-3" data-testid="customer-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-person text-primary me-2"></i>Customer Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Full Name</div><div class="fw-semibold"><?= esc($o['first_name'].' '.$o['last_name']) ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email</div><div class="fw-semibold"><?= esc($o['email']) ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Phone</div><div class="fw-semibold"><?= esc($o['phone'] ?: '—') ?></div></div>
        <?php if (!empty($o['company_name'])): ?>
        <div class="col-12 col-md-4" data-testid="customer-company"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Company</div><div class="fw-semibold"><i class="bi bi-building me-1 text-secondary"></i><?= esc($o['company_name']) ?></div></div>
        <?php endif; ?>
        <div class="col-12 col-md-<?= !empty($o['company_name']) ? '8' : '12' ?>"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Billing Address</div>
          <div class="fw-semibold"><?= esc($o['address']) ?><?= $o['address2'] ? ', ' . esc($o['address2']) : '' ?>, <?= esc($o['city']) ?>, <?= esc($o['state']) ?> <?= esc($o['zip']) ?> · <?= esc($o['country'] ?: $o['billing_country'] ?: '—') ?></div>
        </div>
        <div class="col-6 col-md-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">IP Address</div><div class="fw-semibold"><?= esc($o['ip_address'] ?: '—') ?></div></div>
        <?php
          // Render a clickable Stripe Dashboard link when we have a PI id.
          // Honours STRIPE_LIVE_MODE (test PI ids start with pi_test_… on test mode).
          $piId = (string)($o['payment_intent_id'] ?? '');
          if ($piId !== '' && function_exists('stripe_enabled') && stripe_enabled()):
            $isTest = strpos((string)STRIPE_SECRET_KEY, 'sk_test_') === 0;
            $stripeUrl = 'https://dashboard.stripe.com/' . ($isTest ? 'test/' : '') . 'payments/' . urlencode($piId);
        ?>
        <div class="col-6 col-md-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Stripe Payment</div>
          <a href="<?= esc($stripeUrl) ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none" data-testid="stripe-dashboard-link"><i class="bi bi-box-arrow-up-right me-1"></i>View on Stripe Dashboard →</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-e p-4 mb-3" data-testid="purchase-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-box-seam text-primary me-2"></i>Purchase Information</h6>
      <?php foreach ($items as $it):
        $assigned = $keyMap[$it['product_slug']] ?? []; ?>
        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
          <?php if ($it['image']): ?><img src="<?= esc($it['image']) ?>" style="width:64px;height:64px;object-fit:contain;background:var(--bg);border-radius:8px;padding:6px;"><?php endif; ?>
          <div class="flex-grow-1">
            <div class="fw-bold"><?= esc($it['name']) ?></div>
            <div class="small text-muted"><?= esc($it['platform']) ?> · <?= esc($it['category']) ?> · Qty <?= (int)$it['qty'] ?> · <?= region_money((float)$it['price']) ?></div>
            <?php foreach ($assigned as $k): ?>
              <div class="mt-2"><span class="text-muted small">License Key:</span>
                <code style="background:var(--blue-soft);color:var(--brand-dk);padding:3px 10px;border-radius:6px;letter-spacing:1.2px;font-size:12.5px;"><?= esc($k['license_key']) ?></code>
                <span class="s-badge <?= $k['status']==='sold'?'paid':'queued' ?> ms-2"><?= esc($k['status']) ?></span>
              </div>
            <?php endforeach; ?>
            <?php if (!$assigned): ?><div class="mt-2 small text-muted">No key assigned yet.</div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="row small">
        <div class="col-6 col-md-3"><span class="text-muted">Purchase Date:</span><br><strong><?= esc(date('M j, Y H:i', strtotime($o['created_at']))) ?></strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Region:</span><br><strong><?= esc($o['region']) ?></strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Quantity:</span><br><strong><?= array_sum(array_column($items,'qty')) ?> item(s)</strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Total:</span><br><strong style="color:var(--green);font-size:16px;"><?= region_money((float)$o['total']) ?></strong></div>
      </div>
    </div>

    <div class="card-e p-4 mb-3" data-testid="deliver-keys-card">
      <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-primary me-2"></i>Deliver / Update License Key
        <?php if (($o['delivery_status'] ?? 'delivered') === 'pending'): ?>
          <span class="s-badge queued ms-2" data-testid="delivery-status-badge">Pending</span>
        <?php else: ?>
          <span class="s-badge delivered ms-2" data-testid="delivery-status-badge">Delivered</span>
        <?php endif; ?>
      </h6>
      <form method="post" data-testid="deliver-keys-form">
        <input type="hidden" name="action" value="deliver_keys">
        <div class="mb-3">
          <label class="form-label small text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Customer email <span class="text-secondary text-lowercase">(edit to resend to a different address)</span></label>
          <input type="email" name="cust_email" value="<?= esc($o['email']) ?>" class="form-control form-control-sm" required data-testid="deliver-email-input">
        </div>
        <?php foreach ($items as $it):
          $existingKey = $keyMap[$it['product_slug']][0]['license_key'] ?? ''; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1"><i class="bi bi-box-seam me-1 text-secondary"></i><?= esc($it['name']) ?></label>
            <input type="text" name="key_<?= (int)$it['id'] ?>" value="<?= esc($existingKey) ?>" class="form-control form-control-sm" style="font-family:monospace;letter-spacing:1px;" placeholder="Enter license key — e.g. XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" data-testid="deliver-key-input-<?= (int)$it['id'] ?>">
          </div>
        <?php endforeach; ?>
        <button class="btn btn-primary btn-sm" data-testid="deliver-keys-submit" onclick="return confirm('Save the license key(s) and email the customer at the address shown above?');"><i class="bi bi-send-fill me-1"></i>Save Key &amp; Send Email</button>
        <small class="text-muted ms-2">Sends the full key-delivery email and marks the order delivered.</small>
      </form>
    </div>

    <div class="card-e p-4 mb-3" data-testid="payment-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-credit-card text-primary me-2"></i>Payment Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Gateway</div><div class="fw-semibold text-capitalize"><?= esc($o['payment_method']) ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Status</div><span class="s-badge <?= esc($o['status']) ?>"><?= esc($o['status']) ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Currency</div><div class="fw-semibold"><?= esc($o['currency']) ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Billing Country</div><div class="fw-semibold"><?= esc($o['billing_country'] ?: $o['country'] ?: '—') ?></div></div>
        <div class="col-12 col-md-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Transaction ID</div><div class="fw-semibold"><code><?= esc($o['transaction_id'] ?: '—') ?></code></div></div>
        <div class="col-12 col-md-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Statement Name</div><div class="fw-semibold"><?= esc($o['card_statement_name'] ?: statement_name_for($o['payment_method'])) ?></div></div>
      </div>

      <?php if ($o['payment_method'] === 'card'):
        $brandLogo = ['Visa'=>'visa','Mastercard'=>'mastercard','Amex'=>'amex','American Express'=>'amex','Discover'=>'discover'][$o['card_brand']] ?? null;
        // Friendly cardholder name (uppercase, embossed look like a real card)
        $cardholder = trim(strtoupper($o['first_name'] . ' ' . $o['last_name']));
        // Risk-level styling for Stripe Radar score.
        $rLevel = (string)($o['risk_level'] ?? '');
        $rScore = isset($o['risk_score']) && $o['risk_score'] !== null ? (int)$o['risk_score'] : null;
        $riskColors = [
          'normal'   => ['#d1fae5','#047857','Normal'],
          'elevated' => ['#fef3c7','#92400e','Elevated'],
          'highest'  => ['#fee2e2','#b91c1c','Highest'],
          'not_assessed' => ['#e2e8f0','#475569','Not assessed'],
        ];
        [$rBg,$rFg,$rLabel] = $riskColors[$rLevel] ?? ['#e2e8f0','#475569', ($rLevel ?: '—')];
        // ISO-2 → emoji flag for issuing country.
        $flag = '';
        if (!empty($o['card_country']) && strlen($o['card_country']) === 2) {
            $cc = strtoupper($o['card_country']);
            $flag = chr(0xF0) . chr(0x9F) . chr(0x87) . chr(0xA6 + ord($cc[0]) - ord('A'))
                  . chr(0xF0) . chr(0x9F) . chr(0x87) . chr(0xA6 + ord($cc[1]) - ord('A'));
        }
      ?>
        <hr class="my-3">
        <div class="d-flex align-items-center gap-3 mb-2">
          <i class="bi bi-credit-card-2-front" style="font-size:24px;color:var(--brand);"></i>
          <strong>Card Details</strong>
          <span class="text-muted small ms-2" data-testid="card-pci-note"><i class="bi bi-shield-lock"></i> PCI-allowed subset · full PAN &amp; CVV are not stored</span>
          <?php if ($rLevel !== ''): ?>
            <span class="ms-auto px-2 py-1" style="background:<?= esc($rBg) ?>;color:<?= esc($rFg) ?>;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;" data-testid="risk-badge" title="Stripe Radar risk assessment">
              <i class="bi bi-shield-exclamation me-1"></i>Risk: <?= esc($rLabel) ?><?= $rScore !== null ? ' · ' . $rScore : '' ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-3 flex-wrap align-items-stretch">
          <!-- Card visual — embossed cardholder name, brand logo top-right -->
          <div data-testid="card-visual" style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 60%,#020617 100%);color:#fff;border-radius:14px;padding:18px 22px;min-width:320px;position:relative;overflow:hidden;box-shadow:0 8px 18px rgba(0,0,0,.18);">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div style="font-size:10px;letter-spacing:2px;color:#94a3b8;text-transform:uppercase;">Card on File</div>
                <div style="font-size:10px;color:#64748b;margin-top:2px;"><?= esc(SITE_BRAND) ?></div>
              </div>
              <?php if ($brandLogo): ?>
                <img src="assets/images/payments/<?= esc($brandLogo) ?>.svg" alt="<?= esc($o['card_brand']) ?>" style="height:24px;filter:brightness(0) invert(1);opacity:.95;">
              <?php else: ?>
                <div style="font-weight:700;color:#fff;letter-spacing:1px;"><?= esc($o['card_brand'] ?: 'CARD') ?></div>
              <?php endif; ?>
            </div>
            <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:700;letter-spacing:3px;margin:18px 0 8px;color:#fff;">
              •••• •••• •••• <?= esc($o['card_last4'] ?: '----') ?>
            </div>
            <div class="d-flex justify-content-between align-items-end" style="font-size:11px;color:#cbd5e1;">
              <div>
                <div style="font-size:9px;letter-spacing:1.5px;color:#64748b;">CARDHOLDER</div>
                <div style="font-family:'Courier New',monospace;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#fff;font-size:11px;"><?= esc($cardholder ?: 'NAME ON FILE') ?></div>
              </div>
              <div>
                <div style="font-size:9px;letter-spacing:1.5px;color:#64748b;">EXP</div>
                <div style="font-family:'Courier New',monospace;font-weight:700;color:#fff;font-size:12px;"><?= esc($o['card_exp'] ?: '--/--') ?></div>
              </div>
            </div>
            <i class="bi bi-wifi" style="position:absolute;bottom:18px;right:22px;font-size:18px;color:#facc15;transform:rotate(90deg);"></i>
            <!-- subtle chip rendering -->
            <div style="position:absolute;top:46px;left:22px;width:30px;height:22px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:4px;opacity:.9;"></div>
          </div>
          <div class="flex-grow-1 row g-2 small align-content-center">
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Brand</div><div class="fw-semibold"><?= esc($o['card_brand'] ?: '—') ?></div></div>
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Type</div><div class="fw-semibold text-capitalize"><?= esc($o['card_type'] ?: '—') ?></div></div>
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Last 4</div><div class="fw-semibold">•••• <?= esc($o['card_last4'] ?: '—') ?></div></div>
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Expiry</div><div class="fw-semibold"><?= esc($o['card_exp'] ?: '—') ?></div></div>
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Funding</div><div class="fw-semibold text-capitalize"><?= esc($o['card_funding'] ?: 'unknown') ?></div></div>
            <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Issuing Country</div><div class="fw-semibold"><?= $flag ?> <?= esc($o['card_country'] ?: '—') ?></div></div>
          </div>
        </div>

      <?php elseif ($o['payment_method'] === 'paypal'):
        $fs = $o['paypal_funding_source'] ?: 'paypal_balance';
        $fsMap = [
          'paypal_balance' => ['PayPal Balance',        'wallet2',          '#003087', 'Paid directly from PayPal balance.'],
          'pay_later'      => ['PayPal Pay Later',      'calendar-event',   '#0070BA', 'Buy now, pay in 4 interest-free installments.'],
          'paypal_credit'  => ['PayPal Credit',         'credit-card',      '#0070BA', 'PayPal Credit line of credit (deferred interest).'],
          'bank'           => ['Bank Account (linked)', 'bank',             '#10b981', 'Funded from a bank account attached to PayPal.'],
          'card'           => ['Card (linked)',         'credit-card-2-front','#3b82f6','Funded from a card saved inside the PayPal wallet.'],
          'venmo'          => ['Venmo',                 'wallet',           '#3D95CE', 'Paid via Venmo (PayPal-owned).'],
        ];
        [$fsLabel,$fsIcon,$fsColor,$fsDesc] = $fsMap[$fs] ?? ['Unknown source','question-circle','#64748b','Funding source not returned by PayPal.'];
      ?>
        <hr class="my-3">
        <div class="d-flex align-items-center gap-3 mb-3">
          <i class="bi bi-paypal" style="font-size:24px;color:#003087;"></i>
          <strong>PayPal Payment Details</strong>
        </div>
        <div class="d-flex gap-3 flex-wrap align-items-stretch">
          <!-- PayPal funding card -->
          <div style="background:linear-gradient(135deg,#003087 0%,#0070BA 100%);color:#fff;border-radius:14px;padding:18px 22px;min-width:300px;position:relative;overflow:hidden;">
            <div style="display:flex;align-items:center;gap:8px;font-size:18px;font-weight:800;letter-spacing:.3px;">
              <span style="font-style:italic;color:#fff;">Pay</span><span style="font-style:italic;color:#facc15;">Pal</span>
            </div>
            <div style="font-size:11px;letter-spacing:2px;color:#cbd5e1;text-transform:uppercase;margin-top:14px;">Funding Source</div>
            <div style="margin-top:6px;display:flex;align-items:center;gap:10px;">
              <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.15);display:inline-flex;align-items:center;justify-content:center;">
                <i class="bi bi-<?= esc($fsIcon) ?>" style="font-size:18px;"></i>
              </div>
              <div>
                <div style="font-size:15px;font-weight:700;"><?= esc($fsLabel) ?></div>
                <?php if ($fs==='card' && $o['paypal_funding_card_brand']): ?>
                  <div style="font-size:12px;color:#cbd5e1;">
                    <?= esc($o['paypal_funding_card_brand']) ?> ending •••• <?= esc($o['paypal_funding_card_last4']) ?>
                  </div>
                <?php elseif ($fs==='bank' && $o['paypal_funding_bank_name']): ?>
                  <div style="font-size:12px;color:#cbd5e1;"><?= esc($o['paypal_funding_bank_name']) ?></div>
                <?php elseif ($fs==='paypal_credit' && $o['paypal_funding_card_last4']): ?>
                  <div style="font-size:12px;color:#cbd5e1;">
                    Backing card: <?= esc($o['paypal_funding_card_brand'] ?: 'Card') ?> ••<?= esc($o['paypal_funding_card_last4']) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div style="font-size:11px;color:#cbd5e1;margin-top:14px;line-height:1.5;"><?= esc($fsDesc) ?></div>
          </div>
          <div class="flex-grow-1 row g-2 small align-content-center">
            <div class="col-12"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Funding Source</div><div class="fw-semibold"><?= esc($fsLabel) ?></div></div>
            <div class="col-12"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Payer Email</div><div class="fw-semibold"><?= esc($o['paypal_payer_email'] ?: '—') ?></div></div>
            <div class="col-12"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Payer ID</div><div class="fw-semibold"><code><?= esc($o['paypal_payer_id'] ?: '—') ?></code></div></div>
            <?php if ($fs==='card' || $fs==='paypal_credit'): ?>
              <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Funding Card</div><div class="fw-semibold"><?= esc($o['paypal_funding_card_brand'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Last 4</div><div class="fw-semibold">•••• <?= esc($o['paypal_funding_card_last4'] ?: '—') ?></div></div>
            <?php endif; ?>
            <?php if ($fs==='bank'): ?>
              <div class="col-12"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Bank</div><div class="fw-semibold"><?= esc($o['paypal_funding_bank_name'] ?: '—') ?></div></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-e p-4 mb-3" data-testid="fulfillment-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-truck text-primary me-2"></i>Fulfillment Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">License Key Delivery</div>
          <span class="s-badge <?= !empty($keys)?'delivered':'queued' ?>"><?= !empty($keys)?'Assigned':'Pending' ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email Delivery</div>
          <span class="s-badge <?= $lastEmail ? ($lastEmail['status']==='sent'?'delivered':$lastEmail['status']) : 'queued' ?>"><?= $lastEmail ? esc($lastEmail['status']) : 'not sent' ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email Opened</div>
          <?= $lastEmail && $lastEmail['opened_at'] ? '<span class="s-badge opened">Opened '.(int)$lastEmail['opened_count'].'×</span>' : '<span class="text-muted">not viewed</span>' ?></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Install Guide Sent</div>
          <span class="s-badge <?= $lastEmail?'delivered':'queued' ?>">Embedded</span></div>
      </div>
      <?php if ($lastEmail): ?>
        <a href="email-view.php?id=<?= (int)$lastEmail['id'] ?>" target="_blank" class="btn btn-soft-gray btn-sm mt-3"><i class="bi bi-eye me-1"></i> Preview email exactly as customer received it</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Order Timeline -->
  <div class="col-lg-4">
    <div class="card-e p-4 sticky-top" style="top: 90px;">
      <h6 class="fw-bold mb-3"><i class="bi bi-clock-history text-primary me-2"></i>Order Timeline</h6>
      <ul class="timeline" data-testid="order-timeline">
        <?php
        $stages = [
          ['order_created',     'Order Created',         'cart-check'],
          ['payment_completed', 'Payment Completed',     'credit-card-2-back-fill'],
          ['license_assigned',  'License Key Assigned',  'key-fill'],
          ['email_sent',        'Confirmation Email Sent','envelope-check'],
          ['email_delivered',   'Email Delivered',       'inbox-fill'],
          ['email_opened',      'Customer Opened Email', 'envelope-open'],
        ];
        // Hydrate from email tracking
        if ($lastEmail) {
          if ($lastEmail['delivered_at']) $tl['email_delivered'] = $tl['email_delivered'] ?? $lastEmail['delivered_at'];
          if ($lastEmail['opened_at'])    $tl['email_opened']    = $tl['email_opened']    ?? $lastEmail['opened_at'];
        }
        foreach ($stages as [$key,$label,$icon]):
          $done = !empty($tl[$key]);
        ?>
          <li class="<?= $done?'done':'' ?>">
            <div class="ttitle"><i class="bi bi-<?= $icon ?> me-1" style="color: <?= $done?'#10b981':'#94a3b8' ?>;"></i><?= esc($label) ?></div>
            <div class="tdate"><?= $done ? esc(date('M j, Y H:i', strtotime($tl[$key]))) : '— pending —' ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
