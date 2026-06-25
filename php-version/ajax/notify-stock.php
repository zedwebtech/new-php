<?php
// AJAX endpoint: subscribe a customer to "back in stock" notifications for a
// specific product (current region). Publicly callable (no admin auth).
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$in   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$slug = trim((string)($in['product_slug'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));

if ($slug === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing product or email']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

// Stronger validation — catch typo domains (gmial.com / gmail.con / yaho.com
// etc.) and missing MX records BEFORE we accept the subscription, so the
// customer gets a clear "did you mean…?" hint instead of a silent failure
// later when the confirmation email bounces.
if (function_exists('email_address_deliverable')) {
    $deliv = email_address_deliverable($email);
    if (!$deliv['ok'] && in_array($deliv['reason'], ['no_mx','invalid_syntax'], true)) {
        http_response_code(400);
        echo json_encode([
            'ok'     => false,
            'error'  => $deliv['detail'] ?: 'That email address looks undeliverable — please double-check the spelling.',
            'hint'   => $deliv['detail'],
            'reason' => $deliv['reason'],
        ]);
        exit;
    }
}

$pdo    = db();
$region = active_region_code();

// Confirm product exists
$pst = $pdo->prepare('SELECT slug, name FROM products WHERE slug = ?');
$pst->execute([$slug]);
$product = $pst->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found']);
    exit;
}

// De-dupe: if this email is already pending (notified_at IS NULL) for the same
// product+region, just respond success without inserting again.
try {
    $dup = $pdo->prepare("SELECT id FROM stock_notifications
                          WHERE product_slug=? AND email=? AND region=? AND notified_at IS NULL
                          LIMIT 1");
    $dup->execute([$slug, $email, $region]);
    if ($dup->fetchColumn()) {
        echo json_encode([
            'ok'      => true,
            'already' => true,
            'message' => "You're already on the list — we'll email you the moment it's back in stock.",
        ]);
        exit;
    }

    $pdo->prepare("INSERT INTO stock_notifications (product_slug, email, region, created_at)
                   VALUES (?,?,?,NOW())")
        ->execute([$slug, $email, $region]);

    // -----------------------------------------------------------------------
    // Send a friendly "you're on the list" confirmation email so the customer
    // knows the subscription was actually saved.  Queued via the standard
    // SMTP outbox so it goes out reliably + appears in Email Activity.
    // -----------------------------------------------------------------------
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $co       = company_info();
        $base     = rtrim(site_url(), '/');
        $prodUrl  = $base . '/product.php?slug=' . urlencode($product['slug']);
        $shopUrl  = $base . '/shop.php';
        $confirmSubject = "We've added you to the waitlist for " . $product['name'];
        $confirmHtml = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:32px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.08);">
      <!-- HEADER -->
      <tr><td style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);padding:30px 32px;text-align:center;color:#fff;">
        <div style="font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:700;opacity:.9;">' . esc($co['name']) . '</div>
        <div style="margin:16px auto 10px;width:60px;height:60px;background:rgba(255,255,255,.18);border-radius:50%;display:inline-block;text-align:center;line-height:60px;">
          <span style="font-size:28px;">&#x1F514;</span>
        </div>
        <h1 style="margin:6px 0 4px;font-size:24px;font-weight:800;letter-spacing:-.2px;">You\'re on the list!</h1>
        <div style="color:#bfdbfe;font-size:13px;">We\'ll let you know the second it\'s back.</div>
      </td></tr>
      <!-- BODY -->
      <tr><td style="padding:32px 36px 22px;">
        <p style="font-size:15px;color:#334155;margin:0 0 22px;line-height:1.6;">Hi there 👋<br>Thanks for signing up — we\'ve saved your spot. As soon as we restock, you\'ll be the first to know.</p>

        <!-- Product card -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:24px;">
          <tr><td style="padding:18px 20px;">
            <div style="font-size:10.5px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Waitlist for</div>
            <div style="font-size:18px;font-weight:700;color:#0f172a;line-height:1.35;">' . esc($product['name']) . '</div>
            <div style="margin-top:10px;font-size:12.5px;color:#64748b;">Email: <strong style="color:#0f172a;">' . esc($email) . '</strong></div>
          </td></tr>
        </table>

        <!-- What happens next? -->
        <div style="font-size:13.5px;color:#334155;line-height:1.7;margin-bottom:20px;">
          <div style="font-weight:700;color:#0f172a;margin-bottom:6px;">What happens next?</div>
          &#9989; The instant inventory comes in, we\'ll email you with a direct buy link<br>
          &#9989; Stock is usually limited — early subscribers get first pick<br>
          &#9989; You can unsubscribe any time by replying STOP
        </div>

        <!-- CTA - explore other products -->
        <div style="text-align:center;margin:26px 0 8px;">
          <a href="' . esc($shopUrl) . '" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13.5px;letter-spacing:.3px;box-shadow:0 6px 18px rgba(29,78,216,.35);">Browse other products &rarr;</a>
        </div>
        <p style="text-align:center;font-size:11.5px;color:#94a3b8;margin:14px 0 0;">Looking for something similar that\'s in stock right now?</p>
      </td></tr>
      <!-- FOOTER -->
      <tr><td style="background:#0f172a;padding:20px 32px;color:#94a3b8;font-size:11.5px;line-height:1.55;text-align:center;">
        <div style="color:#e2e8f0;font-weight:700;font-size:13px;margin-bottom:6px;">' . esc($co['name']) . '</div>
        Need help? <a href="mailto:' . esc($co['email']) . '" style="color:#60a5fa;text-decoration:none;">' . esc($co['email']) . '</a>
        &middot; <span style="color:#cbd5e1;">' . esc($co['phone']) . '</span><br>
        <span style="color:#64748b;">You\'re receiving this because you asked to be notified when ' . esc($product['name']) . ' is back in stock.</span>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
        // Use send_email() — it handles both production (queues + SMTP worker)
        // and dev mode (marks row as 'sent' for visibility) AND runs the
        // DNS deliverability pre-flight so typo'd addresses land in the
        // Failed tab instead of vanishing silently.
        require_once __DIR__ . '/../includes/email.php';
        send_email($email, $confirmSubject, $confirmHtml, null, 'stock_waitlist_confirm', 0);
    } catch (Throwable $e) {
        // Email failure must NOT break the subscription save — log + continue.
        @error_log('[notify-stock] confirm email failed: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'      => true,
        'already' => false,
        'message' => "Thanks! We've emailed " . $email . " a confirmation and will alert you the moment " . $product['name'] . " is back in stock.",
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save your request. Please try again.']);
}
