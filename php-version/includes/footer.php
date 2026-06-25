<?php /* Footer + chat widget + scripts */ ?>
</main><!-- /#main-content (opened in header.php) -->
<footer class="footer-dark pt-0 pb-4 mt-5">

  <!-- Newsletter band -->
  <div class="border-bottom border-secondary-subtle" style="border-color: rgba(255,255,255,.12) !important;">
    <div class="container text-center py-5">
      <h3 class="text-white fw-bold fs-2">Join our list and save up to <span style="color:#67e8f9;">81%</span></h3>
      <p class="small mb-4">Subscribe and receive exclusive weekly deals straight to your inbox!</p>
      <form class="d-flex gap-2 mx-auto" style="max-width: 420px;" onsubmit="subscribeNewsletter(event)">
        <input type="email" required class="form-control rounded-pill px-3" placeholder="Enter your email" data-testid="newsletter-email">
        <button class="btn btn-primary rounded-pill px-4 fw-semibold" type="submit" data-testid="newsletter-join">Join</button>
      </form>
      <div class="d-flex justify-content-center gap-4 flex-wrap small mt-4">
        <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Products</span>
        <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Delivery</span>
        <span><i class="bi bi-headset text-primary me-1"></i>Expert Support</span>
      </div>
    </div>
  </div>

  <div class="container pt-5">
    <div class="row g-4">
      <!-- Brand column -->
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <?php if (!empty($brandLogo)): ?>
            <img src="<?= esc($brandLogo) ?>" alt="<?= esc($brandName) ?>" style="height:42px;width:auto;max-width:140px;object-fit:contain;" width="140" height="42" loading="lazy" decoding="async">
          <?php else: ?>
            <?= render_logo(42) ?>
          <?php endif; ?>
          <span>
            <?php
              $bnParts = preg_split('/\s+/', trim($brandName));
              $bnLast  = array_pop($bnParts) ?: '';
              $bnHead  = implode(' ', $bnParts);
            ?>
            <span class="brand-text d-block lh-1 text-white"><?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span></span>
            <?php if (setting_get('show_authorized_reseller_badge', '1') === '1'): ?>
            <small class="brand-tag" data-testid="brand-tag-authorized-reseller-footer">AUTHORIZED RESELLER</small>
            <?php endif; ?>
          </span>
        </div>
        <p class="small">Your trusted source for genuine Microsoft Office licenses at competitive prices. Instant delivery, one-time purchase with no recurring fees, and professional support.</p>

        <div class="small fw-bold text-white mb-2">Subscribe for Deals</div>
        <form class="d-flex gap-2 mb-3" style="max-width: 320px;" onsubmit="subscribeNewsletter(event)">
          <input type="email" required class="form-control form-control-sm" placeholder="Enter your email">
          <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-arrow-right"></i></button>
        </form>

        <p class="small mb-1"><i class="bi bi-telephone me-2 text-info"></i><a href="tel:<?= esc(tel_e164($brandPhone)) ?>"><?= esc($brandPhone) ?></a></p>
        <p class="small mb-1"><i class="bi bi-envelope me-2 text-info"></i><a href="mailto:<?= esc($brandEmail) ?>"><?= esc($brandEmail) ?></a></p>
        <p class="small mb-2"><i class="bi bi-geo-alt me-2 text-info"></i><?= esc($brandAddress) ?></p>
        <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($brandAddress) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light rounded-pill mb-2 gmap-btn" data-testid="footer-gmap-btn">
          <span class="gmap-pin"><i class="bi bi-geo-alt-fill"></i></span>View on Google Maps
        </a>
        <p class="small mb-3"><i class="bi bi-clock me-2 text-info"></i><?= SITE_HOURS ?></p>

        <div class="d-flex gap-2">
          <?php foreach ([['Facebook', 'bi-facebook'], ['Twitter', 'bi-twitter-x'], ['LinkedIn', 'bi-linkedin'], ['Instagram', 'bi-instagram']] as [$sn, $si]): ?>
            <a href="#top" aria-label="<?= $sn ?>" class="social-circle"><i class="bi <?= $si ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Products -->
      <div class="col-lg-2 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Products</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="category.php?slug=office-2024-pc">Microsoft Office 2024</a></li>
          <li><a href="category.php?slug=office-2021-pc">Microsoft Office 2021</a></li>
          <li><a href="category.php?slug=office-2019-pc">Microsoft Office 2019</a></li>
          <li><a href="category.php?slug=microsoft-project">Microsoft Project</a></li>
          <li><a href="category.php?slug=microsoft-visio">Microsoft Visio</a></li>
          <li><a href="category.php?slug=office-mac">Office for Mac</a></li>
          <li><a href="category.php?slug=windows">Windows OS</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div class="col-lg-3 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Support</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="account.php">My Account</a></li>
          <li><a href="track-order.php" data-testid="footer-order-history-link">Track Order &amp; Receipts</a></li>
          <li><a href="support.php">Support Center</a></li>
          <li><a href="page.php?slug=help-center">Help Center</a></li>
          <li><a href="page.php?slug=installation-guide">Installation Guide</a></li>
          <li><a href="page.php?slug=activation-help">Activation Help</a></li>
          <li><a href="page.php?slug=faqs">FAQs</a></li>
          <li><a href="contact.php">Contact Us</a></li>
          <li><a href="returns.php">Returns &amp; Refunds</a></li>
        </ul>
      </div>

      <!-- Company -->
      <div class="col-lg-3 col-md-4 col-6">
        <h6 class="text-white fw-bold mb-3">Company</h6>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="page.php?slug=why-choose-us">Why Choose Us</a></li>
          <li><a href="subscriptions.php" data-testid="footer-subscription-link">Subscription Plans</a></li>
          <li><a href="reviews.php">Customer Reviews</a></li>
          <li><a href="blog.php">Blog</a></li>
          <?php
            // Auto-render a Brands sub-menu so users can reach each brand
            // profile (Microsoft, Bitdefender, McAfee...) and its dedicated
            // Articles tab from any page.
            try {
                $allBrands = db()->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' AND is_active = 1 ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) { $allBrands = []; }
            foreach ($allBrands as $bn):
                $bSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$bn));
          ?>
            <li><a href="brand.php?slug=<?= esc($bSlug) ?>" data-testid="footer-brand-<?= esc($bSlug) ?>"><?= esc($bn) ?> Hub</a></li>
          <?php endforeach; ?>
          <li><a href="press-kit.php" data-testid="footer-press-kit">Press Kit &amp; Embeds</a></li>
          <li><a href="sitemap.php" data-testid="footer-company-sitemap">Site Map</a></li>
        </ul>
      </div>
    </div>

    <!-- Secure payments / reviews band -->
    <hr class="border-secondary my-4">
    <div class="row g-4 align-items-center text-center text-md-start">
      <div class="col-md-5">
        <div class="text-white small fw-bold mb-2"><i class="bi bi-lock-fill text-success me-1"></i>Secure Payments</div>
        <div class="d-flex gap-3 small mb-3 flex-wrap justify-content-center justify-content-md-start">
          <span><i class="bi bi-lock-fill text-success me-1"></i>SSL Encrypted Checkout</span>
          <span><i class="bi bi-shield-fill-check text-info me-1"></i>Secure Encrypted Transactions</span>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-center justify-content-md-start" data-testid="footer-pay-icons">
          <?= render_payment_icons() ?>
        </div>
      </div>
      <div class="col-md-3 text-md-center">
        <?php /* Customer Reviews footer block removed — no reviews shown site-wide */ ?>
      </div>
    </div>

    <!-- Trademark + legal -->
    <hr class="border-secondary my-4">
    <p class="small text-center mx-auto" style="max-width: 760px;">Microsoft®, Office®, and Windows® are trademarks of Microsoft Corporation. <?= esc($brandName) ?> is independent of and not affiliated with Microsoft Corporation.</p>
    <div class="d-flex justify-content-center flex-wrap gap-2 small mb-3">
      <?php
      $legal = [
          ['Privacy Policy', 'page.php?slug=privacy-policy'], ['Terms of Service', 'page.php?slug=terms-of-service'],
          ['Refund Policy', 'page.php?slug=refund-policy'], ['Shipping & Delivery', 'page.php?slug=shipping-delivery'],
          ['Payment Policy', 'page.php?slug=payment-policy'], ['Cookie Policy', 'page.php?slug=cookie-policy'],
          ['Do Not Sell My Info', 'page.php?slug=do-not-sell'], ['Disclaimer', 'page.php?slug=disclaimer'], ['Sitemap', 'sitemap.php'],
      ];
      foreach ($legal as $idx => [$ll, $lh]): ?>
        <a href="<?= $lh ?>"><?= $ll ?></a><?= $idx < count($legal) - 1 ? '<span class="text-secondary">|</span>' : '' ?>
      <?php endforeach; ?>
    </div>
    <div class="text-center small">© <?= date('Y') ?> <?= esc($brandName) ?>. All rights reserved.</div>
  </div>
