<?php
// Public customer review submission page (token-based, no login required).
require_once __DIR__ . '/includes/functions.php';
$pdo = db();

// Defensive: some older emails were sent with `{{review_url}}?rating=N` instead
// of `&rating=N` (single ampersand).  That turns the `?rating=N` into part of
// the `t` parameter value (e.g. ?t=abc123?rating=3), making the token look
// invalid and dropping the customer on an error page.  Split it back out so
// previously-sent emails continue to work.
$token     = $_GET['t'] ?? '';
$preRating = (int)($_GET['rating'] ?? 0);
if (is_string($token) && strpos($token, '?rating=') !== false) {
    [$realToken, $tail] = explode('?rating=', $token, 2);
    $token = $realToken;
    $tailRating = (int)$tail;
    if ($tailRating >= 1 && $tailRating <= 5) $preRating = $tailRating;
}
// Also tolerate '&rating=' embedded in the token if a mail client mangled it.
if (is_string($token) && strpos($token, '&rating=') !== false) {
    [$realToken, $tail] = explode('&rating=', $token, 2);
    $token = $realToken;
    $tailRating = (int)$tail;
    if ($tailRating >= 1 && $tailRating <= 5) $preRating = $tailRating;
}

$review = null;
if ($token) {
    $r = $pdo->prepare('SELECT cr.*, p.name AS product_name, p.image AS product_image FROM customer_reviews cr LEFT JOIN products p ON p.slug=cr.product_slug WHERE cr.request_token=? LIMIT 1');
    $r->execute([$token]); $review = $r->fetch();
}

$saved = false;
$saveError = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && $review) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $ai      = (int)($_POST['ai_generated'] ?? 0);
    if ($rating < 1 || $rating > 5) {
        $saveError = 'Please select a star rating.';
    } elseif ($comment === '') {
        $saveError = 'Please write a short comment (or pick one of the AI suggestions).';
    } else {
        // Auto-hide low ratings — anything below 3 stars goes to the Hidden
        // tab in admin's Customer Reviews and is NEVER shown on the public
        // site.  Admin can still see + reply / publish manually if desired.
        $autoStatus = $rating >= 3 ? 'published' : 'hidden';
        $pdo->prepare('UPDATE customer_reviews SET rating=?, comment=?, ai_generated=?, status=?, submitted_at=NOW() WHERE request_token=?')
            ->execute([$rating, $comment, $ai, $autoStatus, $token]);
        $saved = true;
        // Notify the admin PWA bell — low-rating reviews mark themselves
        // as needing attention; happy reviews still buzz because they're
        // an opportunity for a public testimonial / share.
        try {
            $custName = trim((string)($review['customer_name'] ?? '')) ?: 'A customer';
            admin_notify(
                'review',
                $rating . '★ review from ' . $custName,
                mb_substr((string)$comment, 0, 140, 'UTF-8'),
                $rating <= 3
                    ? '/admin.php?tab=reviews&status=hidden'
                    : '/admin.php?tab=reviews&status=published'
            );
        } catch (Throwable $e) { /* best-effort */ }
    }
}

// Pre-rating from the email-link star click (?rating=N).  Already normalised
// above when the URL was malformed.  Bounds-check defensively here too.
if ($preRating < 1 || $preRating > 5) $preRating = 0;

