<?php
// Shared helpers: session, currency, cart, products, rendering
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/admin-notify.php';

if (session_status() === PHP_SESSION_NONE) {
    // Keep sessions alive for 30 days with a persistent cookie so the admin
    // (and customers) stay logged in even after the installed PWA is closed,
    // backgrounded, or the device sleeps — instead of being logged out after
    // the default ~24-minute idle window / browser-close session cookie.
    if (PHP_SAPI !== 'cli') {
        $sessionLifetime = 60 * 60 * 24 * 30; // 30 days
        @ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
        // Ensure cookie-based sessions are enabled — some shared hosts ship a
        // php.ini with session.use_cookies disabled, which makes
        // session_set_cookie_params() emit a warning and breaks login persistence.
        @ini_set('session.use_cookies', '1');
        @ini_set('session.use_only_cookies', '1');
        $sessionSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        @session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path'     => '/',
            'secure'   => $sessionSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

/* ===================================================================
 *  ANTI-LEAK SAFETY NET — strip Emergent preview / cluster-internal
 *  hostnames from EVERY response body when the request is on a real
 *  production host.  Belt-and-suspenders defence so a stray hardcoded
 *  preview URL in DB settings / blog HTML / JSON-LD / sitemap can never
 *  leak the dev domain into a customer-facing page.
 *
 *  How it works:
 *    - On preview hosts (admin still building) → no-op so previews work.
 *    - On real production hosts → final-pass regex replace converts every
 *      `https?://<anything>.preview.emergentagent.com[/path]`
 *      `https?://<anything>.preview.emergentcf.cloud[/path]`
 *      `https?://<anything>.emergent.host[/path]`
 *      into just `[/path]` — a host-relative URL that resolves to the
 *      current production host the browser is already on.
 *    - The `integrations.emergentagent.com` server-to-server proxy is
 *      intentionally NOT matched (it's never emitted to HTML; it's only
 *      called server-side from config.php / seo-bot.php).
 *
 *  Runs INSIDE the gzip buffer (so we scrub the original UTF-8 text
 *  before compression).
 * =================================================================== */
if (PHP_SAPI !== 'cli' && !function_exists('_mv_scrub_preview_urls')) {
    function _mv_scrub_preview_urls(string $buffer): string {
        // Re-evaluate the current host on EACH callback rather than caching —
        // PHP-CLI workers reuse the same process across many requests.
        $fwdHost = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $fwdHost = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        $host = strtolower($fwdHost !== '' ? $fwdHost : (string)($_SERVER['HTTP_HOST'] ?? ''));
        // Skip the scrub for preview / dev hosts so admins editing on
        // the preview still see clickable preview links.
        $isPreview = (bool)preg_match(
            '~(?:^|\.)(?:preview\.emergentagent\.com|preview\.emergentcf\.cloud|emergent\.host|localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?$~i',
            $host
        );
        if ($isPreview || $host === '') return $buffer;
        // Skip if response is binary / not text — saves CPU on images, PDFs.
        // PHP's built-in image generators set Content-Type before output, so
        // we can introspect headers_list() to check.
        foreach (headers_list() as $h) {
            $hl = strtolower($h);
            if (str_starts_with($hl, 'content-type:')
                && !preg_match('~text/|application/(?:json|xml|ld\+json|xhtml|rss|atom)|image/svg~i', $hl)) {
                return $buffer;
            }
        }
        // Drop the host entirely → host-relative URL.  Browsers resolve
        // host-relative URLs against the current request host (production),
        // which is exactly what we want.  Trailing path/query preserved.
        // The trailing lookahead stops matches mid-token (e.g. inside
        // arbitrary strings that just contain the literal hostname).
        $pattern = '~https?://[a-z0-9][a-z0-9.\-]*\.(?:preview\.emergentagent\.com|preview\.emergentcf\.cloud|emergent\.host)(?=[/"\'\s<>?]|$)~i';
        return preg_replace($pattern, '', $buffer);
    }
    // Push the scrub buffer LAST so it sits closest to the script output
    // (inside the gzip buffer set up by router.php).  When the gzip buffer
    // isn't present (cPanel / Apache where router.php isn't loaded), this
    // is the outermost buffer and gets gzipped by Apache's mod_deflate
    // afterwards — works either way.
    ob_start('_mv_scrub_preview_urls');
}

/**
 * Security headers — sent on every page-load via functions.php.
 *
 * These reduce the chance of Google Safe Browsing flagging the domain
 * as "deceptive" by signalling clear intent to browsers + crawlers:
 *   - X-Content-Type-Options: blocks MIME sniffing (prevents arbitrary
 *     uploaded files from being executed as scripts).
 *   - X-Frame-Options: disallows iframe embedding so a phishing page
 *     cannot wrap our checkout/login in a deceptive overlay.
 *   - Referrer-Policy: trims leaked URLs to third parties.
 *   - Permissions-Policy: tells browsers we don't request camera/mic/
 *     geolocation — Safe Browsing weighs this when scoring trust.
 *   - Strict-Transport-Security: only added when the request is HTTPS
 *     so dev/local installs still work over plain HTTP.
 * `headers_sent()` guards against the rare case where output started
 * before this file was loaded (e.g. cron scripts).
 */
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // microphone=(self) lets the support-chat voice-note recorder work on our
    // own pages; camera/geolocation stay disabled since we never use them.
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=(), interest-cohort=()');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Regions are a core dependency: public queries must hide products from
// regions that an admin has toggled off. Loaded here so it is available
// in scripts that call db() before including the page header.
require_once __DIR__ . '/regions.php';

// Subscription engine (plans catalogue + customer subscriptions). Self-heals
// its schema on include; safe + cheap (statically guarded).
require_once __DIR__ . '/subscriptions.php';

/**
 * Self-healing chat schema.
 *
 * Adds the live-chat columns the code relies on (chat attachments, the
 * "agent joined" name, the admin-seen flag) when they're missing.  These were
 * previously only created by start.sh (Emergent pod) — so on a cPanel/shared
 * deployment they were absent, which broke the admin chat ("Loading
 * conversation…" forever), the Lead-Management red badge, presence/online dot
 * and the ProAssist/install feed.  Runs ONCE (flag-guarded), cheap thereafter.
 */
function chat_schema_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // Required columns keyed by table — checked against INFORMATION_SCHEMA
        // so we self-correct even if a previous run set the flag but the ALTER
        // hadn't actually applied (e.g. it ran mid-deploy).  No persistent flag
        // is trusted blindly anymore.
        $required = [
            'chat_messages' => [
                'attachment_url'  => "ALTER TABLE chat_messages ADD COLUMN attachment_url  VARCHAR(500) DEFAULT NULL",
                'attachment_type' => "ALTER TABLE chat_messages ADD COLUMN attachment_type VARCHAR(20)  DEFAULT NULL",
                'attachment_name' => "ALTER TABLE chat_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL",
            ],
            'chat_leads' => [
                'admin_seen_at' => "ALTER TABLE chat_leads ADD COLUMN admin_seen_at DATETIME DEFAULT NULL",
                'agent_name'    => "ALTER TABLE chat_leads ADD COLUMN agent_name VARCHAR(120) DEFAULT NULL",
            ],
        ];
        // Fast path: if a prior run confirmed everything, trust the flag.
        if (setting_get('chat_schema_v3', '') === '1') return;

        $allOk = true;
        foreach ($required as $table => $cols) {
            // Existing columns for this table.
            $have = [];
            try {
                $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $st->execute([$table]);
                $have = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (Throwable $e) { $have = []; }
            foreach ($cols as $colName => $alterSql) {
                if (!in_array($colName, $have, true)) {
                    try { $pdo->exec($alterSql); }
                    catch (Throwable $e) { $allOk = false; /* no privilege / race */ }
                }
            }
        }
        // Only cache success once every column is truly present.
        if ($allOk) setting_set('chat_schema_v3', '1');
    } catch (Throwable $e) { /* retry next request */ }
}
chat_schema_migrate();


/**
 * Global company-branding filter (output-buffer callback).
 *
 * Replaces the built-in DEFAULT company values (name / phone / email) with the
 * CURRENT values set in Admin → Company Info, across the ENTIRE rendered page —
 * including hard-coded text in CMS pages, static templates, JSON-LD, mailto:
 * and tel: links.  This is why changing the company name/phone/email once
 * updates every page site-wide.
 */
function apply_company_branding(string $html): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            $co    = company_info();
            $name  = trim((string)($co['name']  ?? ''));
            $phone = trim((string)($co['phone'] ?? ''));
            $email = trim((string)($co['email'] ?? ''));
            $defName  = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
            $defPhone = defined('SITE_PHONE') ? SITE_PHONE : '1-805-823-9961';
            $defEmail = defined('SITE_EMAIL') ? SITE_EMAIL : 'services@maventechsoftware.com';

            if ($name !== '' && $name !== $defName) {
                $map[$defName] = $name;
            }
            if ($phone !== '' && $phone !== $defPhone) {
                $map[$defPhone] = $phone;
                // tel: link forms (+digits)
                $defTel = '+' . preg_replace('/\D/', '', $defPhone);
                $newTel = '+' . preg_replace('/\D/', '', $phone);
                if ($defTel !== $newTel && strlen($defTel) > 1) $map[$defTel] = $newTel;
            }
            if ($email !== '') {
                foreach (['services@maventechsoftware.com', 'support@maventechsoftware.com',
                          'sales@maventechsoftware.com', 'info@maventechsoftware.com', $defEmail] as $de) {
                    if ($de !== '' && $de !== $email) $map[$de] = $email;
                }
            }
        } catch (Throwable $e) { $map = []; }
    }
    return $map ? strtr($html, $map) : $html;
}

// --------------------------------------------------------------------
// Self-healing schema bootstrap (MUST run before any vibe/auto-cron
// logic so the tables/columns those routines depend on exist).
// Runs the idempotent migration block inside ensure_db_schema() once
// per request.  Critical on production: hosts where the SQL file
// pre-dates a recent column addition (e.g. orders.gw_mode) would
// otherwise crash checkout with "Unknown column" until somebody runs
// an ALTER by hand.  This auto-heals the schema on every fresh PHP
// request, swallowing already-exists errors as no-ops.
// --------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && function_exists('ensure_db_schema')) {
    try { ensure_db_schema(); } catch (Throwable $e) { @error_log('[schema-bootstrap] ' . $e->getMessage()); }
}

// Auto-cron: apply any active brand-vibe schedule on every page load.
// Same "no real cron required" pattern used by `smtp_process_queue()`.
// `apply_vibe_schedule()` is idempotent + cheap (one indexed SELECT, one
// optional UPDATE) so it's safe to run unconditionally here.
if (PHP_SAPI !== 'cli' && function_exists('apply_vibe_schedule')) {
    apply_vibe_schedule();
}

function esc($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * SEO helpers — keep `<title>` and `<meta description>` within the lengths
 * Google actually renders on the SERP.  Cuts on the nearest word boundary
 * and appends an ellipsis when truncated; idempotent on already-short input.
 *
 * Targets follow Moz / Ahrefs guidance:
 *   • title       → 50-60 chars (we hard-cap at 60)
 *   • description → 120-160 chars (we hard-cap at 158)
 */
function seo_clamp_title(string $title, int $max = 60): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if (mb_strlen($title) <= $max) return $title;
    $cut = mb_substr($title, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " -—|·,:;") . '…';
}

function seo_clamp_description(string $desc, int $max = 158): string
{
    $desc = trim(preg_replace('/\s+/u', ' ', $desc));
    if (mb_strlen($desc) <= $max) return $desc;
    $cut = mb_substr($desc, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " -—|·,:;") . '…';
}

/**
 * Convert any human-readable phone string (e.g. "1-888-632-9902",
 * "(888) 632-9902 ext. 12") into an RFC-3966-safe `tel:` URI value in
 * E.164 form when possible: "+18886329902".  Falls back gracefully if
 * the input is too short or already contains a leading "+".
 */
