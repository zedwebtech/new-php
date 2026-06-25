<?php
require_once __DIR__ . '/functions.php';
// Capture the whole page so the company-branding filter can swap default
// name/phone/email for the current Company Info values site-wide.
if (function_exists('apply_company_branding')) { ob_start('apply_company_branding'); }
require_once __DIR__ . '/regions.php';
require_once __DIR__ . '/visitor_track.php';
// Track this public page-view (silently skipped for bots / admin / CLI).
track_visitor();
// Self-heal the public domain settings on the first real production page view
// so cron/CLI order emails + PDF receipts build correct absolute image URLs.
if (function_exists('mv_sync_public_domain')) { mv_sync_public_domain(); }

// Self-cron heartbeat — if the AI Auto-Blogger is overdue (>24 h), fire it
// in the background after this page has finished rendering. Bots, CLI and
// the dedicated cron worker are skipped inside seo_bot_autotick().
require_once __DIR__ . '/seo-bot.php';
seo_bot_autotick();
$co = company_info();                                       // single source of truth
$brandName  = $co['name']  ?: (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$brandEmail = $co['email'] ?: (defined('SITE_EMAIL') ? SITE_EMAIL : '');
$brandPhone = company_phone_for_country() ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
$brandLogo  = $co['logo']  ?: '';
$brandAddress = $co['address'] ?: (defined('SITE_ADDRESS') ? SITE_ADDRESS : '');
$pageTitle = $pageTitle ?? ($brandName . ' | Genuine Microsoft Software Keys');
$cur = current_currency();
$checkoutHeader = $checkoutHeader ?? false;

/* ---- SEO defaults (pages may override before including this header) ---- */
$pageDescription = $pageDescription ?? 'Buy genuine Microsoft Office, Windows 11 & antivirus license keys at up to 81% off. Instant delivery, lifetime activation, 24/7 US support.';
/* Auto-clamp every page title (50-60 chars) and description (120-160 chars)
   so admin-edited copy can never blow past Google's SERP cut-off. */
$pageTitle       = seo_clamp_title($pageTitle, 60);
$pageDescription = seo_clamp_description($pageDescription, 158);
$pageTitleShort  = $pageTitleShort ?? seo_clamp_title(preg_replace('/\s+\|\s+.*$/u', '', $pageTitle), 55);
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$noIndex = $noIndex ?? in_array($script, ['cart.php', 'checkout.php', 'login.php', 'register.php', 'account.php', 'admin.php', 'admin-email-preview.php', 'logout.php', 'order-success.php', '404.php'], true);
if (!isset($canonicalUrl)) {
    $canonicalPath = $script === 'index.php' ? '/' : '/' . $script;
    $canonicalSlug = isset($_GET['slug']) && $_GET['slug'] !== '' ? '?slug=' . urlencode($_GET['slug']) : '';
    $canonicalPathBare = $canonicalPath . $canonicalSlug;            // unprefixed, for hreflang
    $canonicalUrl = site_url() . country_prefix() . $canonicalPathBare;
}
/* ===== Open Graph / Twitter / social card resolution ===========================
 * Per-page overrides (set BEFORE including this header):
 *   $ogImage         absolute or relative URL of the share image
 *   $ogImageAlt      alt text for screen-readers + LinkedIn
 *   $ogImageWidth    pixel width (default 1200)
 *   $ogImageHeight   pixel height (default 630)
 *   $ogType          'website' (default) | 'article' | 'product' | 'profile'
 *   $ogLocale        e.g. 'en_US' (default)
 *   $articlePublishedTime / $articleModifiedTime / $articleAuthor / $articleSection
 *   $productPriceAmount / $productPriceCurrency / $productAvailability
 *   $twitterCard     'summary_large_image' (default) | 'summary' | 'app' | 'player'
 * Defaults below produce a 1200×630 generated brand card that's social-bot
 * friendly out of the box — no SVG (Facebook & WhatsApp ignore SVG OGs).
 * ============================================================================== */
$_defaultOgImg   = site_url() . '/og-default.png';
$ogImage         = $ogImage         ?? $_defaultOgImg;
// Normalise relative paths → absolute (social bots require absolute URLs).
if (!preg_match('~^https?://~i', $ogImage)) {
    $ogImage = rtrim(site_url(), '/') . '/' . ltrim($ogImage, '/');
}
$ogImageAlt      = $ogImageAlt      ?? $pageTitle;
$ogImageWidth    = $ogImageWidth    ?? 1200;
$ogImageHeight   = $ogImageHeight   ?? 630;
$ogLocale        = $ogLocale        ?? 'en_US';
$twitterCard     = $twitterCard     ?? 'summary_large_image';
$twitterSite     = setting_get('twitter_site_handle', '');     // optional, e.g. "@maventechsw"
$fbAppId         = setting_get('facebook_app_id', '');         // optional, all-digits
?>
<!DOCTYPE html>
<html lang="en"<?php
// Server-rendered theme attribute — kills the light→dark flicker on
// navigation for logged-in users.  Source of truth, in priority order:
//   1. users.theme_pref (DB-persisted per-account choice; multi-device-friendly)
//   2. uc_theme cookie  (set by /ajax/user-theme.php — also covers anon visitors)
//   3. localStorage uc_theme (read inside the inline boot script below
//      for users on a brand-new server roundtrip)
$initialTheme = '';
if (!empty($_SESSION['user_id'])) {
    try {
        $st = db()->prepare('SELECT theme_pref FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['user_id']]);
        $tp = trim((string)($st->fetchColumn() ?: ''));
        if (in_array($tp, ['dark', 'light'], true)) $initialTheme = $tp;
    } catch (Throwable $e) { /* column might not exist yet — ignore */ }
}
if ($initialTheme === '') {
    $ck = $_COOKIE['uc_theme'] ?? '';
    if (in_array($ck, ['dark', 'light'], true)) $initialTheme = $ck;
}
echo $initialTheme !== '' ? ' data-bs-theme="' . esc($initialTheme) . '"' : '';
?>>
<head>
  <meta charset="UTF-8">
  <script>
    // Apply saved theme BEFORE styles render — prevents light-mode flicker on every navigation.
    // Honour the server-rendered data-bs-theme first (when the user is
    // logged in we already have their DB-saved choice).  Only fall back
    // to localStorage / prefers-color-scheme when the server didn't set it.
    (function () {
      try {
        var html = document.documentElement;
        if (html.getAttribute('data-bs-theme')) return;     // already set server-side
        var t = localStorage.getItem('uc_theme');
        if (t !== 'dark' && t !== 'light') {
          t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
        }
        html.setAttribute('data-bs-theme', t);
      } catch (e) {}
    })();
  </script>
  <?php /* Critical pre-paint styles — inlined BEFORE the Bootstrap CDN
          <link> so the browser paints the correct theme background on
          the very first frame.  Without this, every navigation flashed
          white for ~50–150 ms (browser default body background) before
          our external CSS loaded and re-painted dark.  The selection
          colour fix below also lives here so highlights are visible on
          ALL pages including the checkout (the bug user reported).  */ ?>
  <style id="critical-theme-pre-paint">
    :root { color-scheme: light dark; }
    html, body { background: #FFFFFF; color: #0F172A; }
    html[data-bs-theme="dark"], html[data-bs-theme="dark"] body {
      background: #050B1B !important; color: #E5ECFA !important;
      color-scheme: dark;
    }
    /* Text-selection highlight — Bootstrap's default `::selection` is
       light blue with light text, which renders nearly invisible on the
       checkout's dark navy form fields.  Override for both themes.
       Light-mode alpha matches the body-selection rule in style.css
       (.22) so admin / public / form inputs share one consistent tint. */
    ::selection { background: rgba(11, 92, 255, .22); color: #0F172A; }
    html[data-bs-theme="dark"] ::selection { background: rgba(96, 165, 250, .55); color: #FFFFFF; }
    /* Form-field selection inside dark mode (checkout inputs, textareas,
       selects) — same crisp white-on-blue contrast so the user can SEE
       what they've highlighted while filling in name / email / address. */
    html[data-bs-theme="dark"] input::selection,
    html[data-bs-theme="dark"] textarea::selection,
    html[data-bs-theme="dark"] select::selection { background: rgba(96, 165, 250, .55); color: #FFFFFF; }
  </style>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle) ?></title>
  <meta name="description" content="<?= esc($pageDescription) ?>">
  <meta name="robots" content="<?= $noIndex ? 'noindex, nofollow' : 'index, follow' ?>">
  <?php if (isset($pageKeywords)): ?>
  <meta name="keywords" content="<?= esc($pageKeywords) ?>">
  <?php endif; ?>
  <link rel="canonical" href="<?= esc($canonicalUrl) ?>">
  <?php
  // hreflang alternates so Google serves the right country/currency URL.
  if (isset($canonicalPathBare)):
      $__hlMap = ['US' => 'en-US', 'UK' => 'en-GB', 'AU' => 'en-AU', 'CA' => 'en-CA', 'EU' => 'en'];
      foreach ($__hlMap as $__cc => $__lang):
          $__alt = site_url() . ($__cc === 'US' ? '' : '/' . strtolower($__cc)) . $canonicalPathBare;
  ?>
  <link rel="alternate" hreflang="<?= $__lang ?>" href="<?= esc($__alt) ?>">
  <?php endforeach; ?>
  <link rel="alternate" hreflang="x-default" href="<?= esc(site_url() . $canonicalPathBare) ?>">
  <?php endif; ?>
  <!-- Favicon set — modern browsers prefer SVG (crisp at any size); older
       Bing / Yandex / SEO-audit crawlers explicitly look for /favicon.ico. -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon/favicon-16.png">
  <link rel="apple-touch-icon" sizes="64x64" href="/assets/images/favicon/favicon-64.png">
  <link rel="shortcut icon" href="/favicon.ico">
  <meta name="theme-color" content="#0066CC">
  <!-- PWA: lets visitors install the storefront to their home screen on
       iOS / Android.  Manifest generated dynamically so it always tracks
       the live Company Info settings (brand name, theme colour, etc). -->
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= esc(preg_replace('/\s+software\s*$/i', '', $brandName)) ?>">
  <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION !== ''): ?>
  <meta name="google-site-verification" content="<?= esc(GOOGLE_SITE_VERIFICATION) ?>">
  <?php elseif (($__gsc = setting_get('google_site_verification_token', '')) !== ''): ?>
  <meta name="google-site-verification" content="<?= esc($__gsc) ?>">
  <?php endif; ?>
  <?php if (defined('BING_SITE_VERIFICATION') && BING_SITE_VERIFICATION !== ''): ?>
  <meta name="msvalidate.01" content="<?= esc(BING_SITE_VERIFICATION) ?>">
  <?php elseif (($__bing = setting_get('bing_site_verification_token', '')) !== ''): ?>
  <meta name="msvalidate.01" content="<?= esc($__bing) ?>">
  <?php endif; ?>
  <?php if (defined('YANDEX_SITE_VERIFICATION') && YANDEX_SITE_VERIFICATION !== ''): ?>
  <meta name="yandex-verification" content="<?= esc(YANDEX_SITE_VERIFICATION) ?>">
  <?php elseif (($__yandex = setting_get('yandex_site_verification_token', '')) !== ''): ?>
  <meta name="yandex-verification" content="<?= esc($__yandex) ?>">
  <?php endif; ?>
  <?php if (defined('PINTEREST_SITE_VERIFICATION') && PINTEREST_SITE_VERIFICATION !== ''): ?>
  <meta name="p:domain_verify" content="<?= esc(PINTEREST_SITE_VERIFICATION) ?>">
  <?php elseif (($__pin = setting_get('pinterest_site_verification_token', '')) !== ''): ?>
  <meta name="p:domain_verify" content="<?= esc($__pin) ?>">
  <?php endif; ?>
  <?php if (defined('BAIDU_SITE_VERIFICATION') && BAIDU_SITE_VERIFICATION !== ''): ?>
  <meta name="baidu-site-verification" content="<?= esc(BAIDU_SITE_VERIFICATION) ?>">
  <?php endif; ?>
  <!-- ====================== Open Graph / Twitter / LinkedIn ====================== -->
  <meta property="og:site_name"   content="<?= esc($brandName) ?>">
  <meta property="og:type"        content="<?= esc($ogType ?? 'website') ?>">
  <meta property="og:title"       content="<?= esc($pageTitle) ?>">
  <meta property="og:description" content="<?= esc($pageDescription) ?>">
  <meta property="og:url"         content="<?= esc($canonicalUrl) ?>">
  <meta property="og:locale"      content="<?= esc($ogLocale) ?>">
  <meta property="og:image"        content="<?= esc($ogImage) ?>">
  <meta property="og:image:secure_url" content="<?= esc($ogImage) ?>">
  <meta property="og:image:alt"    content="<?= esc($ogImageAlt) ?>">
  <meta property="og:image:width"  content="<?= (int)$ogImageWidth ?>">
  <meta property="og:image:height" content="<?= (int)$ogImageHeight ?>">
  <meta property="og:image:type"   content="<?= str_ends_with(strtolower($ogImage), '.png') ? 'image/png' : (str_ends_with(strtolower($ogImage), '.webp') ? 'image/webp' : 'image/jpeg') ?>">
  <?php if (!empty($fbAppId) && ctype_digit((string)$fbAppId)): ?>
  <meta property="fb:app_id"       content="<?= esc($fbAppId) ?>">
  <?php endif; ?>

  <?php /* Article-specific OG (blog posts) */
  if (($ogType ?? '') === 'article'): ?>
    <?php if (!empty($articlePublishedTime)): ?>
    <meta property="article:published_time" content="<?= esc($articlePublishedTime) ?>">
    <?php endif; ?>
    <?php if (!empty($articleModifiedTime)): ?>
    <meta property="article:modified_time"  content="<?= esc($articleModifiedTime) ?>">
    <?php endif; ?>
    <?php if (!empty($articleAuthor)): ?>
    <meta property="article:author"  content="<?= esc($articleAuthor) ?>">
    <?php endif; ?>
    <?php if (!empty($articleSection)): ?>
    <meta property="article:section" content="<?= esc($articleSection) ?>">
    <?php endif; ?>
    <?php foreach ((array)($articleTags ?? []) as $_atag): ?>
    <meta property="article:tag"     content="<?= esc($_atag) ?>">
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* Product-specific OG (product pages) */
  if (($ogType ?? '') === 'product'): ?>
    <?php if (!empty($productPriceAmount)): ?>
    <meta property="product:price:amount"   content="<?= esc((string)$productPriceAmount) ?>">
    <meta property="product:price:currency" content="<?= esc($productPriceCurrency ?? 'USD') ?>">
    <?php endif; ?>
    <?php if (!empty($productAvailability)): ?>
    <meta property="product:availability" content="<?= esc($productAvailability) ?>">
    <?php endif; ?>
    <?php if (!empty($productCondition)): ?>
    <meta property="product:condition"    content="<?= esc($productCondition) ?>">
    <?php endif; ?>
    <?php if (!empty($productBrand)): ?>
    <meta property="product:brand"        content="<?= esc($productBrand) ?>">
    <?php endif; ?>
  <?php endif; ?>

  <meta name="twitter:card"        content="<?= esc($twitterCard) ?>">
  <meta name="twitter:title"       content="<?= esc($pageTitle) ?>">
  <meta name="twitter:description" content="<?= esc($pageDescription) ?>">
  <meta name="twitter:image"       content="<?= esc($ogImage) ?>">
  <meta name="twitter:image:alt"   content="<?= esc($ogImageAlt) ?>">
  <?php if (!empty($twitterSite)): ?>
  <meta name="twitter:site"        content="<?= esc($twitterSite) ?>">
  <meta name="twitter:creator"     content="<?= esc($twitterSite) ?>">
  <?php endif; ?>
  <!-- LinkedIn / Discord respect Open Graph above; Slack and iMessage prefer
       the Twitter set, so all major chat surfaces get a rich preview. -->
  <!-- ===================== /Open Graph / Twitter / LinkedIn ===================== -->
  <!-- Structured data: Organization + WebSite + (optional) LocalBusiness for AEO/GEO -->
  <script type="application/ld+json"><?php
    // Pull aggregate rating from customer_reviews so the org/site schema
    // surfaces star-rating to AI search engines (ChatGPT/Perplexity/etc.)
    // and Google Knowledge Panel.
    $orgRating = null;
    try {
        $r = db()->query("SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS n FROM customer_reviews WHERE status='published' OR status='approved'")->fetch();
        if ($r && (int)$r['n'] > 0) {
            $orgRating = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string)$r['avg_rating'],
                'reviewCount' => (int)$r['n'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }
    } catch (Throwable $e) { /* schema is best-effort */ }
    // ---- Authoritative business identity for AI search + Google Knowledge Panel ----
    // Resolve as much of the postal address as we can from the single-line
    // `company_address` setting + the company city/region/country/postal fields
    // if they exist.  We then build:
    //   1. Organization  (canonical brand entity)
    //   2. LocalBusiness (richer subtype — qualifies us for "near me" / map results)
    //   3. Brand         (links the Brand schema back into the @graph so AI
    //                     engines can quote the brand independently of the org)
    //   4. WebSite       (with SearchAction so AI agents know our search box)
    //   5. ItemList of regions served, with per-market currency.
    //
    // The currenciesAccepted + areaServed combo is the single biggest signal
    // for Google Knowledge Panel eligibility in 2026, lifting it ~30% per
    // industry case studies.
    $rawAddress = trim((string)($co['address'] ?? ($brandAddress ?: '')));
    $addr = ['streetAddress' => $rawAddress, 'addressLocality' => '', 'addressRegion' => '', 'postalCode' => '', 'addressCountry' => 'US'];
    if ($rawAddress) {
        // Best-effort parse:  "123 Maventech Way, Austin TX 78701"
        // → street="123 Maventech Way", locality="Austin", region="TX", postal="78701"
        $parts = array_map('trim', explode(',', $rawAddress));
        if (count($parts) >= 2) {
            $addr['streetAddress'] = $parts[0];
            $tail = trim(end($parts));
            if (preg_match('/^(.*?)\s+([A-Z]{2})\s+([A-Za-z0-9 \-]+)$/', $tail, $m)) {
                $addr['addressLocality'] = $m[1];
                $addr['addressRegion']   = $m[2];
                $addr['postalCode']      = $m[3];
            } else {
                $addr['addressLocality'] = $tail;
            }
        }
    }
    foreach (['city' => 'addressLocality', 'state' => 'addressRegion', 'postal_code' => 'postalCode', 'country' => 'addressCountry'] as $coKey => $schemaKey) {
        if (!empty($co[$coKey])) $addr[$schemaKey] = (string)$co[$coKey];
    }

    // Currencies accepted — read from the regions table so it stays in sync.
    $currenciesAccepted = [];
    $areaServed = [];
    try {
        $regs = db()->query("SELECT code, name, currency FROM regions WHERE active = 1 ORDER BY code")->fetchAll();
        foreach ($regs as $rg) {
            if ($rg['currency']) $currenciesAccepted[] = $rg['currency'];
            $areaServed[] = ['@type' => 'Country', 'name' => $rg['name']];
        }
    } catch (Throwable $e) { /* schema is best-effort */ }
    $currenciesAccepted = $currenciesAccepted ?: ['USD'];

    // Live brand expertise — pulled from the products table so the JSON-LD
    // `knowsAbout` mirrors /agents.json's brand list. Cached for one hour in
    // the settings table to keep per-pageview SQL cheap. Empowers Google's
    // AI Overview + ChatGPT/Perplexity to quote which vendors we resell
    // when answering "where can I buy a genuine McAfee key" style queries.
    $orgBrandList = [];
    try {
        $cachedKnows = (string)setting_get('schema_knows_about_cache', '');
        $cachedAt    = (int)setting_get('schema_knows_about_cache_at', 0);
        if ($cachedKnows && (time() - $cachedAt) < 3600) {
            $orgBrandList = array_values(array_filter(array_map('trim', explode("\n", $cachedKnows))));
        } else {
            $rows = db()->query("SELECT DISTINCT brand FROM products WHERE is_active=1 AND brand IS NOT NULL AND brand != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
            $orgBrandList = array_values(array_filter($rows));
            setting_set('schema_knows_about_cache',    implode("\n", $orgBrandList));
            setting_set('schema_knows_about_cache_at', (string)time());
        }
    } catch (Throwable $e) { /* schema is best-effort */ }
    $areaServed = $areaServed ?: [['@type' => 'Country', 'name' => 'United States']];

    $graph = [
        array_filter([
            '@type' => 'Organization',
            '@id'   => site_url() . '/#organization',
            'name'  => $brandName,
            // legalName + taxID enriches Google Ads trust score and helps
            // pass automated ad-approval audits for software resellers.
            // Falls back to brand name if no registered name was set in admin.
            'legalName' => trim((string)($co['legal_name'] ?? '')) ?: $brandName,
            'taxID'     => trim((string)($co['vat_id']     ?? '')) ?: null,
            'foundingDate' => trim((string)($co['founded_at'] ?? '')) ?: null,
            'url'   => site_url() . '/',
            'logo'  => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'email' => $brandEmail ?: null,
            // address on Organization too (not just LocalBusiness) — Google Ads
            // policy requires verifiable business identity on the root entity.
            'address' => $rawAddress ? array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $addr['streetAddress'] ?: null,
                'addressLocality' => $addr['addressLocality'] ?: null,
                'addressRegion'   => $addr['addressRegion'] ?: null,
                'postalCode'      => $addr['postalCode'] ?: null,
                'addressCountry'  => $addr['addressCountry'] ?: null,
            ]) : null,
            'slogan'=> 'Genuine software licences. Instant digital delivery.',
            'description' => 'Authorised reseller of genuine software licence keys (Microsoft, Bitdefender, Norton, McAfee, Adobe, Autodesk and more) with instant digital delivery to ' . implode(', ', array_column($areaServed, 'name')) . '.',
            'brand' => ['@id' => site_url() . '/#brand'],
            // sameAs — Google Ads / Bing Ads trust auditors expect this
            // array to be present and non-empty on the Organization entity.
            // If the admin hasn't seeded any social-profile URLs yet, we
            // fall back to the canonical /about-us page, which IS a valid
            // sameAs entry (same legal entity, different surface).  Once
            // real social URLs are pasted into Admin → Company Info, those
            // replace the fallback automatically.
            'sameAs' => array_values(array_filter([
                $co['twitter']  ?? null,
                $co['facebook'] ?? null,
                $co['linkedin'] ?? null,
                $co['instagram']?? null,
            ])) ?: [site_url() . '/about-us.php'],
            'contactPoint' => $brandPhone ? [[
                '@type'             => 'ContactPoint',
                'telephone'         => $brandPhone,
                'contactType'       => 'customer service',
                'availableLanguage' => ['English'],
                'areaServed'        => ['US', 'GB', 'AU', 'CA'],
            ]] : null,
            'areaServed'         => $areaServed,
            'currenciesAccepted' => implode(', ', $currenciesAccepted),
            // knowsAbout — the live list of brands we resell. Strongest
            // single signal for AI engines to associate this entity with
            // those vendor names when generating brand-mention answers.
            'knowsAbout'         => $orgBrandList ?: null,
            // subjectOf — anchors the manifest discovery files back to the
            // Organization entity so AI crawlers traversing the JSON-LD
            // can follow the link to /llms.txt and /agents.json without
            // a separate sitemap fetch.
            'subjectOf' => [
                [
                    '@type'         => 'CreativeWork',
                    'name'          => 'AI Discovery Manifest (llms.txt)',
                    'url'           => site_url() . '/llms.txt',
                    'encodingFormat'=> 'text/markdown',
                    'about'         => ['@id' => site_url() . '/#organization'],
                ],
                [
                    '@type'         => 'CreativeWork',
                    'name'          => 'AI Agent Manifest (agents.json)',
                    'url'           => site_url() . '/agents.json',
                    'encodingFormat'=> 'application/json',
                    'about'         => ['@id' => site_url() . '/#organization'],
                ],
            ],
            'aggregateRating'    => $orgRating,
        ]),
        // Explicit Brand node — gives AI engines a single authoritative
        // anchor for the brand identity (logo, slogan, ratings) that they
        // can quote without dragging the entire Organization profile.
        array_filter([
            '@type' => 'Brand',
            '@id'   => site_url() . '/#brand',
            'name'  => $brandName,
            'logo'  => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'slogan'=> 'Genuine software licences. Instant digital delivery.',
            'url'   => site_url() . '/',
            'aggregateRating' => $orgRating,
        ]),
        // LocalBusiness — qualifies for AI "near me" answers + Google's
        // local map panel.  Only emitted when we have a real street address.
        $rawAddress ? array_filter([
            '@type' => 'LocalBusiness',
            '@id'   => site_url() . '/#localbusiness',
            'name'  => $brandName,
            'url'   => site_url() . '/',
            'image' => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'telephone' => $brandPhone ?: null,
            'email'     => $brandEmail ?: null,
            'address'   => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $addr['streetAddress'] ?: null,
                'addressLocality' => $addr['addressLocality'] ?: null,
                'addressRegion'   => $addr['addressRegion'] ?: null,
                'postalCode'      => $addr['postalCode'] ?: null,
                'addressCountry'  => $addr['addressCountry'] ?: null,
            ]),
            'priceRange'         => '$$',
            'currenciesAccepted' => implode(', ', $currenciesAccepted),
            'paymentAccepted'    => 'Credit Card, Stripe, Apple Pay, Google Pay',
            'areaServed'         => $areaServed,
            'openingHoursSpecification' => [
                [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
                    'opens'     => '09:00',
                    'closes'    => '18:00',
                ],
                [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Saturday'],
                    'opens'     => '10:00',
                    'closes'    => '14:00',
                ],
            ],
            'aggregateRating' => $orgRating,
        ]) : null,
        [
            '@type' => 'WebSite',
            '@id'   => site_url() . '/#website',
            'name'  => $brandName,
            'url'   => site_url() . '/',
            'publisher'       => ['@id' => site_url() . '/#organization'],
            'inLanguage'      => 'en',
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => site_url() . '/shop.php?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ],
    ];
    $graph = array_values(array_filter($graph));
    echo json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    ], JSON_UNESCAPED_SLASHES);
  ?></script>
  <?php if (isset($jsonLd)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdBreadcrumb)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdBreadcrumb, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdFaq)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdFaq, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdWebsite)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdWebsite, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdContact)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdContact, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdAboutPage)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdAboutPage, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdHowTo)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdHowTo, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdAiSummary)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdAiSummary, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdPaa)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdPaa, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdItemList)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdItemList, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (!empty($jsonLdVideos) && is_array($jsonLdVideos)):
      foreach ($jsonLdVideos as $__v): ?>
  <script type="application/ld+json"><?= json_encode($__v, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endforeach; endif; ?>
  <?php if (!empty($preloadImage)): ?>
  <!-- Performance: preload the hero (LCP) image so Core Web Vitals stay green -->
  <link rel="preload" as="image" href="<?= esc($preloadImage) ?>" fetchpriority="high">
  <?php endif; ?>
  <!-- Performance: pre-resolve DNS + warm TLS to the third-party CDNs we hit
       on every page so Core Web Vitals (LCP / FCP) stay green. -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap-Icons is render-blocking on every page but the icons aren't
       in the critical above-the-fold paint path. Load it asynchronously
       via the print-onload trick so it never blocks LCP. -->
  <link rel="preload" as="style" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"></noscript>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <!-- Manrope — variable font from Google Fonts (wght@400..800 axis) gives
       us the full weight range (body, semibold, bold, headings) from ONE
       compressed WOFF2 file instead of 6 separate discrete-weight files.
       Saves ~80 KB vs the wght@300;400;500;600;700;800 bundle that
       Lighthouse flagged as "oversized web font". -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400..800&display=swap" rel="stylesheet">
  <!-- Inter — primary geometric sans, used by the Zoom-inspired theme block
       at the bottom of style.css.  Variable-weight + display:swap so the
       initial render still happens with the system fallback if Google Fonts
       is blocked on a corporate network. -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/style.css?v=<?= esc(@filemtime(__DIR__ . '/../assets/css/style.css')) ?>" rel="stylesheet">
  <link href="assets/css/dark-mode-polish.css?v=<?= esc(@filemtime(__DIR__ . '/../assets/css/dark-mode-polish.css')) ?>" rel="stylesheet">
  <script>window.SITE_PHONE = '<?= esc($brandPhone) ?>'; window.CART_SLUGS = <?= json_encode(array_keys(cart())) ?>;</script>

  <?php
  /* ========================================================================
   *  CONVERSION-TRACKING STACK — emits only the trackers whose IDs are set
   *  in Admin → SEO & Tracking.  Empty IDs render nothing (placeholders are
   *  safe).  Order: gtag.js (GA4 + Google Ads) → Bing UET → Microsoft Clarity.
   *  All four tags coexist without conflict — gtag uses dataLayer/gtag,
   *  Bing uses uetq, Clarity uses clarity.
   * ====================================================================== */
  $tk_ga4       = trim((string)setting_get('ga4_measurement_id',        ''));
  $tk_gAds      = trim((string)setting_get('google_ads_tag_id',         ''));
  $tk_gAdsLabel = trim((string)setting_get('google_ads_purchase_label', ''));
  $tk_uet       = trim((string)setting_get('bing_uet_tag_id',           ''));
  $tk_clarity   = trim((string)setting_get('clarity_project_id',        ''));
  ?>

  <?php if ($tk_ga4 !== '' || $tk_gAds !== ''):
      // Single gtag.js load powers BOTH GA4 + Google Ads.  Use whichever
      // ID is set first as the gtag/js?id= parameter; we then call
      // gtag('config', …) for each ID present, which is the official
      // multi-product pattern from Google.
      $primaryGtagId = $tk_ga4 !== '' ? $tk_ga4 : $tk_gAds;
  ?>
  <!-- Google tag (gtag.js) — covers GA4 + Google Ads -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= esc($primaryGtagId) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    <?php if ($tk_ga4 !== ''): ?>gtag('config', '<?= esc($tk_ga4) ?>');<?php endif; ?>
    <?php if ($tk_gAds !== ''): ?>gtag('config', '<?= esc($tk_gAds) ?>');<?php endif; ?>
  </script>
  <?php endif; ?>

  <?php if ($tk_uet !== ''): ?>
  <!-- Bing Universal Event Tracking (UET) -->
  <script>
    (function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:"<?= esc($tk_uet) ?>",enableAutoSpaTracking:true};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,"script","//bat.bing.com/bat.js","uetq");
  </script>
  <?php endif; ?>

  <?php if ($tk_clarity !== ''): ?>
  <!-- Microsoft Clarity (free heatmaps + session replay; signals quality to Bing Ads) -->
  <script type="text/javascript">
    (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", "<?= esc($tk_clarity) ?>");
  </script>
  <?php endif; ?>

</head>
<body data-brand-motion="<?= esc(setting_get('company_logo_motion', 'bounce')) ?>" data-brand-vibe="<?= esc(setting_get('company_brand_vibe', 'classic')) ?>">

<?php if ($checkoutHeader): ?>
<!-- Slim secure-checkout header -->
<nav class="navbar bg-body border-bottom">
  <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2 checkout-header">
    <div class="d-none d-md-flex align-items-center gap-2 small">
      <i class="bi bi-patch-check-fill text-success"></i>
      <span class="fw-semibold">Secure Verified Checkout</span>
    </div>
    <div class="d-flex align-items-center gap-3 small">
      <a href="tel:<?= esc(tel_e164($brandPhone)) ?>" class="text-decoration-none fw-semibold"><i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?></a>
      <span class="text-success fw-semibold d-none d-sm-inline"><i class="bi bi-lock-fill me-1"></i>Secure Checkout</span>
    </div>
  </div>
</nav>
<?php else: ?>

<!-- Promo bar — when an admin-scheduled Brand Vibe is live we render the
     full vibe-promo-banner here (logo + percentage + coupon).  The
     fallback static MAVEN20 strip was retired in Feb 2026 because the
     top deal-bar (further below) now carries the default promo — having
     both was redundant and made the page header feel cluttered. -->
<?php
$_vibeTopbar = function_exists('render_vibe_promo_banner') ? render_vibe_promo_banner('topbar') : '';
if ($_vibeTopbar !== ''):
  echo $_vibeTopbar;
endif;

// Resolve the active promo headline/code early so the inline trustbar
// promo strip can display them. When a vibe-promo schedule is live, the
// scheduled label/code wins; otherwise the static defaults below.
$_vibePromo    = function_exists('active_vibe_promo') ? active_vibe_promo() : null;
$_dealHeadline = 'Save up to 10%';
$_dealCode     = 'MAVEN20';
if ($_vibePromo && !empty($_vibePromo['coupon_code']) && (int)$_vibePromo['coupon_percent'] > 0) {
    $_pct       = (int)$_vibePromo['coupon_percent'];
    $_labelTxt  = trim((string)($_vibePromo['label'] ?? ''));
    $_dealHeadline = $_labelTxt !== '' ? $_labelTxt : ('Save up to ' . $_pct . '%');
    $_dealCode     = strtoupper((string)$_vibePromo['coupon_code']);
}
?>

<!-- Trust bar (sticky-top so it stays visible while the user scrolls; the main
     navbar sticks below it so neither ever covers the other). -->
<div class="trustbar trustbar-sticky py-1 px-3 d-none d-md-block">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-3 align-items-center flex-wrap">
      <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Microsoft Products</span>
      <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Digital Delivery</span>
      <!-- Inline promo strip: configurable headline + copy-able code chip + Shop Now. -->
      <span class="trustbar-deal d-inline-flex align-items-center gap-2" data-testid="trustbar-deal">
        <span class="trustbar-deal-text"><i class="bi bi-tag-fill me-1"></i><?= esc($_dealHeadline ?? 'Save up to 10%') ?></span>
        <button type="button"
                class="trustbar-deal-code"
                data-code="<?= esc($_dealCode ?? 'MAVEN20') ?>"
                data-testid="trustbar-deal-code"
                title="Click to copy"
                onclick="(function(b){var c=b.getAttribute('data-code');if(!c)return;function done(){var o=b.dataset.orig||b.innerHTML;b.dataset.orig=b.dataset.orig||b.innerHTML;b.innerHTML='<i class=\'bi bi-check2\'></i> Copied';b.classList.add('is-copied');setTimeout(function(){b.innerHTML=o;b.classList.remove('is-copied');},1500);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(c).then(done,done);}else{var t=document.createElement('textarea');t.value=c;document.body.appendChild(t);t.select();try{document.execCommand('copy');}catch(_){}t.remove();done();}})(this)">
          <span><?= esc($_dealCode ?? 'MAVEN20') ?></span><i class="bi bi-clipboard"></i>
        </button>
        <a href="shop.php" class="trustbar-deal-shop" data-testid="trustbar-deal-shop">Shop Now <i class="bi bi-chevron-right"></i></a>
      </span>
    </div>
    <div class="d-flex gap-3 align-items-center">
      <!-- Trust + age + phone — wrapped in dedicated classes so the dark-mode
           override block can tune them for crisp contrast on BOTH themes
           (Bootstrap's text-bg-warning + bg-white both wash out against the
           Zoom-navy topbar). -->
      <span class="trustbar-store-pill" data-testid="trustbar-store">
        <i class="bi bi-star-fill"></i>Trusted Software Store
      </span>
      <span class="trustbar-age-pill" data-testid="trustbar-age">2 <small>YRS</small></span>
      <a href="tel:<?= esc(tel_e164($brandPhone)) ?>" class="trustbar-phone-link" data-testid="trustbar-phone">
        <i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?>
      </a>

      <?php /* Utility actions promoted from the main nav so the front line
              breathes (per user feedback "front line should not look so
              congested").  Currency selector + dark-mode toggle live up
              here on desktop; the main nav now carries only Ask AI + Cart
              on its right edge. */ ?>
      <div class="dropdown trustbar-currency" data-testid="trustbar-currency">
        <button class="trustbar-utility-btn dropdown-toggle" data-bs-toggle="dropdown" data-testid="currency-selector" style="white-space:nowrap;">
          <?php $__ctry = current_country_code(); $__ctryFlag = ['US'=>'🇺🇸','UK'=>'🇬🇧','AU'=>'🇦🇺','CA'=>'🇨🇦','EU'=>'🇪🇺'][$__ctry] ?? '🌐'; ?>
          <span class="me-1"><?= $__ctryFlag ?></span><?= esc($cur['code']) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php
          $regionToCurrency = ['US'=>'USD','UK'=>'GBP','EU'=>'EUR','CA'=>'CAD','AU'=>'AUD'];
          $countryLabels    = ['US'=>'United States','UK'=>'United Kingdom','AU'=>'Australia','CA'=>'Canada','EU'=>'Europe'];
          $countryFlags     = ['US'=>'🇺🇸','UK'=>'🇬🇧','AU'=>'🇦🇺','CA'=>'🇨🇦','EU'=>'🇪🇺'];
          $__cleanUri = country_switch_base();
          foreach (all_regions() as $regRow):
            $rc = $regRow['code'];
            if (!isset($regionToCurrency[$rc])) continue;
            $cc = $regionToCurrency[$rc];
            $href = ($rc === 'US' ? '' : '/' . strtolower($rc)) . $__cleanUri;
          ?>
            <li><a class="dropdown-item <?= $rc === $__ctry ? 'active' : '' ?>" href="<?= esc($href) ?>" data-testid="country-opt-<?= $rc ?>" onclick="document.cookie='mv_region_manual=1;path=/;max-age=31536000;SameSite=Lax'"><?= $countryFlags[$rc] ?? '🌐' ?> <?= esc($countryLabels[$rc] ?? $rc) ?> <span class="text-secondary small">(<?= esc($cc) ?>)</span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <button type="button" class="trustbar-utility-btn trustbar-utility-icon" onclick="toggleTheme()" title="Toggle dark mode" data-testid="theme-toggle" aria-label="Toggle dark mode"><i id="theme-icon" class="bi bi-moon"></i></button>
    </div>
  </div>
</div>

<!-- Mobile-only promo chip (sits above the navbar on phones; the trustbar
     itself is hidden on mobile, so this carries the "Save 10% • CODE •
     Shop Now" nudge to the 60-70% of traffic that's mobile). -->
<!-- Mobile promo bar removed per request — keep markup in case it's
     re-enabled later, but hidden via CSS. -->
<div class="mobile-promo d-none" data-testid="mobile-promo" hidden>
  <a href="shop.php" class="mobile-promo-link" data-testid="mobile-promo-link">
    <i class="bi bi-tag-fill mobile-promo-icon"></i>
    <span class="mobile-promo-cta">Shop Now <i class="bi bi-chevron-right"></i></span>
  </a>
</div>

<!-- Main navbar -->
<nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top navbar-below-trustbar">
  <div class="container position-relative">
    <a class="navbar-brand logo-3d d-flex align-items-center gap-2" href="index.php" data-testid="brand-logo">
      <?php if ($brandLogo !== ''): ?>
        <img src="<?= esc($brandLogo) ?>" alt="<?= esc($brandName) ?>" style="height:42px;width:auto;max-width:140px;object-fit:contain;" width="140" height="42" decoding="async" fetchpriority="high">
      <?php else: ?>
        <?= render_logo(42) ?>
      <?php endif; ?>
      <span>
        <?php
          // Split brand name so the LAST word picks up the gradient accent.
          $bnParts = preg_split('/\s+/', trim($brandName));
          $bnLast  = array_pop($bnParts) ?: '';
          $bnHead  = implode(' ', $bnParts);
        ?>
        <span class="brand-text d-block lh-1"><?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span></span>
        <?php if (setting_get('show_authorized_reseller_badge', '1') === '1'): ?>
        <small class="brand-tag" data-testid="brand-tag-authorized-reseller">AUTHORIZED RESELLER</small>
        <?php endif; ?>
      </span>
    </a>
    <div class="d-flex align-items-center gap-2 d-lg-none ms-auto me-2">
      <a href="cart.php" class="btn btn-sm btn-primary rounded-pill position-relative" data-testid="cart-button-mobile">
        <i class="bi bi-cart3"></i>
        <span class="cart-count-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= cart_count() === 0 ? 'd-none' : '' ?>" data-testid="cart-count-mobile"><?= cart_count() ?></span>
      </a>
    </div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" data-testid="navbar-toggler">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <!-- Mobile-only header inside the open hamburger: X close on the right
           + Theme toggle on the left.  Desktop hides this entire row because
           the trustbar above already carries currency/theme; on phones the
           trustbar is collapsed away, so the user has no way to switch
           theme or close the menu without scrolling to a link. -->
      <div class="d-flex d-lg-none align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom" data-testid="mobile-nav-header">
        <button type="button" class="trustbar-utility-btn trustbar-utility-icon"
                onclick="toggleTheme()" aria-label="Toggle dark mode"
                data-testid="theme-toggle-mobile"
                style="background:var(--bs-tertiary-bg);border:1px solid var(--bs-border-color);color:var(--bs-body-color);border-radius:999px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;font-size:.85rem;">
          <i id="theme-icon-mobile" class="bi bi-moon"></i>
          <span style="font-weight:600;">Theme</span>
        </button>
        <div class="dropdown" data-testid="trustbar-currency-mobile">
          <button class="trustbar-utility-btn dropdown-toggle" data-bs-toggle="dropdown"
                  data-testid="currency-selector-mobile"
                  style="background:var(--bs-tertiary-bg);border:1px solid var(--bs-border-color);color:var(--bs-body-color);border-radius:999px;padding:6px 12px;font-size:.85rem;font-weight:600;">
            <?php $__ctryM = current_country_code(); $__ctryFlagM = ['US'=>'🇺🇸','UK'=>'🇬🇧','AU'=>'🇦🇺','CA'=>'🇨🇦','EU'=>'🇪🇺'][$__ctryM] ?? '🌐'; ?>
            <span class="me-1"><?= $__ctryFlagM ?></span><?= esc($cur['code']) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php
            $regionToCurrencyM = ['US'=>'USD','UK'=>'GBP','EU'=>'EUR','CA'=>'CAD','AU'=>'AUD'];
            $countryLabelsM    = ['US'=>'United States','UK'=>'United Kingdom','AU'=>'Australia','CA'=>'Canada','EU'=>'Europe'];
            $countryFlagsM     = ['US'=>'🇺🇸','UK'=>'🇬🇧','AU'=>'🇦🇺','CA'=>'🇨🇦','EU'=>'🇪🇺'];
            $__cleanUriM = country_switch_base();
            foreach (all_regions() as $regRowM):
              $rcM = $regRowM['code'];
              if (!isset($regionToCurrencyM[$rcM])) continue;
              $ccM = $regionToCurrencyM[$rcM];
              $hrefM = ($rcM === 'US' ? '' : '/' . strtolower($rcM)) . $__cleanUriM;
            ?>
              <li><a class="dropdown-item <?= $rcM === $__ctryM ? 'active' : '' ?>" href="<?= esc($hrefM) ?>" data-testid="country-opt-mobile-<?= $rcM ?>" onclick="document.cookie='mv_region_manual=1;path=/;max-age=31536000;SameSite=Lax'"><?= $countryFlagsM[$rcM] ?? '🌐' ?> <?= esc($countryLabelsM[$rcM] ?? $rcM) ?> <span class="text-secondary small">(<?= esc($ccM) ?>)</span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-label="Close navigation menu"
                data-testid="navbar-close-x"
                style="margin-left:auto;"></button>
      </div>
      <ul class="navbar-nav mx-auto">
        <li class="nav-item dropdown position-static">
          <a class="nav-link dropdown-toggle fw-semibold" href="#" data-bs-toggle="dropdown" data-testid="nav-microsoft">Microsoft Products</a>
          <div class="dropdown-menu mega p-3 shadow">
            <div class="row g-4">
              <?php foreach (nav_microsoft() as $heading => $col): ?>
                <div class="col-6 col-lg-3">
                  <div class="mega-heading mb-2"><?= esc($heading) ?></div>
                  <?php foreach ($col['groups'] as $label => $catSlug): ?>
                    <a class="mega-year" href="category.php?slug=<?= esc($catSlug) ?>" data-testid="menu-<?= esc($catSlug) ?>"><?= esc($label) ?></a>
                  <?php endforeach; ?>
                  <a class="mega-link fw-bold text-primary mt-2" href="category.php?slug=<?= esc($col['all'][0]) ?>" data-testid="menu-all-<?= esc($col['all'][0]) ?>"><?= esc($col['all'][1]) ?> <i class="bi bi-arrow-right"></i></a>
                </div>
              <?php endforeach; ?>
            </div>
            <?= render_menu_promo() ?>
            <div class="mt-3 pt-2 border-top d-flex flex-wrap gap-2 align-items-center">
              <span class="small fw-semibold text-secondary me-1"><i class="bi bi-collection-fill text-primary me-1"></i>Topic hubs:</span>
              <a href="hub/microsoft-office" class="badge text-decoration-none" data-testid="menu-hub-office" style="background:#dc26261c;color:#dc2626;border:1px solid #dc26264a;padding:4px 10px;font-size:11px;font-weight:600;">Microsoft Office guide</a>
              <a href="hub/windows" class="badge text-decoration-none" data-testid="menu-hub-windows" style="background:#0078d41c;color:#0078d4;border:1px solid #0078d44a;padding:4px 10px;font-size:11px;font-weight:600;">Windows guide</a>
              <a href="page.php?slug=disclaimer" class="text-decoration-none small ms-auto" data-testid="menu-disclaimer-ms"><i class="bi bi-info-circle me-1"></i>Disclaimer</a>
            </div>
          </div>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle fw-semibold" href="#" data-bs-toggle="dropdown" data-testid="nav-antivirus">Antivirus</a>
          <div class="dropdown-menu p-3 shadow antivirus-menu" style="min-width: 260px;">
            <div class="mega-heading mb-1">ANTIVIRUS</div>
            <?php $_av = nav_antivirus(); foreach ($_av['brands'] as $_avLabel => $_avSlug): ?>
              <a class="mega-year" href="category.php?slug=<?= esc($_avSlug) ?>" data-testid="menu-<?= esc($_avSlug) ?>"><?= esc($_avLabel) ?></a>
            <?php endforeach; ?>
            <a class="mega-link fw-bold text-primary mt-2" href="category.php?slug=<?= esc($_av['all'][0]) ?>" data-testid="menu-all-<?= esc($_av['all'][0]) ?>"><?= esc($_av['all'][1]) ?> <i class="bi bi-arrow-right"></i></a>
            <a class="mega-link mt-1" href="page.php?slug=disclaimer" data-testid="menu-disclaimer-av"><i class="bi bi-info-circle me-1"></i>Disclaimer</a>
            <div class="mt-2 pt-2 border-top">
              <a href="hub/antivirus" class="badge text-decoration-none" data-testid="menu-hub-antivirus" style="background:#16a34a1c;color:#16a34a;border:1px solid #16a34a4a;padding:4px 10px;font-size:11px;font-weight:600;"><i class="bi bi-collection-fill me-1"></i>Antivirus topic hub</a>
            </div>
            <?= render_menu_promo(true) ?>
          </div>
        </li>
        <?php $_others = nav_others(); if (!empty($_others['brands'])): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle fw-semibold" href="#" data-bs-toggle="dropdown" data-testid="nav-others">Others</a>
          <div class="dropdown-menu p-3 shadow others-menu" style="min-width: 240px;">
            <div class="mega-heading mb-1">OTHERS</div>
            <?php foreach ($_others['brands'] as $_oLabel => $_oSlug): ?>
              <a class="mega-year" href="category.php?slug=<?= esc($_oSlug) ?>" data-testid="menu-<?= esc($_oSlug) ?>"><?= esc($_oLabel) ?></a>
            <?php endforeach; ?>
            <?php if (!empty($_others['all'])): ?>
              <a class="mega-link fw-bold text-primary mt-2" href="category.php?slug=<?= esc($_others['all'][0]) ?>" data-testid="menu-all-<?= esc($_others['all'][0]) ?>"><?= esc($_others['all'][1]) ?> <i class="bi bi-arrow-right"></i></a>
            <?php endif; ?>
          </div>
        </li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link fw-semibold" href="contact.php">Request a Quote</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="shop.php" data-testid="nav-shop">Shop</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="blog.php">Blog</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="track-order.php" data-testid="nav-track-order"><i class="bi bi-truck me-1"></i>Track Order</a></li>
      </ul>
      <div class="d-flex align-items-center gap-2 flex-nowrap" data-testid="navbar-actions" style="white-space:nowrap;">
        <?php /* Phone, Currency selector and Theme toggle now live in the
                topbar (right side) so the main nav has room to breathe.
                Only Ask AI + Cart remain here as the primary CTAs. */ ?>
        <button class="btn btn-sm btn-outline-primary rounded-pill flex-shrink-0" onclick="toggleChat()" data-testid="ask-ai-btn" style="white-space:nowrap;"><i class="bi bi-stars me-1"></i>Ask AI</button>
        <a href="cart.php" class="btn btn-sm btn-primary rounded-pill position-relative flex-shrink-0" data-testid="cart-button" style="white-space:nowrap;">
          <i class="bi bi-cart3 me-1"></i>Cart
          <span class="cart-count-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= cart_count() === 0 ? 'd-none' : '' ?>" data-testid="cart-count"><?= cart_count() ?></span>
        </a>
      </div>
    </div>
  </div>
  <!-- Mobile fixed contact strip — stays still inside the sticky header -->
  <div class="mobile-contact-strip d-lg-none w-100" data-testid="mobile-contact-strip">
    <div class="container d-flex align-items-center justify-content-between gap-2 py-1">
      <div class="lh-sm">
        <div class="fw-bold" style="font-size:.74rem;">Have a Question?</div>
        <div class="text-secondary" style="font-size:.62rem;">Call Mon–Fri 9 AM–6 PM EST</div>
      </div>
      <div class="d-flex gap-2 flex-shrink-0">
        <a href="tel:<?= esc(tel_e164($brandPhone)) ?>" class="btn btn-sm rounded-pill fw-bold phone-cta-mobile" data-testid="mobile-call-btn"><i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?></a>
        <button class="btn btn-sm btn-primary rounded-pill fw-bold" style="font-size:.7rem;" onclick="toggleChat()" data-testid="mobile-chat-btn"><i class="bi bi-chat-dots-fill me-1"></i>Chat</button>
      </div>
    </div>
  </div>
</nav>
<!-- Top promo strip moved INLINE into the trustbar above (next to
     "Instant Digital Delivery") — no separate floating bar anymore. -->
<?php endif; ?>
<main id="main-content" role="main">