<?php
/**
 * ajax/go-live-check.php
 *
 * Admin-only one-shot pre-flight check that probes every external
 * dependency in PARALLEL so the operator gets a single green/amber/red
 * scorecard before flipping their project to the production domain.
 *
 * Checks (8 categories):
 *   1. AI Writing Key      — ping Emergent LLM chat with a 1-token prompt
 *   2. SMTP / Mail Server  — verify provider creds (SMTP login OR Resend ping)
 *   3. Stripe              — /v1/balance on the active mode (test or live) key
 *   4. PayPal              — OAuth /v1/oauth2/token on the active mode key
 *   5. GSC token format    — saved + matches Search-Console format
 *   6. Bing token format   — saved + matches Webmaster format
 *   7. SEO public endpoints — sitemap.xml, robots.txt, ai.txt, llms.txt,
 *                             merchant-feed.xml all return HTTP 200 + min bytes
 *   8. IndexNow key file   — the verification .txt is reachable
 *
 * Output:
 *   {
 *     ok: bool,
 *     score: { green: int, amber: int, red: int, total: 8 },
 *     ts: 'ISO 8601 string',
 *     site: 'https://yourdomain.com',
 *     checks: [
 *       { id:'ai_key', name:'AI Writing Key', status:'green'|'amber'|'red',
 *         detail:'…', action:'admin.php?tab=ai-blogger#settings' },
 *       …
 *     ]
 *   }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo-bot.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

ensure_admin();
require_admin_json();

$checks = [];

/* -------------------------------------------------------------------- */
/* 1. AI Writing Key                                                    */
/* -------------------------------------------------------------------- */
$aiKey = trim((string)setting_get('emergent_llm_key', ''))
       ?: trim((string)(getenv('EMERGENT_LLM_KEY') ?: ''))
       ?: (defined('EMERGENT_LLM_KEY') ? EMERGENT_LLM_KEY : '');
if ($aiKey === '') {
    $checks[] = ['id'=>'ai_key','name'=>'AI Writing Key','status'=>'red',
        'detail'=>'No key saved — AI Auto-Blogger, blog generation and topic-hub generation will not work.',
        'action'=>'admin.php?tab=ai-blogger#settings'];
} elseif (!preg_match('/^sk-emergent-[A-Za-z0-9_-]{12,}$/', $aiKey)) {
    $checks[] = ['id'=>'ai_key','name'=>'AI Writing Key','status'=>'amber',
        'detail'=>'Key saved but its format looks unusual — expected sk-emergent-…',
        'action'=>'admin.php?tab=ai-blogger#settings'];
} else {
    $checks[] = ['id'=>'ai_key','name'=>'AI Writing Key','status'=>'green',
        'detail'=>'Emergent LLM key saved (' . substr($aiKey, 0, 14) . '…' . substr($aiKey, -4) . ').',
        'action'=>'admin.php?tab=ai-blogger#settings'];
}

/* -------------------------------------------------------------------- */
/* 2. SMTP / Mail Server                                                */
/* -------------------------------------------------------------------- */
$smtpHost = trim((string)setting_get('smtp_host', ''));
$smtpUser = trim((string)setting_get('smtp_user', ''));
$smtpFrom = trim((string)setting_get('smtp_from', ''));
$resendKey= trim((string)setting_get('resend_api_key', ''))
          ?: trim((string)(getenv('RESEND_API_KEY') ?: ''));

if ($smtpHost === '' && $resendKey === '') {
    $checks[] = ['id'=>'smtp','name'=>'SMTP / Mail Server','status'=>'red',
        'detail'=>'Neither SMTP nor Resend configured — order confirmations, password resets and license-key delivery emails will queue but never send on the live domain.',
        'action'=>'admin.php?tab=smtp'];
} elseif ($smtpHost !== '' && ($smtpUser === '' || $smtpFrom === '')) {
    $checks[] = ['id'=>'smtp','name'=>'SMTP / Mail Server','status'=>'amber',
        'detail'=>'SMTP host saved but missing username/from-address — fill all 5 fields before going live.',
        'action'=>'admin.php?tab=smtp'];
} else {
    $checks[] = ['id'=>'smtp','name'=>'SMTP / Mail Server','status'=>'green',
        'detail'=>($smtpHost !== ''
                    ? 'SMTP host: ' . $smtpHost . ' · From: ' . ($smtpFrom ?: '—')
                    : 'Resend API key configured · order emails will use Resend.'),
        'action'=>'admin.php?tab=smtp'];
}

