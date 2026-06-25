<?php
/* ===========================================================================
 *  PRESS KIT  /  EMBEDDABLE BADGES  —  /press-kit
 *  ---------------------------------------------------------------------------
 *  Public landing page bloggers / affiliates / journalists can use to grab:
 *     • Brand assets (logo, colour palette)
 *     • Pre-written boilerplate paragraphs
 *     • Copy-paste <script> snippets that embed a "Buy now" badge on their
 *       site (every install generates a real backlink back to us).
 *
 *  Bootstrapping backlinks programmatically requires giving the outside
 *  world something easy to embed — this page is that magnet.
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

$pageTitle       = 'Press Kit & Embeddable Badges | ' . SITE_BRAND;
$pageDescription = 'Brand assets, boilerplate copy and copy-paste badge widgets to feature ' . SITE_BRAND . ' on your blog, review site or newsletter.';

$siteUrl = rtrim(site_url(), '/');
// Live-preview badges load from a ROOT-RELATIVE path so they resolve on the
// exact same host + scheme the page is served on (avoids mixed-content blocks
// and internal-host mismatches). The copy-paste snippets keep the canonical
// absolute $siteUrl — the domain publishers should actually paste.
$previewBase = '';
$brand   = function_exists('company_info') ? (company_info()['name'] ?? SITE_BRAND) : SITE_BRAND;

/* Pick three popular products for the per-product badge demos. */
$badgeProducts = db()->query("SELECT slug, name FROM products WHERE " . active_regions_sql_in('region') . " ORDER BY reviews DESC LIMIT 3")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?= render_page_head(
    'Press Kit & Embeddable Badges',
    'Brand assets and copy-paste widgets to feature ' . esc($brand) . ' on your site.',
    ['Press Kit' => null],
    'press-kit-title'
) ?>

<div class="container py-4 py-lg-5">

  <!-- INTRO -->
  <section class="mb-5" data-testid="press-intro">
    <p class="lead text-secondary">
      Featuring <?= esc($brand) ?> in a review, comparison or guide?
      Grab the assets below — and drop one of our copy-paste
      <strong>embed badges</strong> on your site to give your readers a
      one-click path to genuine, licensed software.
    </p>
  </section>

  <!-- EMBEDDABLE BADGE -->
  <section class="mb-5" id="badges" data-testid="press-badges">
    <h2 class="h4 fw-bold mb-2">Embed a "Buy now" badge</h2>
    <p class="text-secondary">
      Paste a single <code>&lt;script&gt;</code> tag wherever you want the
      badge to appear.  It's lightweight (under 2&nbsp;KB gzipped),
      async-loaded, cookieless and fully GDPR-friendly.
    </p>

    <div class="row g-4 mt-1">
      <!-- GENERIC SHOP BADGE -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h3 class="h6 fw-bold">Generic shop badge</h3>
            <p class="small text-secondary mb-3">Links to the full product catalog.</p>
            <div class="p-3 rounded mb-3" style="background:#f8fafc;">
              <!-- live preview -->
              <script src="<?= esc($previewBase) ?>/embed/badge.js" async data-testid="badge-preview-shop"></script>
            </div>
            <label class="small fw-semibold mb-1">Copy &amp; paste:</label>
<pre class="bg-dark text-light rounded p-3 small mb-0" style="white-space:pre-wrap;word-break:break-all;" data-testid="badge-snippet-shop"><code>&lt;script src="<?= esc($siteUrl) ?>/embed/badge.js" async&gt;&lt;/script&gt;</code></pre>
          </div>
        </div>
      </div>

      <!-- LIGHT THEME BADGE -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h3 class="h6 fw-bold">Light-theme badge</h3>
            <p class="small text-secondary mb-3">Same widget, light skin for bright themes.</p>
            <div class="p-3 rounded mb-3" style="background:#0f172a;">
              <script src="<?= esc($previewBase) ?>/embed/badge.js" async data-theme="light" data-testid="badge-preview-light"></script>
            </div>
            <label class="small fw-semibold mb-1">Copy &amp; paste:</label>
