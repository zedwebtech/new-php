<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';

$slug = $_GET['slug'] ?? 'office';
$sort = $_GET['sort'] ?? '';

/* Platform-specific categories (e.g. office-2024-mac) widen to their year family
   so the Platform filter can switch between Windows / Mac / All of the same year. */
$familySlug = preg_replace('/(-for)?-(macs?|pc|windows)$/', '', $slug);
$isPlatformCat = $familySlug !== $slug;
$implied = $isPlatformCat ? (preg_match('/macs?$/', $slug) ? 'Mac' : 'Windows') : '';
$platform = isset($_GET['platform']) ? $_GET['platform'] : $implied;

if ($isPlatformCat) {
    $cats = category_children($familySlug);
    if ($cats === [$familySlug]) $cats = [$familySlug . '-pc', $familySlug . '-mac'];
} else {
    $cats = category_children($slug);
}

$title = category_title($slug);
if ($isPlatformCat && $platform !== $implied) {
    $title = category_title($familySlug) . ($platform === 'Windows' ? ' for Windows' : ($platform === 'Mac' ? ' for Mac' : ''));
}

$year = date('Y');
/* SEO: tight 50-60 char title and 120-160 char description.
 * Example: "Microsoft Office 2024 License Keys (2026) | Maventech" */
$pageTitle       = $title . ' License Keys (' . $year . ') | ' . SITE_BRAND;
$pageDescription = 'Buy genuine ' . $title . ' keys at up to 81% off — lifetime activation, no subscription, instant 15-30 min delivery from ' . SITE_BRAND . '.';
$pageKeywords    = category_long_tail_keywords($title, $platform);

$products = get_products($cats, $platform, $sort);

/* Use the first product's image as the per-category OG image so social
 * previews don't all collapse to the generic brand banner. */
if (!empty($products[0]['image'])) {
    $ogImage    = $products[0]['image'];
    $ogImageAlt = $title . ' — featured product on ' . SITE_BRAND;
}

/* ----- Structured data ----- */
$jsonLdBreadcrumb = category_breadcrumb_jsonld($slug, $title);
$jsonLdItemList   = category_itemlist_jsonld($products, $title);
$catFaqs          = category_faqs($slug, $title);
$jsonLdFaq        = faq_to_jsonld($catFaqs);

/* CollectionPage schema — primary type for category landing pages.
 * Tells Google "this is a curated list of related products" so it
 * indexes the URL as a hub page rather than a thin filter. */
$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $title,
    'description' => $pageDescription,
    'url'         => site_url() . '/category.php?slug=' . urlencode($slug),
    'inLanguage'  => 'en',
    'isPartOf'    => ['@id' => site_url() . '/#website'],
    'about'       => ['@type' => 'Thing', 'name' => $title],
    'mainEntity'  => $jsonLdItemList,
];

include __DIR__ . '/includes/header.php';
?>
<?php
/* Emit the BreadcrumbList JSON-LD for SEO; the visible breadcrumb is
 * rendered ONCE inside render_page_head() below so the page never
 * shows two stacked trails (Feb 2026 alignment fix). */
?>
<?= render_page_head(
        $title . ' License Keys',
        count($products) . ' genuine ' . $title . ' license keys — one-time purchase, no subscription, delivered in 15-30 minutes',
        ['Shop' => 'shop.php', $title => null],
        'category-title',
        [
            ['icon' => 'box-seam',           'label' => count($products) . ' product' . (count($products) === 1 ? '' : 's') . ' available'],
            ['icon' => 'patch-check-fill',   'label' => 'Genuine licenses'],
            ['icon' => 'lightning-charge-fill', 'label' => '15-min delivery'],
        ]
    ) ?>
