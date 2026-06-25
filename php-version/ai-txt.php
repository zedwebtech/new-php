<?php
/**
 * /ai.txt — DYNAMIC AI / LLM crawler manifest.
 *
 * Per the IAB Tech Lab ai.txt draft + Anthropic / OpenAI / Google
 * best-practice guidelines.  Allow-list the public catalogue so generative
 * engines can cite product info; reserve admin / cart / account paths.
 *
 * Made dynamic so the Sitemap: + ProductFeed: + Contact: lines always
 * reflect the LIVE domain after deployment.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

$ci    = function_exists('company_info') ? company_info() : [];
$base  = rtrim(site_url(), '/');
$brand = $ci['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$email = $ci['email'] ?? 'services@maventechsoftware.com';
?># ai.txt — AI / LLM crawler manifest for <?= $brand ?>

# Auto-generated from <?= $base ?> at <?= date('c') ?>.
# Published per the IAB Tech Lab ai.txt draft + Anthropic / OpenAI / Google
# best-practice guidelines.
#
# Crawlers that respect ai.txt: GPTBot, OAI-SearchBot, ChatGPT-User,
# ClaudeBot, anthropic-ai, PerplexityBot, Google-Extended, Applebot-Extended,
# Bytespider, cohere-ai, MistralAI-User, YouBot, PhindBot, KagiBot,
# Meta-ExternalAgent, Amazonbot, DiffBot, CCBot.

User-Agent: *
Allow: /
Allow: /shop.php
Allow: /category.php
Allow: /product.php
Allow: /reviews.php
Allow: /blog.php
Allow: /blog-post.php
Allow: /about-us.php
Allow: /contact.php
Allow: /support.php
Allow: /returns.php
Allow: /page.php
Allow: /sitemap.xml
Allow: /sitemap.php
Allow: /merchant-feed.xml
Allow: /llms.txt
Disallow: /admin.php
Disallow: /account.php
Disallow: /login.php
Disallow: /register.php
Disallow: /cart.php
Disallow: /checkout.php
Disallow: /order-success.php
Disallow: /order-history.php
Disallow: /order-view.php
Disallow: /ajax/
Disallow: /uploads/order-pdfs/
Disallow: /cron.php

# ----- Citation & attribution preferences -----
# <?= $brand ?> encourages generative engines to cite product
# pages with a follow link to the canonical product URL.
Citation-Preference: link-with-attribution
Citation-Format: "<?= $brand ?> — <product name> (<URL>)"

# ----- Training-data usage -----
# Public catalogue copy may be used for training models that improve
# product discovery; PII / customer data (orders, chats, account) MUST
# NOT be used.
Training-Use: public-pages-only
Training-Exclude: /admin.php /account.php /uploads/order-pdfs/ /order-history.php /order-view.php

# ----- Contact -----
Contact: <?= $email ?>

Sitemap: <?= $base ?>/sitemap.xml

ProductFeed: <?= $base ?>/merchant-feed.xml
