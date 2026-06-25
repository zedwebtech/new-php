<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Contact ' . SITE_BRAND . ' | Sales, Support & Activation';
$pageDescription = 'Get in touch with ' . SITE_BRAND . ' for sales, activation help, refunds or partnerships. Live chat, email and phone support Mon–Sat.';

/* ================== SEO + AEO + GEO: ContactPage + Organization =====
   Tells Google / Bing / ChatGPT / Perplexity exactly who to contact
   and how.  The ContactPoint nodes power AI assistant answers like
   "How do I reach Maventech Software support?" with a clickable
   phone number + email + opening hours. */
$jsonLdContact = [
    '@context' => 'https://schema.org',
    '@type'    => 'ContactPage',
    '@id'      => site_url() . '/contact.php#contactpage',
    'url'      => site_url() . '/contact.php',
    'name'     => 'Contact ' . SITE_BRAND,
    'description' => $pageDescription,
    'inLanguage'  => 'en',
    'isPartOf' => ['@id' => site_url() . '/#website'],
    'about'    => [
        '@type'   => 'Organization',
        '@id'     => site_url() . '/#organization',
        'name'    => SITE_BRAND,
        'url'     => site_url() . '/',
        'logo'    => site_url() . '/assets/images/badges/microsoft-verified.svg',
        'sameAs'  => array_values(array_filter([
            (string)(setting_get('contact_facebook_url', '')),
            (string)(setting_get('contact_twitter_url',  '')),
            (string)(setting_get('contact_linkedin_url', '')),
            (string)(setting_get('contact_youtube_url',  '')),
            (string)(setting_get('contact_instagram_url',''))
        ])),
        // Single contactPoint array with BOTH support tracks — gives AI
        // assistants a clean 2-entry block to extract for queries like
        // "How do I contact Maventech Software?" / "Where's their sales line?".
        'contactPoint' => array_values(array_filter([
            [
                '@type'             => 'ContactPoint',
                'contactType'       => 'customer support',
                'telephone'         => (string)setting_get('contact_phone', ''),
                'email'             => (string)setting_get('contact_email', 'support@maventechsoftware.com'),
                'availableLanguage' => ['English'],
                'areaServed'        => ['US', 'GB', 'CA', 'AU'],
                'hoursAvailable'    => 'Mo-Sa 09:00-18:00',
            ],
            [
                '@type'             => 'ContactPoint',
                'contactType'       => 'sales',
                'email'             => (string)setting_get('contact_sales_email', setting_get('contact_email', 'sales@maventechsoftware.com')),
                'availableLanguage' => ['English'],
                'areaServed'        => ['US', 'GB', 'CA', 'AU'],
            ],
        ])),
    ],
];

