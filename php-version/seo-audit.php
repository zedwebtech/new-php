<?php
/**
 * SEO / GEO / AEO Audit Dashboard.
 *
 * Single-click, admin-only audit that crawls every product, category and
 * blog URL on the storefront, scores each on four dimensions, and emits
 * a downloadable HTML report.  Re-uses the existing admin shell so the
 * look matches the rest of the admin UI.
 *
 *  Score (100 pts):
 *    25 pts  Meta keywords  (count of comma-separated phrases, log-scaled)
 *    25 pts  JSON-LD blocks (expected types present + parse cleanly)
 *    25 pts  Visible SEO copy length (H1/H2/H3 word count in <main>)
 *    25 pts  Image alt richness (% of <img> with descriptive alt text)
 *
 *  Endpoints (single file):
 *    GET  /seo-audit.php             → dashboard + "Run Audit" button
 *    GET  /seo-audit.php?action=run  → run audit, render results inline
 *    GET  /seo-audit.php?action=download → run audit, serve HTML report
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$admin = require_admin();

$action = $_GET['action'] ?? '';

/* -------------------------------------------------------------------------
 *  Build the list of URLs to audit (products + categories + blog posts +
 *  the four "marquee" pages: home, shop, blog index, sitemap).
 * ----------------------------------------------------------------------- */
function audit_collect_urls(): array
{
    $pdo = db();
    $urls = [
        ['type' => 'home',     'label' => 'Homepage',    'path' => '/index.php'],
        ['type' => 'shop',     'label' => 'Shop index',  'path' => '/shop.php'],
        ['type' => 'blog',     'label' => 'Blog index',  'path' => '/blog.php'],
    ];
    // Categories
    try {
        $rows = $pdo->query("SELECT slug, name FROM categories WHERE slug IS NOT NULL AND slug <> '' ORDER BY slug")->fetchAll();
        foreach ($rows as $r) {
            $urls[] = ['type' => 'category', 'label' => 'Category — ' . $r['name'], 'path' => '/category.php?slug=' . urlencode($r['slug'])];
        }
    } catch (Throwable $e) {}
    // Products
    try {
        $rows = $pdo->query("SELECT slug, name FROM products WHERE slug IS NOT NULL AND slug <> '' ORDER BY name")->fetchAll();
        foreach ($rows as $r) {
            $urls[] = ['type' => 'product', 'label' => 'Product — ' . $r['name'], 'path' => '/product.php?slug=' . urlencode($r['slug'])];
        }
    } catch (Throwable $e) {}
    // Blog posts
    try {
        $rows = $pdo->query("SELECT id, title FROM blog_posts WHERE id IS NOT NULL ORDER BY id")->fetchAll();
        foreach ($rows as $r) {
            $urls[] = ['type' => 'blog_post', 'label' => 'Blog — ' . $r['title'], 'path' => '/blog-post.php?id=' . urlencode((string)$r['id'])];
        }
    } catch (Throwable $e) {}
    return $urls;
}

/* -------------------------------------------------------------------------
 *  Resolve a base URL the audit can actually reach from inside the server.
 *  Tries the loopback on the real server port first, then the public host,
 *  then the dev port — returns the first that answers.  This makes the audit
 *  work on ANY domain (the old hardcoded 127.0.0.1:3000 only worked in dev,
 *  which is why every URL scored 0 once deployed).
 * ----------------------------------------------------------------------- */
