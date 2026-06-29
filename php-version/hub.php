<?php
/* =====================================================================
 *  Topic Cluster Hub  —  /hub/<topic-slug>
 *  ---------------------------------------------------------------------
 *  One PHP file, every topic.  Aggregates EVERY product, EVERY blog post
 *  and EVERY FAQ that touches a given topic onto a single deep,
 *  citation-friendly page.
 *
 *  Why this exists:
 *    - Google's topical-authority model rewards a clear "hub" that
 *      proves you cover an entire subject — not just a thin landing.
 *    - ChatGPT / Perplexity / Bing Copilot routinely cite hub pages
 *      because the structured H2/H3 hierarchy + visible Q&A makes them
 *      the easiest single URL to quote when asked "tell me everything
 *      about Microsoft Office".
 *
 *  Configuration lives in $TOPICS below.  Adding a new hub is one
 *  associative entry; no template changes required.
 * ===================================================================== */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';
require_once __DIR__ . '/includes/email.php';

/* ---------- TOPIC SOURCE ----------
 *  Hubs are read from the DB (`topic_hubs` table).  topic_hubs_all()
 *  auto-seeds the three default hubs (microsoft-office, windows,
 *  antivirus) on first run so no migration step is required.  Admins
 *  add / edit / auto-generate more hubs from the AI Auto-Blogger panel
 *  -> "Topic Cluster Hubs" section.
 * ---------------------------------- */
$TOPICS = topic_hubs_all(true);

$topicSlug = strtolower(trim((string)($_GET['topic'] ?? '')));
$topic = $TOPICS[$topicSlug] ?? null;
/* Hubs render at the virtual clean URL /hub/<slug>, so the browser would
   resolve every relative asset/link/AJAX call against /hub/ and 404. Emit a
   <base href> pointing at the real (region-aware) site root to fix them all
   in one place — assets, nav, footer, hub body links and the cart AJAX. */
