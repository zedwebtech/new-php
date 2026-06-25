<?php
// Order-success page review submission. Saves the customer's star rating +
// comment (reusing the customer_reviews row created at fulfillment), publishes
// it on the site, pings the admin bell and emails a thank-you confirmation.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$orderNumber = trim((string)($in['order'] ?? ''));
$rating  = (int)($in['rating'] ?? 0);
$comment = trim((string)($in['comment'] ?? ''));
$ai      = (int)($in['ai_generated'] ?? 0);

if ($rating < 1 || $rating > 5) { echo json_encode(['ok' => false, 'error' => 'Please select a star rating.']); exit; }
if ($comment === '')           { echo json_encode(['ok' => false, 'error' => 'Please write a short comment or pick a suggestion.']); exit; }
if ($orderNumber === '')       { echo json_encode(['ok' => false, 'error' => 'Missing order reference.']); exit; }

$pdo = db();
$st = $pdo->prepare('SELECT * FROM orders WHERE order_number=? LIMIT 1');
$st->execute([$orderNumber]);
$order = $st->fetch();
if (!$order || $order['status'] !== 'paid') { echo json_encode(['ok' => false, 'error' => 'Order not found.']); exit; }

// First non-ProAssist product on the order — that's what the review attaches to.
$it = $pdo->prepare("SELECT product_slug, name FROM order_items WHERE order_id=? AND product_slug <> 'proassist-premium' ORDER BY id ASC LIMIT 1");
$it->execute([(int)$order['id']]);
$item = $it->fetch();
$slug = $item['product_slug'] ?? null;
$productName = $item['name'] ?? 'your purchase';

// Auto-hide low ratings (<3) — same policy as review.php. Anything 3+ is
// published straight to the public reviews page.
$autoStatus = $rating >= 3 ? 'published' : 'hidden';
$custName = trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? ''))) ?: 'A customer';

// Reuse the customer_reviews row created at fulfillment if it exists.
$existing = $pdo->prepare('SELECT id, submitted_at FROM customer_reviews WHERE order_id=? AND product_slug=? LIMIT 1');
$existing->execute([(int)$order['id'], $slug]);
$row = $existing->fetch();

if ($row) {
    if (!empty($row['submitted_at'])) {
        echo json_encode(['ok' => false, 'already' => true, 'error' => 'You have already submitted a review for this order. Thank you!']);
        exit;
    }
    $pdo->prepare('UPDATE customer_reviews SET rating=?, comment=?, ai_generated=?, status=?, submitted_at=NOW() WHERE id=?')
        ->execute([$rating, $comment, $ai, $autoStatus, (int)$row['id']]);
} else {
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO customer_reviews (order_id, product_slug, customer_email, customer_name, rating, comment, ai_generated, status, request_token, submitted_at, region) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)')
        ->execute([(int)$order['id'], $slug, $order['email'], $custName, $rating, $comment, $ai, $autoStatus, $tok, $order['region'] ?? 'US']);
}

// Admin PWA bell.
try {
    admin_notify(
        'review',
        $rating . '★ review from ' . $custName,
        mb_substr($comment, 0, 140, 'UTF-8'),
        $rating < 3 ? '/admin.php?tab=reviews&status=hidden' : '/admin.php?tab=reviews&status=published'
    );
} catch (Throwable $e) { /* best-effort */ }

// Thank-you-for-your-review email.
try {
    $co    = company_info();
    $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    $first = esc((string)($order['first_name'] ?? '') ?: 'there');
    $html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;padding:28px 24px;color:#1f2937;">'
           . '<div style="text-align:center;margin-bottom:18px;font-size:20px;font-weight:800;color:#0f172a;">' . esc($co['name']) . '</div>'
           . '<h2 style="color:#0f172a;font-size:20px;margin:0 0 10px;">Thank you for your review, ' . $first . '!</h2>'
           . '<p style="font-size:14px;color:#475569;line-height:1.6;margin:0 0 16px;">We really appreciate you taking a moment to share your experience with <strong>' . esc($productName) . '</strong>. Your feedback helps other customers shop with confidence.</p>'
           . '<div style="background:#f8fafc;border-left:4px solid #06b6d4;border-radius:8px;padding:14px 16px;margin:0 0 16px;">'
           . '<div style="font-size:22px;color:#facc15;letter-spacing:3px;">' . $stars . '</div>'
           . '<div style="font-size:13.5px;color:#374151;line-height:1.55;margin-top:8px;font-style:italic;">&ldquo;' . esc($comment) . '&rdquo;</div>'
           . '</div>'
           . ($rating >= 3
               ? '<p style="font-size:13px;color:#16a34a;margin:0 0 16px;"><strong>Your review is now live</strong> on our website.</p>'
               : '<p style="font-size:13px;color:#475569;margin:0 0 16px;">Our team has been notified and will personally follow up to make things right.</p>')
           . '<p style="font-size:13px;color:#94a3b8;margin:0;">Order #' . esc((string)$order['order_number']) . ' &middot; ' . esc($co['name']) . '</p>'
           . '</div>';
    send_email((string)$order['email'], 'Thanks for your review of ' . $productName . '!', $html, (int)$order['id'], 'review_thanks', 0);
} catch (Throwable $e) { @error_log('[success-review email] ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'published' => ($autoStatus === 'published')]);