$pageTitle = 'Share Your Feedback · ' . esc(SITE_BRAND);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); min-height:100vh; font-family:-apple-system,Segoe UI,Roboto,sans-serif; padding:30px 12px; }
.review-card { max-width:580px; margin:0 auto; background:#fff; border-radius:18px; padding:36px 30px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.stars { display:flex; gap:10px; justify-content:center; margin:20px 0 8px; user-select:none; }
.stars .star { cursor:pointer; font-size:46px; color:#e5e7eb; transition:color .12s, transform .12s; line-height:1; }
.stars .star.lit { color:#facc15; }
.stars .star:hover { transform:scale(1.12); }
.btn-ai { background:linear-gradient(135deg,#8b5cf6 0%,#6d28d9 100%); color:#fff; border:none; font-weight:600; }
.btn-ai:hover { background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%); color:#fff; }
.product-thumb { width:64px; height:64px; object-fit:contain; background:#f8fafc; border-radius:10px; padding:6px; }
.ai-pick { background:#faf5ff; border:1px solid #e9d5ff; border-radius:12px; padding:12px 14px; font-size:13.5px; color:#374151; line-height:1.55; cursor:pointer; transition:all .15s; position:relative; }
.ai-pick:hover { background:#f3e8ff; border-color:#c084fc; transform:translateY(-1px); }
.ai-pick.selected { background:#ede9fe; border-color:#7c3aed; color:#1f2937; box-shadow:0 4px 12px rgba(124,58,237,.15); }
.ai-pick.selected::after { content:"\F26B"; font-family:"bootstrap-icons"; position:absolute; top:8px; right:10px; color:#7c3aed; font-size:18px; }
.ai-pick-label { display:inline-block; font-size:10px; font-weight:700; letter-spacing:1.2px; color:#7c3aed; text-transform:uppercase; margin-bottom:4px; }
</style>
</head>
<body>
<div class="review-card">
  <?php if (!$review): ?>
    <div class="text-center">
      <i class="bi bi-exclamation-triangle text-warning" style="font-size:48px;"></i>
      <h4 class="mt-3">Invalid Link</h4>
      <p class="text-muted small">This review link has expired or is invalid. Contact <a href="mailto:<?= esc(SITE_EMAIL) ?>"><?= esc(SITE_EMAIL) ?></a> for help.</p>
    </div>
  <?php elseif ($saved || $review['submitted_at']): ?>
    <div class="text-center" data-testid="review-thanks">
      <i class="bi bi-check-circle-fill text-success" style="font-size:54px;"></i>
      <h3 class="mt-3">Thank you for your feedback!</h3>
      <p class="text-muted">Your review has been published and helps other customers find great software.</p>
      <a href="<?= esc(SITE_URL) ?>" class="btn btn-dark rounded-pill mt-3">Continue Shopping</a>
    </div>
  <?php else: ?>
    <div class="text-center mb-3">
      <div style="display:inline-flex;align-items:center;gap:10px;font-size:20px;font-weight:800;color:#0f172a;">
        <span style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;">M</span>
        <?= esc(SITE_BRAND) ?>
      </div>
    </div>
    <h3 class="text-center fw-bold">How was your purchase?</h3>
    <p class="text-center text-muted small">Hi <?= esc($review['customer_name']) ?>, we'd love your honest feedback on:</p>

    <?php if ($review['product_name']): ?>
      <div class="d-flex align-items-center gap-3 p-3 rounded mb-3" style="background:#f8fafc;">
        <?php if ($review['product_image']): ?><img src="<?= esc($review['product_image']) ?>" class="product-thumb"><?php endif; ?>
        <div>
          <div class="fw-bold"><?= esc($review['product_name']) ?></div>
          <small class="text-muted">Order #<?= esc($review['order_id'] ? 'MVT-'.$review['order_id'] : '—') ?></small>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($saveError): ?>
      <div class="alert alert-warning small py-2" data-testid="review-error"><?= esc($saveError) ?></div>
    <?php endif; ?>

    <form method="post" id="reviewForm">
      <input type="hidden" name="ai_generated" id="aiFlag" value="0">
      <input type="hidden" name="rating" id="ratingInput" value="<?= $preRating ?>">

      <!-- Star widget (pure JS — no broken row-reverse trick) -->
      <div class="stars" data-testid="star-rating" id="starWidget">
        <?php for ($i=1; $i<=5; $i++): ?>
          <span class="star <?= $i<=$preRating?'lit':'' ?>" data-val="<?= $i ?>" data-testid="star-<?= $i ?>" role="button" tabindex="0" aria-label="<?= $i ?> star<?= $i>1?'s':'' ?>"><i class="bi bi-star-fill"></i></span>
        <?php endfor; ?>
      </div>
      <div class="text-center small text-muted mb-3" id="ratingLabel">
        <?php
        $labels = [0=>'Tap a star to rate',1=>'Poor — 1 star',2=>'Fair — 2 stars',3=>'Good — 3 stars',4=>'Great — 4 stars',5=>'Excellent — 5 stars'];
        echo esc($labels[$preRating] ?? $labels[0]);
        ?>
      </div>

      <label class="form-label small fw-semibold mb-1">Your comment</label>
      <textarea class="form-control" name="comment" id="cmt" rows="4" placeholder="Tell other customers what you liked — or pick a suggestion below…" required data-testid="review-comment"></textarea>

      <!-- AI suggestions picker -->
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small fw-semibold text-muted">
          <i class="bi bi-stars text-warning"></i> Quick suggestions
        </div>
        <button type="button" class="btn btn-ai btn-sm rounded-pill" onclick="loadSuggestions()" data-testid="ai-suggest-btn">
          <i class="bi bi-magic me-1"></i> Generate suggestions
        </button>
      </div>
      <div id="suggestionsBox" class="mt-2 d-grid gap-2" data-testid="suggestions-box" style="display:none;"></div>
      <div id="suggestionsStatus" class="small text-muted mt-1" style="display:none;"></div>

      <button class="btn btn-dark w-100 rounded-pill py-2 fw-bold mt-3" data-testid="submit-review">Submit Review</button>
      <p class="small text-muted text-center mt-3 mb-0">Your review will appear on our website to help other customers.</p>
    </form>

    <script>
    const LABELS = {0:'Tap a star to rate',1:'Poor — 1 star',2:'Below average — 2 stars',3:'Okay — 3 stars',4:'Good — 4 stars',5:'Excellent — 5 stars'};
    const PRODUCT_NAME = <?= json_encode($review['product_name'] ?? 'this product') ?>;
    const widget   = document.getElementById('starWidget');
    const stars    = widget.querySelectorAll('.star');
    const ratingIn = document.getElementById('ratingInput');
    const ratingLb = document.getElementById('ratingLabel');
    const cmt      = document.getElementById('cmt');
    const aiFlag   = document.getElementById('aiFlag');
    const suggBox  = document.getElementById('suggestionsBox');
    const suggStat = document.getElementById('suggestionsStatus');

    function paint(n){
      stars.forEach(s => {
        const v = parseInt(s.dataset.val);
        s.classList.toggle('lit', v <= n);
      });
      ratingLb.textContent = LABELS[n] || LABELS[0];
    }
    function setRating(n){
      ratingIn.value = n;
      paint(n);
      // If suggestions were already shown, refresh them to match the new rating
      if (suggBox.style.display !== 'none') loadSuggestions();
    }

    // Hover / click handlers on each star
    stars.forEach(s => {
      const val = parseInt(s.dataset.val);
      s.addEventListener('mouseenter', () => paint(val));
      s.addEventListener('click', () => setRating(val));
      s.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setRating(val); }
      });
    });
    // When pointer leaves the widget, restore the actually-selected rating
    widget.addEventListener('mouseleave', () => paint(parseInt(ratingIn.value) || 0));

    async function loadSuggestions(){
      const r = parseInt(ratingIn.value) || 0;
      if (r < 1) {
        suggStat.style.display = 'block';
        suggStat.className = 'small text-warning mt-1';
        suggStat.textContent = 'Pick a star rating first — suggestions match the rating you give.';
        return;
      }
      suggBox.style.display = 'grid';
      suggBox.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> Generating 3 suggestions for ' + r + '-star rating…</div>';
      suggStat.style.display = 'none';
      try {
        const url = 'review-ai.php?count=3&rating=' + r + '&product=' + encodeURIComponent(PRODUCT_NAME);
        const res = await fetch(url);
        const data = await res.json();
        const list = (data.suggestions || []).filter(Boolean);
        if (list.length === 0) {
          suggBox.innerHTML = '<div class="text-danger small">AI service unavailable — please type your comment manually.</div>';
          return;
        }
        suggBox.innerHTML = '';
        list.forEach((txt, idx) => {
          const card = document.createElement('div');
          card.className = 'ai-pick';
          card.setAttribute('data-testid', 'ai-suggestion-' + (idx+1));
          card.innerHTML = '<span class="ai-pick-label">Option ' + (idx+1) + '</span><div>' + escapeHtml(txt) + '</div>';
          card.addEventListener('click', () => {
            cmt.value = txt;
            aiFlag.value = '1';
            suggBox.querySelectorAll('.ai-pick').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
          });
          suggBox.appendChild(card);
        });
        suggStat.style.display = 'block';
        suggStat.className = 'small text-muted mt-2';
        suggStat.innerHTML = '<i class="bi bi-info-circle me-1"></i>Click any card to use it — you can still edit the text after.';
      } catch (e) {
        suggBox.innerHTML = '<div class="text-danger small">Network error: ' + e.message + '</div>';
      }
    }
    function escapeHtml(s){ return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    // If user types their own comment, clear the AI flag so we record it as human-written
    cmt.addEventListener('input', () => {
      if (aiFlag.value === '1') {
        aiFlag.value = '0';
        suggBox.querySelectorAll('.ai-pick.selected').forEach(c => c.classList.remove('selected'));
      }
    });

    // Block form submission if rating not chosen
    document.getElementById('reviewForm').addEventListener('submit', (e) => {
      const r = parseInt(ratingIn.value) || 0;
      if (r < 1) {
        e.preventDefault();
        ratingLb.textContent = 'Please pick a star rating first ⭐';
        ratingLb.className = 'text-center small text-danger fw-semibold mb-3';
        widget.scrollIntoView({behavior:'smooth', block:'center'});
        return false;
      }
      if (cmt.value.trim() === '') {
        e.preventDefault();
        cmt.focus();
        return false;
      }
    });
    </script>
  <?php endif; ?>
</div>
</body>
</html>
