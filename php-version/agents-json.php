<?php
/**
 * /agents.json — public AI-agent discovery document.
 *
 * Companion to /llms.txt. Returns a machine-readable JSON manifest that
 * AI agents (ChatGPT plugins, Claude tool-use, Perplexity, Bing Copilot,
 * autonomous shopping agents) can fetch to learn:
 *   - what the site sells
 *   - how to query the catalog programmatically (sitemap, merchant feed,
 *     llms.txt for human-readable summary)
 *   - how to contact a human (phone, email, chat)
 *   - what's NOT allowed (price scraping, automated checkouts, etc.)
 *
 * Refreshed on every request from the live product catalog. Caches
 * publicly for 1 hour so well-behaved crawlers stay polite.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

$pdo  = db();
$ci   = company_info();
$base = rtrim(site_url(), '/');

// Live product counts per category — gives agents an at-a-glance shape.
$catStats = [];
foreach ($pdo->query("SELECT COALESCE(category,'other') AS category, COUNT(*) c, MIN(price) min_p, MAX(price) max_p FROM products WHERE is_active=1 GROUP BY category ORDER BY c DESC") as $r) {
    $catStats[] = [
        'category'   => (string)$r['category'],
        'count'      => (int)$r['c'],
        'min_price'  => (float)$r['min_p'],
        'max_price'  => (float)$r['max_p'],
    ];
}

$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
$brands = $pdo->query("SELECT DISTINCT brand FROM products WHERE is_active=1 AND brand IS NOT NULL AND brand != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

$payload = [
    'schema_version' => '1.0',
    'spec'           => 'https://agents.json/spec',
    'generated_at'   => date('c'),
    'site' => [
        'name'        => $ci['name']  ?: 'Maventech Software',
        'url'         => $base,
        'description' => 'Authorised digital reseller of genuine Microsoft, antivirus (Bitdefender / Norton / McAfee / Kaspersky / ESET), Adobe and AutoCAD license keys with instant email delivery, 24/7 US-based support and a 30-day money-back guarantee.',
        'logo'        => ($ci['logo'] ?? '') ? (preg_match('#^https?://#i', $ci['logo']) ? $ci['logo'] : $base . '/' . ltrim($ci['logo'], '/')) : null,
        'languages'   => ['en-US', 'en-GB'],
        'currencies'  => ['USD', 'GBP', 'EUR', 'CAD', 'AUD'],
        'regions'     => ['US', 'GB', 'AU', 'CA', 'EU', 'IN', 'AE'],
    ],
    'contact' => [
        'email'         => $ci['email'] ?? '',
        'phone'         => $ci['phone'] ?? '',
        'support_hours' => '24/7',
        'chat'          => $base . '/contact.php',
    ],
    'catalog' => [
        'total_active_products' => $totalProducts,
        'brands'                => array_values($brands),
        'categories'            => $catStats,
        'sitemap_url'           => $base . '/sitemap.xml',
        'merchant_feed_url'     => $base . '/merchant-feed.xml',
        'llms_txt_url'          => $base . '/llms.txt',
        'product_page_pattern'  => $base . '/product.php?slug={slug}',
    ],
    'trust' => [
        'pci_dss_level_1'      => true,
        'gdpr_aligned'         => true,
        'money_back_days'      => 30,
        'license_authenticity' => 'Direct from authorised distributors (Microsoft, Bitdefender, Norton, McAfee, Adobe, etc.) — never cracked / pirated / repackaged.',
        'payment_methods'      => ['stripe', 'paypal'],
        'delivery'             => 'Digital license key emailed within seconds of payment confirmation.',
    ],
    // What AI agents are allowed to do programmatically. Tools / function
    // calling implementations should honour these flags.
    'agent_capabilities' => [
        'allow_catalog_browse'   => true,
        'allow_price_quotes'     => true,
        'allow_purchase'         => false,   // human-in-the-loop required
        'allow_account_actions'  => false,
        'rate_limit_per_minute'  => 30,
        'cite_product_page'      => true,
        'never_invent_prices'    => true,
    ],
    // Tool / endpoint manifest — agents can introspect these to plan a query.
    'endpoints' => [
        [
            'name'        => 'browse_catalog',
            'method'      => 'GET',
            'url'         => $base . '/shop.php',
            'description' => 'Browse the full product catalog. Append ?category={slug} or ?q={query} to filter.',
        ],
        [
            'name'        => 'product_detail',
            'method'      => 'GET',
            'url'         => $base . '/product.php?slug={slug}',
            'description' => 'Fetch a single product detail page. Returns price, description, activation URL.',
        ],
        [
            'name'        => 'merchant_feed',
            'method'      => 'GET',
            'url'         => $base . '/merchant-feed.xml',
            'description' => 'Google Merchant XML feed of every active product (machine-readable).',
        ],
        [
            'name'        => 'site_summary',
            'method'      => 'GET',
            'url'         => $base . '/llms.txt',
            'description' => 'Human-readable AI-generated site summary (llmstxt.org spec). Refreshed daily.',
        ],
    ],
    // Crawler etiquette — same allow/disallow rules as robots.txt but in a
    // form AI agents can parse without a separate fetch.
    'robots' => [
        'user_agent'    => '*',
        'allow'         => ['/', '/shop.php', '/product.php', '/blog/', '/sitemap.xml', '/llms.txt', '/agents.json'],
        'disallow'      => ['/admin.php', '/checkout.php', '/order-success.php', '/login.php', '/ajax/', '/uploads/order-pdfs/'],
        'crawl_delay'   => 1,
    ],
];

// Pretty-print so a curl/head view from a human is readable. Adds ~10% size
// vs. minified but the file is < 4 KB.
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
