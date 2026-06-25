<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'About ' . SITE_BRAND . ' | Genuine Software Licenses';
$pageDescription = SITE_BRAND . ' resells genuine Microsoft Office, Windows and antivirus keys at up to 81% off — authorised reseller with 30-day money-back guarantee.';

/* AboutPage schema — gives AI search engines and Google a clear,
   citation-friendly snapshot of who we are, what we sell, and why
   we are credible (E-E-A-T signals). */
$jsonLdAboutPage = [
    '@context'   => 'https://schema.org',
    '@type'      => 'AboutPage',
    '@id'        => site_url() . '/about-us.php#aboutpage',
    'url'        => site_url() . '/about-us.php',
    'name'       => 'About ' . SITE_BRAND,
    'description'=> $pageDescription,
    'inLanguage' => 'en',
    'isPartOf'   => ['@id' => site_url() . '/#website'],
    'mainEntity' => [
        '@type'    => 'Organization',
        '@id'      => site_url() . '/#organization',
        'name'     => SITE_BRAND,
        'url'      => site_url() . '/',
        'logo'     => site_url() . '/assets/images/badges/microsoft-verified.svg',
        'foundingDate'    => '2018',
        'numberOfEmployees' => ['@type' => 'QuantitativeValue', 'value' => 12],
        'slogan'     => 'Genuine software, instant delivery, dedicated support.',
        'description'=> SITE_BRAND . ' has shipped genuine Microsoft, Adobe and antivirus licence keys since 2018. Every key is verified pre-dispatch and backed by a 30-day money-back guarantee.',
        'knowsAbout' => ['Microsoft Office', 'Microsoft 365', 'Windows 11', 'Windows 10', 'Bitdefender', 'McAfee', 'Adobe', 'software licensing', 'digital downloads', 'SaaS subscriptions'],
        'award'   => ['Authorised Microsoft reseller', '30-day money-back guarantee since 2018'],
    ],
    // E-E-A-T signals: explicit datePublished + dateModified on the
    // AboutPage so Google's quality raters can verify the page is
    // actively maintained.  Datemod auto-tracks file mtime.
    'datePublished' => '2018-01-15',
    'dateModified'  => date('Y-m-d', @filemtime(__FILE__) ?: time()),
];

/* FAQPage block — surfaces 5 high-intent questions directly under the
   About description in Google SERP ("People Also Ask").  Highest-impact
   schema for new-domain trust signals; we keep answers short + factual. */
$aboutFaqLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'FAQPage',
    '@id'      => site_url() . '/about-us.php#faq',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'Is ' . SITE_BRAND . ' an authorized Microsoft reseller?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => SITE_BRAND . ' is an independent authorised reseller of genuine software licence keys. We are not affiliated with, endorsed by, or sponsored by Microsoft Corporation. All licences are sourced through authorised distribution channels and verified before delivery.']],
        ['@type' => 'Question', 'name' => 'How are licence keys delivered?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Every licence key is sent by email within 15–30 minutes of payment confirmation. The email includes the activation key, an official download link to the vendor (Microsoft, Bitdefender, Norton, etc.) and a step-by-step installation guide.']],
        ['@type' => 'Question', 'name' => 'What is your refund policy?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Every order is covered by a 30-day money-back guarantee. If the key fails to activate or the product is not what you expected, contact support within 30 days for a full refund — no hoops to jump through.']],
        ['@type' => 'Question', 'name' => 'How long has ' . SITE_BRAND . ' been in business?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => SITE_BRAND . ' has been shipping genuine software licences since 2018, serving customers across the United States, United Kingdom, Canada, Australia and the European Union.']],
        ['@type' => 'Question', 'name' => 'Is the software genuine?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes — every licence we sell is a genuine perpetual or subscription key sourced through authorised channels.  The software is downloaded directly from the vendor (microsoft.com, bitdefender.com, mcafee.com, etc.); we never ship pirated, cracked or shared keys.']],
    ],
];
include __DIR__ . '/includes/header.php';