$baseHref = site_url() . country_prefix() . '/';
if (!$topic) {
    http_response_code(404);
    // 404s must be noindex so Google never indexes them and never flags a
    // "broken canonical". Point canonical at the (valid) region shop, not at
    // this 404 URL, and link out with clean /hub/<slug> URLs.
    $noIndex      = true;
    $pageTitle    = 'Topic Hub Not Found | ' . SITE_BRAND;
    $canonicalUrl = site_url() . country_prefix() . '/shop.php';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5 text-center">';
    echo '<h1 class="h3 fw-bold mb-3">Topic hub not found</h1>';
    echo '<p class="text-secondary">We don&rsquo;t have a hub for &ldquo;' . esc($topicSlug) . '&rdquo; yet.</p>';
    echo '<div class="d-flex gap-2 justify-content-center flex-wrap mt-4">';
    foreach ($TOPICS as $k => $t) {
        echo '<a class="btn btn-outline-primary rounded-pill" href="hub/' . esc($k) . '">' . strip_tags(esc($t['title'])) . '</a>';
    }
    echo '</div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle       = strip_tags($topic['title']) . ' (' . date('Y') . ') | ' . SITE_BRAND;
$pageDescription = strip_tags($topic['headline']);
$pageKeywords    = $topic['keywords'];
/* Canonical must be the clean hub URL — NOT the default /hub.php the header
   would otherwise build (which 404s with no ?topic and shows up in GSC as a
   "broken canonical URL"). $canonicalPathBare also drives the hreflang set. */
$canonicalPathBare = '/hub/' . $topicSlug;
$canonicalUrl      = site_url() . country_prefix() . $canonicalPathBare;

/* ---------- DATA AGGREGATION ---------- */
$pdo = db();

// 1) Products belonging to any of the topic's categories.
$hubProducts = [];
if ($topic['categories']) {
    try {
        $place = implode(',', array_fill(0, count($topic['categories']), '?'));
        $stP = $pdo->prepare(
            "SELECT id, name, slug, image, price, original_price, rating, reviews, platform, category, description
               FROM products
              WHERE category IN ($place)
           ORDER BY (rating * reviews) DESC, price ASC
              LIMIT 24"
        );
        $stP->execute($topic['categories']);
        $hubProducts = $stP->fetchAll();
    } catch (Throwable $e) {}
}

// 2) Blog posts whose title matches any of the topic's LIKE patterns.
$hubPosts = [];
if ($topic['blogTags']) {
    try {
        $whereLikes = implode(' OR ', array_fill(0, count($topic['blogTags']), 'LOWER(title) LIKE ?'));
        $stB = $pdo->prepare(
            "SELECT id, title, image, date, read_time, target_region, COALESCE(updated_at, created_at) AS sort_at, lead
               FROM blog_posts
              WHERE $whereLikes
           ORDER BY sort_at DESC, id DESC
              LIMIT 12"
        );
        $stB->execute(array_map('strtolower', $topic['blogTags']));
        $hubPosts = $stB->fetchAll();
    } catch (Throwable $e) {}
}

// 3) Aggregate FAQs — pull product FAQs from the top 4 products + a few
// hub-level Q&A.  Gives Google AND AI engines a single page that answers
// every common question about the topic.
$hubFaqs = [];
if ($hubProducts) {
    foreach (array_slice($hubProducts, 0, 4) as $p) {
        foreach (product_faqs($p) as $f) {
            $hubFaqs[] = $f;
            if (count($hubFaqs) >= 10) break 2;
        }
    }
}
$hubFaqs = array_slice(_hub_unique_faqs($hubFaqs), 0, 10);

/* ---------- STRUCTURED DATA ---------- */
// Set $jsonLd so the header.php main JSON-LD emit picks it up alongside
// the other auto-detected blocks (BreadcrumbList, ItemList, FAQPage).
$jsonLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'CollectionPage',
    '@id'        => site_url() . '/hub/' . $topicSlug . '#cluster',
    'url'        => site_url() . '/hub/' . $topicSlug,
    'name'       => strip_tags($topic['title']),
    'description'=> $pageDescription,
    'inLanguage' => 'en',
    'isPartOf'   => ['@id' => site_url() . '/#website'],
    'about'      => ['@type' => 'Thing', 'name' => strip_tags($topic['title'])],
    'audience'   => ['@type' => 'Audience', 'audienceType' => $topic['audience']],
    'keywords'   => $topic['keywords'],
    'dateModified' => date('c'),
];

$jsonLdBreadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',   'item' => site_url() . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics', 'item' => site_url() . '/hub/' . $topicSlug],
        ['@type' => 'ListItem', 'position' => 3, 'name' => strip_tags($topic['title'])],
    ],
];

// Build an ItemList of products on the hub (strong topical signal).
$jsonLdItemList = category_itemlist_jsonld($hubProducts, strip_tags($topic['title']));

// Hub-wide FAQPage with Speakable selectors so AI assistants quote us verbatim.
$jsonLdFaq = $hubFaqs ? faq_to_jsonld($hubFaqs) : null;

// Per-video VideoObject schema for each related YouTube link the admin
// added.  Emitted alongside the other JSON-LD blocks in header.php so
// Google + AI engines can pick up structured video data.
$jsonLdVideos = [];
foreach (($topic['videos'] ?? []) as $v) {
    $vj = topic_hub_video_jsonld((array)$v, $topic);
    if ($vj) $jsonLdVideos[] = $vj;
}

// Mentions list (Google graph edge) — link every aggregated blog post + product to the hub.
$mentionsArr = [];
foreach (array_slice($hubProducts, 0, 12) as $p) {
    $mentionsArr[] = ['@type' => 'Product', 'name' => $p['name'], 'url' => site_url() . '/product.php?slug=' . urlencode($p['slug'])];
}
foreach (array_slice($hubPosts, 0, 6) as $bp) {
    $mentionsArr[] = ['@type' => 'Article', 'name' => $bp['title'], 'url' => site_url() . '/blog-post.php?id=' . urlencode((string)$bp['id'])];
}
if ($mentionsArr) {
    $jsonLd['mentions'] = $mentionsArr;
}

