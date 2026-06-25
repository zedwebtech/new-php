<?php
/**
 * /merchant-feed.xml — Google Merchant Center / Bing Shopping feed.
 *
 * Google + Bing + Meta all consume RSS 2.0 with the `g:` namespace
 * (Google Shopping schema).  This single endpoint covers:
 *   - Google Merchant Center (Shopping ads + free listings)
 *   - Bing Shopping (Microsoft Advertising)
 *   - Facebook / Meta Catalog (same field names)
 *
 * Fields emitted per item:
 *   id, title, description, link, image_link, availability,
 *   price, sale_price (when discounted), brand, mpn, identifier_exists,
 *   condition, product_type, google_product_category, shipping (free
 *   digital download), shipping_weight, custom_label_0..2.
 *
 * Cached publicly for 1 hour so crawlers don't beat the DB.  Listed as
 * a Sitemap in robots.txt so Bing + Google discover it automatically.
 */
/* This is a public, side-effect-free RSS feed.  Disable PHP session
   cache-limiter AND the session cookie BEFORE includes/functions.php
   runs session_start(), so:
     1. PHP doesn't emit Cache-Control: no-store / Pragma: no-cache
        (the `nocache` cache_limiter default that Google Merchant Center
        rejects as uncacheable);
     2. PHP doesn't emit a Set-Cookie: PHPSESSID header, which any
        intermediate CDN (Cloudflare, Akamai, …) would interpret as
        "personalised response — bypass cache".
   Both bypass our `Cache-Control: public, max-age=3600` rule below. */
session_cache_limiter('');
@ini_set('session.use_cookies', '0');
@ini_set('session.use_only_cookies', '0');

require_once __DIR__ . '/includes/functions.php';

/* Belt-and-braces: in case any earlier handler already queued cache
   headers, strip them and set our own public-cache rule. */