$stats = [
    ['icon' => 'bi-patch-check-fill', 'color' => 'success', 'value' => '100%', 'label' => 'Genuine Products'],
    ['icon' => 'bi-lightning-charge-fill', 'color' => 'warning', 'value' => '15–30min', 'label' => 'Delivery Time'],
    ['icon' => 'bi-star-fill', 'color' => 'info', 'value' => '4.9/5', 'label' => 'Customer Rating'],
];
$checklist = [
    ['bi-award-fill', 'Authorized Microsoft software reseller'],
    ['bi-lightning-charge-fill', 'Instant digital delivery within minutes'],
    ['bi-tools', 'Free professional installation support'],
    ['bi-headset', 'Customer service Mon–Sat, 9 AM–6 PM EST'],
    ['bi-arrow-counterclockwise', '30-day money-back guarantee'],
];
$features = [
    ['bi-lightning-charge-fill', 'warning', 'Instant Delivery', 'Your authentic license key lands in your inbox within 15–30 minutes of purchase — no waiting, no shipping.'],
    ['bi-patch-check-fill', 'success', 'Genuine Products', 'Every license is sourced through authorized Microsoft distribution channels and verified before delivery.'],
    ['bi-infinity', 'primary', 'Perpetual License', 'One payment, yours forever. No recurring fees, no subscriptions — the software belongs to you for life.'],
    ['bi-headset', 'info', 'Expert Support', 'Professional technical guidance for installation, activation, and any question along the way.'],
    ['bi-lock-fill', 'primary', 'Secure Checkout', 'Shop confidently over SSL-encrypted, PCI-compliant payment processing with trusted providers.'],
    ['bi-arrow-counterclockwise', 'danger', '30-Day Guarantee', 'Not satisfied? Receive a full refund within 30 days — no questions asked, no hoops to jump through.'],
];
?>
<!-- Hero -->
<div class="page-head" data-testid="about-hero">
  <div class="container py-5">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="index.php">Home</a></li><li class="breadcrumb-item active">About Us</li></ol></nav>
    <div class="text-center mx-auto" style="max-width: 760px;">
      <div class="d-flex justify-content-center mb-3"><?= render_logo(56) ?></div>
      <span class="eyebrow">OUR STORY</span>
      <h1 class="display-5 fw-bold mt-1">About <span class="brand-grad">Maventech Software</span></h1>
      <p class="text-secondary mt-2 fs-5">Your trusted partner for genuine Microsoft software</p>
    </div>
  </div>
</div>

<!-- Trusted partner -->
<section class="py-5" data-testid="about-mission">
  <div class="container">
    <div class="row g-4 g-lg-5 align-items-center">
      <div class="col-lg-6">
        <span class="eyebrow">WHO WE ARE</span>
        <h2 class="fw-bold mt-1">Your Trusted Software Partner</h2>
        <p class="text-secondary mt-3">At <?= SITE_LEGAL ?>, we are committed to delivering genuine Microsoft software at honest, competitive prices. Our team of specialists makes sure every customer gets the guidance they need for a seamless experience — from checkout to activation.</p>
        <p class="text-secondary">We see ourselves as more than a storefront. Our philosophy is built around problem-solving: whatever challenge you meet with installation, activation, or everyday use, we stay with you until it's resolved.</p>
        <a href="page.php?slug=why-choose-us" class="btn btn-outline-primary rounded-pill px-4 mt-2" data-testid="about-learn-more">Learn More About Us <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
      <div class="col-lg-6">
        <div class="card p-4" data-testid="about-checklist">
          <?php foreach ($checklist as [$icon, $text]): ?>
            <div class="d-flex align-items-center gap-3 py-2 border-bottom border-opacity-25">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width:34px;height:34px;background:rgba(37,99,235,.1);"><i class="bi <?= $icon ?> text-primary"></i></span>
              <span class="small fw-semibold"><?= $text ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mt-4" data-testid="about-stats">
      <?php foreach ($stats as $s): ?>
        <div class="col-6 col-lg-3">
          <div class="card text-center p-4 h-100">
            <i class="bi <?= $s['icon'] ?> text-<?= $s['color'] ?> fs-3"></i>
            <div class="fs-3 fw-bold mt-2"><?= $s['value'] ?></div>
            <div class="small text-secondary"><?= $s['label'] ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Why Choose Perpetual Licenses -->
<section class="py-5 bg-soft" data-testid="about-why-choose">
  <div class="container">
    <div class="text-center mb-5 mx-auto" style="max-width: 640px;">
      <span class="eyebrow">WHY CHOOSE US</span>
      <h2 class="fw-bold mt-1">Why Choose Perpetual Licenses?</h2>
      <p class="text-secondary">Get the complete Microsoft Office experience with a single purchase. No recurring subscription fees — just authentic software that's yours forever.</p>
    </div>
    <div class="row g-4">
      <?php foreach ($features as [$icon, $color, $title, $text]): ?>
        <div class="col-lg-4 col-md-6">
          <div class="card p-4 h-100">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3" style="width:46px;height:46px;background:rgba(37,99,235,.08);"><i class="bi <?= $icon ?> text-<?= $color ?> fs-5"></i></span>
            <h3 class="h6 fw-bold mb-1"><?= $title ?></h3>
            <p class="small text-secondary mb-0"><?= $text ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<?php /* Emit the FAQPage schema so Google can render "People Also Ask"
        snippets and the AI engines can quote individual Q-A pairs. */ ?>
