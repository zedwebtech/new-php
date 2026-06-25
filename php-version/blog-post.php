<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';

$id = $_GET['id'] ?? '';
$post = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
}
$pageTitle = ($post ? $post['title'] : 'Post Not Found') . ' | ' . SITE_BRAND;
if ($post) {
    $pageDescription = trim(mb_substr(strip_tags($post['content']), 0, 155)) . '…';
    $ogType = 'article';
    $canonicalUrl = site_url() . '/blog-post.php?id=' . rawurlencode((string)$post['id']);
    if (!empty($post['image'])) {
        $ogImage    = $post['image'];
        $ogImageAlt = $post['title'];
    }

    // Article JSON-LD — lets Gemini, ChatGPT, Copilot, Perplexity and other
    // AI engines extract a clean Article schema and cite the post directly.
    $articleDate = '';
    if (!empty($post['created_at'])) {
        $articleDate = date('c', strtotime((string)$post['created_at']));
    } elseif (!empty($post['date'])) {
        $ts = strtotime((string)$post['date']);
        if ($ts) $articleDate = date('c', $ts);
    }
    // Track actual modification time so AI search engines see fresh dates
    // when the auto-blogger refreshes the post via cron.  Falls back to
    // the publish date when no modification has happened yet.
    $modifiedDate = !empty($post['updated_at']) ? date('c', strtotime((string)$post['updated_at'])) : ($articleDate ?: date('c'));
    $authorName = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software';

    // Word count for the wordCount + timeRequired signals — improves
    // E-E-A-T scoring in Google's quality framework.
    $plainBody    = trim(strip_tags((string)$post['content']));
    $articleWords = max(1, str_word_count($plainBody));
    $readMinutes  = max(1, (int)round($articleWords / 220));

    // Article section: drive topical-cluster signals via the linked
    // product's category so the post sits in the right neighbourhood.
    $articleSection = 'Software & Licensing';
    try {
        if (!empty($post['product_id'])) {
            $catRow = db()->prepare("SELECT p.category FROM products p WHERE p.id = ? LIMIT 1");
            $catRow->execute([(int)$post['product_id']]);
            $cs = (string)$catRow->fetchColumn();
            if ($cs !== '' && function_exists('category_title')) $articleSection = category_title($cs);
        }
    } catch (Throwable $e) {}

    $jsonLdArticle = [
        '@context'      => 'https://schema.org',
        '@type'         => !empty($post['is_featured_trends']) ? 'Article' : 'BlogPosting',
        'headline'      => $post['title'],
        'image'         => $post['image'] ? [$post['image']] : [],
        'datePublished' => $articleDate ?: date('c'),
        'dateModified'  => $modifiedDate,
        // Author = the brand (Organization). Keeps the article attribution
        // consistent across the site whether the post is hand-written or
        // AI-assisted, and avoids exposing "AI Editorial Team" copy on the
        // public page (still emitted as schema for E-E-A-T parity).
        'author'        => [
            '@type'        => 'Organization',
            'name'         => $authorName,
            'url'          => site_url() . '/about-us.php',
            'worksFor'     => [
                '@type' => 'Organization',
                'name'  => defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software',
                '@id'   => site_url() . '/#organization',
            ],
            'knowsAbout'   => ['Microsoft licensing', 'Office software', 'Windows activation', 'Cybersecurity', 'Genuine software resale'],
        ],
        'publisher'     => [
            '@type' => 'Organization',
            '@id'   => site_url() . '/#organization',
            'name'  => defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software',
            'url'   => site_url() . '/',
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => site_url() . '/assets/images/badges/microsoft-verified.svg',
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $canonicalUrl,
        ],
        'description'   => $pageDescription,
        'inLanguage'    => 'en',
        'isAccessibleForFree' => true,
        'wordCount'     => $articleWords,
        'timeRequired'  => 'PT' . $readMinutes . 'M',
        'articleSection'=> $articleSection,
        'speakable'     => [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => ['h1', '.aeo-quick-answer', '.lead'],
        ],
    ];

    // AEO: Build FAQPage schema from stored FAQ data
    $jsonLdFaqPage = null;
    if (!empty($post['faq_json'])) {
        $faqItems = json_decode($post['faq_json'], true);
        if (is_array($faqItems) && count($faqItems) > 0) {
            $faqEntities = [];
            foreach ($faqItems as $fi) {
                if (!empty($fi['q']) && !empty($fi['a'])) {
                    $faqEntities[] = [
                        '@type' => 'Question',
                        'name'  => $fi['q'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => $fi['a'],
                        ],
                    ];
                }
            }
            if ($faqEntities) {
                $jsonLdFaqPage = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $faqEntities,
                ];
            }
        }
    }

    // Long-tail meta keywords — auto-derived from the post title, the
    // linked product (when present, via the category-aware intent
    // dispatcher) and the H2/H3 hierarchy of the post body.  Header.php
    // emits `<meta name="keywords">` when $pageKeywords is set, so this
    // lifts the SEO audit's keyword score for blog posts from 0 to 20+.
    $pageKeywords = blog_post_long_tail_keywords($post);

    // BreadcrumbList JSON-LD — mirrors the visible breadcrumb so search
    // engines and AI engines parse the same hierarchy that users see.
    $jsonLdBreadcrumb = blog_post_breadcrumb_jsonld($post);

    /* Surface article-specific OG values so header.php emits
     * <meta property="article:published_time"> etc. — drives the rich
     * preview LinkedIn / WhatsApp / Discord show for shared posts. */
    $articlePublishedTime = $articleDate ?: date('c');
    $articleModifiedTime  = $modifiedDate;
    $articleAuthor        = $authorName;
    $articleTags          = !empty($post['tags']) ? array_filter(array_map('trim', explode(',', (string)$post['tags']))) : [];
} else {
    http_response_code(404);
    $noIndex = true;
}

