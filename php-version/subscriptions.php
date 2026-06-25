<?php
/**
 * Public subscription plans page — a comparison/pricing page customers can
 * browse before clicking through to checkout via /subscribe.php?plan=<slug>.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Subscription Plans | ' . SITE_BRAND;
$pageDescription = 'Compare ' . SITE_BRAND . ' tech-support subscription plans — Quick Fix, Starter Care, Pro Shield and Lifetime Elite. Unlimited remote support, device coverage, priority help and more.';

$plans = sub_plans(true);
$co    = function_exists('company_info') ? company_info() : [];
$phone = (function_exists('company_phone_for_country') ? company_phone_for_country() : ($co['phone'] ?? '')) ?: (defined('SITE_PHONE') ? SITE_PHONE : '');

// Comparison matrix rows (label => per-plan value, keyed by slug).
$matrix = [
    'Plan duration'            => ['quick-fix' => 'One session', 'starter-care' => '1 Year', 'pro-shield' => '3 Years', 'lifetime-elite' => '10 Years'],
    'Device coverage'          => ['quick-fix' => '1', 'starter-care' => '1', 'pro-shield' => 'Up to 3', 'lifetime-elite' => 'Unlimited'],
    'One-time issue resolution'=> ['quick-fix' => true, 'starter-care' => true, 'pro-shield' => true, 'lifetime-elite' => true],
    'Unlimited remote support' => ['quick-fix' => false, 'starter-care' => true, 'pro-shield' => true, 'lifetime-elite' => true],
    'Virus & malware removal'  => ['quick-fix' => true, 'starter-care' => true, 'pro-shield' => true, 'lifetime-elite' => true],
    'Performance optimization' => ['quick-fix' => true, 'starter-care' => true, 'pro-shield' => true, 'lifetime-elite' => true],
    'Device transferability'   => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => true, 'lifetime-elite' => 'Unlimited'],
    'Priority support'         => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => true, 'lifetime-elite' => 'Premium'],
    'Dedicated specialist'     => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => false, 'lifetime-elite' => true],
];
$cell = function ($v) {
    if ($v === true)  return '<i class="bi bi-check-circle-fill text-success"></i>';
    if ($v === false) return '<i class="bi bi-x-lg text-secondary opacity-50"></i>';
    return '<span class="fw-semibold">' . esc((string)$v) . '</span>';
};
include __DIR__ . '/includes/header.php';
?>
<section class="py-5" style="background:linear-gradient(180deg,var(--bs-tertiary-bg,#f1f5f9) 0%, transparent 100%);">
  <div class="container">
    <div class="text-center mx-auto" style="max-width:760px;">
      <span class="badge rounded-pill text-bg-primary mb-2" data-testid="sub-page-badge"><i class="bi bi-stars me-1"></i>Tech Support Subscriptions</span>
      <h1 class="fw-bold display-6 mb-2" data-testid="sub-page-title">Pick the plan that keeps you covered</h1>
      <p class="text-secondary fs-5">Genuine, friendly tech support — from a one-time fix to a decade of premium, priority help across all your devices.</p>
      <?php if (empty($plans)): ?>
        <div class="alert alert-info mt-3">Our subscription plans are being finalised. Please check back soon<?= $phone ? ' or call <strong>' . esc($phone) . '</strong>' : '' ?>.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="row g-4 justify-content-center" data-testid="sub-plan-cards">
      <?php foreach ($plans as $p): $priced = (float)$p['price'] > 0; $featured = $p['slug'] === 'pro-shield'; ?>
        <div class="col-md-6 col-xl-3">
          <div class="card h-100 shadow-sm <?= $featured ? 'border-primary' : '' ?>" style="border-radius:16px;<?= $featured ? 'border-width:2px;' : '' ?>" data-testid="sub-card-<?= esc($p['slug']) ?>">
            <?php if ($featured): ?><div class="text-center"><span class="badge text-bg-primary rounded-pill" style="margin-top:-12px;">Most popular</span></div><?php endif; ?>
            <div class="card-body d-flex flex-column p-4">
              <?php if (!empty($p['icon_image'])): ?>
                <div class="text-center mb-2">
                  <img src="<?= esc($p['icon_image']) ?>" alt="<?= esc($p['name']) ?> icon" data-testid="sub-icon-<?= esc($p['slug']) ?>" loading="lazy"
                       style="width:84px;height:84px;object-fit:contain;filter:drop-shadow(0 8px 18px rgba(37,99,235,.22));">
                </div>
              <?php endif; ?>
              <h3 class="h5 fw-bold mb-1 text-center"><?= esc($p['name']) ?></h3>
              <p class="text-secondary small mb-3 text-center" style="min-height:38px;"><?= esc($p['tagline']) ?></p>
              <div class="mb-2">
                <?php if ($priced): ?>
                  <span class="display-6 fw-bold"><?= esc(format_price((float)$p['price'])) ?></span>
                  <div class="small text-secondary"><?= esc($p['tenure_label']) ?> · <?= esc($p['devices']) ?></div>
                <?php else: ?>
                  <span class="h5 fw-bold text-secondary">Contact us</span>
                  <div class="small text-secondary"><?= esc($p['tenure_label']) ?> · <?= esc($p['devices']) ?></div>
                <?php endif; ?>
              </div>
              <ul class="list-unstyled small flex-grow-1 mb-3">
                <?php foreach (array_slice($p['features'], 0, 7) as $f): ?>
                  <li class="mb-1"><i class="bi bi-check2 text-success me-1"></i><?= esc($f) ?></li>
                <?php endforeach; ?>
                <?php if (count($p['features']) > 7): ?><li class="text-secondary">+ <?= count($p['features']) - 7 ?> more benefits</li><?php endif; ?>
              </ul>
              <?php if ($priced): ?>
                <a href="subscribe.php?plan=<?= esc($p['slug']) ?>" class="btn <?= $featured ? 'btn-primary' : 'btn-outline-primary' ?> w-100 rounded-pill fw-semibold" data-testid="sub-buy-<?= esc($p['slug']) ?>"><i class="bi bi-cart-check me-1"></i>Buy Now</a>
              <?php else: ?>
                <a href="contact.php" class="btn btn-outline-secondary w-100 rounded-pill" data-testid="sub-contact-<?= esc($p['slug']) ?>">Contact us</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (!empty($plans)): ?>
<section class="pb-5">
  <div class="container">
    <h2 class="h4 fw-bold text-center mb-4">Compare all plans</h2>
    <div class="table-responsive">
      <table class="table align-middle text-center" style="min-width:680px;" data-testid="sub-compare-table">
        <thead>
          <tr>
            <th class="text-start" style="width:240px;">Features</th>
            <?php foreach ($plans as $p): ?><th><?= esc($p['name']) ?><?php if ((float)$p['price']>0): ?><div class="small fw-normal text-secondary"><?= esc(format_price((float)$p['price'])) ?></div><?php endif; ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matrix as $label => $vals): ?>
            <tr>
              <td class="text-start fw-semibold"><?= esc($label) ?></td>
              <?php foreach ($plans as $p): ?>
                <td><?= $cell($vals[$p['slug']] ?? false) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td></td>
            <?php foreach ($plans as $p): ?>
              <td><?php if ((float)$p['price']>0): ?><a href="subscribe.php?plan=<?= esc($p['slug']) ?>" class="btn btn-sm btn-primary rounded-pill px-3" data-testid="sub-compare-buy-<?= esc($p['slug']) ?>">Buy Now</a><?php else: ?><a href="contact.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Contact</a><?php endif; ?></td>
            <?php endforeach; ?>
          </tr>
        </tbody>
      </table>
    </div>
    <?php if ($phone): ?>
      <p class="text-center text-secondary mt-3">Questions about a plan? Call us at <a href="tel:<?= esc(tel_e164($phone)) ?>" class="fw-semibold text-decoration-none"><?= esc($phone) ?></a> — we're happy to help.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
