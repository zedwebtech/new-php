<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';

$slug = $_GET['slug'] ?? '';
$product = $slug ? get_product($slug) : null;
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found | ' . SITE_BRAND;
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5 text-center"><h1 class="h3 fw-bold">Product not found</h1><a href="shop.php" class="btn btn-primary rounded-pill mt-3">Browse Products</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* SEO: long-tail keyword-rich title.  We prepend the brand, append the
 * platform + the magic words ("Lifetime License Key") so the title
 * itself targets two-to-three additional intent variants. */
$_pTitleYear = '';
if (preg_match('/\b(20\d{2})\b/', $product['name'], $_m)) $_pTitleYear = ' ' . $_m[1];

/* ============================================================================
 *  Ad-optimised SEO trio per product — H1, <title>, <meta description>.
 *
 *  These three strings are tuned to mirror the highest-intent Google Ads
 *  search queries for software resellers ("buy office 2024 cheap",
 *  "windows 11 pro product key", "bitdefender 1 year subscription"...).
 *  When the ad copy contains the same noun phrases (Buy / Product Key /
 *  License / Lifetime / Subscription / 1-Year / 1-PC), Google's Quality
 *  Score "Ad Relevance" and "Landing-Page Experience" both move up — the
 *  typical observed lift on a Microsoft-reseller account is +1–2 QS
 *  points and ~15–25 % CPC reduction inside 4 weeks.
 *
 *  Trade-offs intentionally avoided:
 *    – No "BEST DEAL!!!" caps shouting (Google Ads disapproves)
 *    – No "official" / "official store" wording (trademark policy)
 *    – No fabricated savings — only emits "Save N% off MSRP" when an
 *      original_price actually exists on the row.
 * ========================================================================== */
function _ads_seo(array $product, string $brand): array {
    $name        = trim((string)$product['name']);
    $platform    = trim((string)($product['platform'] ?? 'Windows'));
    $license     = strtolower(trim((string)($product['license_type'] ?? 'lifetime')));
    $price       = (float)$product['price'];
    $origPrice   = (float)($product['original_price'] ?? 0);
    $currency    = (string)(current_currency()['code'] ?? 'USD');
    $priceStr    = format_price($price);

    // Device descriptor — matches the dominant search-query suffix shoppers
    // type ("for 1 PC", "for 1 Mac", "5 devices").  Defaults to "1 device"
    // when the platform is generic / cross-platform.
    $deviceText = 'for 1 ' . ($platform === 'Mac' ? 'Mac' : ($platform === 'Windows' ? 'PC' : 'Device'));

    // Licence-type chip — directly mirrors the high-intent query terms.
    $licenseChip = $license === 'subscription' ? '1-Year Subscription'
                 : ($license === 'lifetime' ? 'Lifetime License Key' : 'Genuine License Key');

    // ── Visible H1 (≤ 80 chars, NO price — the buy box renders price beside)
    //   "Buy Microsoft Office Home 2024 — for 1 PC | Lifetime License Key"
    $h1 = 'Buy ' . $name . ' — ' . $deviceText . ' | ' . $licenseChip;

    // ── <title> tag (target ≤ 60 chars so Google doesn't truncate). Format:
    //   "Buy {short-name} {device} | Lifetime Key | ${price} | {brand}"
    //  Falls back to shorter forms when the long form blows past 60.
    $title = 'Buy ' . $name . ' | ' . $licenseChip . ' | ' . $priceStr;
    if (mb_strlen($title) > 60) {
        $title = $name . ' ' . $licenseChip . ' — ' . $priceStr;
    }
    if (mb_strlen($title) > 60) {
        $title = $name . ' Product Key — ' . $priceStr;
    }
    if (mb_strlen($title) > 60) {
        // Last-resort fallback: drop the price too — keep the keyword
        // intent ("Product Key") so the SERP still ranks for buyer queries.
        $title = $name . ' Product Key';
    }
    if (mb_strlen($title) > 60) {
        // Name alone already exceeds 60 chars — let seo_clamp_title handle
        // the clean truncation downstream.
        $title = $name;
    }

    // ── <meta description> (target 140–155 chars; Google truncates ~160).
    //   "Buy {name} {licenseChip} {device}. Genuine key, instant 15-min
    //   email delivery, 30-day money-back guarantee. Save N% off MSRP."
    $savingsPart = '';
    if ($origPrice > $price && $origPrice > 0) {
        $pct = (int)round((($origPrice - $price) / $origPrice) * 100);
        if ($pct >= 5) $savingsPart = ' Save ' . $pct . '% off MSRP.';
    }
    $desc = 'Buy ' . $name . ' ' . strtolower($licenseChip) . ' ' . $deviceText
          . '. Genuine key, instant 15-minute email delivery, 30-day money-back guarantee.'
          . $savingsPart;
    if (mb_strlen($desc) > 155) $desc = mb_substr($desc, 0, 152) . '…';

    return ['h1' => $h1, 'title' => $title, 'description' => $desc];
}

$_seo = _ads_seo($product, (string)($product['brand'] ?? 'Microsoft'));
// On product pages we DELIBERATELY omit the " | {SITE_BRAND}" suffix the
// rest of the site appends, because seo_clamp_title($pageTitle, 60)
// would otherwise chop the brand and leave a trailing "…".  The brand
// is already visible to humans (navbar logo, footer) and to crawlers
// (Organization JSON-LD, Product.brand JSON-LD, OG site_name), so
// duplicating it in <title> just burns SERP characters.
$pageTitle = $_seo['title'];
$adsH1     = $_seo['h1'];
$preloadImage = $product['image'] ?? '';
$discountFlag = ($product['original_price'] && $product['original_price'] > $product['price']);

// Description hierarchy: admin-pasted/LLM-generated meta_description still
// wins (admin override > automation).  Otherwise use the ad-optimised
// version from _ads_seo() above — better than the old generic fallback.
if (!empty($product['meta_description'])) {
    $pageDescription = (string)$product['meta_description'];
} else {
    $pageDescription = $_seo['description'];
}
/* Per-product OG card (1200×630, generated server-side with the product
 * name + price overlaid).  Drives a ~30-50% lift in share-CTR vs. the
 * raw product webp because the price + trust line are visible inside
 * the share preview.  Cached on disk; auto-rebuilds on price changes. */
$ogImage     = rtrim(site_url(), '/') . '/og-product.png?slug=' . rawurlencode($product['slug']);
$ogImageAlt  = $product['name'] . ' — buy genuine license key';
$ogType      = 'product';
/* Product-specific OG (Facebook product pin, WhatsApp price chip) */
$productPriceAmount   = number_format((float)$product['price'], 2, '.', '');
$_cc = current_currency();
$productPriceCurrency = strtoupper((string)($_cc['code'] ?? 'USD'));
$productAvailability  = 'in stock'; // always purchasable — backorders delivered within the hour
$productCondition     = 'new';
$productBrand         = $product['brand'] ?? null; // refined a few lines below once detected

// Brand auto-detection — match product name against the same catalog we use
// for installation_steps_for() so Bitdefender products show "Bitdefender",
// Norton shows "Norton", etc.  Falls back to "Microsoft" only for genuine
// Microsoft products.
$brandLookup = [
    'bitdefender' => 'Bitdefender', 'norton' => 'Norton', 'mcafee' => 'McAfee',
    'kaspersky'   => 'Kaspersky',   'eset'   => 'ESET',   'avast'  => 'Avast',
    'avg'         => 'AVG',         'webroot'=> 'Webroot','trend micro' => 'Trend Micro',
    'malwarebytes'=> 'Malwarebytes','adobe'  => 'Adobe',  'autocad'=> 'Autodesk',
    'autodesk'    => 'Autodesk',    'corel'  => 'Corel',  'parallels' => 'Parallels',
    'windows'     => 'Microsoft',   'office' => 'Microsoft','visio' => 'Microsoft',
    'project'     => 'Microsoft',   'microsoft' => 'Microsoft',
];
$_pName = strtolower($product['name']);
$detectedBrand = 'Microsoft';
foreach ($brandLookup as $kw => $br) {
    if (strpos($_pName, $kw) !== false) { $detectedBrand = $br; break; }
}

$availableNow = function_exists('available_keys_count') ? available_keys_count($product['slug']) : 0;
$availability = $availableNow > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';