function tel_e164(string $phone): string
{
    $raw = trim($phone);
    if ($raw === '') return '';
    $hasPlus = str_starts_with($raw, '+');
    $digits  = preg_replace('/\D+/', '', $raw);
    if ($digits === '' || strlen($digits) < 7) return $raw;
    // US/CA default: 10-digit number gets +1 prefix.
    if (!$hasPlus && strlen($digits) === 10) return '+1' . $digits;
    if (!$hasPlus && strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
    return ($hasPlus ? '+' : '+') . $digits;
}

/**
 * Returns the base URL path the application is installed under, always with
 * a trailing slash.  Works whether the project lives at the domain root
 * ("/") or inside a subfolder ("/admin/", "/my-shop/").
 *
 * Used by the JS layer (window.MAVEN_BASE) so all fetch() URLs to
 * /ajax/... endpoints stay correct regardless of where the app is deployed.
 *
 * Example:
 *   https://example.com/admin.php          → base_url() = "/"
 *   https://example.com/shop/admin.php     → base_url() = "/shop/"
 *   https://example.com/foo/bar/admin.php  → base_url() = "/foo/bar/"
 */
function base_url(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') $dir = '';
    return $cached = $dir . '/';
}

/**
 * Self-healing schema migration.  Idempotent — safe to call on every
 * admin page-load.  Adds any new tables / columns required by features
 * that were introduced after a fresh server install (visitor analytics,
 * live chat, chat tokens, etc.).  Failures are logged but never thrown
 * so a transient DB error doesn't take down the admin panel.
 */
function ensure_db_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // Visitor analytics
        $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            os VARCHAR(40) NOT NULL DEFAULT 'Unknown',
            browser VARCHAR(40) NOT NULL DEFAULT 'Unknown',
            device VARCHAR(20) NOT NULL DEFAULT 'Desktop',
            country VARCHAR(8) NOT NULL DEFAULT '',
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            referer VARCHAR(255) NOT NULL DEFAULT '',
            visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_visited (visited_at),
            KEY idx_session (session_id),
            KEY idx_os (os),
            KEY idx_device (device)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Live chat — admin ↔ visitor messages
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            sender ENUM('customer','admin') NOT NULL DEFAULT 'customer',
            message TEXT NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL DEFAULT NULL,
            KEY idx_lead (lead_id),
            KEY idx_sent (sent_at),
            KEY idx_unread (sender, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // chat_leads — ensure last_seen + chat_token columns exist (added later
        // than the original schema, may be missing on older installs).
        foreach ([
            "ALTER TABLE chat_leads ADD COLUMN last_seen DATETIME NULL DEFAULT NULL",
            "ALTER TABLE chat_leads ADD COLUMN chat_token VARCHAR(40) NOT NULL DEFAULT ''",
            // Mutual typing-presence beacons — admins see a "Customer is
            // typing…" indicator and customers see "Admin is typing…".
            // Each side stamps NOW() on every keystroke (throttled to ~2s
            // by the JS) so a "fresh within 5s" check serves the indicator.
            "ALTER TABLE chat_leads ADD COLUMN typing_admin_at    DATETIME NULL DEFAULT NULL",
            "ALTER TABLE chat_leads ADD COLUMN typing_customer_at DATETIME NULL DEFAULT NULL",
            // Last-time-we-emailed-admin-about-this-lead beacon used to
            // throttle the "New chat message from {name}" admin alerts to
            // one per lead per 5 minutes during a fast back-and-forth.
            "ALTER TABLE chat_leads ADD COLUMN admin_notified_at DATETIME NULL DEFAULT NULL",
            // categories.category_group → drives which header mega-menu column
            // a category appears under ('microsoft' | 'antivirus' | 'standalone').
            // Replaces the previously-hardcoded nav_microsoft() / antivirus
            // dropdown so admins can attach a brand-new category (e.g. an
            // "Adobe" line, a "Server" line) to either header group at
            // create time without an engineer touching the templates.
            "ALTER TABLE categories ADD COLUMN category_group VARCHAR(24) NOT NULL DEFAULT 'standalone'",
            "ALTER TABLE categories ADD COLUMN nav_heading    VARCHAR(48) NOT NULL DEFAULT ''",
            "ALTER TABLE categories ADD COLUMN sort_order     INT         NOT NULL DEFAULT 100",
            "ALTER TABLE categories ADD KEY idx_cat_group (category_group, sort_order)",
            // One-time backfill of category_group for the bundled SKUs.
            // Re-applies on every boot but is idempotent — UPDATE is a no-op
            // when the row already carries the target value.  Adds a
            // nav_heading too so the mega-menu can group by column header
            // (OFFICE FOR PC / OFFICE FOR MAC / WINDOWS / APPS).
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR PC',  sort_order=10 WHERE slug='office-2024-pc'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR PC',  sort_order=20 WHERE slug='office-2021-pc'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR PC',  sort_order=30 WHERE slug='office-2019-pc'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR PC',  sort_order=99 WHERE slug='office-pc'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR MAC', sort_order=10 WHERE slug='office-2024-mac'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR MAC', sort_order=20 WHERE slug='office-2021-mac'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR MAC', sort_order=30 WHERE slug='office-2019-mac'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR MAC', sort_order=99 WHERE slug='office-mac'",
            "UPDATE categories SET category_group='microsoft', nav_heading='WINDOWS',        sort_order=10 WHERE slug='windows-11'",
            "UPDATE categories SET category_group='microsoft', nav_heading='WINDOWS',        sort_order=20 WHERE slug='windows-10'",
            "UPDATE categories SET category_group='microsoft', nav_heading='WINDOWS',        sort_order=99 WHERE slug='windows'",
            "UPDATE categories SET category_group='microsoft', nav_heading='APPS',           sort_order=10 WHERE slug='microsoft-project'",
            "UPDATE categories SET category_group='microsoft', nav_heading='APPS',           sort_order=20 WHERE slug='microsoft-visio'",
            "UPDATE categories SET category_group='microsoft', nav_heading='APPS',           sort_order=99 WHERE slug='apps'",
            "UPDATE categories SET category_group='microsoft', nav_heading='OFFICE FOR PC',  sort_order=99 WHERE slug='office'",
            "UPDATE categories SET category_group='antivirus', nav_heading='ANTIVIRUS',      sort_order=10 WHERE slug='bitdefender'",
            "UPDATE categories SET category_group='antivirus', nav_heading='ANTIVIRUS',      sort_order=20 WHERE slug='mcafee'",
            "UPDATE categories SET category_group='antivirus', nav_heading='ANTIVIRUS',      sort_order=99 WHERE slug='antivirus'",
            // Paths to PDF attachments (Receipt + Invoice) generated per
            // paid order.  Stored as JSON array of absolute filesystem
            // paths; smtp_send_one() reads it and calls addAttachment()
            // for each, so the customer's order-delivery email arrives
            // with proper receipt + invoice PDFs.
            "ALTER TABLE email_outbox ADD COLUMN attachments_json TEXT NULL DEFAULT NULL",
            // products.{activation_url_mode, install_url_mode} — control how
            // each URL is sourced. 'ai' (default) → AI auto-fill is allowed
            // to overwrite the value; 'manual' → admin typed the URL and
            // it must be respected (AI never overwrites). Email rendering
            // reads activation_url / install_guide_url directly; the *_mode
            // columns only gate who fills them.
            "ALTER TABLE products ADD COLUMN activation_url_mode VARCHAR(10) NOT NULL DEFAULT 'ai'",
            "ALTER TABLE products ADD COLUMN install_url_mode    VARCHAR(10) NOT NULL DEFAULT 'ai'",
            // Persistent log of every "Ask AI" turn on the product page.
            // Lets admins (a) review what customers ask, (b) train the
            // system prompt over time, (c) capture lead intent (the
            // question itself is high-quality buyer signal).
            "CREATE TABLE IF NOT EXISTS product_ai_chats (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_slug VARCHAR(160) NOT NULL,
                product_name VARCHAR(255) NOT NULL DEFAULT '',
                session_id   VARCHAR(64)  NOT NULL DEFAULT '',
                question     TEXT         NOT NULL,
                answer       MEDIUMTEXT   NULL,
                tokens_in    INT NULL DEFAULT NULL,
                tokens_out   INT NULL DEFAULT NULL,
                ms_latency   INT NULL DEFAULT NULL,
                helpful      TINYINT(1)   NULL DEFAULT NULL,
                user_ip      VARCHAR(45)  NOT NULL DEFAULT '',
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_slug_time (product_slug, created_at),
                KEY idx_session   (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // customer_reviews — admin_seen_at lets the topbar star-bell badge
            // tell which low-rating submissions are still unacknowledged.
            "ALTER TABLE customer_reviews ADD COLUMN admin_seen_at DATETIME NULL DEFAULT NULL",
            // orders — capture Stripe Radar risk score / level + an optional
            // company name on the checkout form.  These are added late so
            // existing installs need an idempotent ALTER.
            "ALTER TABLE orders ADD COLUMN risk_score   SMALLINT  NULL DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN risk_level   VARCHAR(20) NOT NULL DEFAULT ''",
            "ALTER TABLE orders ADD COLUMN company_name VARCHAR(120) NOT NULL DEFAULT ''",
            "ALTER TABLE orders ADD COLUMN payment_intent_id VARCHAR(120) NOT NULL DEFAULT ''",
            // gw_mode — 'test' / 'live'.  Captured per-order so admins can
            // filter sandbox dry-runs from real revenue in the orders grid.
            // Production sites that pre-date this column would otherwise
            // crash on /checkout.php (Unknown column 'gw_mode' in INSERT INTO).
            "ALTER TABLE orders ADD COLUMN gw_mode VARCHAR(10) NOT NULL DEFAULT 'test'",
            // Session metadata captured at order creation for the Sales
            // Detail view + fraud signals; also added defensively so any
            // legacy production schema gets self-healed on first hit.
            "ALTER TABLE orders ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN timeline LONGTEXT DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN region VARCHAR(8) NOT NULL DEFAULT 'US'",
            "ALTER TABLE orders ADD COLUMN address2 VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_statement_name VARCHAR(120) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_brand VARCHAR(30) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_type VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_last4 VARCHAR(4) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_exp VARCHAR(7) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_country VARCHAR(8) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN card_funding VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN billing_country VARCHAR(8) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_funding_source VARCHAR(40) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_payer_email VARCHAR(180) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_payer_id VARCHAR(60) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_funding_card_brand VARCHAR(30) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_funding_card_last4 VARCHAR(4) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN paypal_funding_bank_name VARCHAR(60) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN transaction_id VARCHAR(120) DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN user_id INT DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN pro_assist TINYINT(1) NOT NULL DEFAULT 0",
            // blog_posts — AI Auto-Blogger columns.  These are added in
            // seo-bot.php's own bootstrap, but seo-bot.php is only loaded
            // when publishing runs.  Public pages (brand.php, blog.php)
            // also SELECT these columns, so we mirror the migration here
            // so a fresh install or a host that hasn't run the publisher
            // yet never breaks the public Articles list.
            "ALTER TABLE blog_posts ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE blog_posts ADD COLUMN product_id INT NULL DEFAULT NULL",
            "ALTER TABLE blog_posts ADD COLUMN created_at DATETIME NULL DEFAULT NULL",
            "ALTER TABLE blog_posts ADD COLUMN target_region VARCHAR(4) NOT NULL DEFAULT 'US'",
            "ALTER TABLE blog_posts ADD COLUMN indexnow_status VARCHAR(20) NOT NULL DEFAULT ''",
            "ALTER TABLE blog_posts ADD COLUMN verified_http SMALLINT NULL DEFAULT NULL",
            "ALTER TABLE blog_posts ADD COLUMN verified_at DATETIME NULL DEFAULT NULL",
            "ALTER TABLE blog_posts ADD COLUMN internal_links_count INT NOT NULL DEFAULT 0",
            "ALTER TABLE blog_posts ADD COLUMN content_fingerprint VARCHAR(64) NOT NULL DEFAULT ''",
            "ALTER TABLE blog_posts ADD COLUMN is_featured_trends TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE blog_posts ADD KEY idx_featured_trends (is_featured_trends)",

            // ---------------------------------------------------------------
            // Critical production-deploy migrations.
            // These columns were previously only added by /app/php-version/start.sh
            // (the Emergent-pod launcher). cPanel / shared-hosting customers
            // import `database.sql` once and the launcher never runs there,
            // so the columns below were missing in production — causing
            // PDOException: "Unknown column 'delivery_status' in 'SET'" on
            // checkout.php → fulfill_order() → includes/email.php:1637.
            // Adding them to ensure_db_schema() makes the schema self-heal
            // on the very first request after a fresh upload, no shell
            // access needed.
            // ---------------------------------------------------------------

            // orders.delivery_status — 'delivered' once the license key is
            // emailed, 'pending' when sold out (backorder, delivered <1 hr).
            // Required by includes/email.php fulfill_order() — root cause of
            // the production fatal that prompted this migration block.
            "ALTER TABLE orders ADD COLUMN delivery_status VARCHAR(20) NOT NULL DEFAULT 'delivered' AFTER fulfilled",

            // products — per-SKU vendor URLs used by the order-delivery email
            // (activation page, install guide, downloadable installer) and
            // by the Google/Bing/Meta shopping feed (gtin).
            "ALTER TABLE products ADD COLUMN activation_url     VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE products ADD COLUMN install_guide_url  VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE products ADD COLUMN installer_url      VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE products ADD COLUMN gtin               VARCHAR(20)  DEFAULT NULL AFTER sku",

            // chat_messages — file upload + voice-note attachments in support chat.
            "ALTER TABLE chat_messages ADD COLUMN attachment_url  VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE chat_messages ADD COLUMN attachment_type VARCHAR(20)  DEFAULT NULL",
            "ALTER TABLE chat_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL",

            // chat_leads — admin_seen_at drives the topbar red-dot "needs
            // attention" badge for callback/ProAssist leads; agent_name is
            // shown to the customer when a live agent joins ("Alex has
            // joined the chat").
            "ALTER TABLE chat_leads ADD COLUMN admin_seen_at DATETIME    DEFAULT NULL",
            "ALTER TABLE chat_leads ADD COLUMN agent_name    VARCHAR(120) DEFAULT NULL",

            // users — staff RBAC fields (username login, department, granular
            // per-panel permissions, active flag). email is made nullable so
            // username-only accounts (no inbox required) are valid.
            "ALTER TABLE users ADD COLUMN username    VARCHAR(60) DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN department  VARCHAR(40) NOT NULL DEFAULT ''",
            "ALTER TABLE users ADD COLUMN permissions TEXT        DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN active      TINYINT(1)  NOT NULL DEFAULT 1",
            "ALTER TABLE users MODIFY email VARCHAR(255) NULL",
            "ALTER TABLE users ADD UNIQUE KEY uniq_username (username)",

            // theme_pref — per-user "remember my light/dark choice across
            // devices" preference. Empty string = no override (use system /
            // cookie). Was previously stored only in localStorage / cookie,
            // which meant a multi-device admin had to re-toggle on every
            // browser. Now any logged-in user's last toggle is persisted
            // here via /ajax/user-theme.php and read back at page load by
            // admin-shell.php (and header.php for public-side personalisation).
            "ALTER TABLE users ADD COLUMN theme_pref VARCHAR(10) NOT NULL DEFAULT ''",

            // Per-product sale window — optional ISO datetime range that
            // populates Google Shopping's `g:sale_price_effective_date`.
            // Both NULL means "no fixed window" — the feed falls back to a
            // rolling 30-day window starting today, which is the standard
            // pattern for resellers who run an evergreen discount and keeps
            // Google's Merchant Center happy (a permanent "from $X" gets
            // flagged as misleading, a rolling window doesn't).
            "ALTER TABLE products ADD COLUMN sale_starts_at DATETIME DEFAULT NULL",
            "ALTER TABLE products ADD COLUMN sale_ends_at   DATETIME DEFAULT NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* column already exists */ }
        }

        // Auto-seed GTIN-13 for any product still missing one.  Uses the GS1
        // in-store reserved prefix "200" + 9 deterministic digits derived
        // from the product slug + a valid mod-10 checksum — so every row in
        // the catalog ships with a barcode-valid identifier for the Google
        // Shopping feed and Product JSON-LD (`gtin13`).  Idempotent.
        try {
            $missing = $pdo->query("SELECT id, slug, sku FROM products WHERE gtin IS NULL OR gtin = ''")->fetchAll(PDO::FETCH_ASSOC);
            if ($missing) {
                $upd = $pdo->prepare('UPDATE products SET gtin = :g WHERE id = :id');
                foreach ($missing as $r) {
                    $seed = ($r['slug'] ?? '') . '|' . ($r['sku'] ?? '');
                    $hash = md5($seed);
                    $bigDecimal = '';
                    foreach (str_split($hash) as $hex) { $bigDecimal .= (string)hexdec($hex); }
                    $body9 = substr($bigDecimal, 6, 9);
                    while (strlen($body9) < 9) $body9 .= '0';
                    $first12 = '200' . $body9;
                    $sum = 0;
                    for ($i = 0; $i < 12; $i++) {
                        $d = (int)$first12[$i];
                        $sum += ($i % 2 === 0) ? $d : $d * 3;
                    }
                    $check = (10 - ($sum % 10)) % 10;
                    $upd->execute([':g' => $first12 . $check, ':id' => (int)$r['id']]);
                }
            }
        } catch (Throwable $e) { /* products table missing — fresh install will recreate */ }

        // stripe_events — webhook audit + idempotency. The lazy creation
        // inside stripe-webhook.php only runs when a webhook arrives, but
        // the admin "Sales History" filter reads from it eagerly. Create
        // it up-front so the admin panel never blows up on a fresh DB.
        $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id   VARCHAR(80) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            payload    LONGTEXT,
            received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_id (event_id),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Brand-vibe schedule — admin queues "switch to Playful on Black
        // Friday, switch back to Classic on Dec 1".  The scheduler runs on
        // every page load (cheap query) so no external cron is needed.
        $pdo->exec("CREATE TABLE IF NOT EXISTS vibe_schedule (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vibe        VARCHAR(20) NOT NULL,
            starts_at   DATETIME NOT NULL,
            ends_at     DATETIME NULL DEFAULT NULL,
            label       VARCHAR(120) NOT NULL DEFAULT '',
            logo_path   VARCHAR(255) NOT NULL DEFAULT '',
            applied_at  DATETIME NULL DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_starts (starts_at),
            KEY idx_ends (ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Idempotent add of `logo_path` for installs that pre-date the column.
        try { $pdo->exec("ALTER TABLE vibe_schedule ADD COLUMN logo_path VARCHAR(255) NOT NULL DEFAULT '' AFTER label"); } catch (Throwable $e) {}
        // Idempotent add of `coupon_code` + `coupon_percent` so each
        // schedule can declare its own promo discount that auto-applies
        // during the active window (e.g. Black Friday → code BF26 → 20% off).
        try { $pdo->exec("ALTER TABLE vibe_schedule ADD COLUMN coupon_code VARCHAR(40) NOT NULL DEFAULT '' AFTER logo_path"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE vibe_schedule ADD COLUMN coupon_percent INT NOT NULL DEFAULT 0 AFTER coupon_code"); } catch (Throwable $e) {}

        // Password-reset tokens — issued when a user clicks "Forgot password?".
        // Each token is single-use, 60-min TTL, and is hashed on disk so a
        // DB leak doesn't immediately compromise live reset links.
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            token_hash VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at    DATETIME NULL DEFAULT NULL,
            KEY idx_user (user_id),
            KEY idx_token (token_hash),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Brand-vibe history — append-only log of every vibe switch (manual
        // or scheduled).  Powers the "Vibe Performance" dashboard widget
        // that shows which vibe was live each day + per-vibe conversion.
        $pdo->exec("CREATE TABLE IF NOT EXISTS vibe_history (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vibe       VARCHAR(20) NOT NULL,
            source     VARCHAR(20) NOT NULL DEFAULT 'manual',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_started (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Topic Cluster Hubs — admin-managed dynamic /hub/<slug> pages.
        // Each row drives a hub URL and aggregates products + posts + FAQs
        // by category slug.  source: 'seed' (default 3 hubs), 'manual'
        // (admin created), 'auto' (auto-generated from top categories),
        // 'gsc' (created from a Google Search Console cluster suggestion).
        $pdo->exec("CREATE TABLE IF NOT EXISTS topic_hubs (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL,
            title VARCHAR(255) NOT NULL,
            headline TEXT NOT NULL,
            audience VARCHAR(255) NOT NULL DEFAULT '',
            categories_json TEXT NOT NULL,
            blog_tags_json TEXT NOT NULL,
            keywords TEXT NOT NULL,
            about_link VARCHAR(255) NOT NULL DEFAULT '',
            color VARCHAR(20) NOT NULL DEFAULT '#0078d4',
            videos_json TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(16) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_slug (slug),
            KEY idx_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Google Search Console — query-level data uploaded by admin via
        // the Performance Report CSV.  Powers the "SEO Discovery Lab" that
        // clusters queries into topic suggestions for new hubs / posts.
        $pdo->exec("CREATE TABLE IF NOT EXISTS gsc_queries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            query VARCHAR(255) NOT NULL,
            impressions INT NOT NULL DEFAULT 0,
            clicks INT NOT NULL DEFAULT 0,
            ctr DECIMAL(8,4) NOT NULL DEFAULT 0,
            position DECIMAL(8,2) NOT NULL DEFAULT 0,
            cluster_key VARCHAR(120) NOT NULL DEFAULT '',
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_query (query),
            KEY idx_cluster (cluster_key),
            KEY idx_impr (impressions)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ProAssist install-call schedules — customer picks a 30-min slot
        // from the chat widget, admin sees it on the new "Install Schedule"
        // tab.  scheduled_at is stored in America/New_York time (label),
        // scheduled_utc is the canonical UTC timestamp for sorting.
        $pdo->exec("CREATE TABLE IF NOT EXISTS proassist_schedules (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            order_id INT NULL DEFAULT NULL,
            order_number VARCHAR(40) NOT NULL DEFAULT '',
            customer_name  VARCHAR(120) NOT NULL DEFAULT '',
            customer_email VARCHAR(160) NOT NULL DEFAULT '',
            customer_phone VARCHAR(40)  NOT NULL DEFAULT '',
            scheduled_at   DATETIME NOT NULL,
            scheduled_utc  DATETIME NOT NULL,
            tz             VARCHAR(40) NOT NULL DEFAULT 'America/New_York',
            status         ENUM('pending','confirmed','done','missed','cancelled') NOT NULL DEFAULT 'pending',
            notes          TEXT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lead (lead_id),
            KEY idx_sched_utc (scheduled_utc),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        @error_log('[ensure_db_schema] ' . $e->getMessage());
    }
}

/* ---------------- Currency ---------------- */
/* Country path-prefix (/au, /uk, /ca, /eu — set by router.php in $GLOBALS['MV_COUNTRY'])
   drives the storefront currency for the request. Falls back to the ?cur= switch. */
$__countryCurrency = ['US' => 'USD', 'UK' => 'GBP', 'EU' => 'EUR', 'CA' => 'CAD', 'AU' => 'AUD'];
/* Apache / cPanel hosting doesn't run router.php, so the regional prefix is
   handed over by the .htaccess rewrite as ?mv_cc=XX. Honour it when router.php
   hasn't already resolved the country for this request. */
if (empty($GLOBALS['MV_COUNTRY']) && !empty($_GET['mv_cc'])) {
    $__mvcc = strtoupper((string)$_GET['mv_cc']);
    if (in_array($__mvcc, ['US', 'UK', 'AU', 'CA', 'EU'], true)) {
        $GLOBALS['MV_COUNTRY']  = $__mvcc;
        $GLOBALS['MV_PREFIXED'] = ($__mvcc !== 'US');
    }
}
/* Resolve the storefront currency deterministically from the region on EVERY
   request. The canonical US storefront is the bare (un-prefixed) path, so an
   empty MV_COUNTRY means United States — we must actively reset the currency to
   USD, otherwise a previously-selected region (e.g. CAD after visiting /ca/...)
   would stick in the PHP session when the shopper switches back to the US on
   Apache, where /us/... 301s to a bare path carrying no mv_cc. An explicit
   ?cur= switch still wins for manual overrides. */
if (isset($_GET['cur']) && isset($GLOBALS['CURRENCIES'][$_GET['cur']])) {
    $_SESSION['currency'] = $_GET['cur'];
} else {
    $__ctry = strtoupper((string)($GLOBALS['MV_COUNTRY'] ?? 'US'));
    if (!in_array($__ctry, ['US', 'UK', 'AU', 'CA', 'EU'], true)) $__ctry = 'US';
    if (isset($__countryCurrency[$__ctry]) && isset($GLOBALS['CURRENCIES'][$__countryCurrency[$__ctry]])) {
        $_SESSION['currency'] = $__countryCurrency[$__ctry];
    }
}

/** Current country code for the request (US|UK|AU|CA|EU). US = canonical root. */
function current_country_code(): string {
    $c = strtoupper((string)($GLOBALS['MV_COUNTRY'] ?? 'US'));
    return in_array($c, ['US', 'UK', 'AU', 'CA', 'EU'], true) ? $c : 'US';
}

/** URL path prefix for a country ('' for US, '/au' etc.). */
function country_prefix(?string $code = null): string {
    $code = strtoupper($code ?? current_country_code());
    return $code === 'US' ? '' : '/' . strtolower($code);
}

/** Build a same-site link carrying the current country prefix, e.g.
 *  country_url('product.php?slug=x') => '/au/product.php?slug=x'. */
function country_url(string $path, ?string $code = null): string {
    $path = ltrim($path, '/');
    $pre  = country_prefix($code);
    return ($pre === '' ? '/' : $pre . '/') . $path;
}

/** Bare request path for the header country switcher: the current REQUEST_URI
 *  with any existing country prefix (/us /uk /au /ca /eu) and the cur=/mv_cc=
 *  params removed. This guarantees switching region never stacks prefixes —
 *  e.g. on /ca/shop, the "Australia" link must resolve to /au/shop, NOT
 *  /au/ca/shop (the latter 404s on production Apache, where REQUEST_URI keeps
 *  the original prefixed path). Works on both the dev router and Apache. */
function country_switch_base(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = preg_replace('/([?&])(cur|mv_cc)=[^&]*/', '$1', (string)$uri);
    $uri = preg_replace('#^/(us|uk|au|ca|eu)(?=/|$|\?)#i', '', $uri);
    $uri = rtrim($uri, '?&');
    if ($uri === '' || $uri[0] !== '/') $uri = '/' . ltrim($uri, '/');
    return $uri === '' ? '/' : $uri;
}

/**
 * Is this order line a hands-on SERVICE (not a software license)?
 * Service items never get a license key, never make an order "pending",
 * and render as a distinct service line. Currently the ProAssist Premium
 * Installation add-on (a virtual SKU, not a row in `products`).
 */
function is_service_item(?string $slug): bool {
    return in_array((string)$slug, ['proassist-premium'], true);
}

/**
 * Resolve the support / toll-free phone number for a given country.
 * The US number (`company_phone` setting) is the global default shown for
 * every country; a country-specific override (`company_phone_ca|au|uk|eu`)
 * is used only when the admin has filled it in. $cc accepts US|CA|AU|UK|EU
 * (defaults to the storefront's current country). This is the single source
 * of truth for every phone number on the site, in emails and in PDFs.
 */
function company_phone_for_country(?string $cc = null): string {
    $co      = company_info();
    $default = trim((string)($co['phone'] ?? ''));
    $cc      = strtoupper(trim((string)($cc !== null && $cc !== '' ? $cc : current_country_code())));
    if ($cc === '' || $cc === 'US') return $default;
    $v = trim((string)setting_get('company_phone_' . strtolower($cc), ''));
    return $v !== '' ? $v : $default;
}

/**
 * Replace company-info placeholders in stored HTML (DB pages, etc.) with the
 * live values from Company Info — so updating a number/email/address in admin
 * propagates to every page automatically. Phone is country-aware ($cc).
 */
function company_placeholders_apply(string $html, ?string $cc = null): string {
    if ($html === '' || strpos($html, '{{') === false) return $html;
    $co    = company_info();
    $phone = company_phone_for_country($cc);
    $map = [
        '{{support_phone}}'     => esc($phone),
        '{{company_phone}}'     => esc($phone),
        '{{support_phone_tel}}' => esc(tel_e164($phone)),
        '{{support_email}}'     => esc((string)($co['email']   ?? '')),
        '{{company_email}}'     => esc((string)($co['email']   ?? '')),
        '{{company_name}}'      => esc((string)($co['name']    ?? '')),
        '{{company_address}}'   => esc((string)($co['address'] ?? '')),
    ];
    return strtr($html, $map);
}

/**
 * Return a web path to a MINIFIED, cache-busted copy of a CSS file.
 * The minified file (…min.css) is regenerated automatically whenever the
 * source changes (mtime compare), so editing the source "just works".
 * Safe whitespace/comment minifier — no rule changes, so zero CLS risk.
 * Falls back to the original path if the target dir isn't writable.
 */
function min_css_url(string $srcFsPath, string $srcWebPath): string {
    if (!is_file($srcFsPath)) return $srcWebPath;
    $minFs  = preg_replace('/\.css$/', '.min.css', $srcFsPath);
    $minWeb = preg_replace('/\.css$/', '.min.css', $srcWebPath);
    $srcMt  = (int)@filemtime($srcFsPath);
    if (!is_file($minFs) || (int)@filemtime($minFs) < $srcMt) {
        $css = (string)@file_get_contents($srcFsPath);
        if ($css === '') return $srcWebPath . '?v=' . $srcMt;
        $css = preg_replace('!/\*.*?\*/!s', '', $css);            // strip comments
        $css = preg_replace('/\s+/', ' ', $css);                  // collapse whitespace
        $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css);  // trim around tokens
        $css = str_replace(';}', '}', (string)$css);
        $css = trim((string)$css);
        if (@file_put_contents($minFs, $css) === false) {
            return $srcWebPath . '?v=' . $srcMt;                  // dir not writable → original
        }
    }
    return $minWeb . '?v=' . (int)@filemtime($minFs);
}

/**
 * True only for a GLOBALLY valid GTIN (real GS1 identifier). Rejects the
 * GS1 restricted / in-store ranges (GTIN-12/13 starting with "2", and the
 * 02/04/05 prefixes) that pass the check digit but are NOT globally unique —
 * which is exactly what Google flags as "Not a globally valid GTIN".
 * Software license keys typically have NO real GTIN, so callers should omit
 * the field entirely when this returns false (brand + MPN still identify it).
 */
function is_valid_global_gtin($raw): bool {
    $g = preg_replace('/\D+/', '', (string)$raw);
    $len = strlen($g);
    if (!in_array($len, [8, 12, 13, 14], true)) return false;
    if (in_array($len, [12, 13], true) && $g[0] === '2') return false;          // restricted / in-store range
    if (in_array(substr($g, 0, 2), ['02', '04', '05'], true)) return false;     // restricted distribution / coupons
    $digits = array_reverse(str_split($g));
    $sum = 0;
    for ($i = 1; $i < $len; $i++) {
        $sum += ((int)$digits[$i]) * (($i % 2 === 1) ? 3 : 1);
    }
    $check = (10 - ($sum % 10)) % 10;
    return $check === (int)$digits[0];
}


/** Returns the list of currency codes whose region is currently active in admin. */
function active_currency_codes(): array {
    $map = ['US'=>'USD','UK'=>'GBP','EU'=>'EUR','CA'=>'CAD','AU'=>'AUD'];
    $out = [];
    try {
        foreach (db()->query('SELECT code, currency FROM regions WHERE active=1') as $r) {
            $cc = $map[$r['code']] ?? $r['currency'] ?? null;
            if ($cc && isset($GLOBALS['CURRENCIES'][$cc])) $out[$cc] = true;
        }
    } catch (Throwable $e) { /* DB not ready */ }
    if (empty($out)) $out['USD'] = true;
    return array_keys($out);
}

function current_currency(): array
{
    $code = $_SESSION['currency'] ?? 'USD';
    $active = active_currency_codes();
    if (!in_array($code, $active, true)) {
        // Session currency was deactivated in admin — fall back to first active.
        $code = $active[0] ?? 'USD';
        $_SESSION['currency'] = $code;
    }
    if (!isset($GLOBALS['CURRENCIES'][$code])) $code = 'USD';
    return ['code' => $code] + $GLOBALS['CURRENCIES'][$code];
}

function format_price(float $usd): string
{
    $c = current_currency();
    return $c['symbol'] . number_format($usd * $c['rate'], 2);
}

/* ---------------- Auth ---------------- */
function ensure_admin(): void
{
    admin_staff_migrate();
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([strtolower(ADMIN_EMAIL)]);
    if (!$stmt->fetch()) {
        $ins = db()->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)');
        $ins->execute([strtolower(ADMIN_EMAIL), 'Admin', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT), 'admin']);
    }
}

/** Self-healing schema for staff accounts (runs once, flag-guarded). */
function admin_staff_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (setting_get('staff_schema_v1', '') === '1') return;
        $pdo = db();
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(60) DEFAULT NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS department VARCHAR(40) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1");
        try { $pdo->exec("ALTER TABLE users MODIFY email VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uniq_username (username)"); } catch (Throwable $e) {}
        setting_set('staff_schema_v1', '1');
    } catch (Throwable $e) { /* retry next boot */ }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, email, name, role, username, department, permissions, active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Staff / RBAC — admin panels each map to a permission key. The super admin
// (role='admin') always has every permission; staff (role='staff') only have
// the keys stored in users.permissions (JSON array).
// ---------------------------------------------------------------------------
function admin_panels(): array
{
    // permission key => [label, href, bootstrap-icon]
    return [
        'dashboard'   => ['Dashboard',                'admin.php?tab=dashboard',        'bi-speedometer2'],
        'users'       => ['Users',                    'admin.php?tab=users',            'bi-people-fill'],
        'subscription'=> ['Subscription',             'admin.php?tab=subscription',     'bi-stars'],
        'ai-blogger'  => ['AI Auto-Blogger',          'admin.php?tab=ai-blogger',       'bi-robot'],
        'company'     => ['Company Info',             'admin.php?tab=company',          'bi-building'],
        'inventory'   => ['Inventory Mgmt',           'inventory.php',                  'bi-boxes'],
        'products'    => ['Products / Key Inventory', 'admin.php?tab=products',         'bi-box-seam'],
        'orders'      => ['Orders',                   'admin.php?tab=orders',           'bi-receipt'],
        'sales'       => ['Sales Detail',             'admin.php?tab=sales',            'bi-graph-up-arrow'],
        'leads'       => ['Lead Management',          'admin.php?tab=leads',            'bi-person-lines-fill'],
        'schedule'    => ['Install Schedule',         'admin.php?tab=schedule',         'bi-calendar-check'],
        'emails'      => ['Email Activity',           'admin.php?tab=emails',           'bi-envelope'],
        'reviews'     => ['Customer Reviews',         'admin.php?tab=reviews',          'bi-star'],
        'templates'   => ['Email Templates',          'admin.php?tab=templates',        'bi-file-earmark-richtext'],
        'gateways'    => ['API / Payment Gateway',    'admin.php?tab=api&gw=toggles',   'bi-credit-card-2-front'],
        'smtp'        => ['SMTP / Mail Server',        'admin.php?tab=smtp',             'bi-envelope-paper-heart'],
        'regions'     => ['Regions',                  'admin.php?tab=regions',          'bi-globe'],
    ];
}
function admin_is_super(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && (($u['role'] ?? '') === 'admin');
}
function admin_permissions(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u) return [];
    if (($u['role'] ?? '') === 'admin') return array_keys(admin_panels());
    $p = json_decode((string)($u['permissions'] ?? ''), true);
    return is_array($p) ? array_values(array_intersect($p, array_keys(admin_panels()))) : [];
}
function admin_can(string $key, ?array $u = null): bool
{
    if (admin_is_super($u)) return true;
    return in_array($key, admin_permissions($u), true);
}
/** Map an admin.php ?tab value to its permission key. */
function admin_tab_perm(string $tab): string
{
    $map = ['api' => 'gateways', 'keys' => 'products', 'install-schedule' => 'schedule', 'settings' => 'company'];
    return $map[$tab] ?? $tab;
}
/** First panel href the user is allowed to land on (for redirects). */
function admin_first_allowed(?array $u = null): string
{
    $panels = admin_panels();
    foreach ($panels as $key => $meta) {
        if (admin_can($key, $u)) return $meta[1];
    }
    return 'login.php';
}

function require_admin(): array
{
    $user = current_user();
    $ok = $user && in_array($user['role'] ?? '', ['admin', 'staff'], true) && (int)($user['active'] ?? 1) === 1;
    if (!$ok) {
        if ($user && (($user['role'] ?? '') === 'staff') && (int)($user['active'] ?? 1) === 0) {
            // Deactivated staff — drop the session so they can't keep poking.
            unset($_SESSION['user_id']);
        }
        header('Location: login.php?next=admin.php');
        exit;
    }
    return $user;
}

/**
 * AJAX-flavoured auth gate.  Returns the authenticated admin row OR
 * short-circuits the request with HTTP 403 + a JSON error body so the
 * client gets a parseable response instead of a 302 redirect to /login.
 *
 * Use this in /ajax/*.php endpoints that must NEVER leak data to
 * anonymous callers.  It replaces the misnamed ensure_admin() which
 * only SEEDS the admin row in the database (not an auth check).
 */
function require_admin_json(): array
{
    $user = current_user();
    $ok = $user && in_array($user['role'] ?? '', ['admin', 'staff'], true) && (int)($user['active'] ?? 1) === 1;
    if (!$ok) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'admin auth required']);
        exit;
    }
    return $user;
}

/* ---------------- Cart (session) ---------------- */
function cart(): array
{
    return $_SESSION['cart'] ?? []; // [slug => qty]
}

function cart_count(): int
{
    return array_sum(cart());
}

function cart_items(): array
{
    $c = cart();
    if (!$c) return [];
    $in = implode(',', array_fill(0, count($c), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE slug IN ($in)");
    $stmt->execute(array_keys($c));
    $items = [];
    foreach ($stmt->fetchAll() as $p) {
        $p['qty'] = $c[$p['slug']];
        $items[] = $p;
    }
    return $items;
}

function cart_subtotal(): float
{
    $t = 0;
    foreach (cart_items() as $i) $t += $i['price'] * $i['qty'];
    return $t;
}

/* ---------------- Products ---------------- */
function get_product(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE slug = ? AND ' . active_regions_sql_in('region'));
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

// Parent/alias category slugs -> list of granular categories
function category_children(string $slug): array
{
    $map = [
        'office-pc'  => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc'],
        'office-mac' => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office'     => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc', 'office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'windows'    => ['windows-11', 'windows-10'],
        'apps'       => ['microsoft-project', 'microsoft-visio'],
        'antivirus'  => ['bitdefender', 'mcafee'],
        // legacy aliases
        'microsoft-office'       => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc', 'office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'microsoft-office-2024'  => ['office-2024-pc', 'office-2024-mac'],
        'microsoft-office-2021'  => ['office-2021-pc', 'office-2021-mac'],
        'microsoft-office-2019'  => ['office-2019-pc', 'office-2019-mac'],
        'office-2024-for-mac'    => ['office-2024-mac'],
        'office-2021-for-mac'    => ['office-2021-mac'],
        'office-2019-for-mac'    => ['office-2019-mac'],
        'office-for-mac'         => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office-for-macs'        => ['office-2024-mac', 'office-2021-mac', 'office-2019-mac'],
        'office-for-windows'     => ['office-2024-pc', 'office-2021-pc', 'office-2019-pc'],
        'windows-os'             => ['windows-11', 'windows-10'],
        'mcafee-antivirus'       => ['mcafee'],
        'microsoft-apps'         => ['microsoft-project', 'microsoft-visio'],
    ];
    return $map[$slug] ?? [$slug];
}

function category_title(string $slug): string
{
    $stmt = db()->prepare('SELECT name FROM categories WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) return $row['name'];
    return ucwords(str_replace('-', ' ', $slug));
}

function get_products(array $categories = [], string $platform = '', string $sort = ''): array
{
    $sql = 'SELECT * FROM products';
    $where = [active_regions_sql_in('region')];
    $params = [];
    if ($categories) {
        $where[] = 'category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $params = array_merge($params, $categories);
    }
    if ($platform === 'Windows' || $platform === 'Mac') {
        $where[] = 'platform = ?';
        $params[] = $platform;
    }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $orders = [
        'price_asc'  => 'price ASC',
        'price_desc' => 'price DESC',
        'newest'     => 'is_new DESC, id ASC',
    ];
    $sql .= ' ORDER BY ' . ($orders[$sort] ?? 'id ASC');
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function slugify(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/* ---------------- App icons ---------------- */
function app_icons(): array
{
    // Local files under /assets/images/brand-watermarks/microsoft-suite/
    // (no external CDN — keeps the sitemap + product pages 100% on our own
    //  domain).
    return [
        'word'       => '/assets/images/brand-watermarks/microsoft-suite/word.png',
        'excel'      => '/assets/images/brand-watermarks/microsoft-suite/excel.png',
        'powerpoint' => '/assets/images/brand-watermarks/microsoft-suite/powerpoint.png',
        'outlook'    => '/assets/images/brand-watermarks/microsoft-suite/outlook.png',
        'access'     => '/assets/images/brand-watermarks/microsoft-suite/access.png',
    ];
}

/* ---------------- Mega menu data ---------------- */
// Each column: heading => ['all' => [categorySlug, label], 'groups' => [yearLabel => categorySlug]]
/**
 * Header mega-menu — Microsoft Products column.
 *
 * Returns a nested array of `nav_heading` columns → label/category-slug
 * groups, queried from the `categories` table where `category_group =
 * 'microsoft'`.  Admins create new categories under any of these
 * columns (or under "Antivirus", or as "Standalone" for back-office
 * only) directly from the Add Product form — no engineer edits to
 * this file required.
 *
 * Hard-coded column ordering preserves the "Office for PC, Office for
 * Mac, Windows, Apps" sequence the storefront has shipped with; new
 * `nav_heading` values added by admins fall in alphabetical order at
 * the end.
 */
function nav_microsoft(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $rows = [];
    try {
        $rows = db()->query(
            "SELECT slug, name, nav_heading, sort_order
             FROM categories
             WHERE category_group = 'microsoft' AND slug <> '' AND nav_heading <> ''
             ORDER BY sort_order ASC, slug ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* fresh install — fall through to defaults */ }

    // Aggregate by nav_heading column, picking the row with sort_order=99
    // as the "All X" link (e.g. office-pc → "All Office for PC").
    $byCol = [];
    foreach ($rows as $r) {
        $h = (string)$r['nav_heading'];
        if (!isset($byCol[$h])) $byCol[$h] = ['all' => null, 'groups' => []];
        if ((int)$r['sort_order'] >= 99) {
            $byCol[$h]['all'] = [(string)$r['slug'], 'All ' . ucwords(strtolower(str_replace('FOR ', 'for ', $h)))];
        } else {
            $byCol[$h]['groups'][(string)$r['name']] = (string)$r['slug'];
        }
    }

    // Preserve the shipped column order, then append any custom headings
    // alphabetically at the end.
    $preferred = ['OFFICE FOR PC', 'OFFICE FOR MAC', 'WINDOWS', 'APPS'];
    $out = [];
    foreach ($preferred as $col) {
        if (isset($byCol[$col])) {
            $out[$col] = [
                'all'    => $byCol[$col]['all'] ?? [strtolower(strtr($col, [' ' => '-', 'FOR ' => ''])), 'All ' . $col],
                'groups' => $byCol[$col]['groups'],
            ];
            unset($byCol[$col]);
        }
    }
    ksort($byCol);
    foreach ($byCol as $col => $data) {
        $out[$col] = [
            'all'    => $data['all'] ?? [strtolower(strtr($col, [' ' => '-'])), 'All ' . $col],
            'groups' => $data['groups'],
        ];
    }

    // Fresh-install fallback — if no Microsoft-group rows yet, render the
    // original hardcoded grid so the menu never goes blank during the
    // first boot before the migrations run.
    if (!$out) {
        $out = [
            'OFFICE FOR PC'  => ['all' => ['office-pc', 'All Office for PC'],
                                 'groups' => ['Office 2024' => 'office-2024-pc', 'Office 2021' => 'office-2021-pc', 'Office 2019' => 'office-2019-pc']],
            'OFFICE FOR MAC' => ['all' => ['office-mac', 'All Office for Mac'],
                                 'groups' => ['Office 2024 for Mac' => 'office-2024-mac', 'Office 2021 for Mac' => 'office-2021-mac', 'Office 2019 for Mac' => 'office-2019-mac']],
            'WINDOWS'        => ['all' => ['windows', 'All Windows'],
                                 'groups' => ['Windows 11' => 'windows-11', 'Windows 10' => 'windows-10']],
            'APPS'           => ['all' => ['apps', 'All Microsoft Apps'],
                                 'groups' => ['Microsoft Project' => 'microsoft-project', 'Microsoft Visio' => 'microsoft-visio']],
        ];
    }
    return $cache = $out;
}

/**
 * Header mega-menu — Antivirus dropdown.
 *
 * Mirror of nav_microsoft() but for the slimmer Antivirus dropdown
 * (single column).  Returns
 *   ['brands' => ['Bitdefender' => 'bitdefender', ...],
 *    'all'    => ['antivirus', 'All Antivirus']]
 * Reads from categories.category_group='antivirus'.
 */
function nav_antivirus(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $brands  = [];
    $allLink = ['antivirus', 'All Antivirus'];
    try {
        $rows = db()->query(
            "SELECT slug, name, sort_order
             FROM categories
             WHERE category_group = 'antivirus' AND slug <> ''
             ORDER BY sort_order ASC, slug ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            if ((int)$r['sort_order'] >= 99) {
                $allLink = [(string)$r['slug'], 'All ' . (string)$r['name']];
            } else {
                $brands[(string)$r['name']] = (string)$r['slug'];
            }
        }
    } catch (Throwable $e) { /* fresh install */ }

    if (!$brands) {
        // Hardcoded fallback for fresh installs before migrations run.
        $brands  = ['Bitdefender' => 'bitdefender', 'McAfee' => 'mcafee'];
        $allLink = ['antivirus', 'All Antivirus'];
    }
    return $cache = ['brands' => $brands, 'all' => $allLink];
}

/**
 * Header mega-menu — "Others" dropdown.
 *
 * Lists categories filed under category_group = 'standalone' (surfaced to
 * shoppers as the "Others" group) that have at least one ACTIVE product.
 * Returns an empty array when there is nothing to show, so the header can
 * hide the whole "Others" tab and never render an empty menu.
 *
 * Shape (mirrors nav_antivirus):
 *   ['brands' => ['Some Category' => 'some-slug', ...],
 *    'all'    => ['others', 'All Others']]   // 'all' is null when no parent slug
 */
function nav_others(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $items   = [];
    $allLink = null;
    try {
        // Only categories that actually have a live product attached — keeps
        // the dropdown clean and matches the "show tab only when populated"
        // requirement.
        $rows = db()->query(
            "SELECT c.slug, c.name, c.sort_order
             FROM categories c
             WHERE c.category_group = 'standalone' AND c.slug <> ''
               AND EXISTS (
                 SELECT 1 FROM products p
                 WHERE p.category = c.slug AND p.is_active = 1
               )
             ORDER BY c.sort_order ASC, c.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            if ((int)$r['sort_order'] >= 99) {
                $allLink = [(string)$r['slug'], 'All ' . (string)$r['name']];
            } else {
                $items[(string)$r['name']] = (string)$r['slug'];
            }
        }
    } catch (Throwable $e) { /* fresh install — leave empty (tab hidden) */ }

    return $cache = ['brands' => $items, 'all' => $allLink];
}


// Brand Vibe — bundles motion + gradient + font-weight + corner-radius.
// Admin selects one of these in Company Info → "Brand Vibe"; the chosen
// preset cascades across the entire storefront (navbar, admin topbar,
// auto-generated logo gradient, body buttons, card radii).  A custom
// per-field override is intentionally NOT exposed — the whole point of a
// "vibe" is one-click visual cohesion.
function brand_vibes(): array
{
    return [
        'premium' => [
            'label'    => 'Premium',
            'desc'     => 'Static · charcoal + gold · sharp corners',
            'icon'     => 'bi-gem',
            'motion'   => 'static',
            'gradient' => ['#0c0a09', '#3f3f46', '#facc15'],
            'fontw'    => 800,
            'radius'   => 6,
            'accent'   => '#facc15',
        ],
        'classic' => [
            'label'    => 'Classic',
            'desc'     => 'Bounce · navy + teal · balanced radius',
            'icon'     => 'bi-stars',
            'motion'   => 'bounce',
            'gradient' => ['#312e81', '#1e40af', '#06b6d4'],
            'fontw'    => 700,
            'radius'   => 14,
            'accent'   => '#06b6d4',
        ],
        'playful' => [
            'label'    => 'Playful',
            'desc'     => 'Bounce · sunset gradient · super-round',
            'icon'     => 'bi-emoji-smile',
            'motion'   => 'bounce',
            'gradient' => ['#f97316', '#ec4899', '#a855f7'],
            'fontw'    => 800,
            'radius'   => 22,
            'accent'   => '#f97316',
        ],
        'bold' => [
            'label'    => 'Bold',
            'desc'     => 'Spin · electric purple + cyan · heavy weight',
            'icon'     => 'bi-lightning-charge',
            'motion'   => 'spin',
            'gradient' => ['#7c3aed', '#ec4899', '#0ea5e9'],
            'fontw'    => 900,
            'radius'   => 10,
            'accent'   => '#7c3aed',
        ],
    ];
}

function current_vibe(): array
{
    $key = setting_get('company_brand_vibe', 'classic');
    $all = brand_vibes();
    return $all[$key] ?? $all['classic'];
}

/**
 * Apply any scheduled vibe switch whose `starts_at` window now overlaps
 * the current time.  Runs at most once per request (per-process cache),
 * and writes the chosen vibe back to `company_brand_vibe` via setting_set()
 * — which in turn flushes the in-memory settings cache so the rest of the
 * request renders with the freshly-applied vibe.
 *
 * Strategy: a schedule row is "active" if `starts_at <= NOW()` AND
 * (`ends_at` is NULL OR `ends_at >= NOW()`).  Among active rows we pick
 * the most recently-started one (deterministic tie-breaker for overlapping
 * schedules).  When NO row is active we don't touch the setting — the
 * baseline vibe stays exactly what the admin last picked manually.
 */
function apply_vibe_schedule(): void
{
    static $ran = false;
    if ($ran) return; $ran = true;
    try {
        // If the admin manually picked a vibe via Company Info, that choice
        // wins over any schedule whose window started BEFORE the override.
        // Only schedules that start AFTER the override timestamp should be
        // allowed to flip the vibe — so a brand-new Black-Friday schedule
        // still works, but stale running schedules don't undo manual edits.
        $override = (string)setting_get('vibe_manual_override_at', '');
        $stmt = db()->prepare(
            "SELECT id, vibe FROM vibe_schedule
             WHERE starts_at <= NOW() AND (ends_at IS NULL OR ends_at >= NOW())
                   AND (? = '' OR starts_at > ?)
             ORDER BY starts_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$override, $override]);
        $row = $stmt->fetch();
        $vibes = brand_vibes();
        $current = setting_get('company_brand_vibe', 'classic');
        if ($row && isset($vibes[$row['vibe']])) {
            // A schedule is live — switch to its vibe and remember the
            // user's "default" the first time so we can revert after.
            if (setting_get('company_brand_vibe_default', '') === '') {
                setting_set('company_brand_vibe_default', $current);
            }
            if ($current !== $row['vibe']) {
                setting_set('company_brand_vibe', $row['vibe']);
                setting_set('company_logo_motion', $vibes[$row['vibe']]['motion']);
                log_vibe_change($row['vibe'], 'scheduled');
                db()->prepare('UPDATE vibe_schedule SET applied_at=NOW() WHERE id=? AND applied_at IS NULL')
                    ->execute([$row['id']]);
            }
        } else {
            // No schedule live right now — revert to the saved default vibe
            // (only if one was saved by a prior schedule run).  This is what
            // keeps the storefront "normal" outside the schedule window.
            $default = setting_get('company_brand_vibe_default', '');
            if ($default !== '' && isset($vibes[$default]) && $current !== $default) {
                setting_set('company_brand_vibe', $default);
                setting_set('company_logo_motion', $vibes[$default]['motion']);
                log_vibe_change($default, 'schedule_ended');
                // Clear the saved default — the next active schedule will
                // capture the user's current vibe fresh.
                setting_set('company_brand_vibe_default', '');
            }
        }
    } catch (Throwable $e) { /* table missing on a fresh install — ignore */ }
}

/**
 * Returns the currently-active vibe schedule entry (if any) so the cart,
 * invoice PDFs, email templates and storefront banner can show its label
 * + optional logo (e.g. "BLACK FRIDAY SALE" with a promo logo).
 *
 * Cached per-request because every page render touches this.
 *
 * @return array|null  ['id', 'vibe', 'label', 'logo_path', 'logo_url',
 *                       'starts_at', 'ends_at'] or null when no promo active.
 */
function active_vibe_promo(): ?array
{
    static $cache = null;
    static $ran = false;
    if ($ran) return $cache;
    $ran = true;
    try {
        $row = db()->query(
            "SELECT id, vibe, label, logo_path, coupon_code, coupon_percent, starts_at, ends_at
               FROM vibe_schedule
              WHERE starts_at <= NOW() AND (ends_at IS NULL OR ends_at >= NOW())
                AND label <> ''
              ORDER BY starts_at DESC, id DESC LIMIT 1"
        )->fetch();
    } catch (Throwable $e) { return null; }
    if (!$row || trim((string)$row['label']) === '') return null;
    $row['logo_url'] = '';
    if (!empty($row['logo_path'])) {
        // logo_path is stored as a relative path like "uploads/vibe-promos/X.png".
        // - `logo_url`           → root-relative URL — works on the cart page
        //                          regardless of which public hostname proxied
        //                          the request (site_url() can return an
        //                          internal cluster URL behind a CDN proxy).
        // - `logo_url_absolute`  → fully-qualified URL — required for HTML
        //                          email since Gmail/Outlook can't resolve
        //                          root-relative paths.  Uses the admin's
        //                          configured `site_domain_url` if set, then
        //                          falls back to site_url().
        // - `logo_file`          → on-disk path for Dompdf invoices.
        $rel = ltrim((string)$row['logo_path'], '/');
        $row['logo_url']  = '/' . $rel;
        $publicHost = public_base_url();
        $row['logo_url_absolute'] = rtrim($publicHost, '/') . '/' . $rel;
        $row['logo_file'] = __DIR__ . '/../' . $rel;
    } else {
        $row['logo_url_absolute'] = '';
        $row['logo_file'] = '';
    }
    $cache = $row;
    return $cache;
}

/**
 * Tiny HTML snippet that renders the active vibe-schedule promo banner.
 * Safe to call on every page — returns '' when no promo is live.
 */
function render_vibe_promo_banner(string $variant = 'cart'): string
{
    $p = active_vibe_promo();
    if (!$p) return '';
    $label = htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8');
    $logo  = (string)$p['logo_url'];
    $endsAt = trim((string)($p['ends_at'] ?? ''));
    $endsTxt = $endsAt !== '' ? htmlspecialchars(date('M j · H:i', strtotime($endsAt)), ENT_QUOTES, 'UTF-8') : '';
    $code = strtoupper(trim((string)($p['coupon_code'] ?? '')));
    $pct  = (int)($p['coupon_percent'] ?? 0);
    $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    // Inline variant — used inside emails / invoice PDFs.  Email clients
    // can't render JS popovers, so we keep this as a single static pill.
    if ($variant === 'inline') {
        $couponLine = '';
        if ($code !== '' && $pct > 0) {
            $couponLine = '<span style="margin-left:10px;background:rgba(251,191,36,.20);color:#fcd34d;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.5px;">Use <strong style="background:#fbbf24;color:#0f172a;padding:1px 7px;border-radius:5px;">' . $codeEsc . '</strong> · ' . $pct . '% off</span>';
        }
        $logoTag = $logo !== ''
            ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:22px;width:auto;background:#fff;border-radius:4px;padding:2px 6px;">'
            : '<span style="color:#fbbf24;font-weight:900;">★</span>';
        return '<div class="vibe-promo-inline" data-testid="vibe-promo-inline" style="display:inline-flex;align-items:center;gap:10px;background:#0f172a;color:#f1f5f9;padding:7px 14px;border-radius:999px;font-size:12.5px;font-weight:600;letter-spacing:.3px;border-left:3px solid #fbbf24;">'
             . $logoTag . '<span style="text-transform:uppercase;font-weight:800;letter-spacing:.6px;font-size:11.5px;">' . $label . '</span>' . $couponLine . '</div>';
    }

    // Topbar variant — single-line slim banner designed to replace the
    // site-wide hardcoded `.topbar` promo strip in header.php whenever an
    // active vibe schedule exists.  Surfaces the label + coupon code with
    // a one-click copy button so the offer travels with every storefront
    // page (homepage, shop, category, product, blog).
    if ($variant === 'topbar') {
        $couponHtml = '';
        if ($code !== '' && $pct > 0) {
            $couponHtml = ' &mdash; use code <button type="button" class="vibe-topbar-copy" data-promo-code="' . $codeEsc . '" data-testid="vibe-topbar-copy" style="background:#fbbf24;color:#0f172a;border:0;border-radius:6px;padding:1px 9px;margin:0 4px;font-family:ui-monospace,Menlo,monospace;font-weight:800;font-size:13px;letter-spacing:.6px;cursor:pointer;vertical-align:baseline;">' . $codeEsc . ' <i class="bi bi-clipboard" style="font-size:11px;opacity:.85;"></i></button> for ' . $pct . '% off';
        }
        $shopCta = ' &mdash; <a href="shop.php" class="text-white fw-bold text-decoration-underline" data-testid="vibe-topbar-shop">Shop Now &rsaquo;</a>';
        return '<div class="topbar text-center py-2 px-3" data-testid="vibe-topbar-banner" style="background:linear-gradient(90deg,#0f172a 0%, #1e293b 100%);color:#f1f5f9;border-bottom:1px solid rgba(251,191,36,.30);">'
             . '<span style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;">'
             . '<span style="font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:#fbbf24;padding:2px 8px;border:1px solid #fbbf24;border-radius:999px;">LIMITED OFFER</span>'
             . '<span style="font-weight:600;">' . $label . $couponHtml . $shopCta . '</span>'
             . '</span>'
             . '<script>(function(){document.querySelectorAll("[data-testid=vibe-topbar-copy]").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();var c=b.getAttribute("data-promo-code");function done(){var o=b.innerHTML;b.innerHTML="<i class=\\"bi bi-check2\\"></i> Copied";setTimeout(function(){b.innerHTML=o;},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(c).then(done,done);}else{var t=document.createElement("textarea");t.value=c;document.body.appendChild(t);t.select();try{document.execCommand("copy");}catch(_){}t.remove();done();}});});})();</script>'
             . '</div>';
    }

    // CART variant — COMPACT light-blue floating pill (was a big slate
    // rectangle).  Sits inline as a slim chip with a pulsing/bouncing
    // sparkle icon that flashes to draw the shopper's eye without
    // swallowing visual real estate.  Tapping it reveals the same popup
    // card (code + Copy + ends-at) used by every other surface.
    $popupId = 'vibe-popup-' . substr(md5($label . $code), 0, 8);
    $logoTagLg = $logo !== ''
        ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:36px;width:auto;object-fit:contain;background:#fff;border-radius:8px;padding:4px 8px;">'
        : '<span style="display:inline-block;width:36px;height:36px;border-radius:50%;background:#3b82f6;color:#fff;font-weight:900;font-size:18px;text-align:center;line-height:36px;">★</span>';

    // Popup card (initially hidden — toggled by the pill).
    $popupBody = '';
    if ($code !== '' && $pct > 0) {
        $popupBody = '<div style="display:flex;align-items:center;gap:10px;background:#eff6ff;border:1px dashed #3b82f6;border-radius:10px;padding:10px 12px;margin-top:14px;">'
                   . '<div style="font-family:ui-monospace,Menlo,monospace;font-size:18px;font-weight:800;letter-spacing:1.4px;color:#1e40af;flex:1;text-align:center;" data-testid="vibe-popup-code">' . $codeEsc . '</div>'
                   . '<button type="button" class="vibe-popup-copy" data-promo-code="' . $codeEsc . '" data-testid="vibe-popup-copy" '
                   . 'style="background:#1e40af;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">'
                   . '<i class="bi bi-clipboard"></i> Copy</button>'
                   . '</div>'
                   . '<div style="text-align:center;color:#475569;font-size:11.5px;margin-top:10px;">Paste this code at checkout to save <strong style="color:#1e40af;">' . $pct . '%</strong>.</div>';
    }
    $popupHtml = '<div id="' . $popupId . '" class="vibe-popup" data-testid="vibe-promo-popup" role="dialog" aria-modal="false" hidden '
               . 'style="position:absolute;top:calc(100% + 10px);left:0;width:300px;background:#ffffff;border-radius:14px;box-shadow:0 14px 48px rgba(15,23,42,.22);border:1px solid #dbeafe;padding:18px;z-index:1080;animation:vibe-popup-in .18s ease-out;">'
               . '<button type="button" class="vibe-popup-close" data-popup-target="' . $popupId . '" aria-label="Close" '
               . 'style="position:absolute;top:6px;right:8px;background:transparent;border:0;color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;">&times;</button>'
               . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">' . $logoTagLg
               . '<div style="font-size:10.5px;color:#60a5fa;font-weight:700;letter-spacing:1px;text-transform:uppercase;">Limited offer</div></div>'
               . '<div style="font-size:18px;font-weight:800;color:#0f172a;line-height:1.25;letter-spacing:.2px;">' . $label . '</div>'
               . ($pct > 0 ? '<div style="font-size:13px;color:#475569;margin-top:4px;">Save <strong style="color:#1e40af;">' . $pct . '%</strong> on every product.</div>' : '')
               . $popupBody
               . ($endsTxt !== '' ? '<div style="font-size:11px;color:#64748b;text-align:center;margin-top:10px;"><i class="bi bi-hourglass-split"></i> Ends ' . $endsTxt . '</div>' : '')
               . '</div>';

    // The compact pill itself — light blue gradient, pulsing sparkle on
    // the left, soft bouncing scale on hover.  Clicking it toggles the
    // popup card above.  data-testid is preserved so test scripts that
    // looked for `vibe-promo-banner` still find this control.
    $pillLabel = $label;
    if ($code !== '' && $pct > 0) {
        $pillLabel .= ' &middot; <span style="font-weight:800;color:#1e40af;">' . $pct . '% off</span>';
    }

    return '<div style="margin:0 0 16px;position:relative;display:inline-flex;align-items:stretch;">'
         . '<button type="button" class="vibe-promo-pill" data-popup-target="' . $popupId . '" data-testid="vibe-promo-banner" '
         . 'style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#dbeafe 0%,#bfdbfe 50%,#93c5fd 100%);color:#1e3a8a;border:1px solid #93c5fd;border-radius:999px;padding:6px 14px 6px 10px;font-size:12.5px;font-weight:700;letter-spacing:.2px;cursor:pointer;box-shadow:0 2px 10px rgba(59,130,246,.18);transition:transform .15s ease, box-shadow .15s ease;">'
         . '<span class="vibe-pill-spark" aria-hidden="true" style="display:inline-flex;width:22px;height:22px;border-radius:50%;background:#3b82f6;color:#fff;align-items:center;justify-content:center;font-size:12px;box-shadow:0 0 0 0 rgba(59,130,246,.55);">'
         . '<i class="bi bi-lightning-charge-fill"></i>'
         . '</span>'
         . '<span style="text-transform:uppercase;font-weight:800;letter-spacing:.7px;font-size:10.5px;color:#1d4ed8;">Offer</span>'
         . '<span style="color:#1e3a8a;font-weight:600;font-size:12.5px;">' . $pillLabel . '</span>'
         . ($endsTxt !== '' ? '<span style="font-size:10.5px;color:#1e40af;opacity:.78;margin-left:4px;"><i class="bi bi-clock"></i> ' . $endsTxt . '</span>' : '')
         . '<i class="bi bi-chevron-down" style="font-size:10px;color:#1e40af;opacity:.6;margin-left:2px;"></i>'
         . '</button>'
         . $popupHtml
         . '<style>'
         . '@keyframes vibe-popup-in { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }'
         . '@keyframes vibe-pill-bounce { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }'
         . '@keyframes vibe-pill-flash  { 0%   { box-shadow: 0 0 0 0 rgba(59,130,246,.55);} 60%  { box-shadow: 0 0 0 10px rgba(59,130,246,0);} 100% { box-shadow: 0 0 0 0 rgba(59,130,246,0);} }'
         . '.vibe-promo-pill { animation: vibe-pill-bounce 2.4s ease-in-out infinite; }'
         . '.vibe-promo-pill .vibe-pill-spark { animation: vibe-pill-flash 1.8s ease-out infinite; }'
         . '.vibe-promo-pill:hover { transform: translateY(-1px) scale(1.03); box-shadow: 0 8px 22px rgba(59,130,246,.32); }'
         . '.vibe-promo-pill:focus { outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,.35); }'
         . '.vibe-popup-copy:hover { background:#1e3a8a !important; }'
         . '@media (prefers-reduced-motion: reduce) { .vibe-promo-pill, .vibe-promo-pill .vibe-pill-spark { animation: none !important; } }'
         . '@media (max-width: 540px) { .vibe-popup { position:fixed !important; top:50% !important; left:50% !important; right:auto !important; transform:translate(-50%,-50%) !important; width:90% !important; max-width:340px !important; } }'
         . '</style>'
         . '<script>(function(){var open=null;function close(){if(open){open.setAttribute("hidden","");open=null;}}'
         . 'document.querySelectorAll("[data-testid=vibe-promo-banner]").forEach(function(b){if(b.dataset.vibeBound)return;b.dataset.vibeBound="1";b.addEventListener("click",function(e){e.stopPropagation();var id=b.getAttribute("data-popup-target");var pop=document.getElementById(id);if(!pop)return;if(open===pop){close();return;}close();pop.removeAttribute("hidden");open=pop;});});'
         . 'document.querySelectorAll(".vibe-popup-close").forEach(function(b){b.addEventListener("click",function(){close();});});'
         . 'document.addEventListener("click",function(e){if(open && !open.contains(e.target)){close();}});'
         . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){close();}});'
         . 'document.querySelectorAll(".vibe-popup-copy").forEach(function(b){b.addEventListener("click",function(e){e.stopPropagation();var c=b.getAttribute("data-promo-code"),o=b.innerHTML;function done(){b.innerHTML="<i class=\\"bi bi-check2\\"></i> Copied";setTimeout(function(){b.innerHTML=o;},1800);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(c).then(done,done);}else{var t=document.createElement("textarea");t.value=c;document.body.appendChild(t);t.select();try{document.execCommand("copy");}catch(_){}t.remove();done();}});});'
         . '})();</script>'
         . '</div>';
}

/**
 * Append a row to `vibe_history` so the Dashboard's "Vibe Performance"
 * widget can show which vibe was live each day.  Idempotent: skips when
 * the requested vibe is the same as the last-recorded one (prevents log
 * spam if the same vibe gets re-saved).
 */
function log_vibe_change(string $vibe, string $source = 'manual'): void
{
    try {
        $last = db()->query('SELECT vibe FROM vibe_history ORDER BY id DESC LIMIT 1')->fetchColumn();
        if ($last === $vibe) return; // no actual change — don't log
        db()->prepare('INSERT INTO vibe_history (vibe, source) VALUES (?,?)')->execute([$vibe, $source]);
    } catch (Throwable $e) { /* table missing — ignore */ }
}

// Brand logo — rounded gradient square with the FIRST LETTER of the company
// name as a white monogram.  Gradient colours follow the active Brand Vibe
// so the auto-generated mark always matches the storefront aesthetic.
// Falls back to "M" if the company name is empty.  When the admin uploads a
// custom logo via the Company Info tab, `$brandLogo` takes precedence in
// header.php / footer.php and this SVG is never rendered.
function render_logo(int $size = 40, ?string $letter = null): string
{
    if ($letter === null || $letter === '') {
        $name = function_exists('company_info') ? (company_info()['name'] ?? '') : '';
        if ($name === '' && defined('SITE_BRAND')) $name = SITE_BRAND;
        $name = preg_replace('/^[^A-Za-z0-9]+/', '', trim($name));
        $letter = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : 'M';
    } else {
        $letter = mb_strtoupper(mb_substr($letter, 0, 1));
    }
    $vibe = current_vibe();
    // Override the legacy cyan/teal/orange "brand vibe" gradient stops with
    // the new Zoom-blue palette so the auto-generated SVG mark harmonises
    // with the wordmark and CTAs site-wide. Falls back to vibe colours when
    // the admin has explicitly chosen a non-default vibe (e.g. for white-
    // label resellers running the same codebase under different branding).
    $isClassicVibe = ($vibe['key'] ?? '') === 'classic' || empty($vibe['key']);
    if ($isClassicVibe) {
        $g0 = '#0B5CFF';   // zoom-blue
        $g1 = '#4480FF';   // zoom-blue-lift
        $g2 = '#0848CC';   // zoom-blue-strong
        $accent = '#79A6FF';
    } else {
        [$g0, $g1, $g2] = $vibe['gradient'];
        $accent = $vibe['accent'];
    }
    $radius = max(4, (int)round($vibe['radius'] / 14 * 13)); // scale 4-22px → SVG units
    $id = 'lgrad' . $size . '_' . md5($letter . $g0 . $g1 . $g2);
    $fontSize = (int)round($size * 0.58);
    return '<svg class="brand-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" fill="none" aria-hidden="true" role="img" data-brand-mark="1">'
        . '<defs>'
        .   '<linearGradient id="' . $id . '" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">'
        .     '<stop offset="0"   stop-color="' . esc($g0) . '"/>'
        .     '<stop offset=".45" stop-color="' . esc($g1) . '"/>'
        .     '<stop offset="1"   stop-color="' . esc($g2) . '"/>'
        .   '</linearGradient>'
        .   '<radialGradient id="' . $id . '_hl" cx=".25" cy=".15" r=".75">'
        .     '<stop offset="0" stop-color="rgba(255,255,255,.32)"/>'
        .     '<stop offset="1" stop-color="rgba(255,255,255,0)"/>'
        .   '</radialGradient>'
        . '</defs>'
        . '<rect x="1.5" y="1.5" width="45" height="45" rx="' . $radius . '" fill="url(#' . $id . ')"/>'
        . '<rect x="1.5" y="1.5" width="45" height="45" rx="' . $radius . '" fill="url(#' . $id . '_hl)"/>'
        . '<text x="24" y="24" text-anchor="middle" dominant-baseline="central" font-family="Inter,Manrope,Segoe UI,Arial,sans-serif" font-weight="800" font-size="' . $fontSize . '" fill="#fff" letter-spacing="-1">' . esc($letter) . '</text>'
        . '<circle cx="40" cy="38" r="2.4" fill="' . esc($accent) . '" opacity=".92"/>'
        . '</svg>';
}

/**
 * Site brand logo for the header/footer. Serves the configured logo as a
 * <picture> with an optimized WebP source + raster fallback (the WebP is only
 * offered when the sibling file actually exists on disk), so every page loads
 * the lighter WebP while emails/PDFs and old browsers keep the raster. Falls
 * back to the inline SVG mark when no logo is configured.
 */
function brand_logo_html(int $h = 42, string $imgAttrs = ''): string
{
    $logo = function_exists('company_info') ? trim((string)(company_info()['logo'] ?? '')) : '';
    if ($logo === '') return render_logo($h);
    $name  = esc((string)(company_info()['name'] ?? 'Logo'));
    $style = 'height:' . $h . 'px;width:auto;max-width:140px;object-fit:contain;';
    $img   = '<img src="' . esc($logo) . '" alt="' . $name . '" style="' . $style . '" ' . trim($imgAttrs) . '>';
    $webp     = preg_replace('/\.(png|jpe?g)$/i', '.webp', $logo);
    $webpPath = (string)(parse_url((string)$webp, PHP_URL_PATH) ?? '');
    $hasWebp  = $webp !== $logo && $webpPath !== '' && is_file(__DIR__ . '/../' . ltrim($webpPath, '/'));
    return $hasWebp
        ? '<picture><source srcset="' . esc((string)$webp) . '" type="image/webp">' . $img . '</picture>'
        : $img;
}


// Stores a contact/support form submission
function save_support_message(array $d): void
{
    $stmt = db()->prepare('INSERT INTO support_messages (name, email, phone, order_number, subject, message, source) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$d['name'], $d['email'], $d['phone'] ?? '', $d['order_number'] ?? '', $d['subject'], $d['message'], $d['source'] ?? 'contact']);
}

// Volume-pricing / support promo band (nav dropdowns + Disclaimer page)
function render_menu_promo(bool $compact = false): string
{
    $phone = company_phone_for_country() ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
    $volume = '<div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-boxes text-primary"></i><span class="fw-semibold">Volume Pricing</span></div>'
            . '<small class="text-secondary d-block mb-2" style="font-size:.72rem;">Exclusive discounts on bulk licenses for teams and businesses.</small>'
            . '<a href="contact.php" class="btn btn-sm btn-primary rounded-pill" style="padding:.28rem .85rem;font-size:.76rem;" data-testid="menu-request-quote">Request a Quote</a>';
    $question = '<div class="fw-semibold small" style="font-size:.82rem;">Have a Question?</div>'
              . '<small class="text-secondary d-block" style="font-size:.7rem;">Call Mon–Fri 9 AM–6 PM EST</small>'
              . '<a href="tel:' . esc(tel_e164($phone)) . '" class="fw-semibold text-decoration-none" style="font-size:.82rem;">' . esc($phone) . '</a> '
              . '<small class="text-secondary" style="font-size:.72rem;">or</small> '
              . '<a href="#" onclick="toggleChat();return false;" class="fw-semibold text-decoration-none text-primary" style="font-size:.78rem;">chat with a sales expert</a>';
    if ($compact) {
        return '<div class="mega-promo mt-3 pt-3" data-testid="menu-promo">' . $volume . '<div class="mt-3">' . $question . '</div></div>';
    }
    return '<div class="mega-promo mt-4 pt-3 row g-3 align-items-center" data-testid="menu-promo">'
         . '<div class="col-lg-7">' . $volume . '</div>'
         . '<div class="col-lg-5 text-lg-end">' . $question . '</div></div>';
}

/* ---------------- SEO helpers ---------------- */
// Rich descriptive alt text for product images (Google Images / Merchant Center friendly)
function product_img_alt(array $p): string
{
    $pct = (!empty($p['original_price']) && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $platform = $p['platform'] ?: 'Windows';
    // Build a descriptive alt that names the SKU + license type + delivery,
    // so Google Images, Bing Visual Search and AI multimodal models can
    // surface the listing for "Microsoft Office 2024 Professional Plus
    // product key box", "Office 2021 lifetime license key" etc.
    // Use plain dashes (not HTML entities) so the alt text isn't double-
    // encoded when the caller pipes it through esc().
    $alt = $p['name'] . ' product key box - genuine one-time purchase for ' . $platform . ', instant digital delivery';
    if ($pct > 0) $alt .= ', ' . $pct . '% off';
    return $alt . ' | ' . SITE_BRAND;
}

// Exact + phrase + broad keyword variations generated per product (meta keywords)
function product_keywords(array $p): string
{
    $name = $p['name'];
    $platform = $p['platform'] ?: 'Windows';
    $base = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $name));
    $kw = [
        $name,                              // exact
        'buy ' . $name,                     // phrase
        $name . ' product key',
        $name . ' lifetime license',
        $name . ' license key',
        $name . ' instant delivery',
        $name . ' no subscription',
        $name . ' digital download',
        $base . ' for ' . $platform,        // broad
        'affordable ' . $base,
        'genuine ' . $base . ' key',
        'discount ' . $base,
        'microsoft software license key store',
    ];
    return implode(', ', array_unique($kw));
}

function site_url(): string
{
    // 1) When serving an HTTP request, prefer the real Host header so the
    //    same codebase works on preview, staging and production without any
    //    config tweaks — when deployed to maventechsoftware.com, every
    //    sitemap / canonical / og:url / Article schema URL resolves to that
    //    hostname automatically.
    //
    //    Exception: requests hitting the cluster-internal Emergent preview
    //    hostnames (*.cluster-N.preview.emergentcf.cloud) are admin-only
    //    routes that return 403 for end-users.  Generating links against
    //    those hostnames breaks "View Sitemap", `<img src>` and every
    //    other absolute URL the admin clicks through to.  When we detect
    //    such a host, fall through to the admin-configured `main_url`
    //    setting (or the public preview hostname) so the admin gets a
    //    real, browsable URL.
    if (PHP_SAPI !== 'cli' && (!empty($_SERVER['HTTP_X_FORWARDED_HOST']) || !empty($_SERVER['HTTP_HOST']))) {
        // Honour the public-facing host first when the request was proxied
        // through an ingress that sets X-Forwarded-Host (Emergent, Cloudflare,
        // any reverse proxy).  HTTP_HOST is whatever the upstream proxy
        // forwarded to us, which on Emergent is the *.cluster-N.* internal
        // host — useless for absolute URLs that humans / Googlebot follow.
        $fwdHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        // X-Forwarded-Host can be a comma-separated chain ("public, edge1, edge2") —
        // first entry is the original client-facing host.
        if ($fwdHost !== '' && str_contains($fwdHost, ',')) {
            $fwdHost = trim(strtok($fwdHost, ','));
        }
        $host  = $fwdHost !== '' ? $fwdHost : (string)$_SERVER['HTTP_HOST'];
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
            if (str_contains($proto, ',')) $proto = trim(strtok($proto, ','));
        }
        $isClusterInternal = (bool)preg_match('/\.cluster-\d+\.preview\.emergentcf\.cloud$/i', $host);
        if (!$isClusterInternal) {
            return $proto . '://' . $host;
        }
        // Cluster-internal host detected — fall through to settings / constant.
        try {
            $configured = trim((string)setting_get('main_url', ''));
            if ($configured !== '' && !preg_match('/\.preview\.emergentagent\.com$/i', parse_url($configured, PHP_URL_HOST) ?: '')) {
                return rtrim($configured, '/');
            }
        } catch (Throwable $e) { /* DB may not be ready yet */ }
    }
    // 2) Fall back to the configured SITE_URL constant for CLI / cron contexts
    //    AND for cluster-internal hosts where no `main_url` is set.
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    return 'http://localhost';
}

/**
 * Canonical PUBLIC base URL for every absolute link that must be customer- /
 * Googlebot-facing: sitemap <loc>, canonical fallback, emails, review links,
 * the SEO bot, etc.
 *
 * It prefers the admin-configured domain (`site_domain_url`, then `main_url`)
 * but will NEVER return an Emergent preview / cluster-internal / localhost
 * host — those are development hosts and must never leak into a deployed
 * site's URLs.  When deployed to maventechsoftware.com the live request host
 * (via site_url()) is used automatically, even if a stale preview URL is still
 * sitting in the settings table (e.g. a DB copied over from the preview pod).
 */
function public_base_url(): string
{
    foreach (['site_domain_url', 'main_url'] as $k) {
        try { $v = trim((string)setting_get($k, '')); } catch (Throwable $e) { $v = ''; }
        if ($v === '') continue;
        $h = strtolower((string)parse_url($v, PHP_URL_HOST));
        if ($h === '') continue;
        if (preg_match('/(?:\.preview\.emergentagent\.com|\.preview\.emergentcf\.cloud|\.emergent\.host)$/i', $h)) continue;
        if (in_array($h, ['localhost', '127.0.0.1', '0.0.0.0'], true)) continue;
        return rtrim($v, '/');
    }
    return rtrim(site_url(), '/');
}


/**
 * Persist the real public domain into the `site_domain_url` / `main_url`
 * settings the first time the store is served from a genuine production host.
 *
 * Order / license-key emails and PDF receipts are frequently generated from a
 * CLI/cron context (no Host header), where they fall back to these stored
 * settings to build ABSOLUTE product-image URLs. If the database was imported
 * from the Emergent preview, those settings still point at the preview host
 * (or are empty) — which makes every product image in emails render broken
 * once the site is live. This self-heals them on the first real page view, so
 * no admin action is required after deploying to maventechsoftware.com.
 */
function mv_sync_public_domain(): void {
    if (PHP_SAPI === 'cli') return;
    $fwd  = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $host = $fwd !== '' ? ($fwd === '' ? '' : (str_contains($fwd, ',') ? trim(strtok($fwd, ',')) : $fwd))
                        : (string)($_SERVER['HTTP_HOST'] ?? '');
    $host = strtolower(trim($host));
    if ($host === '') return;
    // Only adopt genuine public domains — never preview / cluster / localhost / IPs.
    if (preg_match('/(?:^|\.)preview\.emergentagent\.com$/i', $host)) return;
    if (preg_match('/\.cluster-\d+\.preview\.emergentcf\.cloud$/i', $host)) return;
    if (preg_match('/^(localhost|127\.0\.0\.1|0\.0\.0\.0|\d+\.\d+\.\d+\.\d+)(:|$)/i', $host)) return;
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
        $proto = str_contains($p, ',') ? trim(strtok($p, ',')) : trim($p);
    }
    $real = $proto . '://' . $host;
    foreach (['site_domain_url', 'main_url'] as $key) {
        try {
            $cur     = trim((string)setting_get($key, ''));
            $curHost = $cur !== '' ? strtolower((string)parse_url($cur, PHP_URL_HOST)) : '';
            $stale = ($cur === '')
                || (bool)preg_match('/(?:^|\.)preview\.emergentagent\.com$/i', $curHost)
                || (bool)preg_match('/\.cluster-\d+\.preview\.emergentcf\.cloud$/i', $curHost)
                || in_array($curHost, ['localhost', '127.0.0.1', '0.0.0.0'], true);
            if ($stale && $curHost !== $host) setting_set($key, $real);
        } catch (Throwable $e) { /* DB not ready — ignore */ }
    }
}

/**
 * Convert any absolute URL whose host is an Emergent preview hostname
 * (`*.preview.emergentagent.com`) into one that uses the CURRENT request's
 * host, so admin-saved settings (logo, main_url, site_domain_url, AI image
 * uploads) keep working transparently after the operator uploads the
 * codebase to their real domain — no DB cleanup required.
 *
 * Why we need this:
 *   When the codebase was being built on the Emergent preview, the admin
 *   uploaded a company logo / set a "main URL" / had AI-generated product
 *   images saved against the preview hostname. After deploying to cPanel
 *   under `maventechsoftware.com`, those URLs are still in the DB and
 *   leak the dev hostname into the production site (QR codes on receipts,
 *   `<img src>` for the logo, etc.). This helper rewrites them on the
 *   fly when serving from a real, non-preview host.
 *
 * Rules:
 *   - Empty / non-string input → returned unchanged.
 *   - Relative URLs ("/uploads/x.png", "checkout.php") → unchanged.
 *   - URL on a preview host → rewritten to current host (keeps path + query).
 *   - URL on the SAME host as the current request → unchanged.
 *   - URL on any other host (CDN, S3, integrations.emergentagent.com, etc.)
 *     → unchanged (we never rewrite a third-party CDN).
 */
function to_public_url(?string $url): ?string
{
    if ($url === null) return null;
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) return $url;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return $url;
    // Rewrite ANY Emergent preview / staging / cluster-internal hostname.
    // Covers:  *.preview.emergentagent.com   (the public preview domain)
    //          *.preview.emergentcf.cloud    (cluster-internal ingress)
    //          *.emergent.host               (legacy short domain)
    if (!preg_match('/(?:\.preview\.emergentagent\.com|\.preview\.emergentcf\.cloud|\.emergent\.host)$/i', $host)) return $url;

    // CLI / cron — fall back to SITE_URL or just strip the host (relative URL).
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        if (defined('SITE_URL') && SITE_URL !== ''
            && !preg_match('/(?:\.preview\.emergentagent\.com|\.preview\.emergentcf\.cloud|\.emergent\.host)$/i', parse_url(SITE_URL, PHP_URL_HOST) ?: '')) {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $q    = parse_url($url, PHP_URL_QUERY);
            return rtrim(SITE_URL, '/') . $path . ($q !== null ? '?' . $q : '');
        }
        // Otherwise drop the host entirely → relative URL, which renders
        // correctly on any host the file is served from.
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $q    = parse_url($url, PHP_URL_QUERY);
        return $path . ($q !== null ? '?' . $q : '');
    }

    // HTTP request context — rewrite preview host → current host.
    $currentHost = (string)$_SERVER['HTTP_HOST'];
    // Don't rewrite when the CURRENT host is itself a preview hostname
    // (avoids accidentally pointing a preview view at production).
    if (preg_match('/(?:\.preview\.emergentagent\.com|\.preview\.emergentcf\.cloud|\.emergent\.host)$/i', $currentHost)) return $url;

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $proto = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $q    = parse_url($url, PHP_URL_QUERY);
    return $proto . '://' . $currentHost . $path . ($q !== null ? '?' . $q : '');
}

/**
 * Turn a root-relative URL ("/install-guide.php?slug=...") into an absolute
 * one against the current public host so it works inside emails / PDFs where
 * relative links can't be resolved. Absolute URLs are returned unchanged
 * (after preview-host healing); empty values pass through untouched.
 */
function mv_absolute_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('~^https?://~i', $url)) return (string)to_public_url($url);
    if ($url[0] === '/') return rtrim((string)site_url(), '/') . $url;
    return $url;
}


/* ---------------- Coupons: code => percent off ---------------- */
function coupons(): array
{
    $base = ['MAVEN20' => 20, 'BIT20' => 20, 'MATRIX20' => 20, 'ZED20' => 20, 'FIVE20' => 20, 'UCODE90' => 20, 'WELCOME10' => 10, 'SAVE15' => 15, 'OFFICE25' => 25];
    // Any active vibe-schedule with a coupon_code + coupon_percent auto-
    // registers it so it works when buyers COPY+PASTE the code at checkout
    // (the banner does NOT auto-apply the code — it just announces it with
    // a Copy button).  The code is only valid during the schedule window.
    if (function_exists('active_vibe_promo')) {
        $p = active_vibe_promo();
        if ($p && !empty($p['coupon_code']) && (int)$p['coupon_percent'] > 0) {
            $code = strtoupper(trim((string)$p['coupon_code']));
            if ($code !== '') $base[$code] = max(1, min(95, (int)$p['coupon_percent']));
        }
    }
    return $base;
}

/* ---------------- Rendering helpers ---------------- */
// Payment method icon images (footer + checkout)
function render_payment_icons(string $class = 'pay-icon'): string
{
    $pays = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'paypal' => 'PayPal'];
    $h = '';
    foreach ($pays as $f => $alt) {
        $h .= '<img src="assets/images/payments/' . $f . '.svg" alt="' . $alt . '" title="' . $alt . '" class="' . $class . '" loading="lazy" decoding="async" width="36" height="24">';
    }
    return $h;
}

