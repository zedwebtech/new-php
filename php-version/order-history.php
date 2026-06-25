<?php
/*
 * Customer-facing Order History
 * -----------------------------
 * Lets paying customers re-download their Receipt + Invoice PDFs and
 * resend their license-key email, without having to contact support.
 *
 * Gate: email + order number must BOTH match an existing order.
 * - Order numbers are 13-char random alphanumeric strings — hard to
 *   guess; combined with the customer's email this is a reasonable
 *   defence for a self-service portal.
 * - Failed lookups are rate-limited (per-session: 8 attempts / 10 min)
 *   to prevent brute-force enumeration.
 * - Successful matches are session-bound, so the customer can navigate
 *   freely (refresh / download multiple PDFs) without re-entering data.
 *
 * Actions:
 *   ?action=download&kind=receipt|invoice   stream a PDF
 *   ?action=resend                          re-queue the order-delivery email
 *
 * The page itself is `noindex, nofollow` (handled in includes/header.php).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';

$noIndex   = true;
$pageTitle = 'Order History · ' . SITE_BRAND;

/* ---------- Rate-limit failed lookups (per session) ---------- */
$_SESSION['oh_attempts'] = $_SESSION['oh_attempts'] ?? ['count' => 0, 'reset_at' => 0];
if (time() > ($_SESSION['oh_attempts']['reset_at'] ?? 0)) {
    $_SESSION['oh_attempts'] = ['count' => 0, 'reset_at' => time() + 600];
}
function _oh_throttle_remaining(): int {
    return max(0, 8 - (int)($_SESSION['oh_attempts']['count'] ?? 0));
}

/* ---------- Lookup helper: returns order row + items, or null ---------- */
function _oh_lookup_order(string $emailLc, string $orderNumber): ?array {
    if ($emailLc === '' || $orderNumber === '') return null;
    $pdo = db();
    $st  = $pdo->prepare("SELECT * FROM orders WHERE LOWER(email)=? AND order_number=? LIMIT 1");
    $st->execute([$emailLc, $orderNumber]);
    $o = $st->fetch();
    if (!$o) return null;
    $it = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
    $it->execute([$o['id']]);
    $o['items'] = $it->fetchAll();
    return $o;
}

/* ---------- Build current session unlocked-order map ---------- */
$_SESSION['oh_unlocked'] = $_SESSION['oh_unlocked'] ?? [];
$errors  = [];
$success = '';
$order   = null;

/* ---------- QR-code auto-lookup: when GET params email + order are present
   (from scanning the receipt QR code), treat it as a lookup automatically
   so the customer sees their order details without retyping anything. ---------- */
$_qrEmail = trim($_GET['email'] ?? '');
$_qrOrder = trim($_GET['order'] ?? '');
$_isQrLookup = ($_qrEmail !== '' && $_qrOrder !== '' && empty($_GET['action']) && $_SERVER['REQUEST_METHOD'] !== 'POST');

/* ---------- POST: form submission  /  QR auto-lookup ----------
 *  Skip this block when the POST is a resend (handled separately below),
 *  otherwise the resend payload (which has no email/order_number fields)
 *  would re-run the lookup handler and stack false "Please enter the
 *  email" / "Order number is required" validation errors on top of the
 *  resend response.
 * --------------------------------------------------------------- */
