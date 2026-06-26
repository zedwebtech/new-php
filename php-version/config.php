<?php
// ============================================================
// Maventech Software Store - Configuration
// Edit these values to match your hosting environment.
// ============================================================

// --- Database (MySQL/MariaDB) — env vars override defaults for production. ---
// IMPORTANT: prefix env vars with MYSQL_ to avoid colliding with /app/backend/.env
// which defines DB_NAME for the (unrelated) MongoDB stack.
define('DB_HOST', getenv('MYSQL_HOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_NAME', getenv('MYSQL_DATABASE') ?: 'ucode_store');
define('DB_USER', getenv('MYSQL_USER') ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQL_PASSWORD') ?: (getenv('DB_PASS') ?: ''));

// --- Optional: AI chat (OpenAI-compatible API) ---
// The system checks multiple sources for the AI key:
// 1) Environment variable  2) .env file  3) Database (admin panel)
// You can set the key from the admin panel (AI Auto-Blogger → API Keys)
// and it will work automatically — no file editing needed.
$_llm_key = getenv('OPENAI_API_KEY') ?: (getenv('EMERGENT_LLM_KEY') ?: '');
// Read from .env file if env var is not set (works on cPanel / shared hosting)
if ($_llm_key === '') {
    $_env_path = __DIR__ . '/.env';
    if (is_file($_env_path)) {
        $_env_lines = @file($_env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($_env_lines) {
            foreach ($_env_lines as $_el) {
                $_el = trim($_el);
                if ($_el === '' || $_el[0] === '#') continue;
                if (preg_match('/^(EMERGENT_LLM_KEY|OPENAI_API_KEY)\s*=\s*(.+)$/', $_el, $_em)) {
                    $_v = trim($_em[2], "\"' \t\n\r");
                    if ($_v !== '') { $_llm_key = $_v; break; }
                }
            }
        }
    }
}
// Determine the correct API base URL from the key type
if ($_llm_key !== '' && (str_contains($_llm_key, 'emergent') || str_starts_with($_llm_key, 'ek-'))) {
    $_llm_url = 'https://integrations.emergentagent.com/llm/v1';
} elseif ($_llm_key !== '') {
    $_llm_url = getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';
} else {
    $_llm_url = getenv('OPENAI_BASE_URL') ?: '';
}
define('OPENAI_API_KEY', $_llm_key);
define('OPENAI_BASE_URL', $_llm_url);
define('OPENAI_MODEL', 'gpt-4o-mini');
unset($_llm_key, $_llm_url, $_env_path, $_env_lines, $_el, $_em, $_v);

// --- Optional: Stripe secret key (https://dashboard.stripe.com/apikeys) ---
// Auto-filled from the environment on Emergent preview.
// Leave empty for DEMO MODE: orders are marked paid immediately without charging.
define('STRIPE_SECRET_KEY', getenv('STRIPE_API_KEY') ?: '');
// Stripe API base — on Emergent preview the test key works through the Emergent proxy.
define('STRIPE_API_BASE', getenv('STRIPE_API_BASE')
    ?: (str_contains(STRIPE_SECRET_KEY, 'emergent') ? 'https://integrations.emergentagent.com/stripe' : 'https://api.stripe.com'));

// --- Optional: Resend API key for order/license-key emails (https://resend.com) ---
// Leave empty to queue emails in the database (view them in admin.php > Emails).
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('SENDER_EMAIL', getenv('SENDER_EMAIL') ?: 'onboarding@resend.dev');

// --- Admin account (created automatically on first run) ---
// Override via env vars ADMIN_EMAIL / ADMIN_PASSWORD in production.
define('ADMIN_EMAIL',    getenv('ADMIN_EMAIL')    ?: 'admin@maventechsoftware.com');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'Admin@UC2026!');

// --- Company ---
define('SITE_BRAND', 'Maventech Software');
define('SITE_LEGAL', 'Maventech Software');
// SITE_PHONE / SITE_EMAIL / SITE_ADDRESS / SITE_HOURS are resolved from the
// admin → Company Info settings at the bottom of includes/settings.php so any
// change in the admin panel propagates across the entire site, emails & PDFs.
// Public site URL — auto-detected from the Host header on every HTTP request
// (see site_url() in includes/functions.php). This constant is the fallback
// used only by CLI scripts / cron — set env var SITE_URL on your production
// host to override (e.g. https://maventechsoftware.com).
//
// IMPORTANT: never hard-code an Emergent preview URL here. If unset, leave
// it empty so site_url() falls back to the live request Host (cPanel,
// docker, anywhere). Hard-coding a preview hostname would cause every
// QR code, sitemap entry, og:url, canonical tag and post-purchase email
// link to point at the dev/preview site even after the customer uploads
// to their real domain (maventechsoftware.com).
define('SITE_URL', getenv('SITE_URL') ?: '');
// Google Search Console verification — paste your GSC meta-tag code here (content="..." value)
define('GOOGLE_SITE_VERIFICATION', getenv('GOOGLE_SITE_VERIFICATION') ?: '');
// Bing Webmaster Tools verification (unlocks Copilot + ChatGPT-via-Bing).
define('BING_SITE_VERIFICATION',   getenv('BING_SITE_VERIFICATION')   ?: 'AF7E1FB430EA67709B92D54FA12FBEB7');
// Yandex Webmaster verification (used by Yandex search + several AI engines).
define('YANDEX_SITE_VERIFICATION', getenv('YANDEX_SITE_VERIFICATION') ?: '');
// Pinterest domain verification (rich pins on product pages).
define('PINTEREST_SITE_VERIFICATION', getenv('PINTEREST_SITE_VERIFICATION') ?: '');
// Baidu Webmaster verification (Chinese market — optional).
define('BAIDU_SITE_VERIFICATION',  getenv('BAIDU_SITE_VERIFICATION')  ?: '');

// --- Analytics & advertising tags (MAVENTECH LLC) ---
// These are the baked-in DEFAULTS so the tags render even before anything is
// saved in the admin panel. A value saved in admin → SEO / Tracking ALWAYS
// overrides the default below (the admin panel writes to the settings table,
// which is read first; these constants are only the fallback).
define('GA4_MEASUREMENT_ID', getenv('GA4_MEASUREMENT_ID') ?: '');               // G-XXXXXXXXXX (GA4 — none set yet)
define('GOOGLE_TAG_ID',      getenv('GOOGLE_TAG_ID')      ?: 'GT-TQV4X72G');    // Google tag (gtag.js loader)
define('GOOGLE_ADS_TAG_ID',  getenv('GOOGLE_ADS_TAG_ID')  ?: 'AW-18263028048'); // Google Ads conversion tag
define('CLARITY_PROJECT_ID', getenv('CLARITY_PROJECT_ID') ?: 'xcp5vd09fb');     // Microsoft Clarity project id
define('GOOGLE_MERCHANT_ID', getenv('GOOGLE_MERCHANT_ID') ?: '5815017210');     // Google Merchant Center id
// Public-facing company contact / sender email (used across the site & emails).
define('SITE_EMAIL',         getenv('SITE_EMAIL')         ?: 'services@maventechsoftware.com');

// --- ProAssist upsell price (USD) ---
define('PRO_ASSIST_PRICE', 47.00);

// --- Currencies (rates relative to USD) ---
$GLOBALS['CURRENCIES'] = [
    'USD' => ['symbol' => '$',    'rate' => 1.00, 'flag' => '🇺🇸'],
    'EUR' => ['symbol' => '€',    'rate' => 0.92, 'flag' => '🇪🇺'],
    'GBP' => ['symbol' => '£',    'rate' => 0.79, 'flag' => '🇬🇧'],
    'CAD' => ['symbol' => 'CA$',  'rate' => 1.37, 'flag' => '🇨🇦'],
    'AUD' => ['symbol' => 'AU$',  'rate' => 1.52, 'flag' => '🇦🇺'],
];