/* Per-hub OG image: pick the first product or post image so social shares
 * of /hub/microsoft-office, /hub/windows etc. preview a real product card. */
if (!empty($hubProducts[0]['image'])) {
    $ogImage    = $hubProducts[0]['image'];
    $ogImageAlt = strip_tags($topic['title']) . ' — featured product';
} elseif (!empty($hubPosts[0]['image'])) {
    $ogImage    = $hubPosts[0]['image'];
    $ogImageAlt = strip_tags($topic['title']) . ' — featured guide';
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-4 py-lg-5">

<?= render_breadcrumb_nav([
    ['name' => 'Home',   'url' => 'index.php'],
    ['name' => 'Topics', 'url' => 'hub/' . rawurlencode($topicSlug)],
    ['name' => strip_tags($topic['title'])],
], 'hub-breadcrumb') ?>

<!-- Topic Hero -->
<section class="hub-hero rounded-4 mb-4" data-testid="hub-hero" style="background:linear-gradient(135deg,<?= esc($topic['color']) ?>1c,<?= esc($topic['color']) ?>08);border:1px solid <?= esc($topic['color']) ?>33;padding:32px 28px;">
  <div class="d-inline-flex align-items-center gap-2 mb-3" style="background:<?= esc($topic['color']) ?>;color:#fff;border-radius:999px;padding:6px 14px;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;">
    <i class="bi bi-collection-fill"></i>Topic Cluster Hub
  </div>
  <h1 class="fw-bold mb-2" data-testid="hub-h1" style="font-size:clamp(28px, 4vw, 44px);"><?= $topic['title'] ?></h1>
  <p class="lead text-secondary mb-3" style="max-width:780px;">For <?= esc($topic['audience']) ?>.</p>
  <div class="d-flex flex-wrap gap-3 align-items-center" data-testid="hub-stats">
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-box-seam me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubProducts) ?> products</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-journal-text me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubPosts) ?> guides</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-patch-question-fill me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubFaqs) ?> answers</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-arrow-clockwise me-1" style="color:<?= esc($topic['color']) ?>"></i>Updated <?= date('M Y') ?></span>
    <a class="btn rounded-pill px-4 ms-auto" href="<?= esc($topic['aboutLink']) ?>" style="background:<?= esc($topic['color']) ?>;color:#fff;font-weight:600;" data-testid="hub-cta-primary"><i class="bi bi-cart-plus me-1"></i>Shop the full range</a>
  </div>
</section>

<!-- AEO Quick Answer — top of the page so AI Overviews + voice grab it. -->
<?= render_aeo_answer(
      'What is ' . strip_tags(explode(' — ', $topic['title'])[0]) . '?',
      $topic['headline'],
      'hub-quick-answer'
  ) ?>

<!-- Quick navigation chips — every section anchor for fast scroll + a11y -->
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="On this page" data-testid="hub-toc">
  <?php
    $tocChips = [
        ['#hub-products', '<i class="bi bi-grid-3x3-gap-fill"></i> Products', $hubProducts ? null : 'd-none'],
        ['#hub-guides',   '<i class="bi bi-journal-text"></i> Guides',         $hubPosts    ? null : 'd-none'],
        ['#hub-videos',   '<i class="bi bi-play-btn-fill"></i> Videos',        (!empty($topic['videos']) && is_array($topic['videos']) && count($topic['videos']) > 0) ? null : 'd-none'],
        ['#hub-faqs',     '<i class="bi bi-patch-question-fill"></i> FAQs',    $hubFaqs     ? null : 'd-none'],
        ['#hub-related',  '<i class="bi bi-collection"></i> Related topics',   null],
    ];
    foreach ($tocChips as [$href, $label, $hide]):
      if ($hide) continue;
  ?>
    <a class="badge text-decoration-none" href="<?= esc($href) ?>" style="background:#f1f5f9;color:#1e293b;border:1px solid #e2e8f0;padding:8px 14px;font-size:12px;font-weight:600;"><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($hubProducts): ?>