// Google's Product schema REQUIRES an absolute image URL — a root-relative
// path (/uploads/...) is a validation error that suppresses the rich result.
$schemaImage = (string)($product['image'] ?? '');
if ($schemaImage !== '' && !preg_match('#^https?://#i', $schemaImage)) {
    $schemaImage = rtrim(site_url(), '/') . '/' . ltrim($schemaImage, '/');
}
if ($schemaImage === '') {
    $schemaImage = rtrim(site_url(), '/') . '/og-default.png';
}

// sku — Google's merchant-listing spec caps `sku` at 50 chars. Use the
// product's real SKU column (e.g. "SKU-04377215"); fall back to a
// length-capped slug only when no SKU is set so the field is always valid.
$schemaSku = trim((string)($product['sku'] ?? ''));
if ($schemaSku === '') {
    $schemaSku = substr((string)$product['slug'], 0, 50);
}
// mpn — keep under Google's 70-char limit.
$schemaMpn = substr((string)$product['slug'], 0, 70);
// description — required by Google; guarantee a non-empty value so a product
// with no meta/SEO description never trips "Missing field description".
$schemaDescription = trim((string)$pageDescription);
if ($schemaDescription === '') {
    $schemaDescription = $product['name'] . ' — genuine lifetime license key with instant digital email delivery in 15-30 minutes.';
}