$sent = false;
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
        $formError = 'Please fill in all required fields with a valid email.';
    } else {
        // Reject typo domains (gmail.con / yaho.com etc.) BEFORE we save
        // the ticket so the auto-acknowledgement email isn't sent into
        // the void.  Mirror of the validation we apply at checkout +
        // notify-stock + lead form.
        require_once __DIR__ . '/includes/mailer.php';
        $deliv = function_exists('email_address_deliverable')
            ? email_address_deliverable($email)
            : ['ok' => true];
        if (!$deliv['ok'] && in_array($deliv['reason'] ?? '', ['no_mx','invalid_syntax'], true)) {
            $formError = $deliv['detail'] ?: 'That email address looks undeliverable — please double-check the spelling.';
        } else {
            save_support_message([
                'name' => $name, 'email' => strtolower($email),
                'phone' => trim($_POST['phone'] ?? ''), 'order_number' => trim($_POST['order_number'] ?? ''),
                'subject' => $subject, 'message' => $message, 'source' => 'contact',
            ]);
            // Customer-service auto-acknowledgement (5-minute delayed delivery)
            require_once __DIR__ . '/includes/email.php';
            send_customer_service_ack(strtolower($email), $name, $subject, $message, 'contact');

            // Email the COMPANY the actual enquiry, to the address set in
            // Admin → Company Info (falls back to the contact/site email).
            try {
                $co        = function_exists('company_info') ? company_info() : [];
                $companyTo = trim((string)($co['email'] ?? ''));
                if ($companyTo === '') $companyTo = trim((string)setting_get('contact_email', ''));
                if ($companyTo === '' && defined('SITE_EMAIL')) $companyTo = SITE_EMAIL;
                if ($companyTo !== '' && filter_var($companyTo, FILTER_VALIDATE_EMAIL)) {
                    $brand  = $co['name'] ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
                    $phoneC = trim((string)($_POST['phone'] ?? ''));
                    $ordC   = trim((string)($_POST['order_number'] ?? ''));
                    $nameE  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                    $emailE = htmlspecialchars(strtolower($email), ENT_QUOTES, 'UTF-8');
                    $subjE  = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
                    $msgE   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
                    $phoneE = htmlspecialchars($phoneC, ENT_QUOTES, 'UTF-8');
                    $ordE   = htmlspecialchars($ordC, ENT_QUOTES, 'UTF-8');
                    $brandE = htmlspecialchars((string)$brand, ENT_QUOTES, 'UTF-8');
                    $html = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">'
                        . '<div style="background:#0f172a;padding:18px 22px;border-radius:10px 10px 0 0;color:#fff;">'
                        . '<div style="font-size:11px;letter-spacing:.12em;font-weight:800;text-transform:uppercase;color:#60a5fa;">' . $brandE . ' — Contact Form</div>'
                        . '<div style="font-size:19px;font-weight:800;margin-top:4px;">New message from ' . $nameE . '</div></div>'
                        . '<div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;padding:22px;line-height:1.55;">'
                        . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px;">'
                        . '<tr><td style="padding:6px 0;color:#64748b;width:120px;">From</td><td style="padding:6px 0;font-weight:600;">' . $nameE . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#64748b;">Email</td><td style="padding:6px 0;"><a href="mailto:' . $emailE . '" style="color:#2563eb;text-decoration:none;">' . $emailE . '</a></td></tr>'
                        . ($phoneC !== '' ? '<tr><td style="padding:6px 0;color:#64748b;">Phone</td><td style="padding:6px 0;">' . $phoneE . '</td></tr>' : '')
                        . ($ordC !== '' ? '<tr><td style="padding:6px 0;color:#64748b;">Order #</td><td style="padding:6px 0;">' . $ordE . '</td></tr>' : '')
                        . '<tr><td style="padding:6px 0;color:#64748b;">Subject</td><td style="padding:6px 0;font-weight:600;">' . $subjE . '</td></tr>'
                        . '</table>'
                        . '<div style="font-weight:700;margin-bottom:4px;">Message</div>'
                        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;font-size:13px;color:#334155;">' . $msgE . '</div>'
                        . '<p style="margin:16px 0 0;font-size:12px;color:#94a3b8;">Reply directly to this email to respond to ' . $nameE . '.</p>'
                        . '</div></div>';
                    send_email($companyTo, '[Contact] ' . $subject . ' — ' . $name, $html, null, 'contact_form', 0);
                }
            } catch (Throwable $e) { @error_log('[contact company email] ' . $e->getMessage()); }

            // Admin bell notification too.
            try {
                if (function_exists('admin_notify')) {
                    admin_notify('lead', 'New contact message — ' . $name, $subject, '/admin.php?tab=leads');
                }
            } catch (Throwable $e) { /* best-effort */ }

            $sent = true;
        }
    }
}

