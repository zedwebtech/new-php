<?php
/**
 * /brand.php?slug=microsoft — public Brand profile page.
 *
 * Sections:
 *   • Hero with brand name + logo + product/article counts
 *   • Tab nav (Products · Articles · Reviews)
 *   • "Articles" tab lists every AI-published blog post that features a
 *     product of this brand, organised newest-first and auto-updated.
 */
require_once __DIR__ . '/includes/functions.php';

$slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', $_GET['slug'] ?? ''));
if ($slug === '') { http_response_code(404); $noIndex = true; }

// Resolve the brand name from the products table.  We use a slugged
// lookup so /brand.php?slug=microsoft matches "Microsoft", "microsoft", etc.
$brandLabel = '';
try {
    $rows = db()->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $b) {
        if (strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$b)) === $slug) {
            $brandLabel = (string)$b;
            break;
        }
    }
} catch (Throwable $e) {}
if ($brandLabel === '') {
    http_response_code(404);
    $noIndex = true;
    $pageTitle = 'Brand not found | ' . SITE_BRAND;
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5 text-center"><h1>Brand not found</h1><p><a href="shop.php" class="btn btn-primary">Browse all products</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$active = $_GET['view'] ?? 'products'; // products | articles | reviews
if (!in_array($active, ['products','articles','reviews'], true)) $active = 'products';

// Products for this brand.
$products = [];
try {
    $stmt = db()->prepare("SELECT id, slug, name, brand, category, version, price, image, rating, reviews, description
                             FROM products WHERE brand = ? AND is_active = 1
                             ORDER BY rating DESC, reviews DESC");
    $stmt->execute([$brandLabel]);
    $products = $stmt->fetchAll();
} catch (Throwable $e) {}

// All AI-published articles whose featured product belongs to this brand.
// Self-heal the blog_posts schema first — public pages don't call
// ensure_db_schema() (admin-only) so a fresh install where the AI
// Auto-Blogger has never run yet may be missing these columns and the
// SELECT below would silently return zero rows.
$articles = [];
try {
    $pdo = db();
    $existing = $pdo->query("SHOW COLUMNS FROM blog_posts")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'ai_generated'          => "ALTER TABLE blog_posts ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0",
        'product_id'            => "ALTER TABLE blog_posts ADD COLUMN product_id INT NULL DEFAULT NULL",
        'created_at'            => "ALTER TABLE blog_posts ADD COLUMN created_at DATETIME NULL DEFAULT NULL",
        'target_region'         => "ALTER TABLE blog_posts ADD COLUMN target_region VARCHAR(4) NOT NULL DEFAULT 'US'",
        'indexnow_status'       => "ALTER TABLE blog_posts ADD COLUMN indexnow_status VARCHAR(20) NOT NULL DEFAULT ''",
        'verified_http'         => "ALTER TABLE blog_posts ADD COLUMN verified_http SMALLINT NULL DEFAULT NULL",
        'is_featured_trends'    => "ALTER TABLE blog_posts ADD COLUMN is_featured_trends TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $alter) {
        if (!in_array($col, $existing, true)) { try { $pdo->exec($alter); } catch (Throwable $e) {} }
    }
    $stmt = $pdo->prepare("SELECT bp.id, bp.title, bp.date, bp.image, bp.read_time, bp.target_region,
                                  bp.is_featured_trends, bp.created_at, bp.indexnow_status, bp.verified_http,
                                  p.name AS product_name, p.slug AS product_slug
                             FROM blog_posts bp
                             JOIN products p ON p.id = bp.product_id
                            WHERE bp.ai_generated = 1 AND p.brand = ?
                            ORDER BY COALESCE(bp.created_at, '1970-01-01') DESC, bp.id DESC");
    $stmt->execute([$brandLabel]);
    $articles = $stmt->fetchAll();
} catch (Throwable $e) {
    @error_log('[brand.php articles] ' . $e->getMessage());
}

// Avg rating across this brand's products (for the hero badge + JSON-LD).
$avgRating = 0; $reviewCount = 0;
if ($products) {
    $rTotal = 0; $rWeights = 0;
    foreach ($products as $p) {
        $rWeights += (int)$p['reviews'];
        $rTotal   += (float)$p['rating'] * (int)$p['reviews'];
    }
    $avgRating   = $rWeights > 0 ? round($rTotal / $rWeights, 1) : 0;
    $reviewCount = $rWeights;
}

$pageTitle       = $brandLabel . ' Software Keys & Guides | ' . SITE_BRAND;
$pageDescription = 'Shop genuine ' . $brandLabel . ' software keys at ' . SITE_BRAND . ' — ' . count($products) . ' products, ' . count($articles) . ' guides, instant delivery.';
/* Pick the first product image as the social-share preview so links to
 * /brand/?b=office show an actual Office box instead of the generic banner. */
if (!empty($products[0]['image'])) {
    $ogImage    = $products[0]['image'];
    $ogImageAlt = $brandLabel . ' — featured product on ' . SITE_BRAND;
}
$pageKeywords    = $brandLabel . ' licenses, buy ' . $brandLabel . ', ' . $brandLabel . ' software, ' . $brandLabel . ' deals';
$canonicalUrl    = site_url() . '/brand.php?slug=' . rawurlencode($slug);
$ogType          = 'website';

// Brand schema for AI engines (separate inline node — already a global Brand
// node in header.php, but this page-level one ties it to the product list).
$brandSchema = [
    '@context' => 'https://schema.org',
    '@type'    => 'Brand',
    'name'     => $brandLabel,
    'url'      => $canonicalUrl,
];

include __DIR__ . '/includes/header.php';
?>
<script type="application/ld+json"><?= json_encode(array_filter($brandSchema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<div class="container py-4 py-lg-5" data-testid="brand-profile">
  <!-- Brand hero -->
  <div class="d-flex flex-wrap align-items-center gap-4 mb-4 pb-3 border-bottom" data-testid="brand-hero">
    <div style="width:96px;height:96px;border-radius:16px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:white;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:800;flex-shrink:0;box-shadow:0 6px 24px rgba(124,58,237,.25);">
      <?= strtoupper(esc(substr($brandLabel, 0, 1))) ?>
    </div>
    <div style="flex:1;min-width:220px;">
      <h1 class="h3 fw-bold mb-1" data-testid="brand-name"><?= esc($brandLabel) ?></h1>
      <p class="text-secondary mb-2" style="font-size:14px;line-height:1.5;">Browse every genuine <?= esc($brandLabel) ?> software license available at <?= esc(SITE_BRAND) ?> — plus every editorial article our AI Auto-Blogger has published about <?= esc($brandLabel) ?> products.</p>
      <div class="d-flex gap-2 flex-wrap" style="font-size:12px;font-weight:600;">
        <span style="background:#e0e7ff;color:#3730a3;border-radius:999px;padding:3px 11px;"><i class="bi bi-box-seam me-1"></i><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></span>
        <span style="background:#fef3c7;color:#92400e;border-radius:999px;padding:3px 11px;"><i class="bi bi-journal-text me-1"></i><?= count($articles) ?> article<?= count($articles) === 1 ? '' : 's' ?></span>
        <?php /* rating badge removed — no reviews shown site-wide */ ?>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-4" data-testid="brand-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $active === 'products' ? 'active fw-bold' : '' ?>" href="?slug=<?= esc($slug) ?>&view=products" data-testid="brand-tab-products"><i class="bi bi-box-seam me-1"></i>Products <span class="text-secondary small">(<?= count($products) ?>)</span></a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $active === 'articles' ? 'active fw-bold' : '' ?>" href="?slug=<?= esc($slug) ?>&view=articles" data-testid="brand-tab-articles"><i class="bi bi-journal-text me-1"></i>Articles <span class="text-secondary small">(<?= count($articles) ?>)</span></a>
    </li>
    <?php /* Reviews tab removed — no reviews shown site-wide */ ?>
  </ul>

  <?php if ($active === 'products'): ?>
    <div class="row g-4" data-testid="brand-products-grid">
      <?php if (!$products): ?>
        <div class="text-center text-muted py-5">No active products for <?= esc($brandLabel) ?> right now.</div>
      <?php else: foreach ($products as $p): ?>
        <div class="col-xl-3 col-lg-4 col-sm-6"><?= render_product_card($p) ?></div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif ($active === 'articles'): ?>
    <div data-testid="brand-articles-list">
      <p class="text-secondary small mb-3"><i class="bi bi-info-circle me-1"></i>Every article our AI Auto-Blogger has published that features a <?= esc($brandLabel) ?> product. Updated automatically · 24 new posts per day across US, UK, AU and CA + 1 daily editorial trends piece.</p>
      <?php if (!$articles): ?>
        <div class="text-center text-muted py-5 border rounded-3" style="background:#fafafa;">
          <i class="bi bi-journal-bookmark" style="font-size:32px;color:#9ca3af;"></i>
          <p class="mb-0 mt-2">No articles for <?= esc($brandLabel) ?> yet — the AI Auto-Blogger will start publishing as soon as the next batch fires.</p>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($articles as $a):
            $regionMeta = ['US' => ['🇺🇸','US'], 'UK' => ['🇬🇧','UK'], 'AU' => ['🇦🇺','AU'], 'CA' => ['🇨🇦','CA'], 'ALL' => ['🌐','Editorial']];
            $rm = $regionMeta[$a['target_region']] ?? ['', $a['target_region']];
          ?>
            <div class="col-md-6 col-lg-4">
              <a href="blog-post.php?id=<?= urlencode($a['id']) ?>" class="card h-100 text-decoration-none p-0 article-card" style="border:1px solid #e5e7eb;overflow:hidden;transition:transform .2s, box-shadow .2s;">
                <div style="position:relative;">
                  <img src="<?= esc($a['image']) ?>" alt="<?= esc($a['title']) ?>" style="width:100%;height:140px;object-fit:cover;">
                  <?php if (!empty($a['is_featured_trends'])): ?>
                    <span style="position:absolute;top:8px;left:8px;background:linear-gradient(135deg,#f59e0b 0%,#ef4444 100%);color:white;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.3px;"><i class="bi bi-stars"></i> EDITORIAL · TRENDS</span>
                  <?php else: ?>
                    <span style="position:absolute;top:8px;left:8px;background:rgba(255,255,255,0.95);color:#3730a3;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.3px;"><?= $rm[0] ?> <?= esc($rm[1]) ?></span>
                  <?php endif; ?>
                </div>
                <div class="p-3">
                  <div class="small text-secondary mb-1"><i class="bi bi-calendar3 me-1"></i><?= esc($a['date']) ?> · <?= esc($a['read_time']) ?></div>
                  <div class="fw-bold text-body" style="font-size:14px;line-height:1.35;"><?= esc(mb_strimwidth($a['title'], 0, 90, '…')) ?></div>
                  <?php if (!empty($a['product_name'])): ?>
                    <div class="small text-secondary mt-2"><i class="bi bi-box-seam me-1"></i><?= esc(mb_strimwidth($a['product_name'], 0, 40, '…')) ?></div>
                  <?php endif; ?>
                  <div class="text-primary small fw-semibold mt-2">Read article <i class="bi bi-arrow-right"></i></div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php else: /* reviews */ ?>
    <div class="text-center text-muted py-5" data-testid="brand-reviews-pending">
      <i class="bi bi-chat-quote" style="font-size:32px;color:#9ca3af;"></i>
      <p class="mb-0 mt-2">Customer reviews for <?= esc($brandLabel) ?> products appear here — visit individual product pages to leave a review.</p>
    </div>
  <?php endif; ?>
</div>
<style>
  .article-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
  .article-card { border-radius: 12px !important; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