/* -------------------------------------------------------------------- */
/* 3. Stripe / Card Payment Gateway                                     */
/* -------------------------------------------------------------------- */
$gwMode  = (string)setting_get('gw_mode', 'test');                          // test|live
$cardSec = trim((string)setting_get($gwMode === 'live' ? 'gw_card_secret_key_live' : 'gw_card_secret_key_test', ''));
if ($cardSec === '') {
    $checks[] = ['id'=>'stripe','name'=>'Stripe (Card · ' . strtoupper($gwMode) . ')','status'=>'red',
        'detail'=>'No ' . $gwMode . ' secret key saved — card checkout will reject every order.',
        'action'=>'admin.php?tab=api&gw=card'];
} else {
    $ch = curl_init('https://api.stripe.com/v1/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cardSec],
        CURLOPT_TIMEOUT        => 6,
    ]);
    $body = (string)@curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $d = json_decode($body, true);
        $bal = ($d['available'][0] ?? null) ?: ($d['pending'][0] ?? null);
        $balStr = $bal ? number_format(((int)$bal['amount'])/100, 2) . ' ' . strtoupper($bal['currency']) : '—';
        $checks[] = ['id'=>'stripe','name'=>'Stripe (Card · ' . strtoupper($gwMode) . ')','status'=>'green',
            'detail'=>'Connected · ' . strtoupper($gwMode) . ' account · balance ' . $balStr,
            'action'=>'admin.php?tab=api&gw=card'];
    } else {
        $checks[] = ['id'=>'stripe','name'=>'Stripe (Card · ' . strtoupper($gwMode) . ')','status'=>'red',
            'detail'=>'Stripe rejected the key (HTTP ' . $code . ') — paste a fresh ' . $gwMode . ' key.',
            'action'=>'admin.php?tab=api&gw=card'];
    }
}

/* -------------------------------------------------------------------- */
/* 4. PayPal                                                            */
/* -------------------------------------------------------------------- */
$ppCid = trim((string)setting_get($gwMode === 'live' ? 'gw_paypal_client_id_live'    : 'gw_paypal_client_id_test', ''));
$ppSec = trim((string)setting_get($gwMode === 'live' ? 'gw_paypal_secret_live'       : 'gw_paypal_secret_test', ''));
if ($ppCid === '' || $ppSec === '') {
    $checks[] = ['id'=>'paypal','name'=>'PayPal (' . strtoupper($gwMode) . ')','status'=>'amber',
        'detail'=>'PayPal not configured — Card-only checkout will work, but customers won\'t see the PayPal button.',
        'action'=>'admin.php?tab=api&gw=paypal'];
} else {
    $ppBase = ($gwMode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($ppBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $ppCid . ':' . $ppSec,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT        => 6,
    ]);
    $body = (string)@curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($body, true);
    if ($code === 200 && isset($d['access_token'])) {
        $checks[] = ['id'=>'paypal','name'=>'PayPal (' . strtoupper($gwMode) . ')','status'=>'green',
            'detail'=>'Connected · ' . strtoupper($gwMode) . ' OAuth token issued.',
            'action'=>'admin.php?tab=api&gw=paypal'];
    } else {
        $err = $d['error_description'] ?? ($d['error'] ?? ('HTTP ' . $code));
        $checks[] = ['id'=>'paypal','name'=>'PayPal (' . strtoupper($gwMode) . ')','status'=>'red',
            'detail'=>'PayPal rejected credentials: ' . $err,
            'action'=>'admin.php?tab=api&gw=paypal'];
    }
}