<div class="container py-4 py-lg-5">

  <!-- (SEO intro paragraph was originally rendered here — moved BELOW the
       product grid alongside the Quick Answer so shoppers see inventory
       first.  The H1 + product count above still primes search engines.) -->

  <!-- Structured toolbar: title/count | platform | sort -->
  <div class="shop-toolbar row g-3 align-items-center mb-4 mx-0 p-3" data-testid="category-toolbar">
    <div class="col-lg-4">
      <h2 class="h6 fw-bold mb-0" data-testid="category-toolbar-title"><?= esc($title) ?> Products</h2>
      <small class="text-secondary" data-testid="category-count"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?> available</small>
    </div>
    <div class="col-lg-4 text-lg-center">
      <div class="platform-seg d-inline-flex align-items-center p-1" data-testid="platform-filter">
        <span class="small fw-bold text-secondary ms-2 me-2">Platform:</span>
        <?php foreach (['' => ['All', null], 'Windows' => ['Windows', 'windows'], 'Mac' => ['Mac', 'macos']] as $val => [$label, $osImg]): ?>
          <a href="?slug=<?= esc($slug) ?>&platform=<?= $val ?>&sort=<?= esc($sort) ?>" class="platform-pill <?= $platform === $val ? 'active' : '' ?>" data-testid="platform-<?= $val ? strtolower($val) : 'all' ?>">
            <?php if ($osImg): ?><img src="assets/images/os/<?= $osImg ?>.svg" alt="<?= esc($osImg === 'macos' ? 'macOS platform' : 'Windows platform') ?>" class="os-icon me-1"><?php endif; ?><?= $label ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-4 d-flex justify-content-lg-end align-items-center gap-2">
      <form method="get" class="d-flex align-items-center gap-2">
        <input type="hidden" name="slug" value="<?= esc($slug) ?>">
        <input type="hidden" name="platform" value="<?= esc($platform) ?>">
        <span class="sort-label d-inline-flex align-items-center"><i class="bi bi-sliders me-1"></i>Sort</span>
        <select name="sort" class="form-select form-select-sm sort-select" style="width:auto" onchange="this.form.submit()" data-testid="category-sort">
          <option value="">Default</option>
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
          <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
          <?php /* Top Rated / Most Reviewed sort options removed — no reviews shown */ ?>
        </select>
      </form>
    </div>
  </div>

  <?php if (!$products): ?>
    <div class="alert alert-light border text-center py-5">No products found in this category. <a href="shop.php">Browse all products</a>.</div>
  <?php else: ?>
    <!-- Wide banner rows — the category page's signature layout -->
    <div class="d-grid gap-3" data-testid="category-list">
      <?php foreach ($products as $p): ?>
        <?= render_product_row($p) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- AEO Quick Answer — placed AFTER the product grid so shoppers see
       inventory first, then the AI/AEO-optimised price + delivery answer
       that's also serialised to FAQPage JSON-LD for AI Overviews. -->
  <div class="mt-4 mb-3">
    <?= render_aeo_answer(
          'What does ' . esc($title) . ' cost on ' . esc(SITE_BRAND) . '?',
          esc(SITE_BRAND) . ' offers genuine <strong>' . esc($title) . '</strong> licence keys starting at ' . (count($products) ? esc(format_price((float)min(array_column($products, 'price')))) : '$24') . ' &mdash; up to 81% below retail. Each key is a perpetual one-time purchase (no subscription), delivered by email in 15&ndash;30 minutes, activates inside the official software, and is protected by a 30-day money-back guarantee.',
          'category-quick-answer'
      ) ?>
  </div>

  <!-- SEO mid-tail intro — moved below the product grid (alongside the
       Quick Answer) so it doesn't push the inventory off the fold.  Still
       fully indexable + quotable by AI crawlers. -->
  <p class="lead text-secondary mb-4" data-testid="category-intro-copy" style="max-width:880px;">
    <?= category_intro_seo($slug, $title) ?>
  </p>

  <!-- ============ Long-form SEO copy: buying guide with H2/H3
       hierarchy that targets mid-tail and long-tail searches. ============ -->
  <?= category_buying_guide_html($slug, $title, count($products)) ?>

  <!-- ============ Category FAQ (visible accordion + FAQPage JSON-LD).
       Quotable verbatim by AI search engines / Google AI Overviews. ====== -->
  <section class="cat-faq mt-5" aria-labelledby="cat-faq-heading" data-testid="category-faq">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-patch-question-fill" style="font-size:22px;color:#2563eb;"></i>
      <h2 id="cat-faq-heading" class="fw-bold h4 mb-0"><?= esc($title) ?> &mdash; frequently asked questions</h2>
    </div>
    <div class="accordion pd-faq-accordion" id="cat-faq-accordion">
      <?php foreach ($catFaqs as $idx => $f): $itemId = 'cat-faq-item-' . $idx; ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                    data-bs-target="#<?= esc($itemId) ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                    aria-controls="<?= esc($itemId) ?>" data-testid="cat-faq-q-<?= $idx ?>">
              <?= esc($f['question']) ?>
            </button>
          </h3>
          <div id="<?= esc($itemId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
               data-bs-parent="#cat-faq-accordion">
            <div class="accordion-body" data-testid="cat-faq-a-<?= $idx ?>">
              <?= $f['answer'] ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ============ Deep-link cluster: drives Google's internal PageRank
       graph and helps AI crawlers map this category into the wider
       topical neighbourhood.  Descriptive anchor text uses mid-tail
       keyword phrases. ====================================================== -->
  <?php $catHubs = topic_hubs_for_category($slug); ?>
  <?php if ($catHubs): ?>
  <section class="cat-topic-hub mt-5" data-testid="category-topic-hub" aria-labelledby="cat-hub-heading" style="background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1px solid #c7d2fe;border-radius:14px;padding:18px 22px;">
    <h2 id="cat-hub-heading" class="fw-bold h5 mb-2"><i class="bi bi-collection-fill text-primary me-1"></i>Part of a wider topic hub</h2>
    <p class="text-secondary small mb-3" style="max-width:780px;">Explore every product, editorial guide and frequently asked question on the topic in one place — a deeper resource for buyers (and AI engines).</p>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($catHubs as $__h): ?>
        <a class="badge text-decoration-none" data-testid="category-hub-link" href="hub/<?= esc($__h['slug']) ?>" style="background:#fff;color:<?= esc($__h['color']) ?>;border:1px solid <?= esc($__h['color']) ?>33;padding:8px 14px;font-size:12px;font-weight:600;"><i class="bi bi-arrow-up-right-circle me-1"></i><?= esc(strip_tags($__h['title'])) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <section class="cat-deep-cluster mt-5" data-testid="category-deep-cluster" aria-labelledby="cat-cluster-heading">
    <h2 id="cat-cluster-heading" class="fw-bold h4 mb-3">More categories shoppers explore next</h2>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-collection me-1"></i>Related categories</div>
        <ul class="list-unstyled small">
          <?php foreach (related_category_links($slug) as $rc): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($rc['slug']) ?>" data-testid="cluster-cat-<?= esc($rc['slug']) ?>"><?= $rc['anchor'] ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-search me-1"></i>Popular searches</div>
        <div class="d-flex flex-wrap gap-2 mb-4">
          <?php foreach (popular_search_terms($slug) as $term): ?>
            <a href="shop.php?q=<?= urlencode($term) ?>" class="badge text-decoration-none fw-normal" data-testid="cluster-popular" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:6px 10px;font-size:12px;"><?= esc($term) ?></a>
          <?php endforeach; ?>
        </div>
        <?php
          /* Latest 3 blog posts — keeps the cluster fresh + connects the
             commercial page to the editorial side (topical authority). */
          $clusterPosts = [];
          try {
            $stmtCp = db()->prepare("SELECT id, title FROM blog_posts ORDER BY COALESCE(created_at,'1970-01-01') DESC, id DESC LIMIT 3");
            $stmtCp->execute();
            $clusterPosts = $stmtCp->fetchAll();
          } catch (Throwable $e) {}
        ?>
        <?php if ($clusterPosts): ?>
          <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-journal-text me-1"></i>Latest guides on the blog</div>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($clusterPosts as $bp): ?>
              <li class="mb-1">&rsaquo; <a class="text-decoration-none" href="blog-post.php?id=<?= urlencode((string)$bp['id']) ?>" data-testid="cluster-blog-link"><?= esc($bp['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