function audit_base_url(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port   = (string)($_SERVER['SERVER_PORT'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    $candidates = [];
    if ($port !== '' && !in_array($port, ['80', '443'], true)) $candidates[] = 'http://127.0.0.1:' . $port;
    $candidates[] = $scheme . '://' . $host;                 // public host (works on most domains)
    if ($port !== '') $candidates[] = 'http://127.0.0.1:' . $port;
    $candidates[] = 'http://127.0.0.1:3000';                 // dev fallback
    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $cand) {
        $ch = curl_init($cand . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY         => false,
            CURLOPT_HTTPHEADER     => ['Host: ' . $host],
            CURLOPT_USERAGENT      => 'Maventech-SEO-Audit/1.0',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err === '' && $code >= 200 && $code < 500) {
            return $cached = ['base' => $cand, 'host' => $host];
        }
    }
    return $cached = ['base' => $scheme . '://' . $host, 'host' => $host];
}

/* -------------------------------------------------------------------------
 *  Parallel cURL fetch.  Crawls all URLs against a reachable local base
 *  (resolved by audit_base_url) with the public Host header so pages render
 *  exactly as visitors see them.  Returns path → ['status', 'html'].
 * ----------------------------------------------------------------------- */
function audit_fetch_parallel(array $urls, int $concurrency = 8): array
{
    $results = [];
    $resolved = audit_base_url();
    $base = $resolved['base'];
    $host = $resolved['host'];
    $mh      = curl_multi_init();
    $batches = array_chunk($urls, max(1, $concurrency));
    foreach ($batches as $batch) {
        $handles = [];
        foreach ($batch as $u) {
            $ch = curl_init($base . $u['path']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => ['Host: ' . $host],
                CURLOPT_USERAGENT      => 'Maventech-SEO-Audit/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$u['path']] = $ch;
        }
        // Drain the batch.
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.5);
        } while ($active && $status === CURLM_OK);
        foreach ($handles as $path => $ch) {
            $results[$path] = [
                'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'html'   => (string)curl_multi_getcontent($ch),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }
    curl_multi_close($mh);
    return $results;
}

/* -------------------------------------------------------------------------
 *  Per-URL scoring helpers.  Each returns
 *    ['score' => 0..25, 'label' => '...', 'detail' => 'numeric facts'].
 * ----------------------------------------------------------------------- */
function score_meta_keywords(string $html): array
{
    if (!preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        return ['score' => 0, 'label' => 'missing', 'detail' => 'no <meta name="keywords">'];
    }
    $kws = array_filter(array_map('trim', explode(',', html_entity_decode($m[1]))));
    $n = count($kws);
    // Log-scaled: 0 → 0, 10 → 18, 25 → 25.
    if ($n === 0)       $s = 0;
    elseif ($n < 5)     $s = 6;
    elseif ($n < 10)    $s = 14;
    elseif ($n < 20)    $s = 20;
    else                $s = 25;
    return ['score' => $s, 'label' => $n . ' keywords', 'detail' => $n . ' phrases'];
}

function score_jsonld(string $html, string $type): array
{
    preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m);
    $blocks = $m[1] ?? [];
    $valid = 0;
    $found = [];
    // Recursive @type extractor — walks @graph arrays + nested objects so
    // pages that bundle Organization + LocalBusiness + WebSite inside a
    // single @graph (the homepage) are scored correctly.
    $collect = function ($node) use (&$collect, &$found) {
        if (is_array($node)) {
            if (isset($node['@type'])) {
                $t = $node['@type'];
                if (is_array($t)) foreach ($t as $tt) $found[] = (string)$tt;
                else $found[] = (string)$t;
            }
            foreach ($node as $v) {
                if (is_array($v)) $collect($v);
            }
        }
    };
    foreach ($blocks as $b) {
        $j = json_decode(trim($b), true);
        if (!is_array($j)) continue;
        $valid++;
        $collect($j);
    }
    // Expected types per URL family.
    $expected = match ($type) {
        'product'   => ['Product', 'BreadcrumbList', 'FAQPage'],
        'category'  => ['CollectionPage', 'BreadcrumbList', 'FAQPage', 'ItemList'],
        'blog_post' => ['Article', 'BreadcrumbList'],
        'blog'      => ['Blog', 'BreadcrumbList'],
        'shop'      => ['CollectionPage', 'BreadcrumbList'],
        default     => ['WebSite', 'Organization'],
    };
    $hit = 0;
    foreach ($expected as $exp) {
        foreach ($found as $f) {
            // BlogPosting is a sub-type of Article in schema.org so
            // treat them as interchangeable when scoring blog posts.
            if (stripos($f, $exp) !== false
                || ($exp === 'Article' && stripos($f, 'BlogPosting') !== false)) {
                $hit++; break;
            }
        }
    }
    $coverage = count($expected) ? $hit / count($expected) : 0;
    $score = (int)round($coverage * 22);
    if ($valid > 0 && $score < 25) $score += 3; // bonus for any valid JSON-LD at all
    if ($score > 25) $score = 25;
    return [
        'score'  => $score,
        'label'  => $hit . ' / ' . count($expected) . ' expected schemas',
        'detail' => 'found: ' . (implode(', ', array_slice(array_unique($found), 0, 6)) ?: 'none'),
    ];
}

function score_copy_length(string $html): array
{
    // Strip scripts/styles/nav/footer/headers, then count words in the body.
    $stripped = preg_replace('#<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?</\1>#si', ' ', $html) ?? $html;
    $stripped = preg_replace('#<[^>]+>#', ' ', $stripped) ?? $stripped;
    $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5);
    $words = preg_split('/\s+/', trim($stripped)) ?: [];
    $n = count(array_filter($words));
    if ($n < 300)        $s = 4;
    elseif ($n < 700)    $s = 10;
    elseif ($n < 1200)   $s = 18;
    elseif ($n < 2000)   $s = 22;
    else                 $s = 25;
    return ['score' => $s, 'label' => number_format($n) . ' words', 'detail' => $n . ' word count'];
}

function score_image_alts(string $html): array
{
    preg_match_all('#<img[^>]+>#i', $html, $m);
    $imgs = $m[0] ?? [];
    if (!$imgs) return ['score' => 25, 'label' => 'no images', 'detail' => 'page has no <img>'];
    $rich = 0;
    foreach ($imgs as $tag) {
        if (preg_match('/alt=["\']([^"\']*)["\']/i', $tag, $am)) {
            $altLen = strlen(trim(html_entity_decode($am[1])));
            // "Rich" = >= 12 chars OF descriptive text.
            if ($altLen >= 12) $rich++;
        }
    }
    $ratio = $rich / count($imgs);
    $s = (int)round($ratio * 25);
    return [
        'score'  => $s,
        'label'  => $rich . ' / ' . count($imgs) . ' rich alts',
        'detail' => round($ratio * 100) . '% images with descriptive alt',
    ];
}

/* -------------------------------------------------------------------------
 *  Run the audit end-to-end and return the rows ready for rendering.
 * ----------------------------------------------------------------------- */
function audit_run(): array
{
    @set_time_limit(180);
    @ini_set('memory_limit', '256M');

    $started = microtime(true);
    $urls    = audit_collect_urls();
    $fetched = audit_fetch_parallel($urls);
    $rows    = [];

    foreach ($urls as $u) {
        $r = $fetched[$u['path']] ?? ['status' => 0, 'html' => ''];
        if ($r['status'] !== 200 || $r['html'] === '') {
            $rows[] = [
                'type'   => $u['type'],
                'label'  => $u['label'],
                'path'   => $u['path'],
                'http'   => $r['status'] ?: '—',
                'total'  => 0,
                'parts'  => [
                    'kw'   => ['score' => 0, 'label' => '—', 'detail' => 'fetch failed'],
                    'sd'   => ['score' => 0, 'label' => '—', 'detail' => 'fetch failed'],
                    'copy' => ['score' => 0, 'label' => '—', 'detail' => 'fetch failed'],
                    'alt'  => ['score' => 0, 'label' => '—', 'detail' => 'fetch failed'],
                ],
            ];
            continue;
        }
        $kw   = score_meta_keywords($r['html']);
        $sd   = score_jsonld($r['html'], $u['type']);
        $copy = score_copy_length($r['html']);
        $alt  = score_image_alts($r['html']);
        $rows[] = [
            'type'  => $u['type'],
            'label' => $u['label'],
            'path'  => $u['path'],
            'http'  => $r['status'],
            'total' => $kw['score'] + $sd['score'] + $copy['score'] + $alt['score'],
            'parts' => ['kw' => $kw, 'sd' => $sd, 'copy' => $copy, 'alt' => $alt],
        ];
    }
    // Sort by score ascending → worst first (most actionable view).
    usort($rows, fn($a, $b) => $a['total'] <=> $b['total']);
    return [
        'rows'      => $rows,
        'fetched'   => count($fetched),
        'total'     => count($urls),
        'duration'  => round(microtime(true) - $started, 2),
        'generated' => date('Y-m-d H:i:s'),
    ];
}

/* -------------------------------------------------------------------------
 *  Score → CSS classes for visual banding.
 * ----------------------------------------------------------------------- */
function audit_band(int $score, int $max = 100): array
{
    $pct = $max ? ($score / $max) : 0;
    if ($pct >= 0.90) return ['cls' => 'success',   'label' => 'Excellent'];
    if ($pct >= 0.75) return ['cls' => 'primary',   'label' => 'Good'];
    if ($pct >= 0.60) return ['cls' => 'warning',   'label' => 'Fair'];
    return                  ['cls' => 'danger',    'label' => 'Needs work'];
}

/* -------------------------------------------------------------------------
 *  Downloadable HTML report — fully standalone, embeddable in email.
 * ----------------------------------------------------------------------- */
if ($action === 'download') {
    $audit = audit_run();
    $brand = defined('SITE_BRAND') ? SITE_BRAND : 'Site';
    $filename = 'seo-audit-' . date('Ymd-His') . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $h = '<!doctype html><html><head><meta charset="utf-8"><title>SEO Audit Report &mdash; ' . esc($brand) . ' &mdash; ' . esc($audit['generated']) . '</title>';
    $h .= '<style>body{font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;}h1{font-size:22px;margin:0 0 4px}small{color:#64748b}table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{background:#f1f5f9;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}.b-success{background:#dcfce7;color:#166534}.b-primary{background:#dbeafe;color:#1e40af}.b-warning{background:#fef3c7;color:#92400e}.b-danger{background:#fee2e2;color:#991b1b}.badge{display:inline-block;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:600}.score{font-weight:700;font-size:14px}.muted{color:#64748b;font-size:11px}.kpi{display:inline-block;background:#fff;border-radius:10px;padding:14px 22px;margin:0 8px 12px 0;box-shadow:0 1px 3px rgba(0,0,0,.06)}.kpi b{display:block;font-size:22px;color:#0f172a}.kpi i{display:block;font-size:11px;color:#64748b;font-style:normal;text-transform:uppercase;letter-spacing:.05em;margin-top:4px}</style></head><body>';
    $h .= '<h1>SEO / GEO / AEO Audit &mdash; ' . esc($brand) . '</h1>';
    $h .= '<small>Generated ' . esc($audit['generated']) . ' &middot; ' . esc((string)$audit['fetched']) . ' URLs crawled in ' . esc((string)$audit['duration']) . 's</small>';

    $totals = array_column($audit['rows'], 'total');
    $avg    = $totals ? array_sum($totals) / count($totals) : 0;
    $excel  = count(array_filter($totals, fn($x) => $x >= 90));
    $needs  = count(array_filter($totals, fn($x) => $x < 60));
    $h .= '<div style="margin:14px 0 18px">';
    $h .= '<div class="kpi"><b>' . esc(number_format($avg, 1)) . ' / 100</b><i>Avg score</i></div>';
    $h .= '<div class="kpi"><b>' . esc((string)$excel) . '</b><i>Excellent (&ge; 90)</i></div>';
    $h .= '<div class="kpi"><b>' . esc((string)$needs) . '</b><i>Needs work (&lt; 60)</i></div>';
    $h .= '<div class="kpi"><b>' . esc((string)count($audit['rows'])) . '</b><i>URLs scanned</i></div>';
    $h .= '</div>';

    $h .= '<table><thead><tr><th>URL</th><th>HTTP</th><th>Score</th><th>Keywords</th><th>JSON-LD</th><th>Copy</th><th>Image Alt</th></tr></thead><tbody>';
    foreach ($audit['rows'] as $r) {
        $band = audit_band((int)$r['total']);
        $h .= '<tr><td><div style="font-weight:600">' . esc($r['label']) . '</div><div class="muted">' . esc($r['path']) . '</div></td>';
        $h .= '<td>' . esc((string)$r['http']) . '</td>';
        $h .= '<td><span class="score badge b-' . esc($band['cls']) . '">' . esc((string)$r['total']) . ' &middot; ' . esc($band['label']) . '</span></td>';
        foreach (['kw', 'sd', 'copy', 'alt'] as $k) {
            $p = $r['parts'][$k];
            $pBand = audit_band((int)$p['score'], 25);
            $h .= '<td><span class="badge b-' . esc($pBand['cls']) . '">' . esc((string)$p['score']) . ' / 25</span><div class="muted">' . esc($p['label']) . '</div></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table>';
    $h .= '</body></html>';
    echo $h;
    exit;
}

/* -------------------------------------------------------------------------
 *  Browser-visible dashboard.  Re-uses admin-shell so the navigation
 *  topbar, sidebar and theme toggle stay consistent with the rest of the
 *  admin area.
 * ----------------------------------------------------------------------- */
$tab = 'seo-audit';   // pseudo-tab so the admin shell renders without errors.
$pdo = db();
$rg  = active_region();

$audit = null;
if ($action === 'run') {
    $audit = audit_run();
}
include __DIR__ . '/includes/admin-shell.php';
?>
<style>
  .seo-aud-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
  [data-theme="dark"] .seo-aud-card{background:#0f172a;border-color:#1e293b;color:#e2e8f0;}
  .seo-aud-kpi{background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:12px;padding:18px 22px;}
  [data-theme="dark"] .seo-aud-kpi{background:linear-gradient(135deg,#172554,#1e3a8a);border-color:#1e40af;color:#dbeafe;}
  .seo-aud-kpi b{display:block;font-size:26px;font-weight:800;line-height:1;}
  .seo-aud-kpi i{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#475569;font-style:normal;margin-top:6px;}
  [data-theme="dark"] .seo-aud-kpi i{color:#94a3b8;}
  .audit-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#475569;background:#f8fafc;border-bottom:2px solid #e2e8f0;}
  [data-theme="dark"] .audit-tbl th{color:#94a3b8;background:#0f172a;border-color:#1e293b;}
  .audit-tbl td{vertical-align:middle;font-size:13px;border-bottom:1px solid #f1f5f9;}
  [data-theme="dark"] .audit-tbl td{border-color:#1e293b;}
  .audit-tbl .small-muted{font-size:11px;color:#64748b;}
  [data-theme="dark"] .audit-tbl .small-muted{color:#94a3b8;}
</style>

<main class="adm-main py-4" data-testid="seo-audit-main">
  <div class="container-xxl">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
      <div>
        <h1 class="h3 fw-bold mb-0" data-testid="seo-audit-title">SEO / GEO / AEO Audit</h1>
        <small class="text-secondary">Crawls every product, category and blog URL on the live storefront and scores each on four signals.</small>
      </div>
      <div class="ms-auto d-flex flex-wrap gap-2">
        <a href="admin.php?tab=ai-blogger" class="btn btn-outline-secondary rounded-pill px-3" data-testid="back-admin"><i class="bi bi-arrow-left me-1"></i>Back to admin</a>
        <a href="seo-audit.php?action=run" class="btn btn-primary rounded-pill px-4" data-testid="run-audit-btn"><i class="bi bi-radar me-1"></i>Run audit now</a>
        <a href="seo-audit.php?action=download" class="btn btn-success rounded-pill px-4" data-testid="download-report-btn"><i class="bi bi-download me-1"></i>Download HTML report</a>
      </div>
    </div>

    <?php if ($audit === null): ?>
      <div class="seo-aud-card" data-testid="audit-empty-state">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-info-circle text-primary me-1"></i>What this audit measures</h2>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="seo-aud-kpi"><b>25</b><i>Meta keywords</i></div>
            <small class="text-secondary d-block mt-2">Count of long-tail keyword phrases in <code>&lt;meta name="keywords"&gt;</code>.</small>
          </div>
          <div class="col-md-3">
            <div class="seo-aud-kpi"><b>25</b><i>JSON-LD coverage</i></div>
            <small class="text-secondary d-block mt-2">Expected schemas present + parsing cleanly (Product, FAQPage, BreadcrumbList, etc).</small>
          </div>
          <div class="col-md-3">
            <div class="seo-aud-kpi"><b>25</b><i>Visible SEO copy</i></div>
            <small class="text-secondary d-block mt-2">Word count of body text — buying guides, FAQs, intent paragraphs.</small>
          </div>
          <div class="col-md-3">
            <div class="seo-aud-kpi"><b>25</b><i>Image alt richness</i></div>
            <small class="text-secondary d-block mt-2">% of <code>&lt;img&gt;</code> tags with descriptive (&ge;12 char) alt text.</small>
          </div>
        </div>
        <p class="text-secondary mb-0">Click <strong>Run audit now</strong> to crawl every URL. Typical runtime: 5&ndash;30 seconds depending on catalogue size.</p>
      </div>
    <?php else: $rows = $audit['rows'];
      $totals = array_column($rows, 'total');
      $avg    = $totals ? array_sum($totals) / count($totals) : 0;
      $excel  = count(array_filter($totals, fn($x) => $x >= 90));
      $good   = count(array_filter($totals, fn($x) => $x >= 75 && $x < 90));
      $fair   = count(array_filter($totals, fn($x) => $x >= 60 && $x < 75));
      $needs  = count(array_filter($totals, fn($x) => $x < 60));
    ?>
      <div class="row g-3 mb-4" data-testid="audit-summary">
        <div class="col-md-3 col-6"><div class="seo-aud-kpi"><b><?= esc(number_format($avg, 1)) ?></b><i>Avg score / 100</i></div></div>
        <div class="col-md-3 col-6"><div class="seo-aud-kpi" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border-color:#86efac;"><b><?= esc((string)$excel) ?></b><i>Excellent (&ge; 90)</i></div></div>
        <div class="col-md-3 col-6"><div class="seo-aud-kpi" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;"><b><?= esc((string)$fair) ?></b><i>Fair (60-74)</i></div></div>
        <div class="col-md-3 col-6"><div class="seo-aud-kpi" style="background:linear-gradient(135deg,#fee2e2,#fecaca);border-color:#fca5a5;"><b><?= esc((string)$needs) ?></b><i>Needs work (&lt; 60)</i></div></div>
      </div>
      <div class="seo-aud-card">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <h2 class="h6 fw-bold mb-0"><i class="bi bi-table me-1"></i>Audit results (lowest-scoring first)</h2>
          <small class="text-secondary ms-auto">Crawled <?= esc((string)$audit['fetched']) ?> URLs in <?= esc((string)$audit['duration']) ?>s &middot; <?= esc($audit['generated']) ?></small>
        </div>
        <div class="table-responsive">
          <table class="table audit-tbl mb-0" data-testid="audit-results-table">
            <thead><tr>
              <th>URL</th><th>HTTP</th><th>Score</th>
              <th>Keywords (25)</th><th>JSON-LD (25)</th><th>Copy (25)</th><th>Image Alt (25)</th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $r): $band = audit_band((int)$r['total']); ?>
                <tr data-testid="audit-row-<?= $i ?>">
                  <td>
                    <a href="<?= esc($r['path']) ?>" target="_blank" rel="noopener" class="fw-bold text-decoration-none"><?= esc(mb_strimwidth($r['label'], 0, 60, '…')) ?></a>
                    <div class="small-muted"><?= esc($r['path']) ?></div>
                  </td>
                  <td><span class="badge bg-<?= $r['http'] === 200 ? 'success' : 'danger' ?> rounded-pill"><?= esc((string)$r['http']) ?></span></td>
                  <td><span class="badge rounded-pill bg-<?= esc($band['cls']) ?>" style="font-size:13px;padding:6px 10px;"><?= esc((string)$r['total']) ?> &middot; <?= esc($band['label']) ?></span></td>
                  <?php foreach (['kw', 'sd', 'copy', 'alt'] as $k):
                    $p = $r['parts'][$k];
                    $pb = audit_band((int)$p['score'], 25);
                  ?>
                    <td>
                      <span class="badge rounded-pill bg-<?= esc($pb['cls']) ?>"><?= esc((string)$p['score']) ?></span>
                      <div class="small-muted" title="<?= esc($p['detail']) ?>"><?= esc($p['label']) ?></div>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