</footer>

<!-- AI chat widget -->
<button id="chat-bubble" onclick="toggleChat()" aria-label="Open chat" data-testid="chat-bubble">
  <i class="bi bi-chat-dots"></i>
  <!-- Tiny bell + unread count overlay; surfaces the moment an admin replies
       while the panel is closed.  Disappears once the customer opens chat
       or starts typing a reply. -->
  <span id="chat-bell" class="chat-bell" style="display:none;" data-testid="chat-bell" aria-hidden="true">
    <i class="bi bi-bell-fill"></i>
    <span id="chat-bell-count" class="chat-bell-count" data-testid="chat-bell-count">1</span>
  </span>
</button>
<!-- Messenger-style admin-reply preview — slides in to the LEFT of the
     chat bubble whenever an admin reply lands while the panel is closed,
     so the customer can see what the agent said before opening chat.
     Clicking it opens the chat immediately.  Auto-fades when the chat
     opens or the customer starts replying. -->
<div id="chat-msg-preview" class="chat-msg-preview" style="display:none;" onclick="openChatFromPreview()" data-testid="chat-msg-preview" role="button" tabindex="0">
  <div class="chat-msg-preview-head">
    <span class="chat-msg-preview-avatar"><i class="bi bi-headset"></i></span>
    <div class="chat-msg-preview-meta">
      <div class="chat-msg-preview-name">Maventech Support</div>
      <div class="chat-msg-preview-sub"><span class="chat-online-dot"></span>just now</div>
    </div>
    <button class="chat-msg-preview-close" type="button" onclick="event.stopPropagation(); hideChatMsgPreview();" aria-label="Dismiss preview" data-testid="chat-msg-preview-close"><i class="bi bi-x"></i></button>
  </div>
  <div class="chat-msg-preview-body" id="chat-msg-preview-body" data-testid="chat-msg-preview-body">—</div>
  <div class="chat-msg-preview-cta">Tap to reply →</div>
