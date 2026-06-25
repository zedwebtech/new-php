<?php
/**
 * /robots.txt — DYNAMIC generator (replaces the old static robots.txt).
 *
 * Why dynamic?
 *   The Sitemap: URLs need to reflect the LIVE hostname automatically.
 *   When you deploy from the preview to maventechsoftware.com, this file
 *   will pick up site_url() and emit the correct absolute URLs — no manual
 *   find-and-replace required.
 *
 * Served via router.php (built-in server) and via .htaccess (Apache).
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

$base = rtrim(site_url(), '/');

// ----- Search & AI crawlers we explicitly allow -----
$aiCrawlers = [
    // Mainstream search
    'Googlebot', 'Googlebot-Image', 'Bingbot', 'DuckDuckBot', 'Slurp',
    'YandexBot', 'Baiduspider',
    // OpenAI
    'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
    // Anthropic
    'anthropic-ai', 'ClaudeBot', 'Claude-Web',
    // Perplexity
    'PerplexityBot', 'Perplexity-User',
    // Google generative
    'Google-Extended',
    // Apple
    'Applebot', 'Applebot-Extended',
    // Misc AI search
    'cohere-ai', 'Bytespider', 'DiffBot', 'FacebookExternalHit',
    'Amazonbot', 'meta-externalagent', 'YouBot', 'PhindBot', 'KagiBot',
    'MistralAI-User', 'CCBot', 'PetalBot', 'Brave-Search', 'NeevaBot',
    'Andibot',
];

$disallowedPaths = [
    '/cart.php', '/checkout.php', '/login.php', '/register.php',
    '/account.php', '/admin.php', '/admin-email-preview.php',
    '/logout.php', '/order-success.php', '/order-view.php',
    '/order-history.php', '/email-view.php', '/email-api.php',
    '/ajax/', '/uploads/', '/cron.php', '/setup-check.php',
    '/*?session_id=', '/*?order=',
];
?># <?= defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software' ?> — robots.txt
# Dynamically generated from <?= $base ?> at <?= date('c') ?>.
# Edit /robots-txt.php to change the rules; this file is served from <?= $_SERVER['REQUEST_URI'] ?? '/robots.txt' ?>.

# ----- Default policy for all crawlers -----
User-agent: *
Allow: /
<?php foreach ($disallowedPaths as $p): ?>
Disallow: <?= $p ?>

<?php endforeach; ?>

# ----- Explicit allow-list for search + AI crawlers (no rate limit) -----
<?php foreach ($aiCrawlers as $bot): ?>
User-agent: <?= $bot ?>

Allow: /

<?php endforeach; ?>

# ----- Sitemap (auto-resolves to the live host) -----
# Only a valid XML sitemap (<urlset>/<sitemapindex>) belongs here. The product
# feeds are RSS Merchant feeds (submit them in Google Merchant Center / Bing,
# not as a Sitemap) and llms.txt / agents.json are AI-crawler files — listing
# any of these as "Sitemap:" makes Search Console report an unsupported format.
Sitemap: <?= $base ?>/sitemap.xml

# Non-sitemap resources (discovery only — NOT submitted as sitemaps):
#   Google Merchant feed : <?= $base ?>/merchant-feed.xml
#   Bing shopping feed   : <?= $base ?>/feed/bing-shopping.xml
#   AI guidance          : <?= $base ?>/llms.txt , <?= $base ?>/agents.json
