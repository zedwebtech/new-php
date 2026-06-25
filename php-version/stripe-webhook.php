<?php
/**
 * /stripe-webhook.php
 *
 * Stripe → server webhook endpoint.  Closes the loop for payments that
 * complete while the customer's tab is closed (no order-success redirect).
 *
 * Configure on Stripe dashboard: webhooks → add endpoint
 *   URL    : https://<your-domain>/stripe-webhook.php
 *   Secret : paste into Admin → API / Payment Gateway → Card → Webhook Secret
 *   Events : checkout.session.completed, payment_intent.succeeded,
 *            payment_intent.payment_failed, charge.refunded
 *
 * Stripe expects an HTTP 2xx within ~10 s.  We do the bare minimum required
 * to ack quickly, log the event, mark the order paid + fulfil it.
 */

declare(strict_types=1);

// Hide PHP warnings — Stripe must always see a clean status code.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/stripe.php';

// Reject non-POST so accidental browser visits don't get a 500.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$pdo = db();

// 1) Read the RAW body (signature is over the unparsed bytes).
$payload = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// 2) Verify the Stripe signature so we know the request is genuine.
$webhookSecret = (string)setting_get('gw_card_webhook_secret', '');
if ($webhookSecret === '' || !sw_verify_stripe_signature($payload, $sigHeader, $webhookSecret)) {
    error_log('[stripe-webhook] Invalid or missing signature');
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

// 3) Parse the JSON event payload.
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    http_response_code(400);
    echo 'Malformed event';
    exit;
}

$eventId   = (string)$event['id'];
$eventType = (string)$event['type'];

// 4) Idempotency — Stripe retries on non-2xx and may also redeliver.
// Store the first delivery so subsequent ones short-circuit cleanly.
sw_ensure_events_table($pdo);
try {
    $pdo->prepare('INSERT INTO stripe_events (event_id, event_type, payload) VALUES (?,?,?)')
        ->execute([$eventId, $eventType, $payload]);
} catch (Throwable $e) {
    // Duplicate event_id — already processed.  Ack with 200 so Stripe stops retrying.
    http_response_code(200);
    echo json_encode(['ok' => true, 'already_processed' => true]);
    exit;
}

