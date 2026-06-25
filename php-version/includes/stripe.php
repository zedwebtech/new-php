<?php
// Stripe hosted checkout via the HTTP API (no SDK required).
// With STRIPE_SECRET_KEY empty the store runs in DEMO MODE (order marked paid instantly).
require_once __DIR__ . '/functions.php';

/**
 * Returns the active gateway mode for the current request — 'test' or 'live'.
 * Driven by the admin Test↔Live toggle on /admin.php?tab=api&gw=toggles.
 */
function stripe_active_mode(): string
{
    return setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test';
}

/**
 * Returns the Stripe secret key the checkout flow should use for the
 * CURRENT mode. Lookup order:
 *   1. Mode-specific admin override (gw_card_secret_key_test / _live).
 *   2. Legacy single-field admin override (gw_card_secret_key).
 *   3. Env-var STRIPE_API_KEY (which is the default sk_test_emergent proxy).
 * Empty string ⇒ no key configured for this mode ⇒ DEMO fallback.
 */
function stripe_active_secret(): string
{
    $mode = stripe_active_mode();
    $modeKey = trim((string)setting_get('gw_card_secret_key_' . $mode, ''));
    if ($modeKey !== '') return $modeKey;
    $legacy  = trim((string)setting_get('gw_card_secret_key', ''));
    if ($legacy !== '') return $legacy;
    return (string)(defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
}

/**
 * Publishable counterpart of stripe_active_secret() — kept for the
 * client-side Elements bundle (currently unused, but exposed for parity).
 */
function stripe_active_publishable(): string
{
    $mode = stripe_active_mode();
    $modeKey = trim((string)setting_get('gw_card_public_key_' . $mode, ''));
    if ($modeKey !== '') return $modeKey;
    return trim((string)setting_get('gw_card_public_key', ''));
}

function stripe_enabled(): bool
{
    return stripe_active_secret() !== '';
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $secret = stripe_active_secret();
    if ($secret === '') {
        throw new RuntimeException('Stripe is not configured for the active gateway mode.');
    }
    // Pick the right API host. The Emergent proxy is only valid for the
    // bundled sk_test_emergent test key — real sk_test_* / sk_live_* keys
    // must go straight to api.stripe.com.
    $base = defined('STRIPE_API_BASE') ? STRIPE_API_BASE : 'https://api.stripe.com';
    if (str_contains($secret, 'emergent')) {
        $base = 'https://integrations.emergentagent.com/stripe';
    } elseif (str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'sk_live_')) {
        $base = 'https://api.stripe.com';
    }
    $ch = curl_init(rtrim($base, '/') . '/v1/' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_TIMEOUT => 25,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$res, true) ?: [];
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException($data['error']['message'] ?? ('Stripe error HTTP ' . $code));
    }
    return $data;
}

function stripe_create_session(array $order, string $baseUrl): array
{
    $cents = (int)round((float)$order['total'] * 100);
    $mode  = stripe_active_mode();
    $label = ($mode === 'test' ? '[TEST] ' : '')
           . 'Order #' . $order['order_number'] . ' — ' . SITE_LEGAL;
    return stripe_request('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => $label,
        'line_items[0][price_data][unit_amount]' => $cents,
        'line_items[0][quantity]' => 1,
        'customer_email' => $order['email'],
        'metadata[order_number]' => $order['order_number'],
        'metadata[gw_mode]' => $mode,
        'success_url' => $baseUrl . 'order-success.php?order=' . urlencode($order['order_number']) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . 'checkout.php',
    ]);
}

function stripe_get_session(string $sessionId): array
{
    return stripe_request('GET', 'checkout/sessions/' . urlencode($sessionId));
}

/**
 * Fetch a PaymentIntent with its payment_method + latest_charge expanded so
 * we can pull card brand / last4 / expiry / funding / country / risk score
 * back into our local orders row.  Safe to display these — they're the
 * PCI-allowed subset.  Never store the full PAN or CVV.
 */
function stripe_get_payment_intent(string $piId): array
{
    $query = http_build_query([
        'expand' => ['payment_method', 'latest_charge', 'latest_charge.balance_transaction'],
    ]);
    return stripe_request('GET', 'payment_intents/' . urlencode($piId) . '?' . $query);
}

/**
 * Given a Stripe Checkout Session row, pull the underlying PaymentIntent
 * + Charge and return a normalised array of admin-safe card fields ready
 * to be UPSERTed into the orders table.
 *
 * Returns an associative array (keys always present even when empty so the
 * UPDATE statement is stable) with: card_brand, card_last4, card_exp,
 * card_funding, card_country, card_type, risk_score, risk_level,
 * payment_intent_id, transaction_id, billing_country.
 */
function stripe_extract_card_details(array $session): array
{
    $out = [
        'card_brand'         => '',
        'card_last4'         => '',
        'card_exp'           => '',
        'card_funding'       => '',
        'card_country'       => '',
        'card_type'          => '',
        'risk_score'         => null,
        'risk_level'         => '',
        'payment_intent_id'  => '',
        'transaction_id'     => '',
        'billing_country'    => '',
    ];
    $piId = $session['payment_intent'] ?? '';
    if (!$piId || !is_string($piId)) return $out;
    try {
        $pi = stripe_get_payment_intent($piId);
    } catch (Throwable $e) {
        @error_log('[stripe_extract_card_details] PI fetch failed: ' . $e->getMessage());
        return $out;
    }
    $out['payment_intent_id'] = $pi['id'] ?? $piId;

    // Card data lives on the payment_method (expanded above).
    $pm = $pi['payment_method'] ?? null;
    $card = is_array($pm) && isset($pm['card']) ? $pm['card'] : null;
    if (is_array($card)) {
        $out['card_brand']   = (string)($card['display_brand'] ?? $card['brand'] ?? '');
        $out['card_last4']   = (string)($card['last4'] ?? '');
        $expM = isset($card['exp_month']) ? (int)$card['exp_month'] : 0;
        $expY = isset($card['exp_year'])  ? (int)$card['exp_year']  : 0;
        if ($expM && $expY) {
            $out['card_exp'] = sprintf('%02d/%02d', $expM, $expY % 100);
        }
        $out['card_funding'] = (string)($card['funding'] ?? '');        // credit | debit | prepaid | unknown
        $out['card_type']    = ucfirst((string)($card['funding'] ?? '')); // friendlier display
        $out['card_country'] = (string)($card['country'] ?? '');         // ISO-2 issuer country
    }

    // Risk / Radar data lives on the latest_charge.outcome.
    $charge = $pi['latest_charge'] ?? null;
    if (is_array($charge)) {
        $out['transaction_id'] = (string)($charge['id'] ?? '');
        if (isset($charge['outcome']) && is_array($charge['outcome'])) {
            $out['risk_level'] = (string)($charge['outcome']['risk_level'] ?? '');
            if (isset($charge['outcome']['risk_score'])) {
                $out['risk_score'] = (int)$charge['outcome']['risk_score'];
            }
        }
        // Billing address lives on the charge or PM.
        if (isset($charge['billing_details']['address']['country']) && $charge['billing_details']['address']['country']) {
            $out['billing_country'] = (string)$charge['billing_details']['address']['country'];
        }
    }
    if ($out['billing_country'] === '' && isset($pm['billing_details']['address']['country'])) {
        $out['billing_country'] = (string)$pm['billing_details']['address']['country'];
    }
    return $out;
}