<pre class="bg-dark text-light rounded p-3 small mb-0" style="white-space:pre-wrap;word-break:break-all;" data-testid="badge-snippet-light"><code>&lt;script src="<?= esc($siteUrl) ?>/embed/badge.js" async data-theme="light"&gt;&lt;/script&gt;</code></pre>
          </div>
        </div>
      </div>

      <?php foreach ($badgeProducts as $i => $p): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <h3 class="h6 fw-bold"><?= esc($p['name']) ?> badge</h3>
              <p class="small text-secondary mb-3">Links straight to this product page.</p>
              <div class="p-3 rounded mb-3" style="background:#f8fafc;">
                <script src="<?= esc($previewBase) ?>/embed/badge.js" async data-product="<?= esc($p['slug']) ?>" data-testid="badge-preview-<?= $i ?>"></script>
              </div>
              <label class="small fw-semibold mb-1">Copy &amp; paste:</label>
<pre class="bg-dark text-light rounded p-3 small mb-0" style="white-space:pre-wrap;word-break:break-all;" data-testid="badge-snippet-<?= $i ?>"><code>&lt;script src="<?= esc($siteUrl) ?>/embed/badge.js" async
        data-product="<?= esc($p['slug']) ?>"&gt;&lt;/script&gt;</code></pre>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-3 small text-secondary">
      <strong>Tip:</strong> add <code>data-label="Custom headline"</code> or
      <code>data-theme="light"</code> to any snippet above for a quick restyle.
    </div>
  </section>

  <!-- BOILERPLATE -->
  <section class="mb-5" id="boilerplate" data-testid="press-boilerplate">
    <h2 class="h4 fw-bold mb-2">Brand boilerplate</h2>
    <p class="text-secondary">Drop into "About <?= esc($brand) ?>" sections in articles or press releases.</p>
    <div class="card">
      <div class="card-body">
<pre class="mb-0" style="white-space:pre-wrap;font-family:inherit;font-size:.92rem;line-height:1.55;" data-testid="boilerplate-text"><?= esc($brand) ?> is an authorized digital reseller of genuine Microsoft, Bitdefender, Norton, McAfee, Adobe and Autodesk license keys.
Every key is delivered to the customer's inbox within 15-30 minutes of purchase, activates online and is a one-time
purchase — no recurring subscription.  Built for small businesses, IT teams and home users in the US, UK, Canada
and Australia, <?= esc($brand) ?> backs every order with 24/7 support and a 30-day money-back guarantee.</pre>
      </div>
    </div>
  </section>

  <!-- ASSETS -->
  <section class="mb-5" id="assets" data-testid="press-assets">
    <h2 class="h4 fw-bold mb-2">Brand assets</h2>
    <ul class="list-unstyled">
      <li class="mb-2"><a href="<?= esc($siteUrl) ?>/assets/images/badges/microsoft-verified.svg" download data-testid="asset-logo"><i class="bi bi-download me-1"></i> Microsoft Verified badge (SVG)</a></li>
      <li class="mb-2"><a href="<?= esc($siteUrl) ?>/sitemap.xml" data-testid="asset-sitemap"><i class="bi bi-list-ul me-1"></i> Full sitemap.xml</a></li>
      <li class="mb-2"><a href="<?= esc($siteUrl) ?>/llms.txt" data-testid="asset-llms"><i class="bi bi-robot me-1"></i> /llms.txt — AI discovery manifest</a></li>
      <li class="mb-2"><a href="<?= esc($siteUrl) ?>/agents.json" data-testid="asset-agents"><i class="bi bi-cpu me-1"></i> /agents.json — AI agent manifest</a></li>
    </ul>
  </section>

  <!-- AFFILIATE CTA removed -->

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