// 5) Route the event.
try {
    switch ($eventType) {
        case 'checkout.session.completed':
            sw_handle_checkout_completed($event['data']['object'] ?? []);
            break;

        case 'payment_intent.succeeded':
            sw_handle_pi_succeeded($event['data']['object'] ?? []);
            break;

        case 'payment_intent.payment_failed':
            sw_handle_pi_failed($event['data']['object'] ?? []);
            break;

        case 'charge.refunded':
            sw_handle_charge_refunded($event['data']['object'] ?? []);
            break;

        default:
            // We log unknown events but still ack so Stripe stops retrying.
            error_log('[stripe-webhook] Unhandled event type: ' . $eventType);
    }
} catch (Throwable $e) {
    // Don't 500 to Stripe — we'd be re-delivered forever.  Log + ack.
    error_log('[stripe-webhook] Handler error on ' . $eventType . ': ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true]);
exit;


// ============================================================================
// HELPERS
// ============================================================================

/**
 * Verify Stripe's `Stripe-Signature` header against the raw body using the
 * webhook secret.  Header format:
 *   t=<unix_ts>,v1=<hex_signature>[,v1=<hex_signature>...]
 * Multiple v1 values appear when Stripe is rotating secrets.
 * Tolerance: 5 minutes — protects against replay while allowing normal clock skew.
 */
function sw_verify_stripe_signature(string $payload, string $sigHeader, string $secret, int $toleranceSeconds = 300): bool
{
    if ($sigHeader === '' || $secret === '') return false;
    $ts = null;
    $sigs = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        [$k, $v] = $kv;
        if ($k === 't')   $ts = (int)$v;
        if ($k === 'v1')  $sigs[] = $v;
    }
    if (!$ts || !$sigs) return false;
    if (abs(time() - $ts) > $toleranceSeconds) return false;
    $signedPayload = $ts . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($sigs as $given) {
        if (hash_equals($expected, $given)) return true;
    }
    return false;
}

/**
 * Lazy-create the audit table the first time the webhook runs on a fresh DB.
 * Storing the raw payload helps replay/debug at the admin level.
 */
function sw_ensure_events_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id   VARCHAR(80) NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        payload    LONGTEXT,
        received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_id (event_id),
        KEY idx_event_type (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Find an order row by its Stripe session id (set on the order during
 * checkout.php → stripe_create_session()).  Falls back to looking up the
 * metadata.order_number which we also send to Stripe.
 */
function sw_find_order(array $session): ?array
{
    $pdo = db();
    $sessionId = (string)($session['id'] ?? '');
    if ($sessionId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_session_id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    $orderNumber = (string)($session['metadata']['order_number'] ?? '');
    if ($orderNumber !== '') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    return null;
}

/**
 * `checkout.session.completed` is the canonical "the customer paid" event.
 * - Mark the order paid (if it wasn't already from the success redirect).
 * - Persist the Stripe payment_intent + card metadata for the admin views.
 * - Call fulfill_order() to assign license keys + send the delivery email.
 *   fulfill_order() is idempotent so re-deliveries are a no-op.
 * - Append a transaction_logs row for the Recent Transaction Logs table.
 * - Bubble a bell notification to the admin so they see the closed loop.
 */
function sw_handle_checkout_completed(array $session): void
{
    $order = sw_find_order($session);
    if (!$order) {
        error_log('[stripe-webhook] checkout.session.completed for unknown session — id=' . ($session['id'] ?? '?'));
        return;
    }
    $orderId   = (int)$order['id'];
    $paymentOk = (($session['payment_status'] ?? '') === 'paid')
              || (($session['status'] ?? '') === 'complete');
    if (!$paymentOk) {
        error_log('[stripe-webhook] checkout.session.completed but payment_status not paid for order #' . $orderId);
        return;
    }

    $pdo = db();
    // Pull card / risk metadata for the order detail view.
    try {
        $extra = stripe_extract_card_details($session);
    } catch (Throwable $e) {
        $extra = [];
    }
    if (!empty($extra)) {
        $pdo->prepare('UPDATE orders SET
            status            = "paid",
            card_brand        = ?,
            card_last4        = ?,
            card_exp          = ?,
            card_funding      = ?,
            card_country      = ?,
            card_type         = ?,
            risk_score        = ?,
            risk_level        = ?,
            payment_intent_id = ?,
            transaction_id    = ?,
            billing_country   = ?
            WHERE id = ?')
            ->execute([
                $extra['card_brand']        ?? '',
                $extra['card_last4']        ?? '',
                $extra['card_exp']          ?? '',
                $extra['card_funding']      ?? '',
                $extra['card_country']      ?? '',
                $extra['card_type']         ?? '',
                isset($extra['risk_score']) ? (int)$extra['risk_score'] : null,
                $extra['risk_level']        ?? '',
                $extra['payment_intent_id'] ?? '',
                $extra['transaction_id']    ?? '',
                $extra['billing_country']   ?? '',
                $orderId,
            ]);
    } else {
        $pdo->prepare('UPDATE orders SET status = "paid" WHERE id = ?')->execute([$orderId]);
    }

    // Append the transaction log row (one per successful charge).
    try {
        $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status) VALUES (?,?,?,?,?,?)')
            ->execute([
                $orderId,
                'card',
                (string)($extra['transaction_id'] ?? ($session['payment_intent'] ?? $session['id'] ?? '')),
                (float)$order['total'],
                (string)$order['currency'],
                'paid',
            ]);
    } catch (Throwable $e) { /* best-effort logging */ }

    // Fulfil — assign license keys + send delivery email.  Idempotent.
    fulfill_order($orderId);

    // Surface in the admin bell — "Webhook-confirmed payment".
    if (function_exists('admin_notify')) {
        admin_notify(
            'sale',
            'Payment confirmed — ' . ($order['order_number'] ?? ('#' . $orderId)),
            'Stripe webhook closed the loop for ' . ($order['currency'] ?? 'USD') . ' ' . number_format((float)$order['total'], 2) . '. License key(s) delivered.',
            '/admin.php?tab=sales'
        );
    }
}

/**
 * `payment_intent.succeeded` is fired in parallel with checkout.session.completed
 * for Stripe Checkout sessions, but on Payment Element flows it's the only
 * positive signal.  We treat it like a soft confirmation — if the matching
 * order is still pending and was placed against this PI, mark it paid.
 */
function sw_handle_pi_succeeded(array $pi): void
{
    $piId = (string)($pi['id'] ?? '');
    if ($piId === '') return;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE payment_intent_id = ? OR transaction_id = ? LIMIT 1');
    $stmt->execute([$piId, $piId]);
    $order = $stmt->fetch();
    if (!$order) return;
    if (($order['status'] ?? '') === 'paid') return;
    $pdo->prepare('UPDATE orders SET status = "paid", payment_intent_id = ? WHERE id = ?')
        ->execute([$piId, (int)$order['id']]);
    fulfill_order((int)$order['id']);
}

/**
 * `payment_intent.payment_failed` — surface the failure in the bell + tag
 * the order so the customer can be retried via the resend-link flow.
 */
function sw_handle_pi_failed(array $pi): void
{
    $piId = (string)($pi['id'] ?? '');
    if ($piId === '') return;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE payment_intent_id = ? OR stripe_session_id IN (SELECT id FROM (SELECT ? AS id) t) LIMIT 1');
    $stmt->execute([$piId, $piId]);
    $order = $stmt->fetch();
    if (!$order) return;
    $reason = (string)($pi['last_payment_error']['message'] ?? ($pi['last_payment_error']['code'] ?? 'declined'));
    $pdo->prepare('UPDATE orders SET status = "cancelled", payment_intent_id = ? WHERE id = ?')
        ->execute([$piId, (int)$order['id']]);
    if (function_exists('admin_notify')) {
        admin_notify(
            'order',
            'Payment failed — ' . ($order['order_number'] ?? ('#' . (int)$order['id'])),
            'Stripe declined the charge: ' . $reason,
            '/admin.php?tab=orders&q=' . urlencode((string)$order['order_number'])
        );
    }
}

/**
 * `charge.refunded` — flip the order to "refunded" so the admin's Sales /
 * Orders views reflect reality.
 */
function sw_handle_charge_refunded(array $charge): void
{
    $piId = (string)($charge['payment_intent'] ?? '');
    if ($piId === '') return;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE payment_intent_id = ? LIMIT 1');
    $stmt->execute([$piId]);
    $order = $stmt->fetch();
    if (!$order) return;
    $pdo->prepare('UPDATE orders SET status = "refunded" WHERE id = ?')->execute([(int)$order['id']]);
    try {
        $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status) VALUES (?,?,?,?,?,?)')
            ->execute([
                (int)$order['id'],
                'card',
                (string)($charge['id'] ?? $piId),
                (float)$order['total'],
                (string)$order['currency'],
                'refunded',
            ]);
    } catch (Throwable $e) { /* best-effort */ }
    if (function_exists('admin_notify')) {
        admin_notify(
            'order',
            'Refund processed — ' . ($order['order_number'] ?? ('#' . (int)$order['id'])),
            'Stripe webhook confirmed the refund. Order now marked refunded.',
            '/admin.php?tab=orders'
        );
    }
}