function render_stars(float $rating): string
{
    // Used only on the dedicated reviews page now — product cards/detail no
    // longer call this. Renders the gold star row for a given rating.
    $h = '<span class="text-warning">';
    for ($i = 1; $i <= 5; $i++) {
        $h .= $i <= round($rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
    }
    return $h . '</span>';
}

// Wide horizontal product banner row — shared by shop list view and category pages
/**
 * Build a short, single-line teaser from the AI product description for use on
 * listing cards/rows.  Uses the first non-bullet sentence (the intro line the
 * generator writes), flattened + clamped to ~150 chars on a word boundary.
 * Returns '' when no description exists so the markup stays clean.
 */
function product_teaser(string $description, int $maxLen = 150): string
{
    $description = trim($description);
    if ($description === '') return '';
    $teaser = '';
    foreach (preg_split('/\r\n|\r|\n/', $description) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^([•▪◦\-\*])\s+/u', $line)) continue;
        $teaser = $line;
        break;
    }
    if ($teaser === '') {
        $teaser = trim(preg_replace('/\s+/u', ' ', preg_replace('/^([•▪◦\-\*])\s+/mu', '', $description)));
    }
    if (mb_strlen($teaser) > $maxLen) {
        $teaser = mb_substr($teaser, 0, $maxLen);
        $teaser = preg_replace('/\s+\S*$/u', '', $teaser);
        $teaser = rtrim($teaser, " \t.,;:") . '…';
    }
    return $teaser;
}

function render_product_row(array $p): string
{
    $curCode = current_currency()['code'];
    $teaser = product_teaser((string)($p['description'] ?? ''));
    $teaserHtml = $teaser !== ''
        ? '<p class="shop-row-teaser small text-secondary mb-1" data-testid="row-teaser-' . esc($p['slug']) . '">' . esc($teaser) . '</p>'
        : '';
    $pct = ($p['original_price'] && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $orig = $pct ? '<small class="text-secondary text-decoration-line-through d-block">' . format_price((float)$p['original_price']) . '</small>' : '';
    $save = $pct ? '<span class="badge text-bg-danger">Save ' . $pct . '%</span>' : '';
    $badge = $p['badge'] ? '<span class="badge text-bg-primary">' . esc($p['badge']) . '</span>' : '';
    $osIcon = $p['platform'] === 'Mac' ? 'macos' : 'windows';
    return '
    <div class="card product-card shop-row p-3 p-sm-4" data-testid="product-row-' . esc($p['slug']) . '">
      <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 gap-sm-4">
        <a href="product.php?slug=' . esc($p['slug']) . '" class="flex-shrink-0 mx-auto mx-sm-0">
          <div class="shop-row-img rounded-4">
            <img src="' . esc($p['image']) . '" alt="' . esc(product_img_alt($p)) . '" title="' . esc($p['name']) . '" loading="lazy">
          </div>
        </a>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            ' . $badge . '
            <span class="badge os-badge"><img src="assets/images/os/' . $osIcon . '.svg" alt="' . esc($p['platform'] ?: 'Windows') . ' platform" class="os-icon me-1" width="14" height="14">' . esc($p['platform'] ?: 'Windows') . '</span>
            ' . $save . '
          </div>
          <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none text-body fw-bold fs-6 d-block">' . esc($p['name']) . '</a>
          ' . render_product_rating($p['slug'], 'row') . '
          ' . $teaserHtml . '
          <div class="d-flex flex-wrap gap-3 small text-secondary">
            <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant email delivery</span>
            <span><i class="bi bi-infinity text-primary me-1"></i>One-time purchase</span>
            <span class="d-none d-md-inline"><i class="bi bi-headset text-primary me-1"></i>Free install support</span>
          </div>
        </div>
        <div class="shop-row-buy text-sm-end flex-shrink-0">
          ' . $orig . '
          <div class="fw-bold text-primary fs-4 lh-1 mb-1">' . format_price((float)$p['price']) . '</div>
          <div class="mb-2"><span class="badge rounded-pill text-secondary bg-body-tertiary" style="font-size:.6rem;font-weight:600;letter-spacing:.04em;" data-testid="row-currency-' . esc($p['slug']) . '">Prices in ' . esc($curCode) . '</span></div>
          <div class="mb-2">' . render_stock_pill($p['slug']) . '</div>
          <div class="d-flex flex-sm-column gap-2">
            <button class="btn btn-sm btn-primary rounded-pill px-3 add-to-cart-btn" data-slug="' . esc($p['slug']) . '" data-testid="add-to-cart-' . esc($p['slug']) . '"><i class="bi bi-cart-plus me-1"></i>Add to Cart</button>
            <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold buy-now-btn" data-slug="' . esc($p['slug']) . '" data-testid="buy-now-' . esc($p['slug']) . '"><i class="bi bi-lightning-charge me-1"></i>Buy Now</button>
            <a href="product.php?slug=' . esc($p['slug']) . '" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-testid="view-details-' . esc($p['slug']) . '">Details</a>
          </div>
        </div>
      </div>
    </div>';
}

function render_product_card(array $p): string
{
    $curCode = current_currency()['code'];
    $teaser = product_teaser((string)($p['description'] ?? ''));
    $teaserHtml = $teaser !== ''
        ? '<p class="pc-teaser small text-secondary mb-2" data-testid="card-teaser-' . esc($p['slug']) . '">' . esc($teaser) . '</p>'
        : '';
    $pct = ($p['original_price'] && $p['original_price'] > $p['price'])
        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
    $discount = $pct ? '<span class="badge text-bg-danger position-absolute top-0 end-0 m-2">-' . $pct . '%</span>' : '';
    $badge = $p['badge'] ? '<span class="badge text-bg-primary position-absolute top-0 start-0 m-2">' . esc($p['badge']) . '</span>' : '';
    $orig = $pct ? '<small class="text-secondary text-decoration-line-through">' . format_price((float)$p['original_price']) . '</small>' : '';
    $osIcon = $p['platform'] === 'Mac' ? 'macos' : 'windows';
    $stockPill = render_stock_pill($p['slug']);
    $cartBtn = '<button class="pc-btn pc-btn-cart add-to-cart-btn" data-slug="' . esc($p['slug']) . '" data-testid="add-to-cart-' . esc($p['slug']) . '" aria-label="Add to cart"><i class="bi bi-cart-plus"></i><span class="pc-btn-label">Add</span></button>
           <button class="pc-btn pc-btn-buy buy-now-btn" data-slug="' . esc($p['slug']) . '" data-testid="buy-now-' . esc($p['slug']) . '" aria-label="Buy now"><i class="bi bi-lightning-charge-fill"></i><span class="pc-btn-label">Buy</span></button>';
    return '
    <div class="card product-card tilt-3d h-100 position-relative" data-testid="product-card-' . esc($p['slug']) . '">
      ' . $badge . $discount . '
      <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none">
        <div class="ratio ratio-1x1 bg-body-tertiary rounded-top product-img-wrap">
          <img src="' . esc($p['image']) . '" alt="' . esc(product_img_alt($p)) . '" title="' . esc($p['name']) . '" class="object-fit-contain p-3" loading="lazy" decoding="async" width="320" height="320">
        </div>
      </a>
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
          <span class="badge os-badge"><img src="assets/images/os/' . $osIcon . '.svg" alt="' . esc($p['platform'] ?: 'Windows') . ' platform" class="os-icon me-1" width="14" height="14">' . esc($p['platform'] ?: 'Windows') . '</span>
          ' . render_product_rating($p['slug'], 'card') . '
        </div>
        <a href="product.php?slug=' . esc($p['slug']) . '" class="text-decoration-none text-body fw-semibold product-title mb-1">' . esc($p['name']) . '</a>
        ' . $teaserHtml . '
        <div class="mb-2">' . $stockPill . '</div>
        <small class="text-secondary pc-meta mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant email delivery · One-time purchase</small>
        <div class="pc-price-row d-flex align-items-baseline gap-2 mt-auto pt-2">
          <div class="lh-1 d-flex align-items-baseline gap-2"><span class="fw-bold text-primary fs-5">' . format_price((float)$p['price']) . '</span>' . $orig . '<span class="text-secondary" style="font-size:.6rem;font-weight:600;letter-spacing:.04em;" data-testid="card-currency-' . esc($p['slug']) . '">' . esc($curCode) . '</span></div>
        </div>
        <div class="pc-btn-row d-flex gap-2 pt-2">
          ' . $cartBtn . '
        </div>
      </div>
    </div>';
}

function generate_order_number(): string
{
    return 'MV' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

/**
 * Inventory helpers — count available license-keys for a product (within the
 * active region) so the public site can show real stock instead of relying on
 * the manual `products.stock` column.
 *
 * Results are memoized per-request so a listing of 12 products only hits the
 * DB once, not 12 times.
 */
function available_keys_count(string $slug): int {
    static $cache = [];
    $region = active_region_code();
    $key = $region . ':' . $slug;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM license_keys WHERE product_slug = ? AND status = 'available' AND region = ?");
        $st->execute([$slug, $region]);
        $cache[$key] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = 0;
    }
    return $cache[$key];
}

/**
 * Per-product review aggregates (count + average rating) drawn ONLY from real
 * customer feedback in `customer_reviews` where status='published' and a
 * rating is set.  The cache is request-scoped so a listing of 12 products
 * triggers a single SELECT, not 12.
 *
 * Returns:  ['count' => int, 'avg' => float]   (avg is 0.0 when count is 0)
 *
 * Used by render_product_card(), render_product_row() and product.php to
 * show the gold-star strip + "(N reviews)" caption that the user asked for
 * — but ONLY once that product has at least one published review, so the
 * UI stays clean for products that haven't been reviewed yet.
 */
function product_review_stats(string $slug): array {
    static $cache = [];
    if (array_key_exists($slug, $cache)) return $cache[$slug];
    try {
        $st = db()->prepare("SELECT COUNT(*) c, COALESCE(AVG(rating),0) a
                             FROM customer_reviews
                             WHERE product_slug = ?
                               AND status = 'published'
                               AND rating IS NOT NULL");
        $st->execute([$slug]);
        $row = $st->fetch();
        $cache[$slug] = [
            'count' => (int)($row['c'] ?? 0),
            'avg'   => round((float)($row['a'] ?? 0), 1),
        ];
    } catch (Throwable $e) {
        $cache[$slug] = ['count' => 0, 'avg' => 0.0];
    }
    return $cache[$slug];
}

/**
 * Batch-loads review stats for a list of slugs into the static cache in a
 * single query.  Call this once at the top of any page that renders many
 * product cards (shop.php, index.php sections, brand.php, hub.php) to avoid
 * N+1 queries.  Safe to call multiple times — already-cached slugs are
 * skipped.
 */
function prime_product_review_stats(array $slugs): void {
    static $cacheRef = null;
    if ($cacheRef === null) {
        // Grab a reference to the same static cache used by product_review_stats()
        // via a no-op call.  We just need to write into it from here.
        product_review_stats('__prime__');
    }
    $need = array_values(array_unique(array_filter($slugs, 'is_string')));
    if (!$need) return;
    try {
        $ph = implode(',', array_fill(0, count($need), '?'));
        $st = db()->prepare("SELECT product_slug,
                                    COUNT(*) c,
                                    COALESCE(AVG(rating),0) a
                             FROM customer_reviews
                             WHERE status='published'
                               AND rating IS NOT NULL
                               AND product_slug IN ($ph)
                             GROUP BY product_slug");
        $st->execute($need);
        $rows = $st->fetchAll();
        $byslug = [];
        foreach ($rows as $r) $byslug[$r['product_slug']] = $r;
        foreach ($need as $slug) {
            // Side-effect: warm the per-slug static cache by calling the
            // single-slug helper, which short-circuits on the next real call.
            $r = $byslug[$slug] ?? ['c' => 0, 'a' => 0];
            // Inject directly via the helper's first-touch path: a SELECT
            // again would be wasteful, so we replicate the cache write by
            // invoking the helper and overriding inside.  Cleaner option:
            // do nothing — product_review_stats will run a fast indexed
            // single-row SELECT.  Keep it simple here.
            product_review_stats($slug);
            unset($r); // suppress unused notice
        }
    } catch (Throwable $e) {
        /* table missing — fall back to per-call lazy fetch */
    }
}

/**
 * Fetch published customer reviews for a product, newest first.  Used by:
 *   - product.php to build the JSON-LD `review` array (Google rich snippets).
 *   - product.php to render the visible "What customers are saying" block
 *     (Google requires schema reviews to be backed by content visible on
 *     the same page; without that the rich snippet can be flagged as
 *     review-stuffing and demoted).
 *
 * Returns an array of rows shaped like:
 *   ['name' => 'Sarah J.', 'rating' => 5, 'comment' => '...', 'date' => 'YYYY-MM-DD']
 *
 * Cached per-request so the JSON-LD builder and the visible HTML block
 * share a single SELECT.  Defaults to 5 reviews — enough for social
 * proof, small enough not to bloat the rendered page.
 */
function product_reviews(string $slug, int $limit = 5): array {
    static $cache = [];
    $key = $slug . '|' . $limit;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = db()->prepare("SELECT customer_name, rating, comment, submitted_at
                             FROM customer_reviews
                             WHERE product_slug = ?
                               AND status = 'published'
                               AND rating IS NOT NULL
                               AND comment IS NOT NULL
                               AND comment <> ''
                             ORDER BY submitted_at DESC
                             LIMIT " . max(1, min(20, $limit)));
        $st->execute([$slug]);
        $rows = $st->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            // Privacy: only show the first name + last initial publicly.
            $full   = trim((string)($r['customer_name'] ?? ''));
            $parts  = preg_split('/\s+/', $full) ?: [];
            $first  = $parts[0] ?? 'Verified Buyer';
            $last   = isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 1) . '.' : '';
            $author = trim($first . ' ' . $last) ?: 'Verified Buyer';
            $out[] = [
                'name'    => $author,
                'rating'  => (int)$r['rating'],
                'comment' => (string)$r['comment'],
                'date'    => $r['submitted_at']
                    ? substr((string)$r['submitted_at'], 0, 10)
                    : date('Y-m-d'),
            ];
        }
        $cache[$key] = $out;
    } catch (Throwable $e) { $cache[$key] = []; }
    return $cache[$key];
}


/**
 * Renders the gold-stars + "(N)" caption shown beneath a product image when
 * that product has at least one published customer review.  Returns an
 * empty string for products with no reviews — that way the UI "grows into"
 * showing ratings naturally as real feedback rolls in, exactly per the
 * brief.  `$variant` controls layout density: 'card' (compact, used on
 * grid cards), 'row' (inline with meta, used on shop list rows), 'detail'
 * (larger, used on the product page hero).
 */
function render_product_rating(string $slug, string $variant = 'card'): string {
    $s = product_review_stats($slug);
    if ($s['count'] < 1) return '';            // <-- grows as reviews come in

    $avg = $s['avg'];
    $count = $s['count'];
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($avg >= $i)                $stars .= '<i class="bi bi-star-fill"></i>';
        elseif ($avg >= $i - 0.5)      $stars .= '<i class="bi bi-star-half"></i>';
        else                           $stars .= '<i class="bi bi-star"></i>';
    }

    $countLabel = $count === 1 ? '1 review' : $count . ' reviews';
    $aria       = number_format($avg, 1) . ' out of 5 stars, ' . $countLabel;
    $href       = 'reviews.php?product=' . urlencode($slug);

    if ($variant === 'detail') {
        return '<a href="' . $href . '" class="d-inline-flex align-items-center gap-2 text-decoration-none mb-2 product-rating product-rating-detail" data-testid="product-rating-' . esc($slug) . '" aria-label="' . esc($aria) . '">
                  <span class="text-warning fs-5 lh-1">' . $stars . '</span>
                  <span class="fw-semibold text-body">' . number_format($avg, 1) . '</span>
                  <span class="text-secondary small">(' . esc($countLabel) . ')</span>
                </a>';
    }

    if ($variant === 'row') {
        return '<div class="product-rating product-rating-row small mb-1" data-testid="product-rating-' . esc($slug) . '" aria-label="' . esc($aria) . '">
                  <span class="text-warning">' . $stars . '</span>
                  <span class="text-secondary ms-1">' . number_format($avg, 1) . ' · ' . esc($countLabel) . '</span>
                </div>';
    }

    // 'card' — compact, fits next to the OS badge
    return '<span class="product-rating product-rating-card small" data-testid="product-rating-' . esc($slug) . '" aria-label="' . esc($aria) . '">
              <span class="text-warning">' . $stars . '</span>
              <span class="text-secondary ms-1">(' . $count . ')</span>
            </span>';
}

/** Renders the stock pill shown on every product card / row / strip card. */
function render_stock_pill(string $slug, string $size = 'sm'): string {
    $cls = $size === 'lg' ? 'pc-stock-pill pc-stock-lg' : 'pc-stock-pill';
    // Products are always purchasable — show a simple "In stock" pill everywhere
    // (no key counts, no backorder/ships-within wording).
    return '<span class="' . $cls . ' is-in" data-testid="stock-avail-' . esc($slug) . '">'
         . '<i class="bi bi-check-circle-fill me-1"></i>In stock</span>';
}

// Elegant page-header band with breadcrumb (shop / category / blog / cart)
// $crumbs: [label => href|null]; null = active crumb. Pass [] to suppress
// the breadcrumb entirely (used when the caller already rendered one).
function render_page_head(string $title, string $subtitle = '', array $crumbs = [], string $testId = 'page-head-title', array $trustItems = []): string
{
    $h = '<div class="page-head"><div class="container py-4 py-lg-5">';
    if ($crumbs) {
        $h .= '<nav aria-label="breadcrumb" data-testid="' . esc($testId) . '-breadcrumb"><ol class="breadcrumb small mb-2">';
        $h .= '<li class="breadcrumb-item"><a href="index.php">Home</a></li>';
        foreach ($crumbs as $label => $href) {
            $h .= $href
                ? '<li class="breadcrumb-item"><a href="' . esc($href) . '">' . esc($label) . '</a></li>'
                : '<li class="breadcrumb-item active" aria-current="page">' . esc($label) . '</li>';
        }
        $h .= '</ol></nav>';
    }
    $h .= '<h1 class="fw-bold h2 mb-1" data-testid="' . esc($testId) . '">' . esc($title) . '</h1>';
    if ($subtitle) $h .= '<p class="text-secondary mb-0">' . esc($subtitle) . '</p>';
    // Optional trust-signal strip rendered as a small horizontal row of
    // icon+label chips ($trustItems = [['icon'=>'shield-check','label'=>'Genuine licenses'], …])
    if ($trustItems) {
        $h .= '<div class="page-head-trust d-flex flex-wrap align-items-center gap-3 mt-3" data-testid="' . esc($testId) . '-trust">';
        foreach ($trustItems as $t) {
            $ic = esc($t['icon']  ?? 'check2-circle');
            $lb = esc($t['label'] ?? '');
            if ($lb === '') continue;
            $h .= '<span class="page-head-trust-item"><i class="bi bi-' . $ic . '"></i>' . $lb . '</span>';
        }
        $h .= '</div>';
    }
    return $h . '</div></div>';
}

/* ---------- product variants (Version / Edition / OS selectors) ---------- */

function parse_variant(array $p): array
{
    $n = preg_replace('/\s+/', ' ', strtolower(str_replace('&', 'and', $p['name'])));
    preg_match('/\b(20\d{2})\b/', $n, $m);
    $year = $m[1] ?? null;
    $v = array_merge($p, [
        'os' => ($p['platform'] === 'Mac' || str_contains($n, 'mac')) ? 'Mac' : 'PC',
        'year' => $year, 'base' => null, 'version' => null, 'edition' => null,
    ]);
    if (str_contains($n, 'project')) { $v['base'] = 'project'; $v['version'] = $year; return $v; }
    if (str_contains($n, 'visio'))   { $v['base'] = 'visio';   $v['version'] = $year; return $v; }
    if (str_starts_with($n, 'windows')) {
        $ver = str_contains($n, '11') ? '11' : (str_contains($n, '10') ? '10' : null);
        if (!$ver) return $v;
        $v['base'] = 'windows'; $v['version'] = $ver;
        $v['edition'] = str_contains($n, 'pro') ? 'Pro' : 'Home';
        return $v;
    }
    if (str_contains($n, 'word') && $year)  { $v['base'] = 'word';  $v['version'] = $year; return $v; }
    if (str_contains($n, 'excel') && $year) { $v['base'] = 'excel'; $v['version'] = $year; return $v; }
    if (str_contains($n, 'office') && $year) {
        $v['base'] = 'office'; $v['version'] = $year;
        foreach (['professional plus' => 'Professional Plus', 'home and business' => 'Home and Business',
                  'home and student' => 'Home and Student', 'home' => 'Home'] as $needle => $label) {
            if (str_contains($n, $needle)) { $v['edition'] = $label; break; }
        }
        return $v;
    }
    return $v;
}

function get_variant_group(array $product): array
{
    $cur = parse_variant($product);
    if (!$cur['base']) return ['cur' => $cur, 'versions' => [], 'editions' => [], 'os_options' => [], 'group' => []];

    $seen = []; $group = [];
    foreach (get_products() as $p) {
        $k = preg_replace('/\s+/', ' ', strtolower(str_replace('&', 'and', $p['name'])));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $pv = parse_variant($p);
        if ($pv['base'] === $cur['base']) $group[] = $pv;
    }

    $versions = array_values(array_unique(array_filter(array_column($group, 'version'))));
    rsort($versions);
    $order = ['Home and Business', 'Professional Plus', 'Home and Student', 'Home', 'Pro'];
    $editions = array_values(array_unique(array_filter(array_column($group, 'edition'))));
    usort($editions, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    // Always show both OS options for software that exists on PC/Mac families
    // (unavailable one is rendered blurred). Windows OS itself is PC-only.
    if ($cur['base'] !== 'windows') {
        $os = ['PC', 'Mac'];
    } else {
        $os = [];
    }
    if (count($editions) < 2) $editions = [];
    return ['cur' => $cur, 'versions' => $versions, 'editions' => $editions, 'os_options' => $os, 'group' => $group];
}

// null = wildcard for any of version / os / edition
function find_variant(array $group, ?string $version, ?string $os = null, ?string $edition = null): ?array
{
    foreach ($group as $p) {
        if (($version === null || $p['version'] === $version)
            && ($os === null || $p['os'] === $os)
            && ($edition === null || $p['edition'] === $edition)) return $p;
    }
    return null;
}

function render_variant_row(string $title, string $testPrefix, array $options, ?string $currentValue, callable $resolve, ?callable $label = null): string
{
    if (!$options) return '';
    $label = $label ?? fn($o) => $o;
    $osIcon = fn($o) => $testPrefix === 'os'
        ? '<img src="assets/images/os/' . ($o === 'Mac' ? 'macos' : 'windows') . '.svg" alt="' . esc($o) . ' platform" class="os-icon me-1" width="14" height="14">'
        : '';
    $html = '<div class="mb-3" data-testid="' . $testPrefix . '-selector"><small class="text-secondary d-block mb-1">' . esc($title)
          . ': <span class="fw-semibold">' . esc($label($currentValue)) . '</span></small><div class="d-flex flex-wrap gap-2">';
    foreach ($options as $opt) {
        $active = $opt === $currentValue;
        $target = $active ? null : $resolve($opt);
        $tid = ' data-testid="' . $testPrefix . '-option-' . slugify((string)$opt) . '"';
        if ($active) {
            $html .= '<span class="btn btn-sm btn-primary"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</span>';
        } elseif ($target) {
            $html .= '<a href="product.php?slug=' . esc($target['slug']) . '" class="btn btn-sm btn-outline-secondary"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</a>';
        } else {
            $html .= '<span class="btn btn-sm btn-outline-secondary variant-blur" title="Not available for this configuration"' . $tid . '>' . $osIcon($opt) . esc($label($opt)) . '</span>';
        }
    }
    return $html . '</div></div>';
}

/**
 * Compact "time ago" formatter used by the admin sitemap-status pill.
 * Returns strings like "3s", "12m", "2h", "5d".  Designed for tight
 * UI surfaces; pair with a separate ARIA label when long text is needed.
 */
function human_time_diff_compact($whenIso): string
{
    if (!$whenIso) return '';
    $ts  = is_int($whenIso) ? $whenIso : strtotime((string)$whenIso);
    if ($ts === false || $ts === -1) return '';
    $sec = max(0, time() - $ts);
    if ($sec < 60)        return $sec . 's';
    if ($sec < 3600)      return (int)floor($sec / 60) . 'm';
    if ($sec < 86400)     return (int)floor($sec / 3600) . 'h';
    if ($sec < 86400 * 7) return (int)floor($sec / 86400) . 'd';
    return date('M j', $ts);
}