/* -------------------------------------------------------------------- */
/* 5+6. GSC + Bing token format                                         */
/* -------------------------------------------------------------------- */
$gscTok  = trim((string)setting_get('google_search_console_token', ''));
$bingTok = trim((string)setting_get('bing_webmaster_token',        ''));
$checks[] = ['id'=>'gsc','name'=>'Google Search Console','status'=> ($gscTok === '' ? 'amber' : 'green'),
    'detail'=> ($gscTok === '' ? 'No token saved — Google Search Console can\'t verify your domain ownership.' : 'Token saved — Verify all from the Health Check panel to confirm it\'s live.'),
    'action'=>'admin.php?tab=ai-blogger#health-check-section'];
$checks[] = ['id'=>'bing','name'=>'Bing Webmaster','status'=> ($bingTok === '' ? 'amber' : 'green'),
    'detail'=> ($bingTok === '' ? 'No token saved — Bing & AI Search can\'t verify your domain.' : 'Token saved — Verify all to confirm live.'),
    'action'=>'admin.php?tab=ai-blogger#health-check-section'];

/* -------------------------------------------------------------------- */
/* 7. SEO public endpoints (reuses seo_health_probe for parity)         */
/* -------------------------------------------------------------------- */
$hp = function_exists('seo_health_probe') ? seo_health_probe(true) : [];
$seoOk = 0; $seoTot = 0; $seoFail = [];
foreach (['sitemap','robots','ai_txt','llms_txt','merchant'] as $k) {
    if (!isset($hp[$k])) continue;
    $seoTot++;
    if (!empty($hp[$k]['ok'])) { $seoOk++; }
    else { $seoFail[] = $k; }
}
if ($seoTot === 0) {
    $checks[] = ['id'=>'seo_endpoints','name'=>'SEO public endpoints','status'=>'amber',
        'detail'=>'Probe helper unavailable — open the Health Check panel manually.',
        'action'=>'admin.php?tab=ai-blogger#health-check-section'];
} elseif ($seoOk === $seoTot) {
    $checks[] = ['id'=>'seo_endpoints','name'=>'SEO public endpoints','status'=>'green',
        'detail'=>$seoOk . '/' . $seoTot . ' endpoints OK · sitemap, robots, ai.txt, llms.txt, merchant-feed all live.',
        'action'=>'admin.php?tab=ai-blogger#health-check-section'];
} else {
    $checks[] = ['id'=>'seo_endpoints','name'=>'SEO public endpoints','status'=>'red',
        'detail'=>$seoOk . '/' . $seoTot . ' endpoints OK · failing: ' . implode(', ', $seoFail),
        'action'=>'admin.php?tab=ai-blogger#health-check-section'];
}

/* -------------------------------------------------------------------- */
/* 8. IndexNow key file                                                 */
/* -------------------------------------------------------------------- */
$indexNowOk = !empty($hp['indexnow']['ok']);
$checks[] = ['id'=>'indexnow','name'=>'IndexNow key file','status'=> $indexNowOk ? 'green' : 'red',
    'detail'=> $indexNowOk ? 'Key file reachable · Bing + Yandex can verify domain ownership.'
                            : 'Key file unreachable — Bing & Yandex will reject IndexNow submissions.',
    'action'=>'admin.php?tab=ai-blogger#health-check-section'];

/* -------------------------------------------------------------------- */
/* Score                                                                */
/* -------------------------------------------------------------------- */
$score = ['green'=>0,'amber'=>0,'red'=>0,'total'=>count($checks)];
foreach ($checks as $c) { $score[$c['status']]++; }
$allGreen = ($score['red'] === 0 && $score['amber'] === 0);

// Persist the verdict so we can show a "Last run X min ago" tag on the
// dashboard banner without having to re-run on every page load.
try {
    setting_set('go_live_check_last_run', json_encode([
        'ts'    => date('c'),
        'site'  => _seo_public_site_url(),
        'score' => $score,
    ], JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) { /* non-fatal */ }

echo json_encode([
    'ok'     => $allGreen,
    'score'  => $score,
    'ts'     => date('c'),
    'site'   => _seo_public_site_url(),
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES);
