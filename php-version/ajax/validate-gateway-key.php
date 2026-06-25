<?php
/**
 * ajax/validate-gateway-key.php
 *
 * Admin-only helper that pings the gateway (Stripe / PayPal) with a single
 * lightweight call to confirm a pasted API key actually works.
 *
 * Input (JSON or POST):
 *   gateway: 'stripe' | 'paypal'
 *   mode:    'test'   | 'live'    (paypal: 'sandbox' | 'live')
 *   secret:  the secret/client_secret to test
 *   client_id?: paypal client id (only for paypal)
 *
 * Output:
 *   { ok:bool, message:str, brand?:str, balance?:str, account?:str }
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

ensure_admin();
require_admin_json();

$in       = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$gateway  = strtolower(trim((string)($in['gateway'] ?? '')));
$mode     = strtolower(trim((string)($in['mode']    ?? 'test')));
$secret   = trim((string)($in['secret']    ?? ''));
$clientId = trim((string)($in['client_id'] ?? ''));

if ($secret === '') {
    echo json_encode(['ok' => false, 'message' => 'Paste a key into the field first, then click Validate.']);
    exit;
}

if ($gateway === 'stripe') {
    // Sanity-check the key prefix matches the mode the admin is testing.
    $isLiveKey = str_starts_with($secret, 'sk_live_');
    $isTestKey = str_starts_with($secret, 'sk_test_');
    if (!$isLiveKey && !$isTestKey && !str_starts_with($secret, 'rk_')) {
        echo json_encode(['ok' => false, 'message' => 'Doesn\'t look like a Stripe secret key — expected to start with sk_test_, sk_live_ or rk_.']);
        exit;
    }
    if ($mode === 'live' && !$isLiveKey) {
        echo json_encode(['ok' => false, 'message' => 'This is a TEST key (sk_test_…). Paste it in the Test/Sandbox slot, or use a sk_live_… key for Live mode.']);
        exit;
    }
    if ($mode === 'test' && $isLiveKey) {
        echo json_encode(['ok' => false, 'message' => 'This is a LIVE key (sk_live_…). Paste it in the Live slot, or use a sk_test_… key for Test mode.']);
        exit;
    }

    // Ping /v1/balance — cheapest authenticated call that exercises the key
    // and reports the connected Stripe account back to the admin.
    $ch = curl_init('https://api.stripe.com/v1/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code === 200 && is_array($data) && isset($data['available'])) {
        // Format the available balance for the admin's home currency.
        $bal = $data['available'][0] ?? null;
        $balStr = '';
        if ($bal && isset($bal['amount'], $bal['currency'])) {
            $balStr = number_format(((int)$bal['amount']) / 100, 2) . ' ' . strtoupper($bal['currency']);
        }
        echo json_encode([
            'ok'      => true,
            'message' => '✓ Key works — connected to your Stripe ' . ($isLiveKey ? 'LIVE' : 'TEST') . ' account.',
            'brand'   => 'Stripe',
            'mode'    => $isLiveKey ? 'live' : 'test',
            'balance' => $balStr,
        ]);
        exit;
    }
    if ($code === 401) {
        $msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : 'Invalid API key';
        echo json_encode(['ok' => false, 'message' => '✗ Stripe rejected the key: ' . $msg]);
        exit;
    }
    echo json_encode(['ok' => false, 'message' => '✗ Stripe call failed (HTTP ' . $code . '). Check your network or paste a fresh key.']);
    exit;
}

if ($gateway === 'paypal') {
    if ($clientId === '') {
        echo json_encode(['ok' => false, 'message' => 'Paste both the Client ID and the Client Secret, then click Validate.']);
        exit;
    }
    // PayPal sandbox / live OAuth — request an access_token; if the creds
    // are good we get HTTP 200 with a token, otherwise 401 + invalid_client.
    $base = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($base . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code === 200 && is_array($data) && isset($data['access_token'])) {
        echo json_encode([
            'ok'      => true,
            'message' => '✓ Key works — connected to your PayPal ' . ($mode === 'live' ? 'LIVE' : 'SANDBOX') . ' app.',
            'brand'   => 'PayPal',
            'mode'    => $mode,
            'scope'   => $data['scope'] ?? '',
        ]);
        exit;
    }
    if ($code === 401) {
        $err = is_array($data) && isset($data['error_description']) ? $data['error_description'] : ($data['error'] ?? 'invalid_client');
        echo json_encode(['ok' => false, 'message' => '✗ PayPal rejected the credentials: ' . $err]);
        exit;
    }
    echo json_encode(['ok' => false, 'message' => '✗ PayPal call failed (HTTP ' . $code . '). Check your network or paste fresh credentials.']);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown gateway: ' . $gateway]);
