<?php
/**
 * Dynamic XML sitemap (served at /sitemap.xml via router.php).
 *
 * Engineered for maximum SEO + GEO + AEO discoverability:
 *   - Image extension namespace (xmlns:image) so Googlebot-Image, Bing
 *     visual search and Lens can crawl every product / blog / hub image
 *     straight from the sitemap.
 *   - News extension namespace (xmlns:news) on blog posts published in
 *     the last 48 h — helps AI overviews + Google News pick up fresh content.
 *   - Real per-row <lastmod> (from `updated_at` columns) so crawlers only
 *     re-crawl what's actually changed → faster index of fresh content,
 *     less crawl-budget waste on stale pages.
 *   - All Topic Cluster Hubs are emitted (priority 1.0) — the topical-
 *     authority anchor pages ChatGPT / Perplexity / Claude reward.
 *   - Each hub also emits an <image:image> using the first product image
 *     from its primary category so the hub appears in image search too.
 *   - Canonical host pulled from the `site_domain_url` admin setting so
 *     the sitemap matches what Google has on file even when the PHP
 *     server is reached via a different internal hostname (ingress rewrite).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=1800');

// Prefer the admin-configured canonical domain over the request host so
// the sitemap always emits the URL Google has on file — even when this
// PHP process is reached via an internal ingress hostname.
$canonical = trim((string)setting_get('site_domain_url', ''));
$base = $canonical !== '' ? rtrim($canonical, '/') : rtrim(site_url(), '/');
$today    = date('Y-m-d');
$nowUtc   = gmdate('Y-m-d\TH:i:s\Z');
$urls     = [];

// ---------------------------------------------------------------
// 1) Core marketing / informational pages.
// These don't carry a row-level `updated_at`, so today's date is a
// safe heuristic — search engines treat `lastmod` as a soft hint.
// ---------------------------------------------------------------
foreach ([
    ['/',                  '1.0', 'daily'],
    ['/shop.php',          '0.9', 'daily'],
    ['/subscriptions.php', '0.9', 'weekly'],
    ['/reviews.php',       '0.6', 'weekly'],
    ['/blog.php',          '0.8', 'daily'],
    ['/about-us.php',      '0.6', 'monthly'],
    ['/why-choose-us.php', '0.6', 'monthly'],
    ['/contact.php',       '0.6', 'monthly'],
    ['/press-kit',         '0.6', 'monthly'],
    ['/support.php',       '0.6', 'monthly'],
    ['/returns.php',       '0.5', 'monthly'],
    ['/sitemap.php',       '0.4', 'monthly'],
    ['/track-order.php',   '0.5', 'monthly'],
    ['/order-history.php', '0.5', 'monthly'],
] as [$path, $pri, $freq]) {
    $urls[] = ['loc' => $base . $path, 'lastmod' => $today, 'freq' => $freq, 'pri' => $pri, 'images' => []];
}

// ---------------------------------------------------------------
// 1b) Subscription plans — one URL per active plan (revenue pages).
// Pulled live from sub_plans() so new/retired plans auto-track.
// ---------------------------------------------------------------
if (function_exists('sub_plans')) {
    try {
        foreach (sub_plans(true) as $sp) {
            if (empty($sp['slug'])) continue;
            $urls[] = [
                'loc'     => $base . '/subscribe.php?plan=' . $sp['slug'],
                'lastmod' => $today,
                'freq'    => 'weekly',
                'pri'     => '0.7',
                'images'  => [],
            ];
        }
    } catch (Throwable $e) {}
}

// ---------------------------------------------------------------
// 2) Categories — derived from the live `products.category` column
// so the sitemap auto-tracks new categories without admin-side edits.
// ---------------------------------------------------------------
$catRows = [];
// Pull `updated_at` only when the column actually exists (it doesn't on
// legacy product schemas) — fall back to NULL so the loop below still
// gets a usable row shape.
$prodHasUpdatedAt = false;
try {
    $prodHasUpdatedAt = (bool)db()->query("SHOW COLUMNS FROM products LIKE 'updated_at'")->fetch();
} catch (Throwable $e) {}
$catLmExpr = $prodHasUpdatedAt ? "MAX(updated_at)" : "NULL";
try {
    $catRows = db()->query(
        "SELECT category AS slug, $catLmExpr AS lm
           FROM products
          WHERE is_active = 1 AND category IS NOT NULL AND category <> ''
            AND " . active_regions_sql_in('region') . "
          GROUP BY category"
    )->fetchAll();
} catch (Throwable $e) {}
foreach ($catRows as $r) {
    $cs = (string)$r['slug'];
    if ($cs === '') continue;
    $lm = !empty($r['lm']) ? substr((string)$r['lm'], 0, 10) : $today;
    $urls[] = ['loc' => $base . '/category.php?slug=' . $cs, 'lastmod' => $lm, 'freq' => 'weekly', 'pri' => '0.8', 'images' => []];
}

// ---------------------------------------------------------------
// 2b) Brand profile pages — /brand.php?slug=<brand>.  One URL per
// distinct active brand so brand hubs (Microsoft, Bitdefender, …) are
// crawlable.  Slug mirrors brand.php's own normalisation.
// ---------------------------------------------------------------
try {
    $brandRows = db()->query(
        "SELECT DISTINCT brand FROM products
          WHERE brand IS NOT NULL AND brand <> '' AND is_active = 1
            AND " . active_regions_sql_in('region')
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($brandRows as $b) {
        $bSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$b));
        $bSlug = trim($bSlug, '-');
        if ($bSlug === '') continue;
        $urls[] = ['loc' => $base . '/brand.php?slug=' . $bSlug, 'lastmod' => $today, 'freq' => 'weekly', 'pri' => '0.7', 'images' => []];
    }
} catch (Throwable $e) {}

// ---------------------------------------------------------------
// 3) Topic Cluster Hubs — highest topical-authority pages.
// Each hub emits an <image:image> using the first active product image
// from its primary category, so hubs ALSO appear in Google Images.
// ---------------------------------------------------------------
$hubs = topic_hubs_all(true);
// Bulk-resolve "one product image per category" in a single query so we
// don't fan out N×SELECT inside the loop.
$catImageMap = [];
try {
    foreach (db()->query(
        "SELECT category, MIN(image) AS image
           FROM products
          WHERE is_active = 1 AND image IS NOT NULL AND image <> ''
          GROUP BY category"
    ) as $row) {
        if (!empty($row['image'])) {
            $catImageMap[strtolower((string)$row['category'])] = (string)$row['image'];
        }
    }
} catch (Throwable $e) {}
foreach ($hubs as $h) {
    $lm = !empty($h['updated_at']) ? substr((string)$h['updated_at'], 0, 10) : $today;
    $hubImg = '';
    foreach ((array)($h['categories'] ?? []) as $hc) {
        $k = strtolower((string)$hc);
        if (isset($catImageMap[$k])) { $hubImg = $catImageMap[$k]; break; }
    }
    $hubImgAbs = $hubImg === '' ? '' : (preg_match('#^https?://#i', $hubImg) ? $hubImg : $base . '/' . ltrim($hubImg, '/'));
    $urls[] = [
        'loc'     => $base . '/hub/' . $h['slug'],
        'lastmod' => $lm,
        'freq'    => 'weekly',
        'pri'     => '1.0',
        'images'  => $hubImgAbs ? [['loc' => $hubImgAbs, 'title' => trim((string)$h['title'])]] : [],
    ];
}

// ---------------------------------------------------------------
// 4) Products — real `updated_at` + image entry.  Visual search love.
// ---------------------------------------------------------------
$prodCols = ['slug', 'name', 'image'];
if ($prodHasUpdatedAt) $prodCols[] = 'updated_at';
$colsSql = implode(',', $prodCols);
foreach (db()->query("SELECT $colsSql FROM products WHERE is_active = 1 AND " . active_regions_sql_in('region')) as $r) {
    $lm = !empty($r['updated_at']) ? substr((string)$r['updated_at'], 0, 10) : $today;
    $imgRaw = trim((string)($r['image'] ?? ''));
    $imgAbs = $imgRaw === '' ? '' : (preg_match('#^https?://#i', $imgRaw) ? $imgRaw : $base . '/' . ltrim($imgRaw, '/'));
    $urls[] = [
        'loc'     => $base . '/product.php?slug=' . $r['slug'],
        'lastmod' => $lm,
        'freq'    => 'weekly',
        'pri'     => '0.8',
        'images'  => $imgAbs ? [['loc' => $imgAbs, 'title' => trim((string)$r['name'])]] : [],
    ];
}

// ---------------------------------------------------------------
// 5) Blog posts — image entries + News markup for the freshest 48 h.
// AI overview engines (Google AIO, Perplexity, ChatGPT search) bias
// toward sitemaps with explicit news annotations on fresh content.
// ---------------------------------------------------------------
$blogCols = 'id, title, date, image';
try {
    $has = db()->query("SHOW COLUMNS FROM blog_posts LIKE 'updated_at'")->fetch();
    if ($has) $blogCols .= ', updated_at';
} catch (Throwable $e) {}
$brandName = function_exists('site_brand_safe') ? site_brand_safe() : (defined('SITE_BRAND') ? SITE_BRAND : '');
$fresh48hCutoff = strtotime('-48 hours');
foreach (db()->query("SELECT $blogCols FROM blog_posts ORDER BY date DESC") as $r) {
    $dateStr = (string)($r['updated_at'] ?? '') ?: (string)$r['date'];
    $lm  = substr($dateStr, 0, 10) ?: $today;
    $ts  = strtotime($dateStr) ?: 0;
    $img = trim((string)($r['image'] ?? ''));
    $imgAbs = $img === '' ? '' : (preg_match('#^https?://#i', $img) ? $img : $base . '/' . ltrim($img, '/'));
    $row = [
        'loc'     => $base . '/blog-post.php?id=' . $r['id'],
        'lastmod' => $lm,
        'freq'    => 'monthly',
        'pri'     => '0.7',
        'images'  => $imgAbs ? [['loc' => $imgAbs, 'title' => trim((string)$r['title'])]] : [],
    ];
    if ($ts > $fresh48hCutoff && $brandName !== '') {
        // Google News annotations for posts published in the last 48h.
        $row['news'] = [
            'publication_name'     => $brandName,
            'publication_language' => 'en',
            'publication_date'     => gmdate('Y-m-d\TH:i:s\Z', $ts),
            'title'                => trim((string)$r['title']),
        ];
    }
    $urls[] = $row;
}

// ---------------------------------------------------------------
// 6) Content / legal pages — last-modified from DB.
// ---------------------------------------------------------------
$pageCols = 'slug';
try {
    $has = db()->query("SHOW COLUMNS FROM pages LIKE 'updated_at'")->fetch();
    if ($has) $pageCols .= ', updated_at';
} catch (Throwable $e) {}
foreach (db()->query("SELECT $pageCols FROM pages") as $r) {
    $lm = !empty($r['updated_at']) ? substr((string)$r['updated_at'], 0, 10) : $today;
    $urls[] = ['loc' => $base . '/page.php?slug=' . $r['slug'], 'lastmod' => $lm, 'freq' => 'monthly', 'pri' => '0.4', 'images' => []];
}

// ---------------------------------------------------------------
// EMIT
// ---------------------------------------------------------------
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
/* XSLT stylesheet — turns the raw XML into a human-readable table when
   opened in a browser (admin "View Sitemap" click, manual inspection,
   etc.).  Search-engine crawlers ignore the PI, so SEO crawl quality is
   unaffected — only the visual presentation gets a facelift. */
