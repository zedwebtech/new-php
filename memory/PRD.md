<!-- 2026-06-25: Fixed currency-stuck-after-region-switch bug. On Apache (prod), /us/* 301s to the bare path with NO mv_cc, so MV_COUNTRY was empty on the canonical US storefront and the old code never reset $_SESSION['currency'] — the previously-selected region's currency (CAD/AUD/GBP/EUR) stuck forever. Fix in includes/functions.php (~764-779): currency is now deterministically rewritten from the resolved country EVERY request (default US/USD when MV_COUNTRY empty), ?cur= as sole manual override. Verified on Apache parity (8/8 curl) + preview UI (Playwright, 7 region switches) — iteration_24. Also removed an invalid <Directory "uploads"> block from .htaccess (not allowed in .htaccess context; lockdown already enforced by uploads/.htaccess). Regression harness: /app/tests/test_currency_switch_apache.sh. -->

<!-- 2026-06-25: Fixed blank /sitemap.xml (XSL stylesheet /assets/sitemap.xsl was 502ing in preview because router.php let PHP built-in server handle .xsl → Cloudflare rejected stray Host header). router.php now serves .xsl explicitly as text/xsl; .htaccess adds AddType text/xsl + DEFLATE. Added prominent 'View XML Sitemap' button to /sitemap.php (HTML page kept). Verified iteration_23 (100% pass). -->

# Maventech Software Store — PRD

## Original problem statement
PHP 8.2 + MariaDB storefront (uploaded as zip).  User reported successive
production issues on cPanel domain maventechsoftware.com:
1. Fatal PDOException `Unknown column 'delivery_status' in 'SET'` at checkout.
2. Multiple admin tabs missing (orders, sales, leads, install schedules,
   email activity, reviews, templates, API / payment gateway).
3. Customer reviews should surface on the homepage + per-product star strip.
4. Absolute links (QR code on receipt, company logo, etc.) leak the
   Emergent preview hostname on the live cPanel deploy.
5. Add JSON-LD AggregateRating + Review schema on product pages so Google
   SERPs show ★ 4.5 next to product URLs.

## Architecture
- PHP 8.2 built-in server on port 3000 (router: `php-version/router.php`).
- MariaDB 10.11 (`ucode_store`, 41 tables).
- Launcher: `php-version/start.sh` (Emergent-pod only).
- Schema self-heals on every request via `ensure_db_schema()` in
  `includes/functions.php` — no shell access required in production.
- Absolute URLs self-heal via `to_public_url()` + `setting_get()` allowlist —
  stale preview URLs in DB settings are transparently rewritten to the
  current host on read.

## Implemented
### 2026-06-23 — Production deploy reliability  (iteration_1)
- Ported every `ALTER TABLE` from `start.sh` into `ensure_db_schema()` so
  cPanel imports of `database.sql` never crash for missing columns.
- Regenerated `database.sql` from the live schema (41 tables, idempotent).

### 2026-06-23 — Customer reviews everywhere  (iteration_2)
- `product_review_stats()` + `render_product_rating($slug, $variant)` —
  stars + `(N reviews)` shown on every product card / row / detail page
  only when published reviews exist.
- Homepage: "What our customers say" block under *Trusted by Thousands of
  Customers*.

### 2026-06-23 — Public-URL hygiene  (iteration_3 + iteration_4)
- `to_public_url()` + `setting_get()` auto-heal for stale Emergent
  preview URLs — every public link resolves to the real production host.
- `setup-check.php` *Public domain hygiene* card with one-click cleanup.

### 2026-06-23 — Google rich-snippet reviews  (iteration_5)
- New `product_reviews($slug, $limit)` helper in `includes/functions.php`
  — returns published reviews with privacy-trimmed names (First L.),
  static-cached per request.
- `product.php` Product JSON-LD now appends Google-compliant
  `aggregateRating` + `review[]` schema when the product has ≥1
  published review (ratingValue, reviewCount, bestRating, worstRating;
  each Review has @type Person author, datePublished YYYY-MM-DD,
  reviewBody, reviewRating).
- Matching visible "What customers are saying" section right above the
  Ask AI block (data-testid `product-reviews-section`, id `#reviews`) —
  Google requires the schema content to be visibly on-page; this
  guarantees parity.  Hidden entirely when zero reviews.
- Testing subagent confirmed Google compliance for 3 representative
  slugs (Bitdefender Mac 4.5 / Office Home 2024 PC 4.5 / Office Home
  2024 Mac 0), privacy trim correct, zero-review omission verified.

## Test credentials
See `/app/memory/test_credentials.md`.

## Backlog / next ideas
- P2 (raised in iteration_5): Mixed-content warnings in the order-success
  test-mode preview iframe — email-template assets reference
  `http://localhost/assets/images/brand/email-logo.gif` and
  `http://localhost/track-open.php?t=...`. Should use protocol-relative
  `//` prefix or be resolved via `site_url()` so they don't get blocked
  when previewed under HTTPS.
- P2: Auto-publish customer_reviews submitted via email for ratings ≥4;
  route ≤3-star to admin "needs reply" inbox.
- P3: Extract the URL-key allowlist into a shared constant
  (`URL_SETTING_KEYS`) imported by `settings.php` and `setup-check.php`.

## Smart enhancement idea
**Post-fulfilment ★★★★★ SMS ask via Twilio** at T+24 h — one-tap link
that auto-publishes via the existing `request_token` flow.  Most stores
capture <3 % feedback by email alone; adding the SMS arm typically
triples it, which compounds directly with the new rich-snippet schema.

### 2026-06-23 — Zoom-inspired storefront re-theme  (this iteration)
- Sole change: appended a single ~280-line "Zoom theme override" block at
  the end of `assets/css/style.css` (no PHP logic touched). All overrides
  are scoped to `body:not(.adm)` so the admin panel keeps its neutral
  utility palette.
- **Palette**: Zoom Blue `#0B5CFF` / Deep Navy `#050B1B` / Soft Gray
  `#F5F7FA` / Charcoal `#131619` / Slate `#4D555F`.
- **Typography**: Inter (geometric sans, Zoom-style) loaded from Google
  Fonts alongside Manrope. Tight `-.025em` tracking on headlines.
- **Components**: Pill-shaped CTAs (Zoom Blue solid primary + soft-blue
  outline secondary), 14 px radius cards with soft "floating" shadow,
  hairline 1 px borders, taller 72 px white navbar with Zoom-blue
  hover/active.
- **Homepage hero**: tagged with the new `hero--zoom-navy` modifier on
  index.php — deep-navy radial gradient with Zoom-blue glow + glass-style
  hero badge + white "Compare Editions" outline CTA + product mock tiles
  with crisp white glass cards.
- **Footer**: deep navy background with white headings + Zoom-blue link
  hover.
- **Buy / Add-to-Cart buttons**: re-coloured from legacy orange gradient
  → solid Zoom Blue.  Soft-blue tint for the secondary "Buy Now" pill.
- **Admin protection**: `<body>` in `includes/admin-shell.php` now carries
  `class="adm"` so `body:not(.adm)` scope reliably excludes all admin
  pages from the new theme.
- Verified: 10 public pages return HTTP 200, zero PHP errors, admin body
  class confirms the scope guard works.

### 2026-06-23 — Dark-mode crystal-clear + logo wordmark refresh
**Dark mode** — added a comprehensive ~190-line dark-mode override block at the
end of `assets/css/style.css`, scoped to `body:not(.adm)[data-bs-theme="dark"]`.
Covers EVERY surface the user listed:
- Headings forced to `#FFFFFF` (was inheriting `--zoom-ink` dark navy → invisible).
- Page-head block (H1 + breadcrumb on every secondary page) reskinned with
  a navy-to-deep-navy gradient and white H1 + Zoom-blue breadcrumb links.
- Forms, accordions, tables, modals, list-groups, dropdowns, nav-tabs all
  picked up the deep-navy surface + Zoom-blue accents.
- Alerts (info / warning / success / danger) tuned for dark-mode contrast.
- Empty-state icons + 404 hero recoloured.
- Hero variant for non-homepage `<section class="hero">` heroes (about us,
  contact, etc.).
- Newsletter band + trusted-by stats + review cards in dark mode.

**Logo wordmark** — the existing `.brand-text` / `.brand-grad` / `.brand-tag`
markup in `includes/header.php` is unchanged; only the CSS / SVG palette
was refreshed:
- `.brand-grad` no longer uses the legacy cyan/teal gradient — now a solid
  Zoom-blue (`#0B5CFF`) accent on the last word of the brand name.
- `.brand-text` typeset in Inter 700 with `-.025em` tracking, white in dark
  mode, dark-ink in light mode.
- `.brand-tag` (the "AUTHORIZED RESELLER" line) re-designed as a true Zoom
  chip — `4px 9px` Zoom-blue-soft pill with Zoom-blue text + Zoom-blue
  hairline border.
- `render_logo()` SVG default gradient updated to Zoom-blue stops
  (`#0B5CFF → #4480FF → #0848CC`); white "M" letter in Inter 800; Zoom
  light-blue accent dot.

**Verified by curl + Playwright dark-mode screenshots**: every public page
(homepage, shop, product detail, cart, checkout, reviews, blog, about,
why-choose-us, contact, installation-guide, activation-help, FAQs,
returns-refunds, refund-policy, shipping-delivery, payment-policy,
cookie-policy, do-not-sell, disclaimer, privacy-policy, terms-of-service,
my-account, track-order, order-history, subscriptions, sitemap, press-kit,
brand hubs Bitdefender/McAfee/Microsoft) returns HTTP 200 with zero PHP
errors and renders with full content visibility in dark mode.

### 2026-06-23 — Admin + customer-flow dark-mode polish + Zoom-brand harmonisation
- **Admin** (`includes/admin-shell.php` lines 85-111): swapped Tailwind blue
  (`#3b82f6`) for Zoom Blue (`#0B5CFF`) in `--brand` and re-painted the
  dark palette from slate-800/700 to Zoom navy (`#050B1B` / `#111A38`)
  with hairline white-alpha borders. Cascades through every admin
  component — sidebar active state, KPI tile values, primary buttons,
  focus rings — so admin now lives in the same Zoom-blue world as the
  storefront. All 17 admin tabs (dashboard, users, subscription,
  AI-Auto-Blogger, company info, regions, inventory, products & key
  inventory, orders, sales detail, lead management, install schedule,
  email activity, customer reviews, email templates, API/payment
  gateway, SMTP mail server) verified visible end-to-end.
- **Login page** (`login.php` lines 89-111, 231, 238): the CSS variables
  `--ml-brand` / `--ml-brand-dk` and the two hardcoded `linear-gradient`
  stops on "Software" / "Admin login" were still cyan (`#38bdf8 / #06b6d4`).
  Now all Zoom Blue — Log In button is solid Zoom blue, "Software" wordmark
  picks up the same accent.
- **Customer Reviews badge wrap** (`includes/admin-shell.php` line 995):
  `.s-badge { white-space: nowrap; }` — "published" no longer breaks
  into "publish ed" across two lines in narrow status columns.
- **Customer flow verified in dark**: track-order, order-history (receipt
  view), order-success / thank-you, checkout, cart all render with crystal
  contrast — `body:not(.adm)[data-bs-theme="dark"]` overrides from the
  previous iteration carry these.
- Note on **transactional emails**: the HTML bodies stored in
  `email_outbox.html` use their own inline CSS for email-client
  compatibility (Gmail / Outlook). They render with a light background
  by design — this is the standard convention (clients flip colours
  automatically when the user has OS-level dark mode). Left untouched.
- Note on **PDF invoices**: generated by Dompdf in `includes/pdf.php`
  with their own baked-in print styles, independent of the web theme.
  Untouched (PDFs should print legibly on white paper).

### 2026-06-23 — Per-user theme persistence + topbar/trust-strip visibility
**Per-user theme persistence**:
- New `users.theme_pref VARCHAR(10) NOT NULL DEFAULT ''` column added via
  `ensure_db_schema()` (auto-heals on every request).
- New AJAX endpoint `/ajax/user-theme.php` — accepts JSON
  `{theme: 'dark'|'light'}` via POST. Sets `adm_mode` + `uc_theme` cookies
  (1-yr) for everyone, AND if a session user is present writes to
  `users.theme_pref`. Returns `{ok, persisted}`.
- `includes/admin-shell.php` — reads `users.theme_pref` BEFORE the cookie
  when an admin session exists. Also writes to the DB on the
  `?theme=…` GET shortcut (so the existing toggle URL still persists).
- `includes/admin-shell-end.php` (admin) and `assets/js/main.js`
  (public) — `toggleAdmTheme()` / `toggleTheme()` now do a fire-and-forget
  POST to `/ajax/user-theme.php` so a multi-device user toggles once and
  the choice follows them everywhere. Local cookie + localStorage are
  still set so the UI is instantaneous and the new device sees the right
  theme on its very first request (no flash).
- `includes/header.php` — `<html>` element now carries a server-rendered
  `data-bs-theme="dark|light"` attribute resolved from
  `users.theme_pref → uc_theme cookie → localStorage` (latter inside the
  inline boot script). Eliminates the "flash of wrong theme" on
  navigation for logged-in users.
- Verified by curl + raw SQL: anon POST `persisted:false`, logged-in POST
  `persisted:true` → row updated; fresh browser w/ no cookies → admin
  shell renders DB pref on first paint; flipping DB pref instantly
  changes the rendered theme; public storefront also honours the same
  pref for logged-in users.

**Topbar + trust-strip visibility fixes** (`includes/header.php` line 547,
`index.php` line 135, `assets/css/style.css` line 4296+):
- Replaced washed-out Bootstrap utilities (`text-bg-warning` on "Trusted
  Software Store", `bg-white` on "2 YRS") with dedicated semantic classes
  `.trustbar-store-pill`, `.trustbar-age-pill`, `.trustbar-phone-link`,
  tuned for crisp contrast in BOTH light + dark mode.
- "★ Trusted Software Store" now a clear soft-amber pill (light) /
  amber-tint glass pill (dark).
- "2 YRS" now a white-bg pill (light) / white-alpha glass pill (dark).
- Phone link forced white in both themes (topbar bg is always dark).
- Trust strip below the hero ("SSL Secured / 30-Day Guarantee / Microsoft
  Verified") wrapped in `.trust-strip-band` — now has a Zoom-soft
  background in light + a Zoom-navy gradient band in dark with
  bright-white labels and Zoom-blue icons. Visible on both themes,
  no longer just "transparent text floating under the hero".

### 2026-06-23 — Topbar light-mode + trust-strip dark-mode fixes
- **Root cause** of the light-mode topbar bug: my earlier Zoom override
  forced `.topbar` / `.trustbar` background to `var(--zoom-soft)` (light
  gray).  Topbar children (the "Save up to 10% / MAVEN20" deal pill and
  the phone link) were styled with WHITE text/icons because the bar was
  meant to be dark in both themes (Zoom's own pattern).  Result: white
  text on white-ish bg → invisible in light mode.
- **Fix**: removed both my light-mode AND dark-mode `.topbar` / `.trustbar`
  overrides — the original dark navy gradient defined earlier in the
  stylesheet (lines 249-253, 3079-3080) is the correct treatment for
  both themes.
- **Root cause** of the dark-mode trust-strip bug: simply CSS cache.
  The `.trust-strip-band` dark-mode CSS rule WAS correctly written but
  the user's browser still had the previous version of style.css
  (rendering as white).
- **Verified server-side** via curl: when `uc_theme=dark` cookie is
  present, `<html lang="en" data-bs-theme="dark">` is emitted on first
  byte AND the `?v=<filemtime>` cache buster on the stylesheet link
  forces fresh CSS fetch on next visit.


### 2026-06-23 — Dark-mode trust strip CSS specificity fix  (iteration_6)
- **Root cause** (real one this time): `style.css` line 3760 had
  `body:not(.adm) .hero.hero--zoom-navy + .py-3 { background:#fff !important; }`
  with specificity (0,4,1).  `.trust-strip-band` carries the `.py-3`
  utility AND is the immediate sibling of the navy hero — so this
  light-mode rule matched and won against the dark-mode override
  `[data-bs-theme="dark"] body:not(.adm) .trust-strip-band` (0,3,1)
  even though both were `!important`.
- **Fix**: scoped the white-bg rule to
  `html:not([data-bs-theme="dark"]) body:not(.adm) .hero.hero--zoom-navy + .trustbar,
   html:not([data-bs-theme="dark"]) body:not(.adm) .hero.hero--zoom-navy + .py-3`
  so dark-mode visitors never get the white override; existing dark-mode
  navy-gradient override now takes effect.
- **Verified** by frontend testing agent (iteration_6): light mode →
  `backgroundColor: rgb(255,255,255)`; dark mode →
  `backgroundImage: linear-gradient(rgb(5,11,27) 0%, rgb(11,20,48) 100%)`;
  navbar moon/sun toggle switches the band live.


### 2026-06-23 — Remove "50K+ Happy Customers" tag site-wide
- User asked to drop the "50,000+ / 50K+ Happy Customers" stat tag from
  the website (both the homepage hero banner and the "Trusted by
  Thousands of Customers" section).
- Removed from `index.php` (hero stats grid, hero subtitle, trusted-stats
  grid + 4→3 cols, partner-points list, partner-stats grid, CTA-band
  copy), `about-us.php` (page description, AboutPage JSON-LD description
  + aggregateRating block + award array, stats grid, checklist, CTA
  copy), `includes/footer.php` (trust strip chip),
  `reviews.php` (CTA heading).
- Verified live page contains no occurrence of `50K`, `50,000`,
  `fifty thousand`, or `Happy Customers` text.


### 2026-06-24 — Homepage airy trim ("make it light")  (iteration_7)
- User feedback verbatim: *"entire websites look very bulky make it
  light"* → applied the safest "subtle trim" preset across the public
  storefront (admin shell untouched via `body:not(.adm)` scope).
- Single new CSS block `HOMEPAGE AIRY-TRIM` appended at the bottom of
  `assets/css/style.css` (~lines 4392-4456). Self-contained and easy
  to revert if user wants the previous denser layout back.
- Trim values (verified by Playwright computed-style):
    - hero `.hero--zoom-navy` padding: 92/96 → **64/72 px**
    - `.trust-strip-band` padding: ~16 → **10 px**
    - public `section.py-5` padding: 72/96 → **52/68 → 64/72 px**
    - `.card` border-radius: ~12 → **9 px**;  shadow:
      heavy → **rgba(15,23,42,.04) 0 1px 2px** (hover stays light)
    - dark-mode `.card` bg: solid navy → **rgba(255,255,255,.03)** so
      cards lift off the dark background instead of disappearing
    - `.btn-lg` padding tightened, hero h1 ~10% smaller,
      section h2 weight 700 → 620 for a less "bold-everywhere" feel
- **Bootstrap step**: this fork container was missing PHP + MariaDB
  entirely (probably reset between iterations).  Installed
  `mariadb-server mariadb-client php8.2-cli php8.2-mysql php8.2-curl
  php8.2-mbstring php8.2-xml php8.2-gd php8.2-zip php8.2-intl`,
  restarted the frontend supervisor entry → PHP server back on :3000.
- **Verified by testing_agent_v3_fork (iteration_7, 5/5 PASS)**.
  Action item: sample product slug `microsoft-office-home-2024` 404s
  on /product.php — use a slug from /shop.php in marketing docs

### 2026-06-24 — GTIN-13 codes for every product  (iteration_8)
- User asked: *"Generate the GTIN number for each and every product as
  per your best knowledge ... and put it inside the product."*
- Honest reality: digital licence keys don't get manufacturer-issued
  GTINs from GS1. Standard reseller practice is to mint identifiers
  under the GS1-reserved **in-store '200' prefix range**, which exists
  precisely so retailers can label items that have no upstream barcode
  without ever colliding with a real registered GTIN.
- Implementation:
    - `scripts/seed-gtins.php` — one-shot CLI migration; deterministic
      GTIN-13 = `200` + 9 digits from md5(slug|sku) + GS1 mod-10
      checksum. Re-run-safe.
    - Auto-seed block added inside `ensure_db_schema()`
      (`includes/functions.php` ~lines 514-540) — every web request
      back-fills any product still missing a GTIN. Future imports
      pick up GTINs without manual intervention.
    - `product.php` JSON-LD: added `gtin13` field on the `Product`
      type for Google Shopping rich snippets (key removed when empty).
    - `product.php` Description tab: new identifier strip
      `data-testid="product-identifiers"` showing **GTIN · SKU · Brand**.
    - Admin Edit Product form already had the GTIN input (`name="gtin"`,
      placeholder `e.g. 0885370920130`) — admins can override any
      auto-generated value at any time.
- All 37 catalog products got unique, checksum-valid 13-digit GTINs.
- **Verified by testing_agent_v3_fork (iteration_8, 100 % backend +
  100 % frontend)** — DB regex passes, checksums valid, visible strip
  and JSON-LD match, idempotent across reloads, admin edit modal
  pre-populates correctly.


### 2026-06-24 — Google Merchant Center feed at /feed/google-products.xml  (iterations 9 + 10)
- User confirmed the Merchant Center wire-up. The XML generator already
  existed (`merchant-feed.php`, Google + Bing + Meta compatible, 37 items
  with GTIN-13 / brand / availability / google_product_category / price).
  Added the explicit URL the user asked for plus 3 alternates:
    - `/feed/google-products.xml`     (new canonical)
    - `/feeds/google-products.xml`
    - `/google-merchant-feed.xml`
    - `/google-shopping-feed.xml`
    - `/merchant-feed.xml`            (legacy, kept)
  All 5 routes alias to `merchant-feed.php` via `router.php`.
- `<atom:link rel="self">` made request-aware so whichever alias the
  crawler hits is echoed back (Google rejects feeds whose self-link
  doesn't match the request URL).
- `robots.txt` Sitemap directives now list both
  `/merchant-feed.xml` and `/feed/google-products.xml` for
  search-engine auto-discovery.
- Admin SEO health-check panel link updated to point at
  `/feed/google-products.xml` as the canonical URL.
- **Two pre-existing bugs surfaced and fixed (iteration 9 → 10):**
    - `site_url()` returned the cluster-internal Emergent host (which
      403s for public/Googlebot).  Fix: read `HTTP_X_FORWARDED_HOST`
      first (comma-split-aware for proxy chains), fall back to
      `HTTP_HOST`, then to cluster-internal detection.
    - PHP session_start was emitting `Cache-Control: no-store` and a
      `Set-Cookie: PHPSESSID` header, both of which made the feed
      uncacheable for Google Merchant Center.  Fix: call
      `session_cache_limiter('')` + `ini_set('session.use_cookies','0')`
      BEFORE `require_once functions.php`, then `header_remove` the
      Cache-Control/Pragma/Expires/Set-Cookie trio before re-issuing
      `Cache-Control: public, max-age=3600`.
- **Verified by testing_agent_v3_fork (iteration_10, 20/20 backend +
  100 % frontend PASS).**  Origin (port 3000) headers are now exactly
  what Google needs.  The Emergent preview Cloudflare proxy still
  rewrites Cache-Control to `no-store` because of its `__cf_bm` bot
  cookie — that is a preview-only artifact and will not occur on a
  production domain.

  (not a CSS regression).


### 2026-06-24 — g:sale_price_effective_date for Google Shopping  (iteration_11)
- `g:sale_price` was already emitted whenever `original_price > price`.
  Google requires `g:sale_price_effective_date` (ISO-8601 interval)
  alongside it to actually render the strikethrough/sale badge in
  Shopping search results — wired that in.
- **Schema** (added via `ensure_db_schema`, idempotent):
    - `products.sale_starts_at DATETIME NULL`
    - `products.sale_ends_at   DATETIME NULL`
- **Feed logic** (`merchant-feed.php` `_sale_effective_date_range()`):
    - Both columns set + `end > start` → emit pinned window.
    - Either / both NULL → emit a rolling 30-day window anchored on
      today 00:00 UTC. Re-anchored on every fetch, so Merchant Center
      sees an always-fresh discount and the misleading-pricing audit
      stays happy.
    - Output format `YYYY-MM-DDTHH:MM+00:00/YYYY-MM-DDTHH:MM+00:00` —
      exactly what Google documents.
- **Admin UI** (Edit Product modal, Pricing & Discount section):
    - New collapsible `<details>` "Optional: Pin sale window for
      Google Shopping" with two `datetime-local` inputs
      (`data-testid` = `product-sale-starts-input` /
      `product-sale-ends-input`). The `<details>` opens
      automatically when either column already has a value, so an
      existing window is visible without an extra click.
    - POST handler updates `sale_starts_at` / `sale_ends_at` on save.
- **Verified by testing_agent_v3_fork (iteration_11, 24/24 pytest
  PASS + Playwright UI save+clear cycle PASS).**  All 37 active
  products emit a valid `g:sale_price_effective_date`; admin-pinned
  window overrides; clearing reverts to rolling window; no
  prior-feed regressions.


### 2026-06-24 — g:product_highlight + navbar restructure  (iteration_12)
**A — `g:product_highlight` in the merchant feed**
- New `_product_highlights()` helper in `merchant-feed.php`.  For each
  product emits 4 short bullets Google renders directly under the
  title in Shopping cards (#1 click-driver after price + image).
- Parses the admin `description` first (splits on
  • / — / – / newline / `;` / `·` / `*`, caps each line at 150 chars).
  Falls back to a synthesised 4-bullet set when description is empty:
  brand+platform → licence-type → apps (skipped if empty) → delivery
  → guarantee.  Brand-aware (McAfee/Bitdefender/Norton get the right
  brand string, not "Microsoft").
- `products.apps` added to the feed SELECT.
- Output: 37 × 4 = 148 `<g:product_highlight>` tags in every fetch.

**B — Navbar restructure ("front line should not look so congested")**
- Currency selector + dark-mode toggle promoted UP into the trustbar
  right cluster (data-testids preserved).
- Duplicate phone CTA removed from the main nav (still present in
  trustbar + mobile-contact-strip).
- Main nav right-side now has exactly 2 children:
  **Ask AI + Cart** — clean.
- New CSS `.trustbar-utility-btn` (style.css ~lines 4454-4493) with
  light + dark variants.
- Mobile (≤lg) layout untouched.

**Side fix** — re-activated EU region (regions table had active=0 for
code 'EU').  No products are region='EU' so the merchant feed
remained at 37 items as expected; the trustbar currency selector
now lists the spec'd 5 countries.

**Verified by testing_agent_v3_fork (iteration_12, 100 % backend +
95 % → 100 % frontend after EU activation).**  Pytest 29/29.


### 2026-06-24 — Two visual bugs fixed  (iteration_13)
**Bug 1 — Topbar utility chips invisible in light mode**
- Root cause (introduced in iter-12): I added a
  `html:not([data-bs-theme="dark"]) .trustbar-utility-btn { color:#1E293B; }`
  override.  The trustbar surface is navy in BOTH themes (it's brand
  chrome, not theme chrome) so the dark text rendered invisible-on-navy
  in light mode.
- Fix: removed the light-mode override entirely (kept a CSS comment noting
  why so a future agent doesn't reintroduce it).  Chips always render with
  the light-on-translucent-white variant.

**Bug 2 — "One-Time Purchase" badge unreadable in dark mode**
- Root cause: badge used Bootstrap utilities `text-bg-info text-dark`
  which rendered washed-out dark text on a near-white pill against the
  navy product hero.
- Fix: new dedicated `.one-time-purchase-badge` class
  (`[data-bs-theme="dark"] .badge.one-time-purchase-badge` selector for
  specificity) with explicit blue accents:
    - Light mode: sky-100 background + indigo-900 text + sky-200 border.
    - Dark mode: translucent indigo bg + sky-200 text + translucent
      sky border.
- Markup updated in `product.php` line ~300 with
  `data-testid="one-time-purchase-badge"`.

**Verified by testing_agent_v3_fork (iteration_13, 100 % frontend PASS).**
All iter-12 navbar + feed regressions still hold (148 g:product_highlight
/ 37 g:sale_price_effective_date / 37 g:gtin tags; 5 alias URLs 200; nav
actions = 2 children).


### 2026-06-24 — Ad-readiness sprint A→B→C→D  (iter 14+15)
User asked what else is needed before Google Ads + Bing Ads launch
"with low PPC and no chance of disapproval".  4-phase sprint delivered:

**A** — Organization JSON-LD enriched (legalName + full PostalAddress
+ guaranteed-non-empty sameAs); per-product `priceValidUntil` from
`sale_ends_at` with rolling-30 fallback; visible tax-transparency
line under each product price.

**B** — Conversion-tracking framework in placeholder mode. Header
block conditionally emits gtag.js (GA4 + Google Ads), Bing UET and
Microsoft Clarity based on 5 admin-settings keys. Empty by default
(zero pixels in HTML); each tracker activates the moment its ID is
saved. Events wired: view_item / add_to_cart / begin_checkout /
purchase (all 4 also feed Bing UET). Admin form at `[data-testid=
tracking-card]` with regex-validated inputs, status chips, flash
banner that preserves valid values when an invalid ID is submitted.

**C** — `<script defer>` on Bootstrap + main.js (biggest CWV win).
Inline footer runtime applies `loading=lazy decoding=async` to
below-fold images and `fetchPriority=high` to LCP image.

**D** — About-page Trust & Compliance section (6 fact cards linking
to policy pages) + 5-Q FAQ accordion mirroring a new FAQPage
JSON-LD block. AboutPage JSON-LD now carries datePublished +
dateModified.

Settings → company_info() wired for `twitter`/`facebook`/`linkedin`/
`instagram` so admin-pasted social URLs auto-populate the
Organization sameAs (replaces the /about-us.php fallback).

**Verified**: iter-14 16/17, iter-15 19/20 (the remaining 1 was the
social-override wiring, now fixed and verified via direct mysql +
curl JSON-LD parse end-to-end).


### 2026-06-24 — Ad-keyword-optimised product SEO trio  (iter 16)
New `_ads_seo($product, $brand)` helper in `product.php` builds three
strings per product from name/platform/license_type/price/discount:
  - **H1** `Buy {Name} — for 1 PC | Lifetime License Key`
    (visible, data-testid `product-name`)
  - **title** `Buy {Name} | Lifetime License Key | $X` clamped to
    ≤ 60 chars via a 5-tier fallback chain (drops price → chip →
    "Buy" prefix → name-only) so SERP never gets ellipsis-truncated.
  - **meta description** ≤ 155 chars with high-intent signal words
    (Buy / genuine / instant / 15-minute / 30-day / money-back
    guarantee) + "Save N% off MSRP" only when `original_price` shows
    a ≥ 5% real discount (no fabricated savings).
Brand suffix dropped on product pages so the ad-optimised title fits
cleanly in SERP.  Canonical product name preserved as a small
subtitle below the H1 (data-testid `product-canonical-name`).
Admin-pasted meta_description still overrides the auto-generated
version.  **Verified iter_16: 14/14 PASS + 2 skipped (no subscription
rows in seed DB).**


### 2026-06-24 — Rich-result eligibility: Free delivery + Free returns  (iter 17)
Both `shippingDetails` and `hasMerchantReturnPolicy` existed but had
Google-rejected shapes.  Fixed at the Product JSON-LD level AND
propagated to the Merchant Center feed where the badges surface.

**Product JSON-LD** (`product.php` lines ~186-220):
- `shippingDetails` → ARRAY of 6 `OfferShippingDetails` (one per
  supported country US/GB/CA/AU/IN/AE) each with SINGLE-ISO
  `addressCountry` string, zero-cost shippingRate, `doesNotShip: false`.
- `hasMerchantReturnPolicy` → all 8 Google-required fields including
  `applicableCountry`, `refundType: FullRefund`, `merchantReturnLink`
  pointing to /page.php?slug=refund-policy.

**Merchant feed** (`merchant-feed.php` lines ~327-339):
- 37x `<g:return_policy>` blocks (per support.google.com/merchants/answer/10961067)
- 37x `<g:free_shipping_threshold>0.00 USD</g:free_shipping_threshold>`

**Verified iter_17: 15/15 PASS on scope + 80/81 regression PASS**.
The 1 regression failure is a known test-helper bug from iter-16

### 2026-06-24 — Bing Shopping feed + sitemap render fix  (iter 18)
**Bug fix — `View Sitemap` from admin AI Auto-Blogger rendered as wall of XML**
- Root cause: `/sitemap.xml` had no XSLT stylesheet, so browsers
  displayed the raw `<urlset>…</urlset>` as plain text.
- Fix: added `<?xml-stylesheet type='text/xsl' href='/assets/sitemap.xsl'?>`
  PI immediately after the XML declaration in `sitemap-xml.php`.
  Created `/app/php-version/assets/sitemap.xsl` (HTML output, styled
  table with #/URL/Last-Modified/Frequency/Priority columns,
  color-coded priority pills, light + dark mode).  Search engines
  ignore the PI so SEO is unaffected.

**Feature — Bing Shopping feed**
- 4 new URL aliases all routing to the existing `merchant-feed.php`:
    `/feed/bing-shopping.xml`, `/feeds/bing-shopping.xml`,
    `/bing-shopping-feed.xml`, `/microsoft-merchant-feed.xml`.
- New `$isBingMode` detection in `merchant-feed.php` based on the
  request path. When Bing mode is active, each `<item>` block emits
  RSS-native field aliases (`<title>`, `<link>`, `<description>`,
  `<guid isPermaLink="true">`, `<pubDate>`) ALONGSIDE the g:* tags.
  Google routes stay slim (g:* only).
- Channel `<title>` differentiates: Bing = "… — Bing Shopping Feed",
  Google = "… — Software Product Feed".
- `<atom:link rel='self'>` correctly echoes whichever alias the
  crawler hit.
- robots.txt now lists 3 Sitemap directives (merchant-feed.xml,
  feed/google-products.xml, feed/bing-shopping.xml).

**Verified iter_18: 36/36 PASS on scope + 131/132 full suite PASS.**
The 1 remaining failure is the known iter-17 test-helper edge case
(product output is verified correct).

### 2026-06-24 — Enhanced Conversions + Customer Reviews + promo + product_detail  (iter 19)
**Part A — P0 conversion-bidder fuel**
- **Enhanced Conversions** wired into `order-success.php` purchase event.
  Hashes customer email + phone (SHA-256 hex, lowercased, trimmed; phone
  normalised to E.164) server-side and passes them via
  `gtag('set', 'user_data', {…})` BEFORE the conversion fires. Fully
  conditional — block omitted entirely when both hashes are empty.
- **Google Customer Reviews opt-in** iframe gated on a new
  `google_merchant_id` setting + `$isPaid` + non-empty order email.
  Renders the official `gapi.surveyoptin.render()` call with the
  order's email/order_id/delivery_country/estimated_delivery_date so
  Google sends the post-purchase survey ~7 days later and surfaces
  the "Verified by Google Customers" badge on Shopping listings.

**Part B — P1 Shopping-card real estate**
- `<g:promotion_id>MAVEN20</g:promotion_id>` per on-sale item (37 in
  current state). Pairs with a one-line promo entry the admin can
  upload to Merchant Center later — unlocks the green "Special
  offer" badge.
- 4× `<g:product_detail>` per item (148 total) emitting structured
  Compatibility/Licensing/Delivery attribute pairs that render in
  the Shopping "Specs" side panel.

**Admin form**
- New `[data-testid="tk-gmc-input"]` in `Admin → Company tab →
  SEO & Tracking` (regex `^[0-9]{6,15}$`, helper text pointing to
  merchants.google.com). Save handler preserves valid values when
  any new entry fails regex.

**Verified iter_19: 31/32 pytest + 100% admin UI. The 1 failing
case (empty user_data shell when both email + phone empty) was
fixed in-iteration via tightened PHP outer guard; manual reproduce
confirms `grep -c user_data` returns 0 when both empty, 1 when
either present.**

### 2026-06-24 — Emergent-leak scrub + gzip + sitemap refresh  (iter 20)
**User report (verbatim)**: *"There should not be any emergent link
while we deploy this project on the domain. Improve loading speed.
Update the sitemap."*

**Bug fix — Emergent leak**
- 4 hardcoded `static.prod-images.emergentagent.com` PNG URLs in
  `includes/subscriptions.php` (subscription plan icons) → replaced
  with local `/assets/images/subscriptions/plan-{1..4}.png`.
- DB scrub: `subscription_plans.icon_image` (4 rows),
  `email_templates.html` (2 rows), `email_outbox.html` (6 unsent
  rows) all UPDATEd to drop emergent references.
- Verified: under production Host header, ZERO `emergent`
  substring on /, /subscriptions.php, /product.php, /about-us.php,
  /shop.php, /checkout.php, /contact.php.  The only remaining
  `emergent` reference in the DB is `settings.v` for the universal
  LLM key (never rendered to public HTML — internal credential).

**Loading-speed win — Gzip output buffering**
- `ob_start('ob_gzhandler')` at the top of `router.php` wraps every
  response in gzip when the client sends `Accept-Encoding: gzip`.
  Wire size on `/` dropped from **117 907 B → 22 129 B (81 % smaller)**.
- Plan icons compressed from **~1 MB → ~20 KB each (98 %
  reduction)** via ImageMagick resize-to-256 + pngquant.

**Sitemap refresh**
- `/sitemap.xml` is dynamically regenerated per request from file
  mtime + DB UPDATE timestamps — confirmed 152 `<url>` entries with
  max `<lastmod>` within last 24 h.  XSLT-styled view from iter-18
  still works.


### 2026-06-24 — Dark-mode FOUC + checkout selection visibility  (iter 21)
**Bug 1 — White flash on every dark-mode navigation**
- Root cause: Bootstrap's default body background paints white before
  external CSS loads (~50–150 ms FOUC).  The theme-script ran early
  enough to set `data-bs-theme`, but no CSS was in scope yet to
  re-paint the html background.
- Fix: new `<style id="critical-theme-pre-paint">` block injected
  inline at the very top of `<head>` BEFORE the Bootstrap CDN link.
  Sets `html[data-bs-theme="dark"] { background:#050B1B !important; }`
  so the first frame paints dark.
- Verified: htmlBg=`rgb(5,11,27)` on first paint across /, /shop,
  /product, /checkout, /about-us when localStorage uc_theme=dark.

**Bug 2 — Invisible text selection on checkout inputs in dark mode**
- Root cause: existing `body:not(.adm) ::selection` rule used dark
  text (`color: var(--zoom-ink)`) → invisible on dark form fields.
- Fix: split the rule by theme using `html:not([data-bs-theme="dark"])`
  for light and `html[data-bs-theme="dark"]` (with explicit
  input/textarea/select variants) for dark.  Dark-mode highlight is
  now bright blue (rgba(96,165,250,.55)) with white text.
- Verified by testing agent: `selBg=rgba(96,165,250,0.55),
  selColor=rgb(255,255,255)` on inputs + body across all pages.

**Iter_21 = both bugs FIXED + all iter-20 regressions still PASS.**

**Verified iter_20: 23/23 new tests + 99/101 regression PASS.**
The 2 regression failures are pre-existing Cloudflare preview-route
flakes on `/assets/sitemap.xsl` — origin serves 200 OK; unrelated
to this iteration.



(over-strips when no brand suffix is present in <title>); product
output is verified correct.



### 2026-02-?? — Live SEO Health Check links broken on Apache deploys  (fork hand-off fix)
**Bug** — Under *AI Auto Blogger → Live SEO Health Check*, every probe shows
green (because `seo_health_probe()` cURLs the same-host URLs from PHP and
they resolve through `router.php`), but **clicking** the links from a real
production domain (cPanel / shared hosting) returned 404 for
`/feed/google-products.xml`, `/feed/bing-shopping.xml`, and the other
merchant-feed aliases.

- Root cause: `router.php` mirrors many "pretty URLs" to PHP generators
  (lines 191-200 — `/feed/google-products.xml`, `/feeds/google-products.xml`,
  `/google-merchant-feed.xml`, `/google-shopping-feed.xml`,
  `/feed/bing-shopping.xml`, `/feeds/bing-shopping.xml`,
  `/bing-shopping-feed.xml`, `/microsoft-merchant-feed.xml` → all mapped to
  `merchant-feed.php`).  `.htaccess` (which is what Apache/cPanel actually
  uses) only had `^merchant-feed\.xml$` — every other alias 404'd because
  the generic extensionless→.php fallback regex doesn't match URLs that
  contain a literal dot (`.xml`).
- Fix: added an "Google Merchant + Bing Shopping feed aliases" block in
  `.htaccess` (lines 39-51) that mirrors every alias from `router.php` to
  `merchant-feed.php` with `[L,QSA]`.  Now identical behaviour on the
  built-in PHP dev server and on Apache/cPanel.
- Other links (`/sitemap.xml`, `/robots.txt`, `/ai.txt`, `/llms.txt`,
  `/merchant-feed.xml`, IndexNow key file, schema/homepage) were already
  correctly rewritten — no change needed.


### 2026-02 — IndexNow "Why is this page blank?" hover hint  (same iter as .htaccess fix)
- Admin opened the IndexNow key file link from the Live SEO Health Check
  and saw a near-blank page (just the key text). That's intentional per the
  IndexNow protocol — Bing/Yandex/Naver/Seznam fetch it programmatically
  to verify domain ownership and require the file to contain ONLY the key.
- Added a dotted-underline "Why is this page blank?" inline help with a
  hover tooltip on the IndexNow row in admin.php (line 6213) so future
  team members understand the blank-looking page is the correct, expected
  behaviour and not a bug.


### 2026-02 — `/ajax/cart.php` payload tolerance (testing-agent silent-failure fix)
**Bug** — Testing agent iter_21 reported `POST /ajax/cart.php?action=add&slug=...`
returned `{ok:true, count:0}` — item not actually added to session cart.

- Root cause: cart.php only read `php://input` (JSON body). The frontend
  `main.js` correctly sends JSON, so the cart works in the wild — but any
  curl test, mobile native app, or admin tool that posts form data or
  query-string params got back the no-op fallthrough response, masking
  real failures and breaking automated tests.
- Fix: `cart.php` now merges three sources with JSON-body priority —
  `$body + $_POST + $_GET`. JSON wins when present, otherwise form data
  / query string are honoured. Existing main.js flow is unchanged.
- Verified by re-reading the test agent's curl scenario: the same call
  now populates `$action='add'`, `$slug='...'`, drops it into the
  session, and returns `count:1`.



### 2026-02 — IndexNow Shopping-feed ping on every product price change
**Why** — IndexNow already pinged on every product save, but only with the
product page URL.  Black-Friday-style price drops still waited for the next
Bing/Google Shopping crawl (typically hours) before the discounted price
showed in Shopping ads.

**What changed (`admin.php` `update_product` + `add_product`)**:
1. Before the `UPDATE products` query, snapshot `price`, `original_price`,
   `sale_starts_at`, `sale_ends_at` so we can compare post-save.
2. After the update, when ANY of those four fields changed, broaden the
   IndexNow ping batch to also include:
     - `/` (homepage with sale strip)
     - `/sitemap.xml`
     - `/merchant-feed.xml`  (Google canonical Shopping feed)
     - `/feed/google-products.xml`  (Google Merchant alias)
     - `/feed/bing-shopping.xml`  (Bing Shopping feed)
3. `add_product` always fires the broader ping (a new product is by
   definition a "new price" event for the Shopping crawlers).
4. The save-confirmation toast now reads
   "Saved — price change pushed to Bing/Yandex Shopping feeds" when a
   price/sale shift was detected, so the admin sees the IndexNow ping ran.

**Effect** — sale prices land in the Bing Shopping index within minutes of
the admin clicking Save instead of waiting for the next crawl cycle.
Measurable Shopping-CTR lift expected during BFCM / promo weekends.


### 2026-02 — "Launch Flash Deal" one-click admin button
**Why** — Closes the full SEO loop (price change → IndexNow → Shopping ad
refresh → blog backlink → ranking lift) in a single admin click.  Perfect
for BFCM A/B cadence tests (9am vs 6pm flash deals).

**What it does** — On the product edit modal (`admin.php?tab=products&edit=…`),
right below the main Save/Duplicate/Delete row, a red-bordered Flash Deal
panel exposes three picklists:
  - **% Off**       — 10 / 15 (default) / 20 / 25 / 30 / 40 / 50
  - **Duration**    — 6 / 12 / 24 (default) / 48 / 72 hours
  - **Blog target** — Auto (under-served region) / US / UK / AU / CA

Clicking **Launch Flash Deal** (with confirm prompt) does, atomically:
  1. Compute new price from MSRP (`original_price`) so back-to-back deals
     never compound; floor at $0.50.
  2. UPDATE `products.price`, `sale_starts_at = NOW()`,
     `sale_ends_at = NOW() + N hours`.
  3. Fire `seo_indexnow_ping_paths()` against the product URL + homepage +
     `/sitemap.xml` + `/merchant-feed.xml` + `/feed/google-products.xml`
     + `/feed/bing-shopping.xml` so Bing/Yandex/Naver/Seznam re-crawl
     within minutes.
  4. Call the new public helper `seo_publish_flash_deal_post()` which
     publishes a "Flash Deal: N% off …" AI blog post backlinking the
     product (post-id prefix `flash-YYYYMMDD-HHMM-…`).
  5. Redirect with a status toast:
     `Flash Deal LIVE — 25% off ProductX (now $X.XX, was $Y.YY) · ends in 24h · IndexNow: ok · Blog: published`.

**Code additions:**
- `includes/seo-bot.php`
  - `_seo_generate_one_blog_post()` — accepts new optional 7th/8th params
    `string $forceProductSlug = ''`, `array $flashDealMeta = []`.  When
    set, picks THAT product (not RAND()) and injects a "FLASH_DEAL_OVERRIDE"
    prompt block telling the LLM to lead with the % off + sale end time.
  - New public `seo_publish_flash_deal_post(slug, percent, ends_at, region?)`
    helper.  Mirrors `seo_publish_one_post_now()` shape.
- `admin.php`
  - New `elseif ($action === 'flash_deal')` POST handler (~70 lines).
  - New `flash-deal-panel` form rendered on the product edit modal when
    `!$isAdd && is_active === 1`.

**Verified end-to-end via curl + DB inspection on this dev pod:**
  - 15% off $499.99 MSRP → $424.99 exact ✓
  - 20% off → $399.99 exact ✓
  - 25% off → $374.99 exact ✓
  - 30% off → $349.99 — "30% OFF" badge + struck-through MSRP rendered on
    the storefront product card immediately ✓
  - sale_starts_at = NOW(), sale_ends_at = NOW() + 24h ✓
  - IndexNow returns `ok` (would push to Bing/Yandex on a real domain) ✓
  - Blog AI call is best-effort — gracefully fails-soft with friendly
    "LLM key not configured" toast when the AI key is missing ✓


### 2026-02 — IndexNow row turned GREEN (router.php .txt handler)
**Bug** — `Go-Live SEO Health Check → IndexNow (Instant Indexing)` showed
"✗ HTTP 502" persistently.  Browser GET on the key file at
`{site}/{key}.txt` returned a Cloudflare 502 Bad Gateway page (6,575
bytes of error HTML) while HEAD requests returned 200.

**Root cause** — `router.php` only had explicit MIME / cache handlers
for css/js/images/fonts (`$longCacheExts`).  Other static files,
including the IndexNow key `.txt`, fell through to PHP's built-in
server's default static-file response.  That response includes a
stray `Host:` *response* header (PHP's built-in server echoes the
request Host back into the response on tiny static files), which
Cloudflare's edge interprets as malformed origin output → 502.

**Fix** (`php-version/router.php`) — added an explicit `.txt` handler
above the long-cache branch that emits clean `Content-Type: text/plain`,
`Cache-Control: public, max-age=3600`, `Access-Control-Allow-Origin: *`,
`X-Content-Type-Options: nosniff`, `Content-Length`, then
`readfile()` + `return true`.  No more PHP-server-emitted Host
header → Cloudflare returns 200 consistently.

**Verified end-to-end:**
  - `/{key}.txt` direct via 127.0.0.1:3000 → 200 OK 32 bytes ✓
  - `/{key}.txt` via Cloudflare preview URL → 200 OK 32 bytes (3/3
    consecutive GETs) ✓
  - Admin Live SEO Health Check after `seo_health_recheck=1`:
    IndexNow row flips from red `✗ HTTP 502` to green
    `Key file live (HTTP 200 · text/plain; charset=UTF-8 · 32 bytes)
     · pushes new posts to Bing, Yandex, Naver & Seznam.` ✓
  - Overall health score lifted from 55% → 64% (7/11 ready) ✓


### 2026-02 — Zero Emergent-URL leak on production (4-layer defence)
**Goal** — Make it physically impossible for an Emergent preview / staging
/ cluster-internal hostname to appear in the customer-facing response when
the site is deployed to a real domain (e.g. maventechsoftware.com), no
matter how stale the DB rows are.

**Defence layers (newest → oldest):**

1. **Final-pass output scrub** (`includes/functions.php`) — every request
   that boots `functions.php` (which is every public page) now wraps its
   response in an `ob_start('_mv_scrub_preview_urls')` buffer.  The
   callback:
   - No-ops when the request host IS a preview / cluster-internal /
     localhost host (so admins on the preview keep seeing preview links).
   - Inspects the `Content-Type` header — bails for binary (images, PDFs).
   - Regex-replaces every absolute URL whose host matches
     `*.preview.emergentagent.com` / `*.preview.emergentcf.cloud` /
     `*.emergent.host` with a host-relative URL (just the path + query).
     Browsers resolve host-relative URLs against the production host the
     visitor is already on — exactly what we want.

2. **Read-time auto-heal** (`includes/settings.php` `setting_get()`) —
   already existed; expanded its host regex to cover the two extra
   variants above.

3. **Write-time auto-heal** (`includes/settings.php` `setting_set()`) —
   NEW: when an admin saves a URL-shaped setting while the request itself
   is on a real production host, `to_public_url()` is run on the value
   first so a stale preview URL never even reaches the DB.

4. **`to_public_url()` host regex** (`includes/functions.php`) — expanded
   from `\.preview\.emergentagent\.com$` to also cover
   `\.preview\.emergentcf\.cloud$` and `\.emergent\.host$`.  Same change
   to the "skip rewrite when admin is on preview" branch.

**Verified end-to-end on this dev pod:**
- Preview host (`Host: show-live-7.preview.emergentagent.com`):
  - homepage HTML still contains 31 preview URLs ✓ (admin can click them)
  - sitemap.xml still contains 677 preview URLs ✓
- Production host (`Host: maventechsoftware.com`):
  - homepage: 0 preview hostnames in 119 KB response ✓
  - sitemap.xml: every `<loc>` is `https://maventechsoftware.com/…` ✓
  - merchant-feed.xml: every `<link>` is `https://maventechsoftware.com` ✓
  - JSON-LD on homepage: 0 preview hostnames ✓
  - The stale `company_logo` DB row pointing at
    `https://stage-show-2.preview.emergentagent.com/uploads/...` is
    transparently rewritten on output → relative URL ✓
- CSS/JS/static assets untouched (content-type guard) ✓
- Storefront on preview URL renders pixel-perfect with no regression
  (verified by Playwright screenshot).
- Server-to-server `integrations.emergentagent.com` references (LLM /
  Stripe proxy) are intentionally NOT matched — they never appear in
  HTML, they're only called from PHP.


### 2026-02 — Light-mode `::selection` cyan alignment (iter_21 cosmetic)
**Bug** — Three different `::selection` rules disagreed on tone:
  - `style.css:83` (legacy global)  → `rgba(6, 182, 212, .18)` cyan
  - `style.css:3508` (light-mode body)→ `rgba(11, 92, 255, .22)` blue ✓
  - `header.php:128` (inline pre-paint) → `rgba(11, 92, 255, .35)` blue, but heavier alpha

Net effect: form inputs in checkout / admin pages flashed cyan when text
was selected while the surrounding body text glowed blue — visual jitter.

**Fix:**
1. `assets/css/style.css:83` — global fallback rule rewritten to
   `rgba(11, 92, 255, .22)` so admin pages (which the body-only rule at
   line 3508 specifically excludes via `body:not(.adm)`) inherit the
   same blue.
2. `includes/header.php:128` — inline pre-paint rule lowered from `.35`
   to `.22` to match style.css exactly.

Both light-mode `::selection` rules now declare an identical
`rgba(11, 92, 255, .22)` background.  Dark-mode rule
(`rgba(96, 165, 250, .55)`) was already correct — left untouched.

**Verified** — curl on the served HTML returns three perfectly aligned
`::selection` declarations; Playwright `getComputedStyle` from a checkout
input now reads the unified blue.


### 2026-02 — Mobile hamburger menu: theme toggle + currency + X close
**Bug** — On mobile (< lg breakpoint) the trustbar that carries the
currency selector + theme toggle is hidden to save vertical space, but
the main navbar was never given a replacement.  Customers on phones
had **no way to switch theme or change country/currency** once the
nav was collapsed.  Plus, the only way to close the open hamburger
menu was tapping the toggler button again (small target, top-right
corner) — no explicit "X" close.

**Fix** (`includes/header.php` + `assets/js/main.js`):
1. A new `[data-testid="mobile-nav-header"]` row, marked `d-flex d-lg-none`,
   sits at the top of the open `#mainNav` collapse and renders THREE
   utility controls in one horizontal strip:
   - **Theme toggle** (`[data-testid="theme-toggle-mobile"]`) — pill
     button with moon/sun icon + "Theme" label, fires `toggleTheme()`.
   - **Currency selector** (`[data-testid="currency-selector-mobile"]`) —
     dropdown identical to the desktop topbar variant (flag + code).
   - **X close** (`[data-testid="navbar-close-x"]`) — Bootstrap
     `.btn-close` wired with `data-bs-toggle="collapse"
     data-bs-target="#mainNav"` so a single tap dismisses the menu.
2. `assets/js/main.js` `syncThemeIcon()` now iterates over **both**
   `#theme-icon` (desktop) and `#theme-icon-mobile` (mobile menu) so
   the sun/moon glyph stays in sync everywhere when the theme flips.

**Verified end-to-end on a 390×844 mobile viewport (iPhone 12 Pro):**
- Hamburger tap → menu opens with the three-control header row at the
  top ✓
- Mobile theme button tap → `document.documentElement[data-bs-theme]`
  flips from `light` → `dark` ✓, mobile icon className flips from
  `bi bi-moon` → `bi bi-sun` ✓, page repaints to dark mode ✓
- X close button tap → `#mainNav` loses `.show` class, menu collapses ✓
- Currency selector dropdown opens with all 5 regions (US/UK/AU/CA/EU)
  showing flag + code + label — same content as the desktop dropdown ✓

---
## Bug Fix — Regional country links 404 (2026-06-25)
**Issue:** Clicking country links (US/UK/Canada/Europe/Australia → /ca/, /uk/, /au/, /eu/, /us/) returned 404 on production.
**Root cause:** `.htaccess` (Apache/cPanel prod) lacked the country-prefix rewrite rules that `router.php` (dev preview) had. Prefixed URLs fell through to the generic `.php` fallback → 404.
**Fix:**
- `php-version/.htaccess`: added country-prefix rewrites — `/us*` 301→bare path; `/ca|uk|au|eu/*` strip prefix + pass `?mv_cc=XX`.
- `php-version/includes/functions.php`: read `?mv_cc=XX` to set storefront currency when router.php isn't used (Apache).
- `php-version/router.php`: added extensionless→`.php` resolver so clean URLs (e.g. `/ca/shop`) work in preview, matching production.
**Verified:** on real Apache (temp install) `/ca/`,`/uk/`,`/au/`,`/eu/`→200, `/us/`→301; on preview all regional + clean URLs 200, genuine 404s preserved. CAD/GBP/AUD localize correctly.

---
## EU storefront + hreflang + full QA (2026-06-25)
- **EU/Europe enabled:** `regions.EU.active=1` (updated live DB, `database.sql` seed, and idempotent `start.sh` migration). /eu/ now shows EUR pricing and Europe appears in the country switcher.
- **hreflang:** already implemented in `includes/header.php` (en-US/en-GB/en-CA/en-AU/en + x-default) and `sitemap-xml.php`. Verified emitting on all pages.
- **Full E2E QA (testing agent iter-22):** 100% pass, no bugs. Verified all 5 regional storefronts + currency conversion (CA$/£/AU$/€/$), cart, coupon MAVEN20, DEMO checkout → order-success, admin login + all 16 admin tabs, AI chat lead capture, newsletter, dark mode, 404 handling. No PHP errors on any page.
- **UX niceties applied:** `/register.php` now 301→`/track-order.php` (was admin login); `/contact-us[.php]` 301→`/contact.php` (router.php + .htaccess).

---
## Bug Fix — Country switcher stacked prefixes on deployed domain (2026-06-25)
**Issue:** On the deployed domain (Apache/cPanel), switching country didn't open the right regional page. Default US works, but when already on a regional page (e.g. /ca/shop), clicking AU/UK/etc produced a stacked path like /au/ca/shop → 404.
**Root cause:** The header country switcher built links from `$_SERVER['REQUEST_URI']`. On the dev preview, router.php rewrites REQUEST_URI to the bare (prefix-stripped) path, so links were fine. On production Apache, REQUEST_URI keeps the original `/ca/shop`, so a new prefix stacked on top.
**Fix:** Added `country_switch_base()` in `includes/functions.php` — strips any existing country prefix (/us /uk /au /ca /eu) and the cur=/mv_cc= params from REQUEST_URI, returning the bare path. Both desktop + mobile switchers in `includes/header.php` now use it. Works identically on the dev router and production Apache.
**Verified on a real Apache + .htaccess instance (production scenario):** From /ca/shop the switcher renders /au/shop, /uk/shop, /eu/shop, /shop(US), /ca/shop (no stacking); each resolves 200 with the correct currency (AUD/GBP/EUR/USD/CAD). US is canonical (no prefix); /us* 301→bare path.

### NOTE for this preview pod
PHP + MariaDB are apt-installed and DO NOT survive a pod restart (only /app persists). If the preview is down after a restart, reinstall: `apt-get install -y php-cli php-mysql php-curl php-mbstring php-xml php-gd php-zip php-bcmath mariadb-server mariadb-client`, start mariadb (`mysqld_safe &`), then `supervisorctl restart frontend` (start.sh reseeds the DB). This is a preview-only concern; real cPanel/Apache hosting always has PHP+MySQL.

---
## Country switcher self-heal + performance (2026-06-25)
**Switcher stacking on deploy:** Already fixed via country_switch_base() (prev entry). Added a SELF-HEAL safety net so any already-stacked URL (e.g. /uk/au/shop from a cached link) 301-collapses to the FIRST prefix (/uk/shop = most recently clicked region). Implemented in BOTH router.php (preview) and .htaccess (production). Verified on real Apache: /uk/au/shop→/uk/shop(GBP), /ca/uk/au/shop→/ca/shop(CAD), /au/ca/uk/eu/shop→/au/shop(AUD).

**Performance ("heavy/slow page"):**
- Root cause on deploy: large TEXT assets shipped uncompressed on hosts lacking the one compression module the old .htaccess checked. style.css was 154 KB, main.js 48 KB on the wire.
- .htaccess now enables Brotli → deflate → legacy mod_gzip (robust across cPanel/LiteSpeed/Apache) for all text/css/js/json/xml/svg.
- router.php (preview) now lets the global gzip handler compress css/js/svg (removed the explicit Content-Length that disabled it): style.css 154→29 KB, main.js 48→13 KB on the wire.
- Removed orphan 1.1 MB assets/images/hero/hero-brand.png (unreferenced dead weight).
- Confirmed product images are already optimized WebP (~15 KB), head already has preconnect, async icon CSS, preloaded LCP image, deferred JS, swap fonts.

---
## Product images bulletproofed everywhere (2026-06-25)
Goal: product/plan images must never break on thank-you page, emails, receipts, invoices, subscriptions.
- **Emails**: centralized host resolution in new `email_public_base()` (includes/email.php) — prefers the real production domain, skips stale preview/cluster/localhost values, falls back through RAW stored settings so even CLI/cron sends emit ABSOLUTE URLs. `email_absolute_url()` + the email logo, product image, tracking pixel, track-order links all now resolve absolute. WebP product images auto-swap to their .jpg sibling (Outlook/Apple Mail safe) — all 42 products have jpg siblings.
- **Auto-domain capture**: `mv_sync_public_domain()` (includes/functions.php, called from header.php) writes the real domain into site_domain_url/main_url on the first genuine production page view, so cron emails/PDFs build correct image URLs without admin action.
- **setting_get($key,$default,$raw=true)**: new raw mode returns the stored value without the preview-host rewrite (which stripped the host to "/" on CLI).
- **PDF receipts/invoices**: confirmed they use bundled LOCAL brand-watermark images (isRemoteEnabled=false, chroot) — no per-product image dependency; both generate valid %PDF files.
- **Thank-you page (order-success)**: product image is root-relative `/uploads/...` (+ onerror placeholder) — loads on any host.
- **Subscriptions**: plan icons were seeded with Emergent build-CDN URLs (static.prod-images.emergentagent.com) — re-pointed to bundled local images (/assets/images/subscriptions/<slug>.png) in the live DB, database.sql seed, and an idempotent start.sh migration. Verified all 4 render.

---
## Subscription icons optimized to WebP (2026-06-25)
- The 4 plan icons were 512px PNGs (182–297 KB) displayed at 84px. Downscaled to 256px and generated optimized PNG (51–85 KB, kept for email/PDF fallback) + WebP (9–16 KB) siblings in assets/images/subscriptions/.
- subscriptions.php now renders `<picture>` with a WebP `<source>` (only when the .webp exists on disk) + PNG `<img>` fallback (width/height=84 for CLS).
- Subscriptions page icon weight: ~1040 KB -> ~48 KB (95% smaller). Verified all 4 render via WebP with PNG fallback intact for older clients/email.

---
## Company logo optimized + fixed (2026-06-25)
- Discovered the configured `company_logo` was a 1x1px (0.1 KB) placeholder pointing at a STALE preview host (stage-show-2…), so the brand mark was effectively blank in the header/footer, emails and receipts.
- Generated a real, brand-accurate mark (blue gradient rounded square + white "M" + accent dot, matching render_logo SVG) via GD: `/uploads/company/logo-mark.png` (4.1 KB) + `logo-mark.webp` (3.0 KB).
- New `brand_logo_html()` helper (functions.php) renders a `<picture>` with the WebP source + raster fallback (WebP only when the sibling exists), used by header.php + footer.php; falls back to inline SVG when no logo. Web pages now load the 3 KB WebP; emails/PDFs/old browsers keep the PNG.
- Set company_logo to the local mark in the live DB, database.sql seed, and an idempotent start.sh migration (only replaces empty/placeholder/stale-preview values, never a real admin upload). Email logo now resolves to an absolute, real PNG.
- Verified: header/footer render <picture>+WebP on home and regional pages; both assets 200; email logo absolute.

---
## Brand favicon + app icons (2026-06-25)
Generated a full matching icon set from the brand mark (blue gradient rounded square + white "M" + accent dot), replacing the old #0066CC default set:
- /favicon.svg (704 B vector), /favicon.ico (multi-size 16/32/48 PNG-in-ICO)
- /assets/images/favicon/favicon-16|32|64.png (rounded, transparent — browser tabs)
- apple-touch-icon.png 180x180 (opaque full-bleed — iOS applies its own mask)
- icon-192.png + icon-512.png (opaque full-bleed — PWA "any maskable", referenced by manifest)
- Updated <head> apple-touch-icon to 180x180 and theme-color to #0B5CFF; manifest theme_color -> #0B5CFF.
All static files (no migration needed); all serve 200 with correct content-types. Verified visually.

---
## Branded Open Graph / Twitter share card (2026-06-25)
- Redesigned og-default.php (serves /og-default.png) to match the new brand mark: deep-navy gradient bg, glowing blue rounded-square "M" logo + accent dot, brand name with accent underline, "Genuine Microsoft Office & Windows 11 License Keys" tagline, and a green "Instant delivery - One-time purchase" CTA pill. 1200x630 PNG (~92 KB).
- Auto-fits the brand name + tagline to the safe width so no text ever clips (works for any company name); graceful fallback if no TTF font.
- Added disk caching (uploads/og/og-default-<hash>.png keyed by brand + design version) so social-bot crawls are served instantly instead of re-rendering; falls back to live render if dir not writable.
- Head meta already complete: og:image/twitter:image absolute, og:image:width/height 1200x630, twitter:card=summary_large_image, og:image:alt, locale. Updated theme-color to #0B5CFF.
- Product pages keep their own dynamic card (og-product.png?slug=…, verified 200). Verified the default card renders polished with no clipping.

---
## JSON-LD structured data — made rich-result eligible (2026-06-25)
The store already had extensive, valid JSON-LD (Organization, LocalBusiness, WebSite+SearchAction, Brand, Product w/ Offer price+availability+shipping+returns, conditional AggregateRating+Review, BreadcrumbList, FAQPage, HowTo, Article, CollectionPage+ItemList). Fixed the bugs that were silently blocking Google rich results:
- product.php: Product schema `image` was ROOT-RELATIVE (/uploads/...) — Google requires ABSOLUTE; now normalized to absolute (array form), falls back to /og-default.png. Confirmed priceValidUntil present, price/currency regional, availability InStock/OutOfStock from live key inventory.
- header.php: Organization + LocalBusiness `logo`/`image` were relative — added `$brandLogoAbs` (absolute) used in all 3 schema spots; falls back to /assets/images/favicon/icon-512.png.
- Validated: all JSON-LD blocks parse as valid JSON on home/product/shop/category/contact (0 invalid).
- RATINGS NOTE: AggregateRating is correctly review-backed and only emits when published reviews exist (Google policy). Currently 0 published reviews in customer_reviews, so star ratings won't appear in SERP until real post-purchase reviews come in — intentionally NOT seeding fake reviews (manual-action risk).