$contactFaqs = [
    ['How long does it take to receive my license key?', 'Your license key and download instructions are delivered by email within 15-30 minutes of purchase — usually just a few minutes.'],
    ['What if my license key doesn\'t work?', 'First make sure the key is entered exactly (no extra spaces, watch 0 vs O). If it still fails, contact our support team with your order number and we\'ll resolve it or replace the key.'],
    ['Do you offer refunds?', 'Yes — we offer a money-back guarantee. See our Refund Policy for full details, or start a request on the Return & Refund page.'],
    ['How do I activate my Microsoft Office license?', 'Download the official installer from setup.office.com, sign in with a Microsoft account, and enter your 25-character product key when prompted. Full steps are in our Activation Help guide.'],
    ['Can I use my license on multiple devices?', 'Most licenses are valid for 1 PC or Mac unless the product states otherwise. Buying for a team? Ask us about volume licensing.'],
];

include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div class="page-head">
  <div class="container py-5 text-center">
    <span class="eyebrow">WE'RE HERE TO HELP</span>
    <h1 class="fw-bold display-6 mt-1" data-testid="contact-title">Contact <?= esc(SITE_BRAND) ?> — Sales &amp; Support</h1>
    <p class="text-secondary mx-auto" style="max-width:620px;">Have questions about your order, license activation, or need technical support? Our team is ready to assist you.</p>
    <div class="d-flex justify-content-center gap-4 flex-wrap small mt-3">
      <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Microsoft Licenses</span>
      <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Digital Delivery</span>
      <span><i class="bi bi-arrow-counterclockwise text-primary me-1"></i>30-Day Money Back Guarantee</span>
    </div>
  </div>
</div>