$isResendPost = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend');
if (((($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['action'])) && !$isResendPost)) || $_isQrLookup) {
    if (_oh_throttle_remaining() <= 0) {
        $errors[] = 'Too many lookup attempts. Please wait 10 minutes and try again.';
    } else {
        // QR auto-lookup uses GET params; manual form uses POST params
        $email    = strtolower(trim($_isQrLookup ? $_qrEmail : ($_POST['email'] ?? '')));
        $orderNum = strtoupper(trim($_isQrLookup ? $_qrOrder : ($_POST['order_number'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter the email address used at checkout.';
        }
        if ($orderNum === '') {
            $errors[] = 'Order number is required (e.g. MV25060512345).';
        }
        if (!$errors) {
            $order = _oh_lookup_order($email, $orderNum);
            if (!$order) {
                $_SESSION['oh_attempts']['count']++;
                $remaining = _oh_throttle_remaining();
                $errors[] = 'We couldn\'t find an order matching that email and order number.'
                          . ($remaining > 0 ? ' (' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left)' : '');
                $order = null;
            } else {
                // Unlock this order for the rest of the session.
                $_SESSION['oh_unlocked'][(int)$order['id']] = strtolower($order['email']);
                $_SESSION['oh_attempts']['count'] = 0; // reset on success
            }
        }
    }
}

/* ---------- Resolve "currently viewed" order from session if no POST  ---------- */
if (!$order && !empty($_SESSION['oh_unlocked'])) {
    $oid = array_key_last($_SESSION['oh_unlocked']);
    $stx = db()->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
    $stx->execute([(int)$oid]);
    $o2 = $stx->fetch();
    if ($o2 && strtolower($o2['email']) === ($_SESSION['oh_unlocked'][(int)$oid] ?? '')) {
        $it = db()->prepare("SELECT * FROM order_items WHERE order_id=?");
        $it->execute([(int)$oid]);
        $o2['items'] = $it->fetchAll();
        $order = $o2;
    }
}

/* ---------- Action: download / resend ---------- */
$action = $_GET['action'] ?? '';
// `resend` is now POSTed (with optional editable to_email) so we accept it
// from either method.  Downloads stay GET so the receipt/invoice anchors
// work as plain links.
if ($action === 'resend' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend')) {
    $action = 'resend';
}
if (in_array($action, ['download', 'resend'], true)) {
    if (!$order) { http_response_code(403); exit('Locked.'); }
    if (!isset($_SESSION['oh_unlocked'][(int)$order['id']])) { http_response_code(403); exit('Locked.'); }

    if ($action === 'download') {
        require_once __DIR__ . '/includes/pdf.php';
        $kind = $_GET['kind'] ?? '';
        $pdfItems = [];
        foreach (($order['items'] ?? []) as $it) {
            $pdfItems[] = array_merge($it, [
                'unit_price' => $it['price'] ?? 0,
                'quantity'   => $it['qty']   ?? 1,
            ]);
        }
        try {
            if ($kind === 'receipt') {
                $bin = generate_receipt_pdf($order, $pdfItems);
                $fname = 'Receipt-' . $order['order_number'] . '.pdf';
            } elseif ($kind === 'invoice') {
                $bin = generate_invoice_pdf($order, $pdfItems);
                $fname = 'Invoice-' . $order['order_number'] . '.pdf';
            } elseif ($kind === 'subscription') {
                require_once __DIR__ . '/includes/subscriptions.php';
                $cs = db()->prepare("SELECT * FROM customer_subscriptions WHERE order_id=? LIMIT 1");
                $cs->execute([(int)$order['id']]);
                $subRow = $cs->fetch(PDO::FETCH_ASSOC);
                if (!$subRow) { http_response_code(404); exit('No subscription on this order.'); }
                $plan = sub_plan_get((string)$subRow['plan_slug']) ?: ['name'=>$subRow['plan_name'],'tenure_label'=>'','features'=>[],'devices'=>'','tagline'=>'','duration_months'=>0];
                $bin = sub_generate_certificate_pdf($order, $subRow, $plan);
                $fname = 'Subscription-Details-' . $subRow['customer_id'] . '.pdf';
            } else {
                http_response_code(400); exit('Unknown PDF type.');
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: private, no-store');
            echo $bin; exit;
        } catch (Throwable $e) {
            error_log('[order-history pdf] ' . $e->getMessage());
            http_response_code(500); exit('PDF generation failed. Please contact support.');
        }
    }

    if ($action === 'resend') {
        // Resend the order-delivery email — with PDFs + license keys.
        // The customer can optionally pass a NEW recipient email via the
        // form ("to_email" POST param) — useful when their inbox changed
        // or they want the keys forwarded to a different mailbox.  When
        // omitted we fall back to the email on file.
        $toEmail = strtolower(trim((string)($_POST['to_email'] ?? '')));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $toEmail = (string)$order['email'];
        }

        // Catch typo domains (gmail.con / yaho.com etc.) BEFORE we
        // build PDFs + fire send_email().  Without this guard
        // send_email() silently inserts a status='failed' row but the
        // customer sees a "We've resent" toast and waits in vain.
        require_once __DIR__ . '/includes/mailer.php';
        if (function_exists('email_address_deliverable')) {
            $deliv = email_address_deliverable($toEmail);
            if (!$deliv['ok'] && in_array($deliv['reason'] ?? '', ['no_mx','invalid_syntax'], true)) {
                $errors[] = $deliv['detail']
                    ?: 'That email address looks undeliverable — please double-check the spelling and try again.';
                // Fall through to render below; do NOT call send_email().
                goto resend_finished;
            }
        }
        try {
            require_once __DIR__ . '/includes/pdf.php';
            $pdo = db();
            $items = $order['items'] ?? [];
            // Pull already-assigned license keys for this order so the resent
            // email shows them.
            $keys = $pdo->prepare("SELECT product_slug, license_key FROM license_keys WHERE order_id=?");
            $keys->execute([(int)$order['id']]);
            $keysByProduct = [];
            foreach ($keys->fetchAll() as $k) {
                $keysByProduct[$k['product_slug']][] = $k['license_key'];
            }
            $assignments = [];
            foreach ($items as $it) {
                if ($it['product_slug'] === 'proassist-premium') continue;
                $prodRow = $pdo->prepare("SELECT image, description, apps, activation_url, install_guide_url, brand FROM products WHERE slug=? LIMIT 1");
                $prodRow->execute([$it['product_slug']]);
                $p = $prodRow->fetch() ?: [];
                $assigned = $keysByProduct[$it['product_slug']] ?? [];
                $qty = (int)$it['qty'];
                for ($i = 0; $i < $qty; $i++) {
                    $assignments[] = [
                        'name'              => $it['name'],
                        'image'             => $p['image'] ?? '',
                        'description'       => $p['description'] ?? '',
                        'installation_guide'=> $p['apps'] ?? '',
                        'activation_url'    => activation_url_for_product($it['name'], $p['brand'] ?? '', $p['activation_url'] ?? ''),
                        'install_guide_url' => $p['install_guide_url'] ?? '',
                        'key'               => $assigned[$i] ?? null,
                    ];
                }
            }
            $tok = bin2hex(random_bytes(16));
            // Embed the same review widget on resend — reuse the order's existing
            // review token if present, otherwise create one.
            $reviewUrl = '';
            $rowTok = $pdo->prepare('SELECT request_token FROM customer_reviews WHERE order_id=? ORDER BY id ASC LIMIT 1');
            $rowTok->execute([$order['id']]);
            $rtok = (string)($rowTok->fetchColumn() ?: '');
            if ($rtok === '' && !empty($items[0]['product_slug'])) {
                $rtok = bin2hex(random_bytes(16));
                $pdo->prepare('INSERT INTO customer_reviews (order_id, product_slug, customer_email, customer_name, request_token, region) VALUES (?,?,?,?,?,?)')
                    ->execute([$order['id'], $items[0]['product_slug'], $order['email'], trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')), $rtok, $order['region'] ?? 'US']);
            }
            if ($rtok !== '') {
                $reviewBase = trim((string)setting_get('site_domain_url', '')) ?: site_url();
                $reviewUrl  = rtrim($reviewBase, '/') . '/review.php?t=' . $rtok;
            }
            $html = build_order_email_html($order, $items, $assignments, $tok, $reviewUrl);
            $subjectTpl = setting_get('email_template_subject', 'Your Microsoft product key — Order #{{order_number}}');
            try {
                $row = $pdo->prepare("SELECT subject FROM email_templates WHERE code='order_delivery' AND active=1 LIMIT 1");
                $row->execute();
                $s = $row->fetchColumn();
                if ($s) $subjectTpl = $s;
            } catch (Throwable $e) {}
            $subject = strtr($subjectTpl, [
                '{{order_number}}' => $order['order_number'],
                '{{customer_name}}'=> $order['first_name'] ?? '',
                '{{product_name}}' => $items[0]['name'] ?? 'your software',
                '{{amount}}'       => number_format((float)($order['total'] ?? 0), 2),
                '{{company_name}}' => company_info()['name'] ?? '',
            ]);
            $pdfItems = [];
            foreach ($items as $it) {
                $pdfItems[] = array_merge($it, ['unit_price' => $it['price'] ?? 0, 'quantity' => $it['qty'] ?? 1]);
            }
            $pdfPaths = generate_order_pdfs($order, $pdfItems);
            send_email($toEmail, $subject, $html, (int)$order['id'], 'order_delivery', 0, $pdfPaths);
            // Confirm the row actually landed as 'queued' or 'sent', not
            // 'failed' — the deliverability pre-check above catches typos
            // but transient SMTP failures can still flip the row to
            // 'failed'.  Read back the latest row and gate the success
            // message on its status.
            $latestStatus = '';
            try {
                $stRow = $pdo->prepare("SELECT status, note FROM email_outbox
                                        WHERE recipient=? AND order_id=?
                                        ORDER BY id DESC LIMIT 1");
                $stRow->execute([$toEmail, (int)$order['id']]);
                $latestRow = $stRow->fetch();
                $latestStatus = (string)($latestRow['status'] ?? '');
                $latestNote   = (string)($latestRow['note']   ?? '');
            } catch (Throwable $e) { /* non-fatal */ }
            if ($latestStatus === 'failed') {
                $errors[] = 'We couldn\'t deliver the email to ' . esc($toEmail) . '. '
                          . ($latestNote ? '(' . esc($latestNote) . ')' : '')
                          . ' Try a different inbox or contact support.';
            } else {
                $success = 'We\'ve resent your license keys + PDFs to ' . esc($toEmail) . '. Check that inbox in a few minutes.';
            }
        } catch (Throwable $e) {
            error_log('[order-history resend] ' . $e->getMessage());
            $errors[] = 'Something went wrong while resending the email. Please contact support.';
        }
        resend_finished:
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 900px;">
  <div class="text-center mb-4">
    <h1 class="fw-bold mb-2" style="font-size:2.1rem;letter-spacing:-.5px;" data-testid="oh-heading">Track Order &amp; Receipts</h1>
    <p class="text-secondary mb-0" style="font-size:1.05rem;">Look up your order with your <strong>email + order number</strong>, then re-download Receipts / Invoices or resend your license keys to any email.</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger" data-testid="oh-errors">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success" data-testid="oh-success"><i class="bi bi-check2-circle me-1"></i><?= $success /* already escaped */ ?></div>
  <?php endif; ?>

  <?php if ($order): ?>
    <!-- Order found — show summary + actions -->
    <?php
    // Generated freshly each render so PDFs are always up-to-date.
    $orderTotal    = number_format((float)$order['total'], 2);
    $orderDate     = date('F j, Y', strtotime((string)($order['created_at'] ?? 'now')));
    $statusLabel   = strtoupper((string)$order['status']);
    $statusColor   = ['paid' => 'success', 'pending' => 'warning', 'refunded' => 'secondary', 'failed' => 'danger'][$order['status']] ?? 'secondary';
    ?>
    <div class="card shadow-sm border-0 mb-4" data-testid="oh-order-card" style="border-radius:18px;overflow:hidden;">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
          <div>
            <div class="text-uppercase small text-secondary" style="letter-spacing:1.5px;font-weight:700;">Order</div>
            <div class="fw-bold" style="font-size:1.35rem;color:#0f172a;" data-testid="oh-order-number">#<?= esc($order['order_number']) ?></div>
            <div class="small text-secondary mt-1">Placed on <?= esc($orderDate) ?></div>
          </div>
          <div class="text-end">
            <div class="text-uppercase small text-secondary" style="letter-spacing:1.5px;font-weight:700;">Total</div>
            <div class="fw-bold" style="font-size:1.45rem;color:#0f172a;" data-testid="oh-order-total">$<?= esc($orderTotal) ?> <?= esc($order['currency'] ?? 'USD') ?></div>
            <span class="badge rounded-pill text-bg-<?= esc($statusColor) ?>-subtle text-<?= esc($statusColor) ?> mt-1"><?= esc($statusLabel) ?></span>
          </div>
        </div>

        <div class="border-top pt-3 mb-3">
          <div class="text-uppercase small text-secondary mb-2" style="letter-spacing:1.5px;font-weight:700;">Items</div>
          <ul class="list-unstyled mb-0">
            <?php foreach ($order['items'] as $it): ?>
              <li class="d-flex justify-content-between py-1" data-testid="oh-line-item">
                <span><?= esc($it['name']) ?> <span class="text-secondary">&times; <?= (int)$it['qty'] ?></span></span>
                <span class="fw-semibold">$<?= number_format((float)$it['price'] * (int)$it['qty'], 2) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="border-top pt-3 mb-3" data-testid="oh-delivery-status">
          <?php
            $dStatus = $order['delivery_status'] ?? 'delivered';
            $hasKeysYet = false;
            try { $ck = db()->prepare("SELECT COUNT(*) FROM license_keys WHERE order_id=? AND license_key<>''"); $ck->execute([(int)$order['id']]); $hasKeysYet = (int)$ck->fetchColumn() > 0; } catch (Throwable $e) {}
          ?>
          <div class="text-uppercase small text-secondary mb-2" style="letter-spacing:1.5px;font-weight:700;">Delivery status</div>
          <?php if ($dStatus === 'pending' || !$hasKeysYet): ?>
            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#fef3c7;color:#92400e;">
              <i class="bi bi-clock-history fs-5"></i>
              <div class="small"><strong>License key pending.</strong> Your key is being prepared and will be emailed to <strong><?= esc($order['email']) ?></strong> within 30 minutes to 1 hour. This page will show the key once it's ready.</div>
            </div>
          <?php else: ?>
            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#dcfce7;color:#166534;">
              <i class="bi bi-check-circle-fill fs-5"></i>
              <div class="small"><strong>Delivered.</strong> Your license key(s) are shown below and were emailed to <strong><?= esc($order['email']) ?></strong>.</div>
            </div>
          <?php endif; ?>
        </div>

        <?php
        // License keys for this order (assigned at fulfilment), grouped by slug.
        $ohKeys = [];
        try {
            $stmt = db()->prepare("SELECT product_slug, license_key FROM license_keys WHERE order_id=? ORDER BY id ASC");
            $stmt->execute([(int)$order['id']]);
            foreach ($stmt->fetchAll() as $r) {
                $ohKeys[$r['product_slug']][] = $r['license_key'];
            }
        } catch (Throwable $e) { /* keys not assigned yet */ }
        ?>
        <?php if ($ohKeys): ?>
          <div class="border-top pt-3 mb-3" data-testid="oh-keys-block">
            <div class="text-uppercase small text-secondary mb-2" style="letter-spacing:1.5px;font-weight:700;">
              <i class="bi bi-key-fill text-warning me-1"></i>License Keys
            </div>
            <?php foreach ($order['items'] as $it):
              $slug = $it['product_slug'] ?? '';
              $myKeys = $ohKeys[$slug] ?? [];
              if (!$myKeys) continue;
            ?>
              <div class="mb-2" data-testid="oh-keys-row">
                <div class="small fw-semibold mb-1" style="color:#0f172a;"><?= esc($it['name']) ?></div>
                <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2">
                  <?php foreach ($myKeys as $k): ?>
                    <li class="d-inline-flex align-items-center gap-1">
                      <code class="d-inline-block px-2 py-1 fw-bold oh-key-code" data-testid="oh-key" style="background:#fef3c7;color:#92400e;border-radius:6px;font-family:'SF Mono',Menlo,monospace;letter-spacing:.6px;font-size:13px;border:1px solid #fde68a;"><?= esc($k) ?></code>
                      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 oh-copy-key" data-key="<?= esc($k) ?>" data-testid="oh-copy-key" title="Copy license key" style="font-size:12px;"><i class="bi bi-clipboard"></i></button>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
            <small class="text-secondary"><i class="bi bi-shield-lock text-success me-1"></i>Keep your keys private — anyone with access to this page can use them.</small>
          </div>
        <?php endif; ?>

        <div class="border-top pt-3 d-flex flex-wrap gap-2 align-items-center">
          <a href="?action=download&kind=receipt" class="btn btn-primary rounded-pill px-4" data-testid="oh-download-receipt">
            <i class="bi bi-receipt me-1"></i> Download Receipt (PDF)
          </a>
          <a href="?action=download&kind=invoice" class="btn btn-outline-primary rounded-pill px-4" data-testid="oh-download-invoice">
            <i class="bi bi-file-earmark-text me-1"></i> Download Invoice (PDF)
          </a>
          <?php if (!empty($order['subscription_plan'])): ?>
            <a href="?action=download&kind=subscription" class="btn btn-success rounded-pill px-4" data-testid="oh-download-subscription">
              <i class="bi bi-patch-check me-1"></i> Download Subscription Details (PDF)
            </a>
          <?php endif; ?>
        </div>

        <!-- Resend link with editable recipient email -->
        <div class="border-top pt-3 mt-3" data-testid="oh-resend-card">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-envelope-arrow-up text-primary"></i>
            <strong style="color:#0f172a;font-size:.95rem;">Resend license keys + PDFs</strong>
          </div>
          <p class="small text-secondary mb-2" style="line-height:1.5;">Need the keys at a different mailbox? Edit the email below before sending — we'll deliver to that address instead.</p>
          <form method="post" class="row g-2 align-items-end" data-testid="oh-resend-form">
            <input type="hidden" name="action" value="resend">
            <div class="col-md-7">
              <label class="form-label small fw-semibold mb-1" for="oh-resend-email"><i class="bi bi-envelope me-1"></i>Send to</label>
              <input type="email" name="to_email" id="oh-resend-email" class="form-control" required
                     value="<?= esc($order['email']) ?>"
                     placeholder="you@example.com"
                     data-testid="oh-resend-email-input">
              <div class="form-text small">Defaults to the email on file (<strong><?= esc($order['email']) ?></strong>). Change it to forward to a different inbox.</div>
            </div>
            <div class="col-md-5">
              <button type="submit" class="btn btn-outline-primary rounded-pill px-4 w-100" data-testid="oh-resend-email-btn">
                <i class="bi bi-send-arrow-up me-1"></i> Resend link
              </button>
            </div>
          </form>
        </div>

        <div class="small text-secondary mt-3">
          <i class="bi bi-shield-lock text-success me-1"></i>
          Documents are generated fresh on each download. Resend is rate-limited to prevent abuse.
        </div>
      </div>
    </div>

    <div class="text-center">
      <a href="order-history.php?clear=1" class="text-secondary small text-decoration-none" data-testid="oh-look-up-another">
        <i class="bi bi-arrow-left-short"></i> Look up a different order
      </a>
    </div>
    <?php if (!empty($_GET['clear'])): ?>
      <script>sessionStorage.clear();</script>
      <?php $_SESSION['oh_unlocked'] = []; header('Location: order-history.php'); exit; ?>
    <?php endif; ?>

  <?php else: ?>
    <!-- Lookup form -->
    <div class="card shadow-sm border-0" style="border-radius:18px;overflow:hidden;">
      <div class="card-body p-4 p-md-5">
        <form method="post" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Email used at checkout</label>
            <input type="email" name="email" required class="form-control form-control-lg" placeholder="you@example.com" value="<?= esc($_POST['email'] ?? '') ?>" data-testid="oh-email-input">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Order number</label>
            <input type="text" name="order_number" required class="form-control form-control-lg" placeholder="e.g. MV25060512345" value="<?= esc($_POST['order_number'] ?? '') ?>" style="font-family:'SF Mono',Menlo,monospace;letter-spacing:.5px;" data-testid="oh-order-input">
            <div class="form-text small">It looks like <code>MV</code> followed by 11 numbers (in the confirmation email).</div>
          </div>
          <div class="col-12 d-flex flex-wrap gap-2 align-items-center mt-2">
            <button type="submit" class="btn btn-primary btn-lg rounded-pill px-4" data-testid="oh-lookup-btn">
              <i class="bi bi-search me-1"></i> Find my order
            </button>
            <small class="text-secondary ms-2"><i class="bi bi-shield-lock me-1"></i>Secured by email + order-number match. No login needed.</small>
          </div>
        </form>
      </div>
    </div>

    <div class="text-center mt-4">
      <p class="text-secondary mb-0" style="font-size:.92rem;">
        Can't find your order number? Check the subject line of your <strong>order confirmation</strong> email, or
        <a href="contact.php" class="text-primary text-decoration-none">contact support</a> &mdash; we'll find it for you.
      </p>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