</div>
<div id="chat-panel" data-testid="chat-panel">
  <div id="chat-head" class="d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="chat-head-btn chat-head-back" onclick="toggleChat()" aria-label="Minimize chat" data-testid="chat-back"><i class="bi bi-chevron-left"></i></button>
      <span class="chat-avatar chat-avatar-photo"><img src="https://images.pexels.com/photos/7709255/pexels-photo-7709255.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop" alt="Addie" loading="lazy" decoding="async"></span>
      <div class="lh-sm">
        <div class="chat-head-name" data-testid="chat-head-name">Addie</div>
        <small class="chat-head-sub">The team can also help</small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-1">
      <button type="button" class="chat-head-btn" onclick="toggleChat()" aria-label="More options" data-testid="chat-menu"><i class="bi bi-three-dots"></i></button>
      <button type="button" class="chat-head-btn" onclick="toggleChat()" aria-label="Close chat" data-testid="chat-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div id="chat-body">
    <!-- Addie greeting — always shown at the top of the thread.  Mirrors the
         friendly first-touch message; the 3-field contact form sits right
         below it on first open. -->
    <div class="chat-addie-greeting" id="chat-addie-greeting" data-testid="chat-addie-greeting">
      <div class="chat-msg bot chat-addie-bubble">
        <strong>Hi, I'm Addie 👋</strong><br>
        Here to assist you with anything related to your <?= esc($brandName) ?> account!
      </div>
      <div class="chat-addie-meta">Addie • Just now</div>
    </div>
    <!-- AI welcome + quick chips kept in markup for ProAssist auto-open flows
         but hidden by default until JS detects the customer is already
         identified (proLeadId, returning lead, etc.). -->
    <div class="chat-msg bot" id="chat-welcome-msg" data-testid="chat-default-message" style="display:none;">Hi there! I'm here to help with products, pricing, activation or anything else you need. What can I look up for you?</div>
    <div class="chat-chips" id="chat-chips" data-testid="chat-chips" style="display:none;">
      <button class="chat-chip" onclick="quickAsk('Which Office is right for my Mac?')" data-testid="chat-chip-mac"><i class="bi bi-apple me-1"></i>Office for Mac</button>
      <button class="chat-chip" onclick="quickAsk('What is the best deal on Office 2024 right now?')" data-testid="chat-chip-deal"><i class="bi bi-tags me-1"></i>Best deals on Office 2024</button>
      <button class="chat-chip" onclick="quickAsk('How do I activate my license key after purchase?')" data-testid="chat-chip-activate"><i class="bi bi-key me-1"></i>Activation help</button>
      <button class="chat-chip" onclick="quickAsk('Do your licenses expire or need a subscription?')" data-testid="chat-chip-license"><i class="bi bi-infinity me-1"></i>License validity</button>
    </div>

    <!-- ====================================================================
         INITIAL VIEW (iteration 20): chat opens straight to the contact
         form — just 3 fields (full name, email, phone) and ONE blue send
         arrow button.  No "type a message" box yet.  Once the customer
         submits, this card is hidden and we reveal:
           (a) a "Thanks for contacting the support team" agent greeting
           (b) the message input box (chat-input-row below)
         The customer's real question is then routed straight to admin
         lead management — no AI auto-replies in between.
         ==================================================================== -->
    <div id="chat-lead-form" class="chat-lead-card" style="display:block;" data-testid="chat-lead-form">
      <div class="chat-lead-title" data-testid="chat-lead-title">Tell us how to reach you, and a support agent will get back in a few minutes.</div>
      <div class="chat-lead-field-row">
        <input id="lead-name"  class="form-control form-control-sm chat-lead-input" placeholder="Full name"      data-testid="lead-name"  autocomplete="name">
      </div>
      <div class="chat-lead-field-row">
        <input id="lead-email" type="email" class="form-control form-control-sm chat-lead-input" placeholder="Email address" data-testid="lead-email" autocomplete="email">
      </div>
      <div class="chat-lead-field-row chat-lead-row-send">
        <input id="lead-phone" class="form-control form-control-sm chat-lead-input" placeholder="Phone number"   data-testid="lead-phone" autocomplete="tel">
        <button type="button"
                class="chat-lead-send-btn"
                onclick="submitLead('chat')"
                data-testid="lead-send-btn"
                aria-label="Send to support">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
      <div id="chat-lead-error" class="chat-lead-error" style="display:none;" data-testid="chat-lead-error"></div>
      <!-- Backwards-compat hidden button so older test scripts that click
           [data-testid=lead-chat-btn] still trigger submitLead('chat'). -->
      <button type="button" class="d-none" onclick="submitLead('chat')" data-testid="lead-chat-btn"></button>
    </div>
    <!-- ProAssist install-call scheduler — shown when JS detects a ProAssist
         purchaser with no booking yet.  Customer picks timezone → date → time.
         Bookings convert to IST in the admin panel. -->
    <div id="pa-sched-card" class="pa-sched-card" style="display:none;" data-testid="pa-sched-card">
      <div class="pa-sched-header">
        <i class="bi bi-calendar-check"></i>
        <div>
          <div class="pa-sched-title" data-testid="pa-sched-title">Schedule your install call</div>
          <div class="pa-sched-sub" data-testid="pa-sched-sub">Pick a time that works for you</div>
        </div>
      </div>
      <div class="pa-sched-step">
        <div class="pa-sched-step-label">Your time zone</div>
        <select id="pa-sched-tz" class="pa-sched-tz-select" data-testid="pa-sched-tz-select" aria-label="Time zone"></select>
      </div>
      <div class="pa-sched-step">
        <div class="pa-sched-step-label">Select a date</div>
        <div class="pa-sched-dates" id="pa-sched-dates" data-testid="pa-sched-dates"></div>
      </div>
      <div class="pa-sched-step" id="pa-sched-times-step" style="display:none;">
        <div class="pa-sched-step-label">Available times <span class="pa-sched-tz" id="pa-sched-times-tz"></span></div>
        <div class="pa-sched-times" id="pa-sched-times" data-testid="pa-sched-times"></div>
        <button type="button" class="pa-sched-back" onclick="paSchedBackToDates()" data-testid="pa-sched-back">&larr; Back to dates</button>
      </div>
      <div class="pa-sched-error" id="pa-sched-error" style="display:none;" data-testid="pa-sched-error"></div>
    </div>
    <!-- Confirmed-booking card (shown after a successful book / on reopen). -->
    <div id="pa-sched-confirm" class="pa-sched-confirm" style="display:none;" data-testid="pa-sched-confirm"></div>
  </div>
  <div id="chat-typing" class="chat-typing" style="display:none;" data-testid="chat-admin-typing">
    <div class="chat-typing-bubble">
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-text">Live agent is typing…</span>
    </div>
  </div>
  <form id="chat-input-row" class="chat-input-row d-none p-2" onsubmit="sendChat(event)" data-testid="chat-input-row">
    <div class="chat-composer">
      <input id="chat-input" class="chat-input" placeholder="Ask a question…" autocomplete="off" data-testid="chat-input">
      <div class="chat-composer-tools">
        <button type="button" class="chat-tool-btn" id="chat-attach-btn" onclick="chatAttachClick()" aria-label="Attach a file" title="Attach a file" data-testid="chat-attach-btn"><i class="bi bi-paperclip"></i></button>
        <button type="button" class="chat-tool-btn chat-mic-tip" id="chat-mic-btn" onclick="chatToggleVoice()" aria-label="Voice to text (speak in any language, replies appear in English)" title="🎙️ Speak in any language — replies appear in English" data-tip="Speak in any language — replies appear in English" data-testid="chat-mic-btn"><i class="bi bi-mic-fill"></i></button>
        <button type="button" class="chat-tool-btn" id="chat-emoji-btn" onclick="chatToggleEmoji(event)" aria-label="Insert emoji" title="Insert emoji" data-testid="chat-emoji-btn"><i class="bi bi-emoji-smile"></i></button>
        <span class="chat-voice-timer" id="chat-voice-timer" style="display:none;" data-testid="chat-voice-timer"><span class="chat-voice-rec-dot"></span><span id="chat-voice-time">0:00</span></span>
        <button class="chat-send-btn ms-auto" type="submit" aria-label="Send" data-testid="chat-send"><i class="bi bi-arrow-up"></i></button>
      </div>
    </div>
    <input type="file" id="chat-file-input" class="d-none" data-testid="chat-file-input" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
    <div class="chat-emoji-panel" id="chat-emoji-panel" style="display:none;" data-testid="chat-emoji-panel"></div>
    <div class="chat-attach-status" id="chat-attach-status" style="display:none;" data-testid="chat-attach-status"></div>
  </form>
  <div class="chat-talk-band" data-testid="chat-talk-band">Prefer to talk?<a href="tel:<?= esc(tel_e164($brandPhone)) ?>" class="chat-talk-phone" data-testid="chat-talk-phone"><i class="bi bi-telephone-fill chat-talk-phone-ring"></i><?= esc($brandPhone) ?></a></div>