<div class="container py-5">
  <!-- Contact methods -->
  <div class="row g-4 mb-5">
    <div class="col-lg-4">
      <div class="card h-100 p-4 text-center position-relative" data-testid="contact-card-email">
        <span class="badge text-bg-primary position-absolute top-0 start-50 translate-middle">Recommended</span>
        <i class="bi bi-envelope-fill text-primary fs-2"></i>
        <h3 class="h6 fw-bold mt-2 mb-1">Email Support</h3>
        <small class="text-secondary d-block mb-2">Get a response within 24 hours</small>
        <a href="mailto:<?= SITE_EMAIL ?>" class="fw-bold text-decoration-none"><?= SITE_EMAIL ?></a>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100 p-4 text-center position-relative" data-testid="contact-card-chat">
        <span class="badge text-bg-success position-absolute top-0 start-50 translate-middle">Instant</span>
        <i class="bi bi-chat-dots-fill text-primary fs-2"></i>
        <h3 class="h6 fw-bold mt-2 mb-1">Live Chat</h3>
        <small class="text-secondary d-block mb-2">Chat with our support team</small>
        <button class="btn btn-sm btn-outline-primary rounded-pill px-3 mx-auto" onclick="toggleChat()">Start a chat · <?= SITE_HOURS ?></button>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100 p-4 text-center" data-testid="contact-card-phone">
        <i class="bi bi-telephone-fill text-primary fs-2"></i>
        <h3 class="h6 fw-bold mt-2 mb-1">Phone Support</h3>
        <small class="text-secondary d-block mb-2">Talk to a specialist</small>
        <a href="tel:<?= esc(tel_e164(company_phone_for_country())) ?>" class="fw-bold text-decoration-none"><?= esc(company_phone_for_country()) ?></a>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- Form -->
    <div class="col-lg-7">
      <div class="card p-4 p-lg-5">
        <h2 class="h4 fw-bold mb-1">Send Us a Message</h2>
        <p class="text-secondary small mb-4">Fill out the form below and we'll respond as soon as possible.</p>

        <?php if ($sent): ?>
          <div class="alert alert-success" data-testid="contact-success"><i class="bi bi-check-circle-fill me-2"></i>Thanks! Your message has been received — we'll get back to you within 24 hours.</div>
        <?php else: ?>
          <?php if ($formError): ?><div class="alert alert-danger py-2 small" data-testid="contact-error"><?= esc($formError) ?></div><?php endif; ?>
          <form method="post">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Full Name *</label>
                <input name="name" class="form-control" placeholder="John Doe" required data-testid="contact-name">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Email Address *</label>
                <input type="email" name="email" class="form-control" placeholder="john@example.com" required data-testid="contact-email">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Phone Number <span class="text-secondary fw-normal">(Optional)</span></label>
                <input name="phone" class="form-control" placeholder="+1 (555) 123-4567" data-testid="contact-phone">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Order Number <span class="text-secondary fw-normal">(Optional)</span></label>
                <input name="order_number" class="form-control" placeholder="UC-XXXXXX" data-testid="contact-order">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Subject *</label>
                <select name="subject" class="form-select" required data-testid="contact-subject">
                  <option value="General Inquiry">General Inquiry</option>
                  <option value="Order Issue">Order Issue</option>
                  <option value="License / Activation Help">License / Activation Help</option>
                  <option value="Refund Request">Refund Request</option>
                  <option value="Technical Support">Technical Support</option>
                  <option value="Volume / Business Pricing">Volume / Business Pricing</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Message *</label>
                <textarea name="message" class="form-control" rows="5" placeholder="Please describe your question or issue in detail..." required data-testid="contact-message"></textarea>
              </div>
              <div class="col-12">
                <button class="btn btn-primary rounded-pill px-4 fw-semibold" data-testid="contact-send">Send Message</button>
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-5">
      <div class="card p-4 mb-4" data-testid="contact-office">
        <h3 class="h6 fw-bold mb-2"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Our Office</h3>
        <p class="small text-secondary mb-0"><strong class="text-body"><?= SITE_LEGAL ?></strong><br><?= SITE_ADDRESS ?><br>United States</p>
      </div>
      <div class="card p-4 mb-4" data-testid="contact-hours">
        <h3 class="h6 fw-bold mb-2"><i class="bi bi-clock-fill text-primary me-2"></i>Business Hours</h3>
        <div class="small d-grid gap-1">
          <div class="d-flex justify-content-between"><span class="text-secondary">Monday - Friday</span><span class="fw-semibold">9:00 AM - 6:00 PM EST</span></div>
          <div class="d-flex justify-content-between"><span class="text-secondary">Saturday</span><span class="fw-semibold">10:00 AM - 4:00 PM EST</span></div>
          <div class="d-flex justify-content-between"><span class="text-secondary">Sunday</span><span class="fw-semibold">Closed</span></div>
        </div>
        <small class="text-secondary mt-2"><i class="bi bi-chat-dots me-1"></i>Live chat: <?= SITE_HOURS ?></small>
      </div>
      <div class="card p-4" style="background: rgba(37,99,235,.05);">
        <h3 class="h6 fw-bold mb-1"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quick Response</h3>
        <small class="text-secondary">We typically respond to all inquiries within 24 hours. For urgent matters, please use our live chat for immediate assistance.</small>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="mt-5 mx-auto" style="max-width: 760px;">
    <div class="text-center mb-4">
      <h2 class="fw-bold h3">Frequently Asked Questions</h2>
      <p class="text-secondary small">Find quick answers to common questions</p>
    </div>
    <div class="accordion" id="contactFaq" data-testid="contact-faq">
      <?php foreach ($contactFaqs as $i => [$q, $a]): ?>
        <div class="accordion-item">
          <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cfaq<?= $i ?>"><?= esc($q) ?></button></h2>
          <div id="cfaq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#contactFaq"><div class="accordion-body small text-secondary"><?= esc($a) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CTA -->
  <div class="rounded-4 text-center text-white p-5 mt-5" style="background: linear-gradient(135deg, #2563eb, #4338ca);">
    <h2 class="fw-bold h3">Still Need Help?</h2>
    <p class="opacity-75 mx-auto" style="max-width:520px;">Our dedicated support team is here to ensure you have the best experience. Don't hesitate to reach out!</p>
    <div class="d-flex justify-content-center gap-2 flex-wrap">
      <a href="mailto:<?= SITE_EMAIL ?>" class="btn btn-light rounded-pill px-4 fw-semibold">Email Support</a>
      <button class="btn btn-outline-light rounded-pill px-4" onclick="toggleChat()">Start Live Chat</button>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
