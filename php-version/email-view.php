<?php
// Render the EXACT HTML that was sent to the customer (admin view).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
ensure_admin();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$em = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
$em->execute([$id]); $em = $em->fetch();
if (!$em) { http_response_code(404); die('Email not found'); }

/**
 * Re-render the email body from the linked order + template_code when the
 * stored html column is too sparse to be a real email (e.g. demo/seed rows
 * that only contain "<p>Hi</p>"). Falls back to the stored html on failure.
 */
function regenerate_email_html_for_view(array $em): string {
    $stored = (string)($em['html'] ?? '');
    // Heuristic: a real templated email is well over 500 chars and contains
    // either <html>, <table> or <div style="…">. Sparse rows like "<p>Hi</p>"
    // (~9 chars) get rebuilt below.
    $looksReal = strlen($stored) >= 500
        || stripos($stored, '<table') !== false
        || stripos($stored, '<div style') !== false;
    if ($looksReal) return $stored;

    $orderId = (int)($em['order_id'] ?? 0);
    $tplCode = (string)($em['template_code'] ?? '');
    if ($tplCode === '') return $stored;

    $pdo = db();
    try {
        $order = null; $items = []; $assignments = [];
        if ($orderId) {
            $o = $pdo->prepare('SELECT * FROM orders WHERE id=?');
            $o->execute([$orderId]); $order = $o->fetch();
        }
        if ($order) {
            // Fetch items
            $its = $pdo->prepare('SELECT oi.*, p.image, p.description, p.apps AS installation_guide, p.activation_url, p.install_guide_url, p.brand
                                  FROM order_items oi LEFT JOIN products p ON p.slug = oi.product_slug
                                  WHERE oi.order_id = ?');
            $its->execute([$orderId]); $items = $its->fetchAll();

            // Fetch already-assigned license keys for this order (don't consume new ones)
            $keysStmt = $pdo->prepare('SELECT product_slug, license_key FROM license_keys WHERE order_id=? AND status="sold"');
            $keysStmt->execute([$orderId]);
            $keysByProduct = [];
            foreach ($keysStmt->fetchAll() as $k) { $keysByProduct[$k['product_slug']][] = $k['license_key']; }

            foreach ($items as $item) {
                if ($item['product_slug'] === 'proassist-premium') continue;
                for ($i = 0; $i < (int)$item['qty']; $i++) {
                    $key = $keysByProduct[$item['product_slug']][$i] ?? null;
                    $assignments[] = [
                        'name'              => $item['name'],
                        'image'             => $item['image'],
                        'description'       => $item['description'] ?? '',
                        'installation_guide'=> $item['installation_guide'] ?? '',
                        'activation_url'    => activation_url_for_product($item['name'], $item['brand'] ?? '', $item['activation_url'] ?? ''),
                        'install_guide_url' => $item['install_guide_url'] ?? '',
                        'key'               => $key,
                    ];
                }
            }
        } else {
            // Fallback synthetic order so the template still renders styled
            $order = [
                'email'           => $em['recipient'] ?? '',
                'first_name'      => '',
                'last_name'       => '',
                'order_number'    => 'PREVIEW',
                'total'           => 0,
                'payment_method'  => 'card',
                'card_statement_name' => '',
            ];
        }

        $tok = (string)($em['tracking_token'] ?? bin2hex(random_bytes(16)));

        if ($tplCode === 'order_delivery') {
            // Reuse the order's review token so the embedded widget renders in the view.
            $rvTok = '';
            try {
                $rt = db()->prepare('SELECT request_token FROM customer_reviews WHERE order_id=? ORDER BY id ASC LIMIT 1');
                $rt->execute([$order['id'] ?? 0]);
                $rvTok = (string)($rt->fetchColumn() ?: '');
            } catch (Throwable $e) {}
            $reviewUrl = $rvTok !== '' ? (rtrim((trim((string)setting_get('site_domain_url','')) ?: site_url()), '/') . '/review.php?t=' . $rvTok) : '';
            return build_order_email_html($order, $items, $assignments, $tok, $reviewUrl);
        }
        // Other templates — render via the generic template renderer with order vars
        $firstItem = $items[0] ?? [];
        $vars = [
            'customer_name'  => esc(($order['first_name'] ?? '') ?: 'there'),
            'customer_email' => esc($order['email'] ?? ''),
            'order_number'   => esc($order['order_number'] ?? ''),
            'amount'         => number_format((float)($order['total'] ?? 0), 2),
            'product_name'   => esc($firstItem['name'] ?? 'your software'),
            'review_url'     => rtrim(site_url(), '/') . '/review.php?t=' . $tok,
        ];
        $html = render_template($tplCode, $vars);
        return $html ?: $stored;
    } catch (Throwable $e) {
        return $stored;
    }
}

if (($_GET['raw'] ?? '') === '1') {
    // Render the original HTML in an isolated iframe context
    header('Content-Type: text/html; charset=utf-8');
    echo regenerate_email_html_for_view($em);
    exit;
}

$pageTitle = 'Email Preview · ' . esc($em['subject']);
$adminActive = 'emails';
include __DIR__ . '/includes/admin-shell.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <a href="admin.php?tab=emails" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Email Activity</a>
    <h1 class="h5 fw-bold mb-0 mt-1" data-testid="email-preview-title">Email Preview</h1>
    <small class="text-muted">Exactly as <?= esc($em['recipient']) ?> received it</small>
    <?php
    $stored = (string)($em['html'] ?? '');
    $rebuilt = strlen($stored) < 500
        && stripos($stored, '<table') === false
        && stripos($stored, '<div style') === false
        && trim((string)$em['template_code']) !== '';
    if ($rebuilt): ?>
      <div class="small text-warning mt-1" data-testid="email-preview-rebuilt">
        <i class="bi bi-info-circle me-1"></i>Preview rebuilt from the live <code><?= esc($em['template_code']) ?></code> template + order data.
      </div>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <span class="s-badge <?= esc($em['status']) ?>"><?= esc($em['status']) ?></span>
    <?php if ($em['opened_at']): ?><span class="s-badge opened">Opened <?= (int)$em['opened_count'] ?>×</span><?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-e p-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Email Metadata</h6>
      <table class="table table-sm mb-0" style="background:transparent;color:var(--text);">
        <tr><th>Subject Line</th><td><?= esc($em['subject']) ?></td></tr>
        <tr><th>Recipient</th><td><?= esc($em['recipient']) ?></td></tr>
        <tr><th>Template</th><td><?= esc($em['template_code'] ?: 'inline') ?></td></tr>
        <tr><th>Provider</th><td><?= esc($em['provider_id'] ?: '—') ?></td></tr>
        <tr><th>Sent</th><td><?= esc(date('M j, Y H:i', strtotime($em['created_at']))) ?></td></tr>
        <tr><th>Delivered</th><td><?= $em['delivered_at'] ? esc(date('M j, Y H:i', strtotime($em['delivered_at']))) : '—' ?></td></tr>
        <tr><th>Opened</th><td><?= $em['opened_at'] ? esc(date('M j, Y H:i', strtotime($em['opened_at']))) : 'not yet' ?></td></tr>
        <tr><th>Open Count</th><td><?= (int)$em['opened_count'] ?></td></tr>
      </table>
      <?php if ($em['order_id']): ?>
        <hr>
        <a href="order-view.php?id=<?= (int)$em['order_id'] ?>" class="btn btn-soft-blue btn-sm"><i class="bi bi-receipt me-1"></i>View Linked Order</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card-e p-0">
      <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="background:var(--bg);">
        <small class="text-muted">Subject: <strong style="color:var(--text);"><?= esc($em['subject']) ?></strong></small>
        <small class="text-muted">To: <strong style="color:var(--text);"><?= esc($em['recipient']) ?></strong></small>
      </div>
      <iframe src="email-view.php?id=<?= (int)$id ?>&raw=1" data-testid="email-iframe"
              style="width:100%;height:780px;border:none;background:#fff;border-radius:0 0 12px 12px;"></iframe>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