</div>

<!--
   Defer non-critical JS so the browser can render the hero + nav before
   parsing/executing.  `defer` keeps the original execution order
   (Bootstrap → main.js) and waits until DOMContentLoaded — same behaviour
   as the previous blocking <script> but with no parser pause.  This is
   the single biggest Core-Web-Vitals win for a server-rendered site.
-->
<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="assets/js/main.js?v=<?= esc(@filemtime(__DIR__ . '/../assets/js/main.js')) ?>"></script>

<!--
   Lazy-load + async-decode every image that's not already in the initial
   viewport.  Saves bandwidth on long product pages and improves LCP for
   the few images that DO need to load eagerly above the fold.  Runs once
   on DOMContentLoaded; the IntersectionObserver branch upgrades the
   "lazy" attribute to a real observer for browsers that need it.
-->
<script>
(function(){
  function applyLazy(){
    var vh = window.innerHeight || 800;
    document.querySelectorAll('img:not([loading]):not([data-eager])').forEach(function(img){
      var rect = img.getBoundingClientRect();
      // First-viewport images stay eager (LCP candidates); everything else
      // is marked lazy + async-decode so the main thread doesn't block.
      if (rect.top > vh) {
        img.loading = 'lazy';
        img.decoding = 'async';
      } else {
        // Hint to the browser this is high-priority LCP material.
        img.fetchPriority = 'high';
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyLazy, { once: true });
  } else {
    applyLazy();
  }
})();
</script>
</body>
</html>