echo '<?xml-stylesheet type="text/xsl" href="/assets/sitemap.xsl"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' . "\n";
echo '        xmlns:xhtml="http://www.w3.org/1999/xhtml"' . "\n";
echo '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
// hreflang map — mirrors includes/header.php so the on-page <link rel="alternate">
// tags and the sitemap agree on every region/language signal Google sees.
$hreflangMap = ['US' => 'en-US', 'UK' => 'en-GB', 'AU' => 'en-AU', 'CA' => 'en-CA', 'EU' => 'en'];
foreach ($urls as $u) {
    // Region-aware bare path (everything after the canonical host) so we can
    // emit the /au, /uk, /ca, /eu equivalents as hreflang alternates.
    $bare = substr($u['loc'], strlen($base));
    if ($bare === '' || $bare === false) $bare = '/';
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    echo "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
    echo "    <changefreq>" . $u['freq'] . "</changefreq>\n";
    echo "    <priority>" . $u['pri'] . "</priority>\n";
    // Per-country hreflang alternates + an x-default pointing at the US root.
    foreach ($hreflangMap as $cc => $lang) {
        $pre = ($cc === 'US') ? '' : '/' . strtolower($cc);
        echo '    <xhtml:link rel="alternate" hreflang="' . $lang . '" href="' . htmlspecialchars($base . $pre . $bare, ENT_XML1) . "\"/>\n";
    }
    echo '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . $bare, ENT_XML1) . "\"/>\n";
    if (!empty($u['news'])) {
        echo "    <news:news>\n";
        echo "      <news:publication>\n";
        echo "        <news:name>" . htmlspecialchars($u['news']['publication_name'], ENT_XML1) . "</news:name>\n";
        echo "        <news:language>" . htmlspecialchars($u['news']['publication_language'], ENT_XML1) . "</news:language>\n";
        echo "      </news:publication>\n";
        echo "      <news:publication_date>" . $u['news']['publication_date'] . "</news:publication_date>\n";
        echo "      <news:title>" . htmlspecialchars($u['news']['title'], ENT_XML1) . "</news:title>\n";
        echo "    </news:news>\n";
    }
    foreach ($u['images'] as $img) {
        echo "    <image:image>\n";
        echo "      <image:loc>"   . htmlspecialchars($img['loc'], ENT_XML1) . "</image:loc>\n";
        if (!empty($img['title'])) {
            echo "      <image:title>" . htmlspecialchars($img['title'], ENT_XML1) . "</image:title>\n";
        }
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}
echo '</urlset>';