<!-- Products section — the heart of the hub. -->
<section id="hub-products" class="mb-5" aria-labelledby="hub-products-h2" data-testid="hub-products">
  <h2 id="hub-products-h2" class="fw-bold h3 mb-3"><?= count($hubProducts) ?> top picks in this topic</h2>
  <div class="row g-3">
    <?php foreach (array_slice($hubProducts, 0, 12) as $p): ?>
      <div class="col-md-6 col-lg-4">
        <a href="product.php?slug=<?= esc($p['slug']) ?>" class="card text-decoration-none h-100 hub-product-card" data-testid="hub-product-card" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($topic['color']) ?>';this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(15,23,42,.06)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none';this.style.boxShadow='none'">
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= esc($p['image']) ?>" alt="<?= esc($p['name']) ?>" style="width:64px;height:64px;object-fit:contain;flex-shrink:0;" loading="lazy" decoding="async">
            <div class="flex-grow-1" style="min-width:0;">
              <div class="fw-bold text-truncate" style="color:#0f172a;font-size:14px;" title="<?= esc($p['name']) ?>"><?= esc($p['name']) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <?php /* rating stars / review count removed — no reviews shown site-wide */ ?>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-baseline gap-2 mt-2">
            <span class="fw-bold" style="color:<?= esc($topic['color']) ?>;font-size:18px;"><?= esc(format_price((float)$p['price'])) ?></span>
            <?php if (!empty($p['original_price']) && (float)$p['original_price'] > (float)$p['price']): ?>
              <span class="text-secondary text-decoration-line-through small"><?= esc(format_price((float)$p['original_price'])) ?></span>
            <?php endif; ?>
            <span class="ms-auto small fw-semibold" style="color:<?= esc($topic['color']) ?>;">View &rsaquo;</span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($hubProducts) > 12): ?>
    <div class="text-center mt-3"><a href="<?= esc($topic['aboutLink']) ?>" class="btn btn-outline-secondary rounded-pill" data-testid="hub-products-view-all">View all <?= count($hubProducts) ?> products <i class="bi bi-arrow-right ms-1"></i></a></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($hubPosts): ?>
<!-- Long-form guides aggregated from the blog. -->
<section id="hub-guides" class="mb-5" aria-labelledby="hub-guides-h2" data-testid="hub-guides">
  <h2 id="hub-guides-h2" class="fw-bold h3 mb-3">Editorial guides on this topic</h2>
  <p class="text-secondary mb-3" style="max-width:780px;">In-depth articles our editorial team has published about <?= esc(strip_tags(explode(' — ', $topic['title'])[0])) ?> &mdash; updated regularly so the dates you see reflect the freshest information.</p>
  <div class="row g-3">
    <?php foreach (array_slice($hubPosts, 0, 9) as $bp): ?>
      <div class="col-md-6 col-lg-4">
        <a href="blog-post.php?id=<?= urlencode((string)$bp['id']) ?>" class="card text-decoration-none h-100" data-testid="hub-guide-card" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($topic['color']) ?>';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
          <?php if (!empty($bp['image'])): ?>
            <img src="<?= esc($bp['image']) ?>" alt="<?= esc($bp['title']) ?>" style="width:100%;height:140px;object-fit:cover;" loading="lazy" decoding="async">
          <?php endif; ?>
          <div style="padding:14px;">
            <div class="fw-bold mb-2" style="color:#0f172a;font-size:14px;line-height:1.35;"><?= esc($bp['title']) ?></div>
            <?php if (!empty($bp['lead'])): ?>
              <p class="text-secondary small mb-2" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?= esc(mb_substr((string)$bp['lead'], 0, 200)) ?></p>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2 small text-secondary">
              <span><i class="bi bi-calendar-event"></i> <?= esc($bp['date']) ?></span>
              <?php if (!empty($bp['read_time'])): ?><span>·</span><span><?= esc($bp['read_time']) ?></span><?php endif; ?>
              <?php $rcb = (string)($bp['target_region'] ?? ''); if ($rcb !== '' && $rcb !== 'ALL'): ?>
                <span>·</span><span class="badge text-bg-light"><?= esc($rcb) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($topic['videos']) && is_array($topic['videos'])): ?>