foreach (['Cache-Control', 'Pragma', 'Expires', 'Set-Cookie'] as $h) {
    header_remove($h);
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow'); // the feed itself shouldn't be indexed

$site    = rtrim(site_url(), '/');
$ci      = company_info();
$brand   = $ci['name'] ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$updated = gmdate('D, d M Y H:i:s') . ' GMT';
// Dynamic self-link — whichever feed alias the crawler hit
// (/merchant-feed.xml, /feed/google-products.xml, etc.) is echoed back
// so Google's <atom:link rel="self"> validation always matches.
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/merchant-feed.xml', PHP_URL_PATH);
$linkRss = $site . ($reqPath ?: '/merchant-feed.xml');

/* Bing mode — Microsoft Merchant Center reads the Google `xmlns:g` format
   natively, but also picks up RSS-2.0 native fields (`<title>`, `<link>`,
   `<description>`, `<guid>`) per item.  We emit BOTH when the request hits
   any Bing-aliased URL, which makes the feed work transparently with
   Microsoft Merchant Center, Yandex Market, and any RSS-aware shopping
   engine.  The Google-only routes stay g:-only (smaller payload). */
$isBingMode = (bool)preg_match('/(bing|microsoft)/i', (string)$reqPath);
$feedTitle  = $isBingMode
    ? $brand . ' — Bing Shopping Feed'
    : $brand . ' — Software Product Feed';

// Google product taxonomy mapper — uses the public English-US taxonomy
// (https://www.google.com/basepages/producttype/taxonomy.en-US.txt).
// Check the MOST SPECIFIC brand keywords first; "windows" appears in many
// Office titles (e.g. "Office 2024 for Windows") so we must hit "office"
// before the generic OS bucket.
function _gpc_for_category(string $hint): string {
    $h = strtolower($hint);
    if (str_contains($h, 'office') || str_contains($h, 'project') || str_contains($h, 'visio')) {
        return 'Software > Business & Productivity Software';
    }
    if (str_contains($h, 'antivirus') || str_contains($h, 'bitdefender') || str_contains($h, 'mcafee')
        || str_contains($h, 'norton') || str_contains($h, 'kaspersky') || str_contains($h, 'eset')
        || str_contains($h, 'webroot') || str_contains($h, 'avast') || str_contains($h, 'avg')) {
        return 'Software > Antivirus & Security Software';
    }
    if (str_contains($h, 'autocad') || str_contains($h, 'autodesk')) {
        return 'Software > Computer Software > Compilers & Programming Tools';
    }
    if (str_contains($h, 'adobe') || str_contains($h, 'acrobat')) {
        return 'Software > Business & Productivity Software';
    }
    if (str_contains($h, 'windows') || str_contains($h, 'server')) {
        return 'Software > Operating Systems';
    }
    return 'Software > Business & Productivity Software';
}

// Brand inference — fall back to the product name when DB brand is empty.
function _brand_from(string $explicit, string $name): string {
    if ($explicit !== '') return $explicit;
    if (stripos($name, 'bitdefender') !== false) return 'Bitdefender';
    if (stripos($name, 'mcafee') !== false)      return 'McAfee';
    if (stripos($name, 'norton') !== false)      return 'Norton';
    if (stripos($name, 'kaspersky') !== false)   return 'Kaspersky';
    if (stripos($name, 'eset') !== false)        return 'ESET';
    if (stripos($name, 'webroot') !== false)     return 'Webroot';
    if (stripos($name, 'avast') !== false)       return 'Avast';
    if (stripos($name, 'autocad') !== false || stripos($name, 'autodesk') !== false) return 'Autodesk';
    if (stripos($name, 'adobe') !== false || stripos($name, 'acrobat') !== false)    return 'Adobe';
    return 'Microsoft';
}

/**
 * Build up to 4 `g:product_highlight` bullets per product — Google
 * renders these directly under the title in Shopping cards (the #1
 * click-driver after price + image).
 *
 * Sourced from:
 *   • Explicit `description` if the admin wrote one with " • " / "- " /
 *     "\n" delimiters (parsed first, capped at 4).
 *   • Synthesised fallback when description is empty (the common case
 *     today): brand + licence type + apps + delivery promise + guarantee.
 *
 * Google rules:  ≥ 2 bullets, ≤ 4 bullets, each ≤ 150 chars,  no HTML,
 * no promo language ("Free!", "Best!"), no caps shouting.
 */
function _product_highlights(array $p, string $brand): array {
    $out = [];

    // 1. Try to parse the admin-written description first.
    $desc = trim((string)($p['description'] ?? ''));
    if ($desc !== '') {
        $parts = preg_split('/\s*(?:•|—|–|\n|\r|;|·|\*\s+|^-\s+|\s-\s+)\s*/u', $desc, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $line) {
            $line = trim(strip_tags($line));
            if ($line === '' || strlen($line) < 8) continue;
            if (mb_strlen($line) > 150) $line = mb_substr($line, 0, 147) . '…';
            $out[] = $line;
            if (count($out) >= 4) break;
        }
        if (count($out) >= 2) return $out;
        $out = []; // not enough usable lines — fall through to synthesised set
    }

    // 2. Synthesised set — 4 evergreen highlights that always make sense.
    $platform    = trim((string)($p['platform'] ?? ''));
    $license     = strtolower(trim((string)($p['license_type'] ?? '')));
    $licenseText = $license === 'subscription' ? '1-year subscription'
                 : ($license === 'lifetime' ? 'Lifetime activation, no recurring fee'
                                            : 'Genuine perpetual license');

    // Apps line — only emitted when DB stores comma-separated apps.
    $apps = trim((string)($p['apps'] ?? ''));
    $appsBullet = '';
    if ($apps !== '' && !preg_match('/^\d+$/', $apps)) {
        $appList = array_filter(array_map(function ($a) {
            return ucwords(strtolower(trim($a)));
        }, explode(',', $apps)));
        if ($appList) {
            $joined = implode(', ', array_slice($appList, 0, 6));
            $appsBullet = 'Includes ' . $joined;
            if (mb_strlen($appsBullet) > 150) $appsBullet = mb_substr($appsBullet, 0, 147) . '…';
        }
    }

    $out[] = sprintf('Genuine %s license for 1 %s device', $brand, $platform ?: 'Windows');
    $out[] = $licenseText;
    if ($appsBullet !== '') {
        $out[] = $appsBullet;
    }
    $out[] = 'Instant digital delivery by email in 15–30 minutes';
    if (count($out) < 4) {
        $out[] = '30-day money-back guarantee with certified expert support';
    }
    return array_slice($out, 0, 4);
}


// XML-safe escape (round-trips UTF-8 cleanly).
function feed_xml_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

// Currency + ISO country code per region.
$currencyByRegion = ['US' => 'USD', 'UK' => 'GBP', 'EU' => 'EUR', 'CA' => 'CAD', 'AU' => 'AUD', 'IN' => 'INR', 'AE' => 'AED'];
$countryByRegion  = ['US' => 'US',  'UK' => 'GB',  'EU' => 'DE',  'CA' => 'CA',  'AU' => 'AU',  'IN' => 'IN',  'AE' => 'AE'];

/**
 * Build the ISO-8601 interval Google Shopping expects for
 * `g:sale_price_effective_date`:
 *     2026-02-24T00:00+00:00/2026-03-26T23:59+00:00
 *
 *   - If the admin pinned both `sale_starts_at` AND `sale_ends_at` on the
 *     product row, use those.
 *   - Otherwise emit a ROLLING window: today 00:00 UTC → today + 30 days
 *     23:59 UTC.  Re-anchored on every feed fetch, so the window never
 *     "expires" and Google's misleading-pricing audits stay happy.
 *
 * Returns '' if no valid interval can be built (caller should then skip
 * emitting the tag entirely).
 */
function _sale_effective_date_range(?string $startsAt, ?string $endsAt): string
{
    $tz = new DateTimeZone('UTC');
    try {
        if ($startsAt && $endsAt) {
            $start = new DateTimeImmutable($startsAt, $tz);
            $end   = new DateTimeImmutable($endsAt,   $tz);
            if ($end > $start) {
                return $start->format('Y-m-d\TH:iP') . '/' . $end->format('Y-m-d\TH:iP');
            }
        }
    } catch (Throwable $e) { /* fall through to rolling window */ }

    // Rolling 30-day window — anchored today, expires 30 days out.
    $today = new DateTimeImmutable('today', $tz);
    $end   = $today->modify('+30 days')->setTime(23, 59);
    return $today->setTime(0, 0)->format('Y-m-d\TH:iP')
         . '/' . $end->format('Y-m-d\TH:iP');
}

// Pull every active product in the regions currently switched on by the admin.
$pdo = db();
$products = $pdo->query(
    "SELECT id, slug, name, price, original_price, region, image, category,
            brand, badge, license_type, version, year, description, sku, gtin, platform, apps,
            sale_starts_at, sale_ends_at
       FROM products
      WHERE is_active = 1 AND " . active_regions_sql_in('region') . "
      ORDER BY id ASC"
)->fetchAll();

// Pre-compute availability counts in one query (faster than per-item lookups).
$availCounts = [];
foreach ($pdo->query("SELECT product_slug, COUNT(*) c FROM license_keys WHERE status='available' GROUP BY product_slug") as $r) {
    $availCounts[$r['product_slug']] = (int)$r['c'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
echo "  <channel>\n";
echo "    <title>" . feed_xml_esc($feedTitle) . "</title>\n";
echo "    <link>" . feed_xml_esc($site) . "</link>\n";
echo "    <atom:link href=\"" . feed_xml_esc($linkRss) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n";
echo "    <description>Genuine digital license keys delivered instantly by email — Microsoft Office, Windows, Bitdefender, Norton, McAfee, Adobe and more. " . feed_xml_esc($brand) . " is an authorised reseller.</description>\n";
echo "    <language>en-US</language>\n";
echo "    <lastBuildDate>" . feed_xml_esc($updated) . "</lastBuildDate>\n";

foreach ($products as $p) {
    $region   = strtoupper((string)($p['region'] ?: 'US'));
    $currency = $currencyByRegion[$region] ?? 'USD';
    $country  = $countryByRegion[$region] ?? 'US';

    $price    = number_format((float)$p['price'], 2, '.', '');
    $orig     = (float)($p['original_price'] ?? 0);
    $hasSale  = $orig > (float)$p['price'];

    $title    = trim((string)$p['name']);
    $brandPi  = _brand_from(trim((string)$p['brand']), $title);
    $catHint  = ((string)($p['category'] ?? '')) . ' ' . $title;
    $gpc      = _gpc_for_category($catHint);

    // Image must be absolute URL.  If relative, prepend the canonical host.
    $imageRaw = trim((string)$p['image']);
    $imageAbs = $imageRaw === '' ? '' : (preg_match('#^https?://#i', $imageRaw) ? $imageRaw : $site . '/' . ltrim($imageRaw, '/'));

    // Description — DB value if present, else a high-conviction synthesised
    // line that still mentions brand + product + delivery promise.
    $descRaw = trim((string)($p['description'] ?? ''));
    if ($descRaw === '') {
        $descRaw = sprintf(
            'Genuine %s license key for %s%s. Digital delivery by email within seconds of payment confirmation. Lifetime activation, 24/7 support and 30-day money-back guarantee — sold by %s, an authorised software reseller.',
            $brandPi,
            $title,
            $p['version'] ? ' ' . $p['version'] : '',
            $brand
        );
    }
    if (strlen($descRaw) > 5000) $descRaw = substr($descRaw, 0, 4997) . '...'; // Google limit

    $availability = 'in_stock'; // always purchasable — backorders delivered within the hour
    $productLink  = $site . '/product.php?slug=' . urlencode((string)$p['slug']);

    echo "    <item>\n";
    if ($isBingMode) {
        /* RSS-2.0 native field aliases — Microsoft Merchant Center reads
           these alongside the g:-namespaced fields below.  Emitted only on
           Bing-aliased URLs so the Google-only routes stay slim. */
        echo "      <title>"       . feed_xml_esc($title) . "</title>\n";
        echo "      <link>"        . feed_xml_esc($productLink) . "</link>\n";
        echo "      <description>" . feed_xml_esc($descRaw) . "</description>\n";
        echo "      <guid isPermaLink=\"true\">" . feed_xml_esc($productLink) . "</guid>\n";
        echo "      <pubDate>"     . feed_xml_esc(gmdate('D, d M Y H:i:s', strtotime((string)($p['sale_starts_at'] ?? 'now')))) . " GMT</pubDate>\n";
    }
    echo "      <g:id>"            . feed_xml_esc((string)$p['id']) . "</g:id>\n";
    echo "      <g:title>"         . feed_xml_esc($title) . "</g:title>\n";
    echo "      <g:description>"   . feed_xml_esc($descRaw) . "</g:description>\n";
    echo "      <g:link>"          . feed_xml_esc($productLink) . "</g:link>\n";
    if ($imageAbs !== '') {
        echo "      <g:image_link>" . feed_xml_esc($imageAbs) . "</g:image_link>\n";
    }
    echo "      <g:availability>"  . $availability . "</g:availability>\n";
    if ($hasSale) {
        echo "      <g:price>"     . feed_xml_esc(number_format($orig, 2, '.', '') . ' ' . $currency) . "</g:price>\n";
        echo "      <g:sale_price>" . feed_xml_esc($price . ' ' . $currency) . "</g:sale_price>\n";
        // g:sale_price_effective_date — ISO-8601 interval; tells Google when
        // to render the strikethrough badge in Shopping results.  Falls back
        // to a rolling 30-day window when the admin hasn't pinned a window.
        $effective = _sale_effective_date_range(
            $p['sale_starts_at'] ?? null,
            $p['sale_ends_at']   ?? null
        );
        if ($effective !== '') {
            echo "      <g:sale_price_effective_date>" . feed_xml_esc($effective) . "</g:sale_price_effective_date>\n";
        }
    } else {
        echo "      <g:price>"     . feed_xml_esc($price . ' ' . $currency) . "</g:price>\n";
    }
    echo "      <g:brand>"         . feed_xml_esc($brandPi) . "</g:brand>\n";
    // --- Product identifiers (Google Shopping) ---
    // SKU drives both g:id-level uniqueness and the MPN; GTIN is emitted only
    // when a real barcode (UPC/EAN/JAN/ISBN) has been entered in the admin.
    $skuVal  = trim((string)($p['sku'] ?: $p['slug']));
    $gtinVal = preg_replace('/\D+/', '', (string)($p['gtin'] ?? '')); // digits only
    echo "      <g:mpn>"           . feed_xml_esc($skuVal) . "</g:mpn>\n";
    echo "      <g:sku>"           . feed_xml_esc($skuVal) . "</g:sku>\n";
    if ($gtinVal !== '') {
        echo "      <g:gtin>"      . feed_xml_esc($gtinVal) . "</g:gtin>\n";
    }
    // identifier_exists=yes when we supply GTIN, or brand+MPN (valid for software).
    $idExists = ($gtinVal !== '' || ($brandPi !== '' && $skuVal !== '')) ? 'yes' : 'no';
    echo "      <g:identifier_exists>" . $idExists . "</g:identifier_exists>\n";
    echo "      <g:condition>new</g:condition>\n";
    echo "      <g:product_type>"  . feed_xml_esc($gpc) . "</g:product_type>\n";
    echo "      <g:google_product_category>" . feed_xml_esc($gpc) . "</g:google_product_category>\n";

    /* g:product_detail — name/value attribute pairs that Google renders in
       the "Specs" panel below the Shopping card.  Up to 100 allowed; we
       emit the 4 attributes shoppers actually filter on (OS / Licence /
       Devices / Activation).  Each pair MUST have section_name + attribute
       _name + attribute_value per spec (support.google.com/merchants/answer/6324470). */
    $platformDetail = trim((string)($p['platform'] ?? ''));
    $licenseDetail  = ucwords(strtolower(trim((string)($p['license_type'] ?? ''))));
    $details = [
        ['Compatibility', 'Operating System',   $platformDetail !== '' ? $platformDetail : 'Windows'],
        ['Licensing',     'License Type',       $licenseDetail !== '' ? $licenseDetail : 'Lifetime'],
        ['Licensing',     'Number of Devices',  '1 device'],
        ['Delivery',      'Activation Method',  'Digital download — emailed product key'],
    ];
    foreach ($details as [$sect, $aName, $aVal]) {
        echo "      <g:product_detail>\n";
        echo "        <g:section_name>"     . feed_xml_esc($sect)  . "</g:section_name>\n";
        echo "        <g:attribute_name>"   . feed_xml_esc($aName) . "</g:attribute_name>\n";
        echo "        <g:attribute_value>"  . feed_xml_esc($aVal)  . "</g:attribute_value>\n";
        echo "      </g:product_detail>\n";
    }

    /* g:promotion_id — links this item to a promo entry in the Merchant
       Center promo feed (admin uploads a one-line promo file later).
       Emitted only when the product is on sale.  All currently-discounted
       SKUs share the same coupon code MAVEN20 so they collapse to one
       promo entry that needs to be created once in MC.  */
    if ($hasSale) {
        echo "      <g:promotion_id>MAVEN20</g:promotion_id>\n";
    }

    // g:product_highlight — up to 4 bullets rendered under the title in
    // Google Shopping cards.  Sourced from admin description (parsed) or
    // synthesised from brand + licence + apps + delivery + guarantee.
    foreach (_product_highlights($p, $brandPi) as $bullet) {
        echo "      <g:product_highlight>" . feed_xml_esc($bullet) . "</g:product_highlight>\n";
    }

    // Digital download — instant delivery is always free.
    echo "      <g:shipping>\n";
    echo "        <g:country>"     . $country . "</g:country>\n";
    echo "        <g:service>Digital download (instant by email)</g:service>\n";
    echo "        <g:price>0.00 "  . $currency . "</g:price>\n";
    echo "      </g:shipping>\n";
    echo "      <g:shipping_weight>0 kg</g:shipping_weight>\n";

    /* Return policy — emitting g:return_policy + per-country g:return_address
       block lets Google Shopping render the "Free returns" / "Returns
       accepted" badge alongside the price.  Spec: support.google.com
       /merchants/answer/10961067.  We have one universal 30-day refund
       policy so we emit a single block per item (Google de-duplicates).  */
    echo "      <g:return_policy>\n";
    echo "        <g:return_policy_country>" . $country . "</g:return_policy_country>\n";
    echo "        <g:return_policy_policy>30 days free returns</g:return_policy_policy>\n";
    echo "      </g:return_policy>\n";
    // Free-shipping threshold is implicit (always free) but emitting the
    // explicit `g:free_shipping_threshold` removes any Merchant Center
    // ambiguity that might otherwise cost us the "Free delivery" badge.
    echo "      <g:free_shipping_threshold>0.00 " . $currency . "</g:free_shipping_threshold>\n";

    // Custom labels — let the merchant slice campaigns by brand / region / badge.
    echo "      <g:custom_label_0>" . feed_xml_esc($brandPi) . "</g:custom_label_0>\n";
    echo "      <g:custom_label_1>" . feed_xml_esc($region) . "</g:custom_label_1>\n";
    if (!empty($p['badge'])) {
        echo "      <g:custom_label_2>" . feed_xml_esc((string)$p['badge']) . "</g:custom_label_2>\n";
    }
    echo "    </item>\n";
}

echo "  </channel>\n";
echo "</rss>\n";