include __DIR__ . '/includes/header.php';
?>
<?php if (!empty($jsonLdArticle)): ?>
<script type="application/ld+json"><?= json_encode($jsonLdArticle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php if (!empty($jsonLdFaqPage)): ?>
<script type="application/ld+json"><?= json_encode($jsonLdFaqPage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<div class="container py-5" style="max-width: 800px;">
  <?php if ($post): ?>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="blog.php">Blog</a></li>
        <li class="breadcrumb-item active"><?= esc($post['title']) ?></li>
      </ol>
    </nav>
    <h1 class="fw-bold" data-testid="blog-post-h1"><?= esc($post['title']) ?></h1>
    <p class="text-secondary small d-flex flex-wrap align-items-center gap-2 mb-3" data-testid="blog-post-byline">
      <span><i class="bi bi-calendar-event me-1"></i><?= esc($post['date']) ?></span>
      <span class="text-muted">·</span>
      <span><i class="bi bi-clock me-1"></i><?= esc($post['read_time']) ?></span>
      <?php if (!empty($post['updated_at']) && $post['updated_at'] !== ($post['created_at'] ?? '')): ?>
        <span class="text-muted">·</span>
        <span class="badge text-bg-light text-secondary" data-testid="blog-post-last-updated" title="Last updated"><i class="bi bi-arrow-clockwise me-1"></i>Updated <?= esc(date('M j, Y', strtotime((string)$post['updated_at']))) ?></span>
      <?php endif; ?>
      <?php
        // Targeted-country badge — quick visual cue for international readers
        // PLUS a small E-E-A-T signal (regionally localised content).
        $rTag = strtoupper((string)($post['target_region'] ?? ''));
        $rFlagMap = ['US'=>'🇺🇸','UK'=>'🇬🇧','AU'=>'🇦🇺','CA'=>'🇨🇦'];
        if (isset($rFlagMap[$rTag])):
      ?>
        <span class="text-muted">·</span>
        <span class="badge text-bg-light" data-testid="blog-post-region-badge" style="border:1px solid #e2e8f0;"><?= $rFlagMap[$rTag] ?> <?= esc($rTag) ?></span>
      <?php endif; ?>
    </p>
    <?php if (!empty($post['lead'])): ?>
      <?= render_aeo_answer(
            (string)$post['title'],
            esc((string)$post['lead']),
            'blog-post-quick-answer'
        ) ?>
    <?php endif; ?>
    <img src="<?= esc($post['image']) ?>" class="img-fluid rounded mb-4 w-100 object-fit-cover" style="max-height:380px;" alt="<?= esc($post['title']) ?>" loading="lazy" decoding="async">
    <div class="post-content" data-testid="blog-post-content"><?= $post['content'] /* trusted HTML seeded from database.sql */ ?></div>

    <?php
      // -------- Internal "Related products" + "More articles" widgets --------
      // Internal linking is one of the single biggest SEO levers — Google uses
      // these intra-site anchors to understand topical authority and to crawl
      // deeper.  We pull (a) the featured product (if any), (b) 3 sibling
      // products in the same category, and (c) 3 newest blog posts excluding
      // the current one.
      $relatedProducts = [];
      $featuredProduct = null;
      if (!empty($post['product_id'])) {
          $fp = db()->prepare('SELECT id, slug, name, brand, category, price, image FROM products WHERE id = ? AND is_active = 1');
          $fp->execute([(int)$post['product_id']]);
          $featuredProduct = $fp->fetch();
      }
      if ($featuredProduct) {
          $rp = db()->prepare('SELECT slug, name, brand, price, image FROM products
                                WHERE category = ? AND id != ? AND is_active = 1
                                ORDER BY rating DESC, reviews DESC LIMIT 3');
          $rp->execute([$featuredProduct['category'], (int)$featuredProduct['id']]);
          $relatedProducts = $rp->fetchAll();
      }
      // Fallback if there's no featured product — pick top-rated overall.
      if (!$relatedProducts) {
          $relatedProducts = db()->query('SELECT slug, name, brand, price, image FROM products WHERE is_active = 1 ORDER BY rating DESC, reviews DESC LIMIT 3')->fetchAll();
      }
      $morePosts = db()->prepare("SELECT id, title, date, read_time, image FROM blog_posts WHERE id != ? ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC LIMIT 3");
      $morePosts->execute([$post['id']]);
      $morePosts = $morePosts->fetchAll();
    ?>

    <?php if ($featuredProduct): ?>
      <hr class="my-4">
      <div class="card p-3 d-flex flex-row align-items-center gap-3" style="border-left:4px solid #4338ca;background:#fafaff;" data-testid="featured-product-card">
        <img src="<?= esc($featuredProduct['image']) ?>" alt="<?= esc($featuredProduct['name']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;">
        <div style="flex:1;min-width:0;">
          <div class="small text-uppercase text-secondary" style="letter-spacing:1px;font-weight:700;font-size:10px;color:#4338ca !important;">Article featured product</div>
          <div class="fw-bold" style="font-size:16px;color:#0f172a;line-height:1.3;"><?= esc($featuredProduct['name']) ?></div>
          <div class="small text-secondary mt-1"><?= esc($featuredProduct['brand'] ?: 'Genuine license') ?> · From $<?= number_format((float)$featuredProduct['price'], 2) ?></div>
        </div>
        <a href="product.php?slug=<?= urlencode($featuredProduct['slug']) ?>" class="btn btn-primary rounded-pill px-4 flex-shrink-0" data-testid="featured-product-link">View product →</a>
      </div>
    <?php endif; ?>

    <?php if ($relatedProducts): ?>
      <h3 class="fw-bold mt-5 h5">You might also like</h3>
      <div class="row g-3 mt-1" data-testid="related-products">
        <?php foreach ($relatedProducts as $rp): ?>
          <div class="col-md-4">
            <a href="product.php?slug=<?= urlencode($rp['slug']) ?>" class="card h-100 text-decoration-none p-2" style="border:1px solid #e5e7eb;">
              <img src="<?= esc($rp['image']) ?>" alt="<?= esc($rp['name']) ?>" class="rounded mb-2" style="width:100%;height:120px;object-fit:cover;">
              <div class="small fw-bold text-body" style="line-height:1.3;"><?= esc(mb_strimwidth($rp['name'], 0, 60, '…')) ?></div>
              <div class="small text-secondary mt-1"><?= esc($rp['brand']) ?> · $<?= number_format((float)$rp['price'], 2) ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($morePosts): ?>
      <h3 class="fw-bold mt-5 h5">More from the blog</h3>
      <div class="row g-3 mt-1" data-testid="more-posts">
        <?php foreach ($morePosts as $mp): ?>
          <div class="col-md-4">
            <a href="blog-post.php?id=<?= urlencode($mp['id']) ?>" class="card h-100 text-decoration-none p-2" style="border:1px solid #e5e7eb;">
              <img src="<?= esc($mp['image']) ?>" alt="<?= esc($mp['title']) ?>" class="rounded mb-2" style="width:100%;height:120px;object-fit:cover;">
              <div class="small fw-bold text-body" style="line-height:1.3;"><?= esc(mb_strimwidth($mp['title'], 0, 70, '…')) ?></div>
              <div class="small text-secondary mt-1"><i class="bi bi-calendar3 me-1"></i><?= esc($mp['date']) ?> · <?= esc($mp['read_time']) ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <hr class="my-4">
    <div class="card p-4 text-center">
      <h5 class="fw-bold">Ready to upgrade your software?</h5>
      <p class="small text-secondary">Genuine Microsoft licenses with instant delivery.</p>
      <a href="shop.php" class="btn btn-primary rounded-pill px-4 mx-auto">Shop Now</a>
    </div>
  <?php else: ?>
    <div class="text-center py-5">
      <h1 class="fw-bold">Post not found</h1>
      <a href="blog.php" class="btn btn-primary rounded-pill px-4 mt-3">Back to Blog</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