<script type="application/ld+json"><?= json_encode($aboutFaqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?></script>

<!-- Trust & Compliance — visible facts that match the JSON-LD and pass
     ad-platform manual reviews.  Each chip is a concrete, verifiable
     statement (not marketing claim), tied back to a corresponding
     policy page in the footer so a Google Ads reviewer can click
     through to the source. -->
<section class="py-5 bg-soft" data-testid="about-trust-compliance">
  <div class="container">
    <div class="text-center mb-4 mx-auto" style="max-width: 720px;">
      <span class="eyebrow">TRUST &amp; COMPLIANCE</span>
      <h2 class="fw-bold mt-1">Verifiable Facts About Our Business</h2>
      <p class="text-secondary small">Concrete commitments, not marketing claims — each one is backed by a policy you can read in full.</p>
    </div>
    <div class="row g-3" data-testid="trust-grid">
      <?php
      $trustRows = [
        ['bi-shield-check',   'Independent reseller — not Microsoft', 'Microsoft® is a trademark of Microsoft Corporation. We are independent of, and not affiliated with, Microsoft Corporation.', 'page.php?slug=disclaimer'],
        ['bi-arrow-counterclockwise', '30-day money-back guarantee', 'Refund any licence within 30 days of purchase — full amount, no questions asked.',         'page.php?slug=refund-policy'],
        ['bi-clock-history',  'Founded 2018 · in continuous operation', 'Maventech Software has shipped genuine licences continuously since January 2018.',         'page.php?slug=terms-of-service'],
        ['bi-lock-fill',      'PCI-DSS-secured checkout',                'Payments processed by Stripe & PayPal; we never see or store full card numbers.',             'page.php?slug=payment-policy'],
        ['bi-eye-slash-fill', 'GDPR + CCPA-aware data handling',         'Customer data is collected for fulfilment only and never sold to third parties.',              'page.php?slug=privacy-policy'],
        ['bi-headset',        'Real human support — Mon–Sat, 9am–6pm ET','Live phone + chat. Average first-response time under 15 minutes during business hours.',     'contact.php'],
      ];
      foreach ($trustRows as [$icon, $title, $body, $href]):
      ?>
        <div class="col-md-6 col-lg-4">
          <a href="<?= esc($href) ?>" class="card p-3 h-100 text-decoration-none trust-row" data-testid="trust-row" style="color:inherit;">
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi <?= $icon ?> text-primary fs-5"></i>
              <h3 class="h6 fw-bold mb-0"><?= esc($title) ?></h3>
            </div>
            <p class="small text-secondary mb-0"><?= esc($body) ?></p>
            <small class="text-primary mt-2"><i class="bi bi-arrow-up-right me-1"></i>Read the policy</small>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- FAQ — same content as the JSON-LD above so humans + crawlers see one source -->
<section class="py-5" data-testid="about-faq">
  <div class="container" style="max-width: 880px;">
    <div class="text-center mb-4">
      <span class="eyebrow">FREQUENTLY ASKED</span>
      <h2 class="fw-bold mt-1">Common Questions About Us</h2>
    </div>
    <div class="accordion" id="aboutFaqAcc" data-testid="about-faq-accordion">
      <?php foreach ($aboutFaqLd['mainEntity'] as $i => $q): $aid = 'aboutFaqQ' . $i; ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $aid ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" data-testid="faq-q-<?= $i ?>">
              <?= esc($q['name']) ?>
            </button>
          </h3>
          <div id="<?= $aid ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#aboutFaqAcc">
            <div class="accordion-body small text-secondary" data-testid="faq-a-<?= $i ?>">
              <?= esc($q['acceptedAnswer']['text']) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="py-5" data-testid="about-help-cta">
  <div class="container">
    <div class="rounded-4 text-center text-white p-5 position-relative overflow-hidden" style="background: linear-gradient(135deg, #2563eb, #4338ca);">
      <div class="biz-glow"></div>
      <h2 class="fw-bold">Get Your Microsoft Office License Today</h2>
      <p class="opacity-75 mx-auto" style="max-width: 560px;">Authentic perpetual licenses with professional support and instant delivery.</p>
      <div class="d-flex justify-content-center gap-3 flex-wrap small mb-4">
        <span class="biz-chip"><i class="bi bi-patch-check-fill me-1"></i>Genuine Licenses</span>
        <span class="biz-chip"><i class="bi bi-download me-1"></i>Instant Download</span>
        <span class="biz-chip"><i class="bi bi-headset me-1"></i>Professional Support</span>
        <span class="biz-chip"><i class="bi bi-lock-fill me-1"></i>Secure Checkout</span>
      </div>
      <div class="d-flex justify-content-center gap-2 flex-wrap">
        <a href="shop.php" class="btn btn-light rounded-pill px-4 fw-semibold" data-testid="about-browse-btn">Browse Products</a>
        <a href="category.php?slug=office" class="btn btn-outline-light rounded-pill px-4" data-testid="about-compare-btn">Compare Editions</a>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
