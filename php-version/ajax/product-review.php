<?php
// Public "Leave a review" submission from the product page.
// Genuine + Google-compliant: the reviewer MUST verify a real PAID order
// (order number + email) that actually contains this product. No purchase →
// no review, so nothing is ever fabricated. Auto-publishes 3★+ (same policy
// as review.php / success-review.php); <3★ is hidden for admin follow-up.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
header('Content-Type: application/json');

$in          = json_decode(file_get_contents('php://input'), true) ?: [];
$slug        = trim((string)($in['product_slug'] ?? ''));
$orderNumber = trim((string)($in['order_number'] ?? ''));
$email       = strtolower(trim((string)($in['email'] ?? '')));
$rating      = (int)($in['rating'] ?? 0);
$comment     = trim((string)($in['comment'] ?? ''));

if ($slug === '')                 { echo json_encode(['ok' => false, 'error' => 'Missing product.']); exit; }
if ($rating < 1 || $rating > 5)   { echo json_encode(['ok' => false, 'error' => 'Please select a star rating.']); exit; }
if (mb_strlen($comment) < 10)     { echo json_encode(['ok' => false, 'error' => 'Please write a short comment (at least 10 characters).']); exit; }
if ($orderNumber === '' || $email === '') { echo json_encode(['ok' => false, 'error' => 'Enter the order number and email from your purchase to verify it.']); exit; }

$pdo = db();

// Verify the order: must exist, be paid, and the email must match.
$st = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
$st->execute([$orderNumber]);
$order = $st->fetch();
if (!$order || $order['status'] !== 'paid' || strtolower((string)$order['email']) !== $email) {
    echo json_encode(['ok' => false, 'error' => "We couldn't match that order number + email. Please check your confirmation email."]);
    exit;
}

// The order must actually contain this product.
$it = $pdo->prepare('SELECT name FROM order_items WHERE order_id = ? AND product_slug = ? LIMIT 1');
$it->execute([(int)$order['id'], $slug]);
$item = $it->fetch();
if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'That order does not include this product, so it cannot be reviewed here.']);
    exit;
}
$productName = $item['name'] ?? 'your purchase';

// One review per order + product.
$existing = $pdo->prepare('SELECT id, submitted_at FROM customer_reviews WHERE order_id = ? AND product_slug = ? LIMIT 1');
$existing->execute([(int)$order['id'], $slug]);
$row = $existing->fetch();
if ($row && !empty($row['submitted_at'])) {
    echo json_encode(['ok' => false, 'already' => true, 'error' => 'You have already reviewed this product for this order. Thank you!']);
    exit;
}

$autoStatus = $rating >= 3 ? 'published' : 'hidden';
$custName   = trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? ''))) ?: 'A customer';

if ($row) {
    $pdo->prepare('UPDATE customer_reviews SET rating=?, comment=?, ai_generated=0, status=?, submitted_at=NOW() WHERE id=?')
        ->execute([$rating, $comment, $autoStatus, (int)$row['id']]);
} else {
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO customer_reviews (order_id, product_slug, customer_email, customer_name, rating, comment, ai_generated, status, request_token, submitted_at, region) VALUES (?,?,?,?,?,?,0,?,?,NOW(),?)')
        ->execute([(int)$order['id'], $slug, $order['email'], $custName, $rating, $comment, $autoStatus, $tok, $order['region'] ?? 'US']);
}

try {
    admin_notify(
        'review',
        $rating . '★ review from ' . $custName,
        mb_substr($comment, 0, 140, 'UTF-8'),
        $rating < 3 ? '/admin.php?tab=reviews&status=hidden' : '/admin.php?tab=reviews&status=published'
    );
} catch (Throwable $e) { /* best-effort */ }

echo json_encode([
    'ok'        => true,
    'published' => ($autoStatus === 'published'),
    'message'   => $autoStatus === 'published'
        ? 'Thank you! Your review is now live and helps other customers.'
        : 'Thank you for your feedback — our team will personally follow up.',
]);