<!-- Related Videos — YouTube embeds with VideoObject schema for SEO/AI. -->
<section id="hub-videos" class="mb-5" aria-labelledby="hub-videos-h2" data-testid="hub-videos">
  <h2 id="hub-videos-h2" class="fw-bold h3 mb-3">Watch &amp; learn</h2>
  <p class="text-secondary mb-3" style="max-width:780px;">Curated tutorials and product walkthroughs to help you compare and choose with confidence.</p>
  <div class="row g-3">
    <?php foreach (array_slice($topic['videos'], 0, 6) as $v):
      $vu = trim((string)($v['url'] ?? ''));
      if ($vu === '') continue;
      $vid = '';
      if (preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_\-]{11})#', $vu, $m)) $vid = $m[1];
      if ($vid === '') continue;
      $vt = trim((string)($v['title'] ?? '')) ?: 'Video — ' . strip_tags($topic['title']);
    ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100" data-testid="hub-video-card" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
          <div style="position:relative;width:100%;padding-bottom:56.25%;background:#000;">
            <iframe loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen
                    src="https://www.youtube.com/embed/<?= esc($vid) ?>"
                    style="position:absolute;inset:0;width:100%;height:100%;border:0;" title="<?= esc($vt) ?>"></iframe>
          </div>
          <div style="padding:12px 14px;">
            <div class="fw-semibold" style="color:#0f172a;font-size:13.5px;line-height:1.35;"><?= esc($vt) ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($hubFaqs): ?>
<!-- AEO FAQs — visible Q&A serialised to FAQPage JSON-LD via $jsonLdFaq. -->
<section id="hub-faqs" class="mb-5" aria-labelledby="hub-faqs-h2" data-testid="hub-faqs">
  <h2 id="hub-faqs-h2" class="fw-bold h3 mb-3">Everything else people ask</h2>
  <div class="accordion pd-faq-accordion" id="hub-faq-accordion">
    <?php foreach ($hubFaqs as $idx => $f): $itemId = 'hub-faq-q-' . $idx; ?>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                  data-bs-target="#<?= esc($itemId) ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                  aria-controls="<?= esc($itemId) ?>" data-testid="hub-faq-q-<?= $idx ?>">
            <?= esc($f['question']) ?>
          </button>
        </h3>
        <div id="<?= esc($itemId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>" data-bs-parent="#hub-faq-accordion">
          <div class="accordion-body" data-testid="hub-faq-a-<?= $idx ?>"><?= $f['answer'] ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Related topic hubs — internal-link cluster across hubs. -->
<section id="hub-related" class="mb-4" aria-labelledby="hub-related-h2" data-testid="hub-related">
  <h2 id="hub-related-h2" class="fw-bold h3 mb-3">Other topic hubs you might explore</h2>
  <div class="row g-3">
    <?php foreach ($TOPICS as $otherSlug => $other): if ($otherSlug === $topicSlug) continue; ?>
      <div class="col-md-4">
        <a href="hub/<?= esc($otherSlug) ?>" class="card text-decoration-none h-100" data-testid="hub-related-link" style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($other['color']) ?>';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
          <div class="d-inline-block mb-2" style="background:<?= esc($other['color']) ?>;color:#fff;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.5px;">TOPIC HUB</div>
          <div class="fw-bold mb-1" style="color:#0f172a;font-size:14px;"><?= strip_tags($other['title']) ?></div>
          <div class="text-secondary small"><?= esc(mb_substr(strip_tags($other['headline']), 0, 110)) ?>&hellip;</div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
/* ----- helpers -------------------------------------------------- */
function _hub_unique_faqs(array $faqs): array
{
    $seen = [];
    $out  = [];
    foreach ($faqs as $f) {
        $k = mb_strtolower(trim((string)($f['question'] ?? '')));
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = 1;
        $out[] = $f;
    }
    return $out;
}