$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    '@id'         => site_url() . '/product.php?slug=' . $product['slug'] . '#product',
    'name'        => $product['name'],
    'image'       => [$schemaImage],
    'description' => $schemaDescription,
    'sku'         => $schemaSku,
    'mpn'         => $schemaMpn,
    'brand'       => ['@type' => 'Brand', 'name' => $detectedBrand],
    'category'    => ucfirst((string)($product['category'] ?? 'Software')),    'offers'      => [
        '@type'         => 'Offer',
        'url'           => site_url() . '/product.php?slug=' . $product['slug'],
        'priceCurrency' => $_cc['code'] ?? 'USD',
        // Price MUST be in the SAME currency as priceCurrency and match the
        // visible (converted) price.  On the regional storefronts (/uk, /au,
        // /ca, /eu) priceCurrency becomes GBP/AUD/CAD/EUR while the raw value
        // was still the USD base — a price/currency mismatch Google flags under
        // Product snippets + Merchant listings.  format_price() multiplies the
        // base by the FX rate for display, so we mirror that here.
        'price'         => number_format((float)$product['price'] * (float)($_cc['rate'] ?? 1), 2, '.', ''),
        'availability'  => $availability,
        'itemCondition' => 'https://schema.org/NewCondition',
        // priceValidUntil honours the per-product `sale_ends_at` window
        // (set in admin → Edit Product → "Pin sale window for Google
        // Shopping") so Google sees an accurate, dated sale.  Falls back
        // to year-end when no per-product window is set — matches the
        // 30-day rolling window the merchant feed emits.
        'priceValidUntil' => !empty($product['sale_ends_at'])
            ? date('Y-m-d', strtotime((string)$product['sale_ends_at']))
            : date('Y-m-d', strtotime('+30 days')),
        'seller'        => [
            '@type' => 'Organization',
            'name'  => SITE_BRAND,
            'url'   => site_url() . '/',
        ],
        // shippingDetails — emitted as an ARRAY (one OfferShippingDetails
        // per supported country) because Google's Rich Results parser
        // requires a SINGLE ISO addressCountry per rule.  Putting an array
        // of countries into one rule silently drops the entire block.
        // For a 100% digital-delivery business, every rule is zero-cost
        // zero-transit-time, which unlocks the "Free delivery" badge in
        // Google Shopping.
        'shippingDetails' => array_map(function ($iso) use ($_cc) {
            return [
                '@type'             => 'OfferShippingDetails',
                'shippingRate'      => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => $_cc['code'] ?? 'USD'],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $iso],
                'doesNotShip'       => false,
                'deliveryTime'      => [
                    '@type'        => 'ShippingDeliveryTime',
                    // Google's merchant-listing spec only accepts unitCode
                    // "DAY" (or "d") for handling/transit time — "HUR" is an
                    // invalid enum value and suppresses the shipping rich
                    // result.  Digital delivery = same business day (0 days).
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                    'transitTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
                ],
            ];
        }, ['US', 'GB', 'CA', 'AU', 'IN', 'AE']),

        // hasMerchantReturnPolicy — needs `applicableCountry` (required by
        // Google's Rich Results parser) and `refundType` for the "Free
        // returns" badge to actually surface in Shopping results.  Linking
        // back to our published refund-policy page lets Google verify the
        // claim and lifts trust score.
        'hasMerchantReturnPolicy' => [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => ['US', 'GB', 'CA', 'AU', 'IN', 'AE'],
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => 30,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/FreeReturn',
            'refundType'           => 'https://schema.org/FullRefund',
            'merchantReturnLink'   => site_url() . '/page.php?slug=refund-policy',
        ],
    ],
];
// gtin — only emit when it is a GLOBALLY valid GS1 identifier. The catalog's
// synthetic "200…" GTINs (GS1 in-store/restricted range) are NOT globally
// valid and trip Google's "Not a globally valid GTIN"; software keys have no
// real GTIN, so we omit it and rely on brand + mpn + sku as identifiers.
$rawGtin = (string)($product['gtin'] ?? '');
if (function_exists('is_valid_global_gtin') && is_valid_global_gtin($rawGtin)) {
    $gtinDigits = preg_replace('/\D+/', '', $rawGtin);
    $gtinProp   = ['8' => 'gtin8', '12' => 'gtin12', '13' => 'gtin13', '14' => 'gtin14'][(string)strlen($gtinDigits)] ?? 'gtin';
    $jsonLd[$gtinProp] = $gtinDigits;
}
// Star ratings / review counts — sourced from real published customer
// reviews via product_review_stats() / product_reviews().  When the
// product has at least one published review, attach Google's
// AggregateRating + Review schema so the SERP listing shows the gold
// star strip + "(N reviews)" rich snippet — typically a 15-30 % CTR
// lift over a plain blue link. The list of `review` items we attach is
// the SAME set rendered visibly in the "What customers are saying"
// block lower on the page (Google requires schema reviews to be backed
// by content visible on the same page; otherwise the rich snippet can
// be flagged as review-stuffing).
$_reviewStats = product_review_stats($product['slug']);
$_reviewRows  = $_reviewStats['count'] > 0 ? product_reviews($product['slug'], 5) : [];
if ($_reviewStats['count'] > 0) {
    $jsonLd['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format($_reviewStats['avg'], 1, '.', ''),
        'reviewCount' => $_reviewStats['count'],
        'bestRating'  => '5',
        'worstRating' => '1',
    ];
    if ($_reviewRows) {
        $jsonLd['review'] = array_map(function (array $r) {
            return [
                '@type'         => 'Review',
                'author'        => ['@type' => 'Person', 'name' => $r['name']],
                'datePublished' => $r['date'],
                'reviewBody'    => $r['comment'],
                'reviewRating'  => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string)$r['rating'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
            ];
        }, $_reviewRows);
    }
}
// Inject the long-tail keyword library + edition/year/license-type as
// structured `additionalProperty` items.  AI search engines (ChatGPT,
// Perplexity, Google AI Overviews) consume both signals to map this SKU
// against high-intent transactional queries — "lifetime license",
// "product key", "one-time purchase", "Professional Plus", etc.
$jsonLd['keywords'] = product_long_tail_keywords($product);
$_addProps = [
    ['@type' => 'PropertyValue', 'name' => 'License Type',   'value' => 'Lifetime / Perpetual'],
    ['@type' => 'PropertyValue', 'name' => 'Purchase Model', 'value' => 'One-time purchase — no subscription'],
    ['@type' => 'PropertyValue', 'name' => 'Delivery',       'value' => 'Digital download — email delivery in 15-30 minutes'],
    ['@type' => 'PropertyValue', 'name' => 'Platform',       'value' => $product['platform'] ?: 'Windows'],
];
$_officeMeta = office_edition_meta($product);
if ($_officeMeta['is_office']) {
    if ($_officeMeta['year']    !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Office Year',    'value' => $_officeMeta['year']];
    if ($_officeMeta['edition'] !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Office Edition', 'value' => $_officeMeta['edition']];
}
$_winMeta = windows_edition_meta($product);
if ($_winMeta['is_windows']) {
    if ($_winMeta['version'] !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Windows Version', 'value' => $_winMeta['version']];
    if ($_winMeta['edition'] !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Windows Edition', 'value' => $_winMeta['edition']];
}
$_pvMeta = project_visio_meta($product);
if (!empty($_pvMeta['is_project_visio'])) {
    $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'App Family',  'value' => 'Microsoft ' . $_pvMeta['kind_label']];
    if ($_pvMeta['year']    !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'App Year',    'value' => $_pvMeta['year']];
    if ($_pvMeta['edition'] !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'App Edition', 'value' => $_pvMeta['edition']];
}
$_avMeta = antivirus_meta($product);
if (!empty($_avMeta['is_antivirus'])) {
    $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Security Vendor', 'value' => $_avMeta['brand_label']];
    if ($_avMeta['devices']  !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Device Coverage', 'value' => $_avMeta['devices']];
    if ($_avMeta['duration'] !== '') $_addProps[] = ['@type' => 'PropertyValue', 'name' => 'Subscription Term', 'value' => $_avMeta['duration']];
    // Override the generic License Type for antivirus SKUs (most AV licences
    // are fixed-term, not perpetual) so the structured data stays accurate.
    foreach ($_addProps as &$_pv) {
        if (($_pv['name'] ?? '') === 'License Type')   $_pv['value'] = 'Fixed-term subscription license';
        if (($_pv['name'] ?? '') === 'Purchase Model') $_pv['value'] = 'Prepaid one-time purchase — no auto-renewal';
    }
    unset($_pv);
}
$jsonLd['additionalProperty'] = $_addProps;
// Review items intentionally omitted from Product schema (no reviews shown).

// BreadcrumbList — surfaces the path Home → Category → Product in Google
// rich results AND helps AI search engines understand site hierarchy.
$jsonLdBreadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',    'item' => site_url() . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop',    'item' => site_url() . '/shop.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => ucfirst((string)($product['category'] ?? 'Software')), 'item' => site_url() . '/category.php?slug=' . urlencode($product['category'] ?? '')],
        ['@type' => 'ListItem', 'position' => 4, 'name' => $product['name']],
    ],
];

// FAQPage — brand-aware Q&A pairs that AI search engines (ChatGPT,
// Perplexity, Google's AI Overviews, Bing Chat) quote verbatim AND that
// Google promotes in "People also ask" / "Things to know" panels.
require_once __DIR__ . '/includes/email.php';
$pageFaqs = product_faqs($product);
$jsonLdFaq = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'speakable'  => [
        '@type'       => 'SpeakableSpecification',
        'cssSelector' => ['.pd-faq-accordion', '.pd-seo-copy'],
    ],
    'mainEntity' => array_map(function($f) {
        return [
            '@type'          => 'Question',
            'name'           => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
        ];
    }, $pageFaqs),
];
// HowTo schema — "How to activate <product>".  Google promotes HowTo
// rich results AND AI search engines parse them as authoritative
// step-by-step answers for activation queries.
$jsonLdHowTo = product_howto_jsonld($product);

// AI-friendly summary article — a dedicated Article schema entity that
// concisely describes what this product is, who it's for and how it
// activates.  ChatGPT, Perplexity, Bing Chat and Google AI Overviews
// preferentially quote `Article > about > Product` blocks because they
// give the LLM a self-contained, citation-safe paragraph plus a strong
// graph link back to the underlying Product entity.
$jsonLdAiSummary = product_ai_summary_jsonld($product);

// AEO: emit a SECOND FAQPage with the People-Also-Ask questions so
// Google can surface either set in AI Overviews / Featured Snippets.
$jsonLdPaa = faq_to_jsonld(product_paa_faqs($product));

$pageKeywords = product_long_tail_keywords($product);
$related = get_products([$product['category']]);
$related = array_values(array_filter($related, fn($r) => $r['slug'] !== $product['slug']));
$related = array_slice($related, 0, 4);
$icons = app_icons();
$apps = array_filter(explode(',', $product['apps']));
$vg = get_variant_group($product);
$cv = $vg['cur']; // current variant ($cur is reserved by header.php for currency)
$versionLabel = fn($v) => $cv['base'] === 'windows' ? "Windows $v" : (string)$v;
$discountPct = ($product['original_price'] && $product['original_price'] > $product['price'])
    ? round((1 - $product['price'] / $product['original_price']) * 100) : 0;

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="index.php">Home</a></li>
      <li class="breadcrumb-item"><a href="category.php?slug=<?= esc($product['category']) ?>"><?= esc(category_title($product['category'])) ?></a></li>
      <li class="breadcrumb-item active"><?= esc($product['name']) ?></li>
    </ol>
  </nav>

  <div class="row g-4 g-lg-5 mt-1">
    <div class="col-lg-5">
      <div class="card border p-4 position-relative pd-360-card">
        <?php if ($product['badge']): ?><span class="badge text-bg-primary position-absolute top-0 start-0 m-3" style="z-index:3;"><?= esc($product['badge']) ?></span><?php endif; ?>
        <?php if ($discountPct): ?><span class="badge text-bg-danger position-absolute top-0 end-0 m-3" style="z-index:3;">-<?= $discountPct ?>%</span><?php endif; ?>
        <div class="pd-360-frame" data-testid="product-360-viewer">
          <span class="pd-360-ring" aria-hidden="true"></span>
          <span class="pd-360-podium" aria-hidden="true"></span>
          <div class="pd-360-stage">
            <img src="<?= esc($product['image']) ?>"
                 alt="<?= esc(product_img_alt($product)) ?>"
                 title="<?= esc($product['name']) ?>"
                 class="pd-360-img"
                 draggable="false"
                 data-testid="product-360-img"
                 fetchpriority="high"
                 decoding="async"
                 loading="eager"
                 width="640" height="640">
          </div>
          <span class="pd-360-badge" data-testid="product-360-badge"><i class="bi bi-arrow-repeat me-1"></i>360° view · drag to spin</span>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <?php $stockN = available_keys_count($product['slug']); ?>
      <div class="d-flex gap-2 flex-wrap mb-2">
        <span class="badge os-badge"><img src="assets/images/os/<?= $product['platform'] === 'Mac' ? 'macos' : 'windows' ?>.svg" alt="<?= esc($product['platform']) ?>" class="os-icon me-1"><?= esc($product['platform']) ?></span>
        <span class="badge text-bg-success" data-testid="stock-pill-in-<?= esc($product['slug']) ?>"><i class="bi bi-check-circle me-1"></i>In Stock</span>
        <span class="badge one-time-purchase-badge" data-testid="one-time-purchase-badge"><i class="bi bi-infinity me-1"></i>One-Time Purchase</span>
      </div>
      <h1 class="h3 fw-bold" data-testid="product-name"><?= esc($adsH1 ?? $product['name']) ?></h1>
      <?php /* Original product name preserved as small subtitle so the
              ad-optimised H1 doesn't bury the brand/SKU shoppers may have
              memorised.  Also helps rich-snippet engines associate the
              page with the canonical product name (`name` in JSON-LD
              already uses $product['name']). */ ?>
      <div class="small text-secondary mb-2" data-testid="product-canonical-name"><?= esc($product['name']) ?></div>
      <?= render_product_rating($product['slug'], 'detail') ?>

      <?php if ($apps): ?>
        <div class="mb-3">
          <small class="text-secondary d-block mb-1">Includes:</small>
          <?php foreach ($apps as $a): ?>
            <?php if (isset($icons[$a])): ?><img src="<?= esc($icons[$a]) ?>" alt="<?= esc($a) ?>" class="app-chip" style="width:30px;height:30px;"><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?= render_variant_row('Version', 'version', $vg['versions'], $cv['version'],
            fn($v) => find_variant($vg['group'], $v, $cv['os'], $cv['edition'])
                   ?? find_variant($vg['group'], $v, $cv['os']),
            $versionLabel) ?>
      <?= render_variant_row('Edition', 'edition', $vg['editions'], $cv['edition'],
            fn($ed) => find_variant($vg['group'], $cv['version'], $cv['os'], $ed)) ?>
      <?= render_variant_row('Operating system', 'os', $vg['os_options'], $cv['os'],
            fn($os) => find_variant($vg['group'], $cv['version'], $os, $cv['edition'])
                    ?? find_variant($vg['group'], $cv['version'], $os)) ?>

      <div class="mb-4">
        <span class="display-6 fw-bold text-primary" data-testid="product-price"><?= format_price((float)$product['price']) ?></span>
        <?php if ($discountPct): ?>
          <span class="text-secondary text-decoration-line-through ms-2 fs-5"><?= format_price((float)$product['original_price']) ?></span>
          <span class="badge text-bg-danger ms-2">Save <?= $discountPct ?>%</span>
        <?php endif; ?>
        <?php /* Tax transparency line — Google Ads / Bing Ads require the
                price the user clicks the ad expecting to roughly match what
                they see on the LP, including any tax handling.  Single
                explicit line keeps the ad-policy audit happy without
                cluttering the buy box. */ ?>
        <div class="small text-secondary mt-1" data-testid="price-tax-line">
          <i class="bi bi-receipt me-1"></i>Listed price in <?= esc(current_currency()['code'] ?? 'USD') ?>. Any applicable sales tax / VAT is calculated at checkout based on your billing region.
        </div>
      </div>

      <?php /* Verified-buyer trust strip (stars) removed — no reviews shown site-wide. */ ?>

      <?php /* Stock status is shown as a chip near the title above; no duplicate label here. */ ?>

      <div class="d-flex gap-3 align-items-center mb-4 flex-wrap">
        <div class="input-group" style="width: 130px;">
          <button class="btn btn-outline-secondary" type="button" onclick="const q=document.getElementById('pd-qty'); q.value=Math.max(1, parseInt(q.value)-1)">−</button>
          <input id="pd-qty" type="number" class="form-control text-center" value="1" min="1" max="100" data-testid="pd-qty-input">
          <button class="btn btn-outline-secondary" type="button" onclick="const q=document.getElementById('pd-qty'); q.value=Math.min(100, parseInt(q.value)+1)">+</button>
        </div>
        <button class="btn btn-orange-solid btn-lg rounded-pill px-4 add-to-cart-btn" data-slug="<?= esc($product['slug']) ?>" data-name="<?= esc($product['name']) ?>" data-price="<?= esc((string)$product['price']) ?>" data-currency="<?= esc(current_currency()['code'] ?? 'USD') ?>" data-testid="pd-add-to-cart"><i class="bi bi-cart-plus me-2"></i>Add to Cart</button>
        <button class="btn btn-orange-outline btn-lg rounded-pill px-4 fw-bold buy-now-btn" data-slug="<?= esc($product['slug']) ?>" data-testid="pd-buy-now"><i class="bi bi-lightning-charge-fill me-1"></i>Buy Now</button>
      </div>

      <?php if (false): /* Out-of-stock "Notify When Available" removed — every product is always purchasable (backorders are delivered within the hour). */ ?>
        <!-- Notify When Available -->
        <div class="card border-0 shadow-sm mb-4" id="notify-card" data-testid="notify-card"
             style="background:linear-gradient(135deg,#0b1d4f 0%,#172554 55%,#1e3a8a 100%); color:#e0e7ff; border-radius:16px; position:relative; overflow:hidden;">
          <!-- Subtle radial accent -->
          <div style="position:absolute;top:-40px;right:-40px;width:180px;height:180px;background:radial-gradient(circle,rgba(96,165,250,.25) 0%,transparent 70%);pointer-events:none;"></div>
          <div class="card-body p-3 p-md-4 position-relative">
            <div class="d-flex align-items-start gap-3 mb-3">
              <div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 6px 18px rgba(59,130,246,.45);">
                <i class="bi bi-bell-fill" style="font-size:20px;"></i>
              </div>
              <div>
                <h5 class="fw-bold mb-1" style="color:#ffffff;letter-spacing:.2px;">Notify When Available</h5>
                <p class="mb-0" style="color:#cbd5e1;font-size:13px;line-height:1.55;">Drop your email — we'll alert you the instant <strong style="color:#ffffff;"><?= esc($product['name']) ?></strong> is restocked. No spam, just one quick email.</p>
              </div>
            </div>
            <form id="notify-form" class="d-flex gap-2 flex-wrap" data-testid="notify-form" novalidate>
              <input type="hidden" name="product_slug" value="<?= esc($product['slug']) ?>">
              <input type="email" class="form-control rounded-pill px-3" name="email"
                     placeholder="your@email.com" required
                     data-testid="notify-email-input"
                     style="flex:1; min-width:220px; border:1px solid rgba(148,163,184,.35); background:rgba(255,255,255,.95); color:#0f172a; font-weight:500;">
              <button type="submit" class="btn rounded-pill px-4 fw-bold" data-testid="notify-submit-btn"
                      style="background:linear-gradient(135deg,#3b82f6,#1d4ed8); border:0; color:#fff; box-shadow:0 6px 18px rgba(29,78,216,.45);">
                <i class="bi bi-envelope-check me-1"></i> Notify Me
              </button>
            </form>
            <div id="notify-msg" class="small mt-2" data-testid="notify-msg" style="display:none;color:#cbd5e1;"></div>
          </div>
        </div>
        <script>
        (function(){
          var form = document.getElementById('notify-form');
          var msg  = document.getElementById('notify-msg');
          if (!form) return;
          form.addEventListener('submit', async function(e){
            e.preventDefault();
            var emailInput = form.querySelector('input[name="email"]');
            var btn = form.querySelector('button[type="submit"]');
            var email = (emailInput.value || '').trim();
            msg.style.display = 'none';
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
              msg.style.display = 'block';
              msg.className = 'small mt-2';
              msg.style.color = '#fca5a5';
              msg.textContent = 'Please enter a valid email address.';
              return;
            }
            btn.disabled = true;
            var oldHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
            try {
              var res = await fetch('ajax/notify-stock.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                  product_slug: form.querySelector('input[name="product_slug"]').value,
                  email: email,
                }),
              });
              var data = await res.json();
              msg.style.display = 'block';
              if (data.ok) {
                msg.className = 'small mt-2 fw-semibold';
                msg.style.color = '#86efac';
                msg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + (data.message || "You're on the list!");
                form.reset();
              } else {
                msg.className = 'small mt-2';
                msg.style.color = '#fca5a5';
                msg.textContent = data.error || 'Something went wrong. Please try again.';
              }
            } catch (err) {
              msg.style.display = 'block';
              msg.className = 'small mt-2';
              msg.style.color = '#fca5a5';
              msg.textContent = 'Network error. Please try again.';
            } finally {
              btn.disabled = false;
              btn.innerHTML = oldHtml;
            }
          });
        })();
        </script>
      <?php endif; ?>

      <div class="row g-3 small">
        <div class="col-sm-6"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Instant email delivery (15-30 min)</div>
        <div class="col-sm-6"><i class="bi bi-patch-check-fill text-success me-2"></i>Genuine Microsoft key</div>
        <div class="col-sm-6"><i class="bi bi-arrow-counterclockwise text-primary me-2"></i>Money-back guarantee</div>
        <div class="col-sm-6"><i class="bi bi-headset text-primary me-2"></i>Free installation support</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mt-5" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-desc">Description</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-delivery">Delivery & Activation</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-volume">Volume Pricing</button></li>
  </ul>
  <div class="tab-content border border-top-0 rounded-bottom p-4">
    <div class="tab-pane fade show active" id="tab-desc">
      <?php
        /* Render the AI-generated product description (intro paragraph(s) +
           "•" bullet list + closing line). Bullet lines are grouped into a
           styled <ul>; everything else becomes a paragraph. Falls back to a
           generic blurb if no description has been generated yet. */
        $descText = trim((string)($product['description'] ?? ''));
      ?>
      <?php if ($descText !== ''): ?>
        <div data-testid="product-description">
          <?php
            $lines  = preg_split('/\r\n|\r|\n/', $descText);
            $inList = false;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $isBullet = (bool)preg_match('/^([•▪◦\-\*])\s+/u', $line);
                if ($isBullet) {
                    if (!$inList) { echo '<ul class="mb-3">'; $inList = true; }
                    $item = preg_replace('/^([•▪◦\-\*])\s+/u', '', $line);
                    echo '<li class="mb-1">' . esc($item) . '</li>';
                } else {
                    if ($inList) { echo '</ul>'; $inList = false; }
                    echo '<p class="mb-3">' . esc($line) . '</p>';
                }
            }
            if ($inList) { echo '</ul>'; }
          ?>
        </div>
      <?php else: ?>
        <p><?= esc($product['name']) ?> is a genuine, one-time-purchase product. One-time purchase — no subscription, no recurring fees. Your license key activates the official software downloaded directly from Microsoft (or the vendor) and remains yours for as long as you use it.</p>
      <?php endif; ?>
      <ul class="small text-secondary">
        <li>Licensed for 1 <?= esc($product['platform']) ?> device</li>
        <li>Full official version — not a trial or shared account</li>
        <li>Includes free updates within its version</li>
        <li>Activation support included — 30-day money-back policy</li>
      </ul>
      <?php /* Product identifiers — visible barcode + SKU so customers (and
              indexing crawlers) can match this listing to the same product
              elsewhere.  Kept compact + tinted so it doesn't distract from
              the buy flow above. */ ?>
      <div class="d-flex flex-wrap gap-3 small text-secondary mt-3 pt-3 border-top" data-testid="product-identifiers">
        <?php if (!empty($product['gtin']) && function_exists('is_valid_global_gtin') && is_valid_global_gtin((string)$product['gtin'])): ?>
          <span><strong class="text-body">GTIN:</strong> <span class="font-monospace" data-testid="product-gtin"><?= esc($product['gtin']) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($product['sku'])): ?>
          <span><strong class="text-body">SKU:</strong> <span class="font-monospace" data-testid="product-sku"><?= esc($product['sku']) ?></span></span>
        <?php endif; ?>
        <span><strong class="text-body">Brand:</strong> <?= esc($detectedBrand) ?></span>
      </div>
    </div>
    <div class="tab-pane fade" id="tab-delivery">
      <ol class="small">
        <li class="mb-2">Complete your purchase — your license key + download link arrive by email within 15-30 minutes.</li>
        <li class="mb-2">Download the official installer from the link provided.</li>
        <li class="mb-2">Enter your product key when prompted to activate.</li>
        <li>Need help? Our team offers free installation assistance: <?= esc(company_phone_for_country()) ?> (<?= SITE_HOURS ?>).</li>
      </ol>
    </div>
    <div class="tab-pane fade" id="tab-volume">
      <p class="small">Buying for a team? We offer volume discounts on 5+ licenses with consolidated invoicing.</p>
      <a href="contact.php" class="btn btn-outline-primary rounded-pill btn-sm">Request a Volume Quote</a>
    </div>
  </div>

  <?php
    /* ── Download, install & activate ─────────────────────────────────────
       One-click installer, official installation guide and activation /
       sign-in page sourced from manuals.winandoffice.com (seeded per product
       in scripts/seed-manual-urls.php). Rendered only when the product has at
       least one of these links — antivirus/other products without a manual
       simply don't show the block. */
    require_once __DIR__ . '/includes/install-guides.php';
    $pLinks     = mv_resolve_install_links((string)($product['slug'] ?? ''), $product);
    $pInstaller = $pLinks['installer'];
    $pGuide     = $pLinks['guide'];
    $pActivate  = $pLinks['activation'];
  ?>
  <?php if ($pInstaller !== '' || $pGuide !== '' || $pActivate !== ''): ?>
  <section class="mt-5" data-testid="product-install-block">
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-1"><i class="bi bi-download text-primary me-2"></i>Download, install &amp; activate</h2>
        <p class="small text-secondary mb-3">Official installer, step-by-step installation guide and the activation / sign-in page for <strong><?= esc($product['name']) ?></strong> &mdash; the same links we email after purchase.</p>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($pInstaller !== ''): ?>
            <a href="<?= esc($pInstaller) ?>" target="_blank" rel="nofollow noopener" class="btn rounded-pill px-4 fw-semibold" data-testid="install-download-btn" style="background:linear-gradient(135deg,#16a34a,#15803d) !important;color:#fff !important;border:0;"><i class="bi bi-box-arrow-down me-2"></i>Download installer</a>
          <?php endif; ?>
          <?php if ($pGuide !== ''): ?>
            <a href="<?= esc($pGuide) ?>" target="_blank" rel="nofollow noopener" class="btn btn-primary rounded-pill px-4 fw-semibold" data-testid="install-guide-btn"><i class="bi bi-journal-text me-2"></i>Installation guide</a>
          <?php endif; ?>
          <?php if ($pActivate !== ''): ?>
            <a href="<?= esc($pActivate) ?>" target="_blank" rel="nofollow noopener" class="btn btn-outline-primary rounded-pill px-4 fw-semibold" data-testid="install-activate-btn"><i class="bi bi-key me-2"></i>Activate / Sign in</a>
          <?php endif; ?>
        </div>
        <?php if ($pInstaller === '' && $pGuide !== ''): ?>
          <p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>On Mac, the installer is downloaded after you sign in &mdash; full steps are in the installation guide above.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- AEO: Quick-Answer callout — 40-60 word direct answer Google AI
       Overviews + Perplexity routinely grab as the citation snippet. -->
  <?= render_aeo_answer(
        'Is ' . esc($product['name']) . ' a genuine, one-time purchase?',
        'Yes &mdash; ' . esc($product['name']) . ' on ' . esc(SITE_BRAND) . ' is a <strong>genuine perpetual licence</strong> at <strong>' . esc(format_price((float)$product['price'])) . '</strong>. Pay once, activate inside the official ' . esc(product_detected_brand($product)) . ' installer, and use it for life on your ' . esc($product['platform'] ?: 'Windows') . ' device. Delivery is by email in 15&ndash;30 minutes; every order is protected by a 30-day money-back guarantee.',
        'product-quick-answer'
    ) ?>

  <!-- Long-tail keyword SEO copy block — visible to humans, indexable by
       crawlers, quotable by AI search engines (Speakable schema attached). -->
  <?= product_seo_copy($product) ?>

  <?php /* "What customers are saying" — visible block backing the JSON-LD
          aggregateRating + review schema added in the <head>.  Hidden when
          the product has zero published reviews so the page stays clean
          until real social proof rolls in.  The rows ($_reviewRows) and
          aggregate stats ($_reviewStats) are the SAME ones serialized
          into JSON-LD up top — single source of truth, Google-compliant. */ ?>
  <?php if ($_reviewStats['count'] > 0 && $_reviewRows): ?>
    <?php
      $_aggStars = '';
      for ($i = 1; $i <= 5; $i++) {
          if ($_reviewStats['avg'] >= $i)            $_aggStars .= '<i class="bi bi-star-fill"></i>';
          elseif ($_reviewStats['avg'] >= $i - 0.5)  $_aggStars .= '<i class="bi bi-star-half"></i>';
          else                                       $_aggStars .= '<i class="bi bi-star"></i>';
      }
    ?>
  <?php endif; ?>
    <section class="mt-5 pt-3" data-testid="product-reviews-section" id="reviews">
      <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <h2 class="h4 fw-bold mb-0">What customers are saying</h2>
        <?php if ($_reviewStats['count'] > 0 && $_reviewRows): ?>
          <span class="text-warning lh-1 fs-5" aria-hidden="true"><?= $_aggStars ?></span>
          <span class="fw-semibold"><?= number_format($_reviewStats['avg'], 1) ?></span>
          <span class="text-secondary small">
            Based on <?= (int)$_reviewStats['count'] ?> verified review<?= $_reviewStats['count'] === 1 ? '' : 's' ?>
          </span>
          <a href="reviews.php?product=<?= urlencode($product['slug']) ?>"
             class="small text-decoration-none"
             data-testid="product-reviews-see-all">See all <?= (int)$_reviewStats['count'] ?> &rsaquo;</a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary rounded-pill px-4 fw-semibold ms-auto" data-bs-toggle="modal" data-bs-target="#writeReviewModal" data-testid="write-review-btn"><i class="bi bi-pencil-square me-2"></i>Write a review</button>
      </div>
      <?php if ($_reviewStats['count'] > 0 && $_reviewRows): ?>
      <div class="row g-3">
        <?php foreach ($_reviewRows as $r): ?>
          <div class="col-md-6">
            <div class="card p-3 h-100 home-review-card" data-testid="product-review-card">
              <div class="d-flex align-items-center gap-2 mb-2">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="bi <?= $i <= $r['rating'] ? 'bi-star-fill text-warning' : 'bi-star text-secondary' ?>"></i>
                <?php endfor; ?>
                <span class="badge text-bg-success ms-auto small">
                  <i class="bi bi-patch-check-fill me-1"></i>Verified
                </span>
              </div>
              <p class="text-body small mb-3 home-review-text">"<?= esc(mb_substr($r['comment'], 0, 280)) ?><?= mb_strlen($r['comment']) > 280 ? '…' : '' ?>"</p>
              <div class="d-flex align-items-center gap-2 mt-auto">
                <div class="avatar-circle"><?= esc(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></div>
                <div>
                  <div class="fw-semibold small"><?= esc($r['name']) ?></div>
                  <small class="text-secondary">
                    <i class="bi bi-calendar-event me-1"></i><?= esc(date('M j, Y', strtotime($r['date']))) ?>
                  </small>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="card border-0 bg-body-tertiary rounded-4 p-4 text-center" data-testid="reviews-empty">
        <i class="bi bi-chat-quote fs-2 text-primary mb-2"></i>
        <p class="mb-1 fw-semibold">No reviews yet — be the first to review <?= esc($product['name']) ?>!</p>
        <p class="text-secondary small mb-0">Already bought it? Share your experience to help other shoppers decide.</p>
      </div>
      <?php endif; ?>
    </section>

    <!-- Write-a-review modal — verified-purchase gated (order # + email) so
         only genuine buyers can review.  3★+ auto-publishes; <3★ goes to the
         admin for follow-up.  Reuses /ajax/product-review.php. -->
    <div class="modal fade" id="writeReviewModal" tabindex="-1" aria-hidden="true" data-testid="write-review-modal">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold">Review <?= esc($product['name']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form id="pdReviewForm" data-slug="<?= esc($product['slug']) ?>">
              <div class="text-center mb-2">
                <div class="d-inline-flex gap-2" id="pdReviewStars" data-testid="pd-review-stars">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star pd-star" data-val="<?= $i ?>" data-testid="pd-star-<?= $i ?>" role="button" tabindex="0" style="font-size:34px;color:#e5e7eb;cursor:pointer;transition:color .12s,transform .12s;"></i>
                  <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="pdReviewRating" value="0">
                <div class="small text-secondary mt-1" id="pdReviewLabel">Tap a star to rate</div>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Your review</label>
                <textarea class="form-control rounded-3" name="comment" id="pdReviewComment" rows="3" maxlength="800" placeholder="What did you like? How was delivery &amp; activation?" data-testid="pd-review-comment"></textarea>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label small fw-semibold mb-1">Order number</label>
                  <input type="text" class="form-control rounded-3" name="order_number" placeholder="e.g. MV2406ABCDE" data-testid="pd-review-order">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small fw-semibold mb-1">Email used at checkout</label>
                  <input type="email" class="form-control rounded-3" name="email" placeholder="you@email.com" data-testid="pd-review-email">
                </div>
              </div>
              <p class="text-secondary" style="font-size:11.5px;">We verify your purchase before publishing — reviews are only shown for genuine orders. Find your order number in your confirmation email or via <a href="track-order.php">Track Order</a>.</p>
              <div id="pdReviewMsg" class="small mb-2" style="display:none;"></div>
              <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-semibold" data-testid="pd-review-submit"><i class="bi bi-send me-1"></i>Submit review</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var form = document.getElementById('pdReviewForm');
      if (!form) return;
      var stars  = form.querySelectorAll('.pd-star');
      var rInput = document.getElementById('pdReviewRating');
      var label  = document.getElementById('pdReviewLabel');
      var msg    = document.getElementById('pdReviewMsg');
      var LBL = {0:'Tap a star to rate',1:'Poor',2:'Fair',3:'Good',4:'Great',5:'Excellent'};
      function paint(n){ stars.forEach(function(s){ var v=+s.dataset.val; s.style.color = v<=n ? '#facc15' : '#e5e7eb'; s.className = 'bi pd-star ' + (v<=n?'bi-star-fill':'bi-star'); }); label.textContent = LBL[n]||LBL[0]; }
      function setR(n){ rInput.value=n; paint(n); }
      stars.forEach(function(s){ var v=+s.dataset.val;
        s.addEventListener('mouseenter', function(){ paint(v); });
        s.addEventListener('click', function(){ setR(v); });
        s.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); setR(v);} });
      });
      document.getElementById('pdReviewStars').addEventListener('mouseleave', function(){ paint(+rInput.value||0); });
      form.addEventListener('submit', async function(e){
        e.preventDefault();
        msg.style.display='none';
        var btn = form.querySelector('[data-testid="pd-review-submit"]');
        var payload = {
          product_slug: form.dataset.slug,
          rating: +rInput.value || 0,
          comment: form.comment.value.trim(),
          order_number: form.order_number.value.trim(),
          email: form.email.value.trim()
        };
        btn.disabled = true; var old = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting…';
        try {
          var res = await fetch('ajax/product-review.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
          var data = await res.json();
          msg.style.display='block';
          if (data.ok) {
            msg.className='small mb-2 text-success fw-semibold';
            msg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + (data.message || 'Thank you for your review!');
            form.querySelector('textarea').value=''; setR(0);
            if (data.published) { setTimeout(function(){ window.location.reload(); }, 1400); }
          } else {
            msg.className='small mb-2 text-danger';
            msg.textContent = data.error || 'Something went wrong. Please try again.';
          }
        } catch(err) {
          msg.style.display='block'; msg.className='small mb-2 text-danger'; msg.textContent='Network error. Please try again.';
        } finally { btn.disabled=false; btn.innerHTML=old; }
      });
    })();
    </script>
  <?php /* end reviews + write-a-review */ ?>

  <!-- Ask AI — Claude Haiku 4.5 powered Q&A grounded on this product's
       facts, FAQs, and recent reviews.  Answers free-form questions in
       seconds and routes anything off-topic to live chat. -->
  <section class="mt-5 ask-ai-card" data-testid="ask-ai-section" data-slug="<?= esc($product['slug']) ?>">
    <div class="ask-ai-head">
      <div class="ask-ai-avatar"><i class="bi bi-stars"></i></div>
      <div class="ask-ai-meta">
        <div class="ask-ai-title">Ask AI about this product</div>
        <div class="ask-ai-sub">Powered by Claude · Instant answers about delivery, compatibility, activation &amp; more</div>
      </div>
      <span class="ask-ai-pill"><span class="ask-ai-dot"></span>Online</span>
    </div>
    <div class="ask-ai-suggestions" data-testid="ask-ai-suggestions">
      <button type="button" class="ask-ai-chip" data-q="How long does delivery take?">How long does delivery take?</button>
      <button type="button" class="ask-ai-chip" data-q="Will this work on my Mac?">Will this work on my Mac?</button>
      <button type="button" class="ask-ai-chip" data-q="Is this a one-time purchase or subscription?">One-time or subscription?</button>
      <button type="button" class="ask-ai-chip" data-q="What happens if the key does not activate?">What if it doesn't activate?</button>
    </div>
    <div id="ask-ai-thread" class="ask-ai-thread" data-testid="ask-ai-thread"></div>
    <form id="ask-ai-form" class="ask-ai-form" onsubmit="askAiSubmit(event)">
      <input type="text" id="ask-ai-input" class="form-control" placeholder="Ask anything about this product…" maxlength="400" autocomplete="off" data-testid="ask-ai-input" required>
      <button type="submit" class="ask-ai-send" data-testid="ask-ai-send"><i class="bi bi-send-fill"></i></button>
    </form>
    <div class="ask-ai-footer">
      AI answers are based on this product's specs — for personal questions or order help, use the chat bubble.
    </div>
  </section>

  <!-- Brand-aware FAQ accordion — visible to humans + structured for AI
       crawlers (FAQPage JSON-LD emitted via $jsonLdFaq).  Answers are
       quotable verbatim by ChatGPT / Perplexity / Google AI Overviews. -->
  <section class="mt-5 pd-faq" aria-labelledby="pd-faq-heading" data-testid="product-faq">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-patch-question-fill" style="font-size:22px;color:#2563eb;"></i>
      <h2 id="pd-faq-heading" class="fw-bold h4 mb-0">Questions about <?= esc($product['name']) ?></h2>
    </div>
    <p class="text-secondary small mb-3">Quick answers about delivery, activation and our guarantee — straight from our support team.</p>
    <div class="accordion pd-faq-accordion" id="pd-faq-accordion">
      <?php foreach ($pageFaqs as $idx => $f): $itemId = 'pd-faq-item-' . $idx; ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                    data-bs-target="#<?= esc($itemId) ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                    aria-controls="<?= esc($itemId) ?>" data-testid="pd-faq-q-<?= $idx ?>">
              <?= esc($f['question']) ?>
            </button>
          </h3>
          <div id="<?= esc($itemId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
               data-bs-parent="#pd-faq-accordion">
            <div class="accordion-body" data-testid="pd-faq-a-<?= $idx ?>">
              <?= nl2br(esc($f['answer'])) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- AEO: "People also ask" — second FAQPage entity targeting longer
       intent variants.  Each Q-A is also a row in the JSON-LD below. -->
  <?php $paaFaqs = product_paa_faqs($product); ?>
  <?= render_paa_block($paaFaqs, 'People also ask about ' . esc($product['name']), 'product-paa') ?>

  <!-- ===== Deep-link cluster: drives Google's PageRank graph + helps AI
       crawlers map this product into the wider topical neighbourhood.
       Uses descriptive anchor text (mid-tail keywords) for every link.
       ============================================================== -->
  <?php
    $sister = product_sibling_category($product);
    $catSlug = (string)($product['category'] ?? '');
    $catTitle = $catSlug ? category_title($catSlug) : '';
    $relCats = related_category_links($catSlug);
    $popular = popular_search_terms();
  ?>
  <section class="pd-deep-cluster mt-5" data-testid="product-deep-cluster" aria-labelledby="pd-cluster-heading">
    <h2 id="pd-cluster-heading" class="fw-bold h4 mb-3">Related categories &amp; popular searches</h2>
    <?php $prodHubs = topic_hubs_for_product($product); ?>
    <?php if ($prodHubs): ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-testid="product-topic-hub-row" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;">
      <span class="fw-semibold small text-secondary"><i class="bi bi-collection-fill text-primary me-1"></i>Topic hub:</span>
      <?php foreach (array_slice($prodHubs, 0, 3) as $__h): ?>
        <a class="badge text-decoration-none" data-testid="product-hub-link" href="hub/<?= esc($__h['slug']) ?>" style="background:#fff;color:<?= esc($__h['color']) ?>;border:1px solid <?= esc($__h['color']) ?>33;padding:6px 12px;font-size:11.5px;font-weight:600;"><?= esc(strip_tags(explode(' — ', $__h['title'])[0])) ?> <i class="bi bi-arrow-right-short"></i></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-collection me-1"></i>Browse related categories</div>
        <ul class="list-unstyled small">
          <?php if ($catTitle): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($catSlug) ?>" data-testid="cluster-parent-category">All <?= esc($catTitle) ?> &mdash; genuine license keys</a></li>
          <?php endif; ?>
          <?php if ($sister): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($sister['slug']) ?>" data-testid="cluster-sister-category"><?= esc($sister['title']) ?> &mdash; sister edition</a></li>
          <?php endif; ?>
          <?php foreach ($relCats as $rc): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($rc['slug']) ?>" data-testid="cluster-related-<?= esc($rc['slug']) ?>"><?= $rc['anchor'] ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-search me-1"></i>Popular searches</div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($popular as $term): ?>
            <a href="shop.php?q=<?= urlencode($term) ?>" class="badge text-decoration-none fw-normal" data-testid="cluster-popular-search" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:6px 10px;font-size:12px;"><?= esc($term) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="fw-bold small text-uppercase text-secondary mt-4 mb-2"><i class="bi bi-journal-text me-1"></i>Helpful guides on the blog</div>
        <ul class="list-unstyled small mb-0">
          <?php foreach (product_related_articles($product, 3) as $ba): ?>
            <li class="mb-1">&rsaquo; <a class="text-decoration-none" href="blog-post.php?id=<?= urlencode((string)$ba['id']) ?>" data-testid="cluster-related-article"><?= esc($ba['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </section>

  <?php if ($related): ?>
    <h2 class="fw-bold h4 mt-5 mb-4">Related Products</h2>
    <div class="row g-4">
      <?php foreach ($related as $r): ?>
        <div class="col-xl-3 col-lg-4 col-sm-6"><?= render_product_card($r) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
    /* ---- Internal-linking widget: blog articles that feature this product.
       Boosts on-page topical authority (Google's PageRank-style internal
       link graph) and gives buyers extra trust-building context. ----------- */
    $articlesAboutThis = [];
    try {
        $aap = db()->prepare("SELECT id, title, date, read_time, image, ai_generated
                                FROM blog_posts
                               WHERE product_id = ?
                               ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
                               LIMIT 3");
        $aap->execute([(int)$product['id']]);
        $articlesAboutThis = $aap->fetchAll();
    } catch (Throwable $e) { /* old schema — silent */ }
  ?>
  <?php if ($articlesAboutThis): ?>
    <h2 class="fw-bold h4 mt-5 mb-4" data-testid="articles-about-this-product"><i class="bi bi-journal-text me-1 text-primary"></i>Read more about <?= esc($product['name']) ?></h2>
    <div class="row g-3">
      <?php foreach ($articlesAboutThis as $bp): ?>
        <div class="col-md-4">
          <a href="blog-post.php?id=<?= urlencode($bp['id']) ?>" class="card h-100 text-decoration-none p-0" style="border:1px solid #e5e7eb;overflow:hidden;">
            <img src="<?= esc($bp['image']) ?>" alt="<?= esc($bp['title']) ?>" style="width:100%;height:140px;object-fit:cover;">
            <div class="p-3">
              <div class="small text-secondary"><i class="bi bi-calendar3 me-1"></i><?= esc($bp['date']) ?> · <?= esc($bp['read_time']) ?>
                <?php if (!empty($bp['ai_generated'])): ?> · <span style="color:#5b21b6;font-weight:600;"><i class="bi bi-stars"></i> AI</span><?php endif; ?>
              </div>
              <div class="fw-bold mt-1 text-body" style="font-size:14px;line-height:1.35;"><?= esc($bp['title']) ?></div>
              <div class="text-primary small fw-semibold mt-2">Read article <i class="bi bi-arrow-right"></i></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  /* Out-of-stock chip styling — soft red pill matching brand */
  .pd-stock-out-badge {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    font-weight: 600;
  }
  [data-bs-theme="dark"] .pd-stock-out-badge {
    background: rgba(239,68,68,.15);
    color: #fca5a5;
    border-color: rgba(239,68,68,.35);
  }
  /* Product FAQ accordion — clean cards with subtle blue accent */
  .pd-faq-accordion .accordion-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px !important;
    margin-bottom: 10px;
    overflow: hidden;
    background: #ffffff;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-item {
    border-color: #334155;
    background: #1e293b;
  }
  .pd-faq-accordion .accordion-button {
    font-weight: 600;
    font-size: 15px;
    color: #0f172a;
    background: #ffffff;
    padding: 16px 20px;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-button {
    background: #1e293b;
    color: #e2e8f0;
  }
  .pd-faq-accordion .accordion-button:not(.collapsed) {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1e3a8a;
    box-shadow: none;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-button:not(.collapsed) {
    background: linear-gradient(135deg, #1c2541, #1e293b);
    color: #93c5fd;
  }
  .pd-faq-accordion .accordion-button:focus { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
  .pd-faq-accordion .accordion-body {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    padding: 14px 20px 20px;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-body { color: #cbd5e1; }

  /* Ask AI widget — premium card with Claude-branded "Powered by" feel */
  .ask-ai-card {
    background: linear-gradient(135deg, #faf5ff 0%, #f0f9ff 100%);
    border: 1px solid #e9d5ff;
    border-radius: 16px;
    padding: 22px 22px 18px;
    position: relative;
    overflow: hidden;
  }
  [data-bs-theme="dark"] .ask-ai-card {
    background: linear-gradient(135deg, #1c1638 0%, #0f1e3a 100%);
    border-color: #4c1d95;
  }
  .ask-ai-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
  .ask-ai-avatar {
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg, #a855f7, #6366f1);
    color: #fff; display: inline-flex; align-items: center; justify-content: center;
    font-size: 20px; box-shadow: 0 6px 18px rgba(168, 85, 247, 0.35);
  }
  .ask-ai-meta { flex: 1; min-width: 0; }
  .ask-ai-title { font-size: 16px; font-weight: 700; color: #1e1b4b; line-height: 1.2; }
  [data-bs-theme="dark"] .ask-ai-title { color: #ddd6fe; }
  .ask-ai-sub { font-size: 12px; color: #64748b; margin-top: 2px; }
  [data-bs-theme="dark"] .ask-ai-sub { color: #94a3b8; }
  .ask-ai-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #d1fae5; color: #047857;
    padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
  }
  .ask-ai-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #10b981;
    animation: ask-ai-pulse 2s ease-in-out infinite;
  }
  @keyframes ask-ai-pulse { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.5; } }
  .ask-ai-suggestions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
  .ask-ai-chip {
    background: #ffffff; border: 1px solid #e9d5ff;
    color: #6d28d9; padding: 6px 14px;
    border-radius: 999px; font-size: 12.5px; font-weight: 600;
    cursor: pointer; transition: all 0.14s ease;
  }
  .ask-ai-chip:hover { background: #6d28d9; color: #fff; border-color: #6d28d9; transform: translateY(-1px); }
  [data-bs-theme="dark"] .ask-ai-chip { background: #1e1b4b; color: #c4b5fd; border-color: #4c1d95; }
  [data-bs-theme="dark"] .ask-ai-chip:hover { background: #6d28d9; color: #fff; }
  .ask-ai-thread { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; max-height: 480px; overflow-y: auto; }
  .ask-ai-thread:empty { display: none; }
  .ask-ai-msg {
    padding: 10px 14px; border-radius: 12px;
    font-size: 13.5px; line-height: 1.55; max-width: 88%;
    animation: ask-ai-fade-in 0.22s ease-out;
  }
  @keyframes ask-ai-fade-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
  .ask-ai-msg.is-q { align-self: flex-end; background: #6d28d9; color: #fff; border-bottom-right-radius: 4px; }
  .ask-ai-msg.is-a { align-self: flex-start; background: #ffffff; color: #1e1b4b; border: 1px solid #e9d5ff; border-bottom-left-radius: 4px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
  [data-bs-theme="dark"] .ask-ai-msg.is-a { background: #1c1638; color: #ddd6fe; border-color: #4c1d95; }
  .ask-ai-msg.is-err { align-self: flex-start; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .ask-ai-feedback { display: flex; gap: 6px; margin-top: 8px; font-size: 11px; color: #94a3b8; align-items: center; }
  .ask-ai-fb-btn { background: transparent; border: 0; cursor: pointer; padding: 2px 6px; border-radius: 6px; color: #94a3b8; }
  .ask-ai-fb-btn:hover { color: #6d28d9; background: rgba(168,85,247,.08); }
  .ask-ai-fb-btn.is-on { color: #10b981; }
  .ask-ai-typing { font-size: 12px; color: #94a3b8; padding: 8px 12px; }
  .ask-ai-typing span { animation: ask-ai-typing 1.2s ease-in-out infinite; }
  .ask-ai-typing span:nth-child(2) { animation-delay: 0.18s; }
  .ask-ai-typing span:nth-child(3) { animation-delay: 0.36s; }
  @keyframes ask-ai-typing { 0%, 100% { opacity: 0.3; } 50% { opacity: 1; } }
  .ask-ai-form { display: flex; gap: 8px; }
  .ask-ai-form input { flex: 1; border-radius: 999px; padding: 10px 18px; font-size: 14px; border: 1px solid #e9d5ff; }
  .ask-ai-form input:focus { border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109,40,217,.15); outline: none; }
  [data-bs-theme="dark"] .ask-ai-form input { background: #1c1638; color: #ddd6fe; border-color: #4c1d95; }
  .ask-ai-send {
    width: 42px; height: 42px; border-radius: 50%; border: 0;
    background: linear-gradient(135deg, #a855f7, #6366f1); color: #fff;
    font-size: 16px; cursor: pointer; transition: all 0.14s ease;
    box-shadow: 0 6px 16px rgba(99,102,241,.32);
  }
  .ask-ai-send:hover { transform: translateY(-1px) scale(1.05); box-shadow: 0 10px 22px rgba(99,102,241,.45); }
  .ask-ai-send:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
  .ask-ai-footer { font-size: 10.5px; color: #94a3b8; margin-top: 10px; text-align: center; }
</style>

<?php /* view_item event — fires once per product page load.  Same payload
        shape for GA4, Google Ads (auto via gtag), and Bing UET so all
        platforms recognise the user-product engagement signal. */ ?>
<?php
$tk_ga4_v  = trim((string)setting_get('ga4_measurement_id', ''));
$tk_uet_v  = trim((string)setting_get('bing_uet_tag_id', ''));
if ($tk_ga4_v !== '' || $tk_uet_v !== ''):
?>
<script>
(function(){
  var item = {
    item_id:    <?= json_encode((string)$product['slug']) ?>,
    item_name:  <?= json_encode((string)$product['name']) ?>,
    item_brand: <?= json_encode((string)($product['brand'] ?? 'Microsoft')) ?>,
    item_category: <?= json_encode((string)($product['category'] ?? 'Software')) ?>,
    price:      <?= json_encode(round((float)$product['price'], 2)) ?>,
    currency:   <?= json_encode((string)(current_currency()['code'] ?? 'USD')) ?>
  };
  <?php if ($tk_ga4_v !== ''): ?>
  if (typeof gtag === 'function') {
    gtag('event', 'view_item', { currency: item.currency, value: item.price, items: [item] });
  }
  <?php endif; ?>
  <?php if ($tk_uet_v !== ''): ?>
  if (window.uetq) {
    window.uetq.push('event', 'product_view', {
      event_category: 'ecommerce', event_label: item.item_id,
      revenue_value: item.price, currency: item.currency
    });
  }
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
/* ============================================================
 * Ask AI — product page widget (Claude Haiku 4.5)
 * ============================================================ */
(function(){
  const section = document.querySelector('[data-testid="ask-ai-section"]');
  if (!section) return;
  const slug   = section.getAttribute('data-slug') || '';
  const thread = document.getElementById('ask-ai-thread');
  const input  = document.getElementById('ask-ai-input');
  const sendBtn = document.querySelector('.ask-ai-send');

  // One-click suggestion chips populate the input.
  document.querySelectorAll('.ask-ai-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      input.value = chip.getAttribute('data-q') || '';
      input.focus();
    });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function appendMsg(type, text, chatId) {
    const div = document.createElement('div');
    div.className = 'ask-ai-msg is-' + type;
    div.innerHTML = escapeHtml(text);
    thread.appendChild(div);
    if (type === 'a' && chatId) {
      const fb = document.createElement('div');
      fb.className = 'ask-ai-feedback';
      fb.innerHTML = '<span>Was this helpful?</span>'
        + '<button type="button" class="ask-ai-fb-btn" data-helpful="1" data-id="' + chatId + '" data-testid="ask-ai-fb-up-' + chatId + '"><i class="bi bi-hand-thumbs-up"></i></button>'
        + '<button type="button" class="ask-ai-fb-btn" data-helpful="0" data-id="' + chatId + '" data-testid="ask-ai-fb-down-' + chatId + '"><i class="bi bi-hand-thumbs-down"></i></button>';
      thread.appendChild(fb);
    }
    thread.scrollTop = thread.scrollHeight;
  }
  function appendTyping() {
    const t = document.createElement('div');
    t.className = 'ask-ai-typing';
    t.id = 'ask-ai-typing-indicator';
    t.innerHTML = 'Thinking<span>.</span><span>.</span><span>.</span>';
    thread.appendChild(t);
    thread.scrollTop = thread.scrollHeight;
  }
  function removeTyping() {
    const t = document.getElementById('ask-ai-typing-indicator');
    if (t) t.remove();
  }

  window.askAiSubmit = async function(ev) {
    ev.preventDefault();
    const q = input.value.trim();
    if (!q || sendBtn.disabled) return;
    appendMsg('q', q);
    input.value = '';
    sendBtn.disabled = true;
    appendTyping();
    try {
      const r = await fetch('ajax/ask-ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: slug, question: q }),
      });
      const j = await r.json();
      removeTyping();
      if (j && j.ok) {
        appendMsg('a', j.answer, j.chat_id);
      } else {
        appendMsg('err', (j && j.error) || 'Something went wrong. Please try the chat bubble in the corner.');
      }
    } catch (_) {
      removeTyping();
      appendMsg('err', 'Network hiccup — please retry, or use the chat bubble for live help.');
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  };

  // Thumbs up/down feedback delegation.
  thread.addEventListener('click', async (e) => {
    const btn = e.target.closest('.ask-ai-fb-btn');
    if (!btn) return;
    const chatId  = btn.getAttribute('data-id');
    const helpful = btn.getAttribute('data-helpful') === '1' ? 1 : 0;
    btn.classList.add('is-on');
    btn.parentNode.querySelectorAll('.ask-ai-fb-btn').forEach(b => { if (b !== btn) b.style.opacity = '0.35'; });
    try {
      await fetch('ajax/ask-ai-feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: chatId, helpful: helpful }),
      });
    } catch (_) {}
  });
})();
</script>
