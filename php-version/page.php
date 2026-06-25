<?php
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
if ($slug === 'contact-us') { // rich contact page replaced the DB page
    header('Location: contact.php');
    exit;
}
$page = null;
if ($slug) {
    $stmt = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
}
$pageTitle = ($page ? $page['title'] : 'Page Not Found') . ' | ' . SITE_BRAND;
if ($page) {
    // Resolve company-info placeholders once so they render consistently in
    // the body AND in the meta description / any derived text below.
    $page['content'] = company_placeholders_apply((string)$page['content']);
    $pageDescription = $page['title'] . ' — ' . trim(mb_substr(strip_tags($page['content']), 0, 140)) . '…';
} else {
    http_response_code(404);
    $noIndex = true;
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 860px;">
  <?php if ($page): ?>
    <h1 class="fw-bold" data-testid="page-title"><?= esc($page['title']) ?></h1>
    <?php if ($page['updated']): ?><p class="text-secondary small">Last updated: <?= esc($page['updated']) ?></p><?php endif; ?>
    <hr>
    <div class="page-content"><?= $page['content'] /* trusted HTML; company placeholders already resolved above */ ?></div>

    <?php if ($slug === 'disclaimer'): ?>
      <div class="card p-4 mt-4" data-testid="disclaimer-promo"><?= render_menu_promo() ?></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="text-center py-5">
      <h1 class="fw-bold">Page not found</h1>
      <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-3">Back to Home</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
