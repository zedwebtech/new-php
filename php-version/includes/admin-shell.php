<?php
// Standalone admin layout (replaces public site header for /admin*, /inventory.php, /order-view.php).
require_once __DIR__ . '/regions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Handle theme toggle BEFORE any HTML output
if (isset($_GET['theme']) && in_array($_GET['theme'], ['dark','light'], true)) {
    setcookie('adm_mode', $_GET['theme'], time()+86400*365, '/');
    $_COOKIE['adm_mode'] = $_GET['theme']; // immediate effect
    // Persist the choice to the user row so it follows them across browsers
    // & devices.  Silent if the column doesn't exist yet (ensure_db_schema
    // will add it later in this request).
    if (!empty($_SESSION['user_id'])) {
        try {
            db()->prepare("UPDATE users SET theme_pref = ? WHERE id = ?")
                ->execute([$_GET['theme'], (int)$_SESSION['user_id']]);
        } catch (Throwable $e) { /* column missing — ignore */ }
    }
    $u = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = $_GET; unset($qs['theme']);
    header('Location: ' . $u . ($qs ? '?'.http_build_query($qs) : ''));
    exit;
}

$adminMode = $_COOKIE['adm_mode'] ?? 'dark';
// If the logged-in admin has a saved theme preference, that wins over the
// cookie.  Means a multi-device admin only has to toggle once and the
// choice follows them everywhere.  Falls back silently if the column
// doesn't exist yet (ensure_db_schema will create it on the next request).
if (!empty($_SESSION['user_id'])) {
    try {
        $st = db()->prepare("SELECT theme_pref FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        $row = $st->fetch();
        $saved = trim((string)($row['theme_pref'] ?? ''));
        if (in_array($saved, ['dark', 'light'], true)) {
            $adminMode = $saved;
        }
    } catch (Throwable $e) { /* column missing — fall back to cookie */ }
}
$rg = active_region();
// Ensure all auxiliary tables exist on first admin page-load.  This makes
// the panel self-healing when uploaded to a fresh server where start.sh's
// migrations were never executed.
ensure_db_schema();

// Self-cron — if the AI Auto-Blogger is overdue (>24 h), fire it in the
// background after this admin page finishes rendering. The lock + cooldown
// inside seo_bot_autotick() guarantees only one run per day.
require_once __DIR__ . '/seo-bot.php';
seo_bot_autotick();
if (!function_exists('current_admin')) {
    function current_admin(): ?array { return function_exists('current_user') ? current_user() : null; }
}
$navItems = [
    'dashboard'   => ['icon' => 'bi-speedometer2',       'label' => 'Dashboard',          'href' => 'admin.php?tab=dashboard'],
    'users'       => ['icon' => 'bi-people-fill',        'label' => 'Users',              'href' => 'admin.php?tab=users'],
    'subscription'=> ['icon' => 'bi-stars',               'label' => 'Subscription',       'href' => 'admin.php?tab=subscription'],
    'ai-blogger'  => ['icon' => 'bi-robot',              'label' => 'AI Auto-Blogger',    'href' => 'admin.php?tab=ai-blogger'],
    'company'     => ['icon' => 'bi-building',           'label' => 'Company Info',       'href' => 'admin.php?tab=company'],
    'inventory'   => ['icon' => 'bi-boxes',              'label' => 'Inventory Mgmt',     'href' => 'inventory.php'],
    'products'    => ['icon' => 'bi-box-seam',           'label' => 'Products / Key Inventory', 'href' => 'admin.php?tab=products'],
    'orders'      => ['icon' => 'bi-receipt',            'label' => 'Orders',             'href' => 'admin.php?tab=orders'],
    'sales'       => ['icon' => 'bi-graph-up-arrow',     'label' => 'Sales Detail',       'href' => 'admin.php?tab=sales'],
    'leads'       => ['icon' => 'bi-person-lines-fill',  'label' => 'Lead Management',    'href' => 'admin.php?tab=leads'],
    'schedule'    => ['icon' => 'bi-calendar-check',     'label' => 'Install Schedule',   'href' => 'admin.php?tab=schedule'],
    'emails'      => ['icon' => 'bi-envelope',           'label' => 'Email Activity',     'href' => 'admin.php?tab=emails'],
    'reviews'     => ['icon' => 'bi-star',                'label' => 'Customer Reviews',   'href' => 'admin.php?tab=reviews'],
    'templates'   => ['icon' => 'bi-file-earmark-richtext','label'=> 'Email Templates',   'href' => 'admin.php?tab=templates'],
    'gateways'    => ['icon' => 'bi-credit-card-2-front','label' => 'API / Payment Gateway',  'href' => 'admin.php?tab=api&gw=toggles'],
    'smtp'        => ['icon' => 'bi-envelope-paper-heart','label' => 'SMTP / Mail Server', 'href' => 'admin.php?tab=smtp'],
    'regions'     => ['icon' => 'bi-globe',              'label' => 'Regions',            'href' => 'admin.php?tab=regions'],
    'settings'    => ['icon' => 'bi-gear',               'label' => 'Settings',           'href' => 'admin.php?tab=settings', 'hidden' => true],
];
$adminActive = $adminActive ?? '';
$pageTitle   = $pageTitle ?? 'Admin Panel';
$admin       = $admin ?? current_admin();
// Pull the brand letter for the topbar monogram (and the email "M" badge).
$adm_brand_name   = function_exists('company_info') ? (company_info()['name'] ?? '') : '';
if ($adm_brand_name === '' && defined('SITE_BRAND')) $adm_brand_name = SITE_BRAND;
$adm_brand_letter = mb_strtoupper(mb_substr(preg_replace('/^[^A-Za-z0-9]+/', '', $adm_brand_name) ?: 'M', 0, 1));
$adm_brand_logo   = function_exists('company_info') ? (company_info()['logo'] ?? '') : '';
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= $adminMode === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<!-- PWA: install on desktop + mobile, background notification polling -->
<link rel="manifest" href="/admin-manifest.json">
<meta name="theme-color" content="#06b6d4">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Maventech Admin">
<link rel="apple-touch-icon" href="/assets/images/icons/admin-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/assets/images/icons/admin-512.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/dark-mode-polish.css" rel="stylesheet">
<script>
  // Base URL the panel was loaded from — every fetch() to ajax/... uses this
  // so the admin works whether installed at "/" or in a subfolder like "/admin/".
  window.MAVEN_BASE = <?= json_encode(base_url()) ?>;
</script>
<style>
:root {
  --bg: #f7f8fa;
  --card-bg: #ffffff;
  --border: #e9ecef;
  --text: #1f2937;
  --muted: #64748b;
  /* Zoom blue — matches the public storefront so the admin lives in the
     same brand world.  Was #3b82f6 (generic Tailwind blue) — now Zoom Blue.
     The single change cascades through every component that reads
     var(--brand) / var(--brand-dk): sidebar active border, KPI tile values,
     primary buttons, focus rings, link colour. */
  --brand: #0B5CFF;
  --brand-dk: #0848CC;
  --green: #10b981;
  --green-soft: #d1fae5;
  --red: #ef4444;
  --red-soft: #fee2e2;
  --amber: #f59e0b;
  --amber-soft: #fef3c7;
  --gray-soft: #e5e7eb;
  --blue-soft: #E5EEFF;             /* Zoom-blue tint (was #dbeafe). */
}
[data-bs-theme="dark"] {
  /* Zoom-navy palette — admin lives in the same deep-navy world as the
     public storefront so the theme switch feels seamless between admin
     and store.  Was slate-800/700 (#1e293b / #334155); now Zoom navy. */
  --bg: #050B1B;             /* Zoom navy — page bg */
  --card-bg: #111A38;        /* slightly lifted surface for cards */
  --border: rgba(255,255,255,.10);
  --text: #F1F5F9;
  --muted: #A6B2C8;
  --gray-soft: #1A2552;
  --blue-soft: rgba(11, 92, 255, .22);
  --green-soft:#065f46; --red-soft:#7F1D1D; --amber-soft:#78350F;
}
/* Dark-mode contrast fixes: code blocks, links, soft badges, tables */
[data-bs-theme="dark"] code { background: rgba(255,255,255,0.08); color:#e0e7ff; }
[data-bs-theme="dark"] a { color:#93c5fd; }
[data-bs-theme="dark"] a:hover { color:#bfdbfe; }
[data-bs-theme="dark"] .text-muted { color:#cbd5e1 !important; }
[data-bs-theme="dark"] .text-secondary { color:#cbd5e1 !important; }
[data-bs-theme="dark"] .s-badge { color:#f1f5f9; }

/* ---- Universal dark-mode polish for the patterns the admin uses
   everywhere: blue-soft pills, dotted-blue icons, license-key chips.
   In dark mode the underlying CSS variables are also dark colours so
   "blue-soft bg + brand-dk text" became "deep blue text on deep blue
   bg" → invisible (especially the License delivery / License Key pills
   inside the Email Activity center and Lead drawer). */
[data-bs-theme="dark"] .ec-tpl-chip,
[data-bs-theme="dark"] .ec-key,
[data-bs-theme="dark"] .ec-v a,
[data-bs-theme="dark"] code[style*="var(--blue-soft)"] {
  background: rgba(59,130,246,.18) !important;
  color: #bfdbfe !important;
  border: 1px solid rgba(96,165,250,.30) !important;
}
[data-bs-theme="dark"] .ec-tpl-chip .bi,
[data-bs-theme="dark"] .ec-meta .bi,
[data-bs-theme="dark"] .ec-k .bi,
[data-bs-theme="dark"] .ec-meta { color:#cbd5e1 !important; }
[data-bs-theme="dark"] .ec-k .bi { color:#93c5fd !important; }
[data-bs-theme="dark"] .ec-meta .bi { color:#94a3b8 !important; }
[data-bs-theme="dark"] .ec-field { border-bottom-color: rgba(148,163,184,.20) !important; }
[data-bs-theme="dark"] .ec-k { color:#cbd5e1 !important; }
[data-bs-theme="dark"] .ec-v { color:#f1f5f9 !important; }
[data-bs-theme="dark"] .ec-v .text-muted { color:#94a3b8 !important; }
[data-bs-theme="dark"] .ec-v a { background:transparent !important; border:none !important; color:#93c5fd !important; }

/* Pill toggles + selected lead row */
[data-bs-theme="dark"] .pill-toggle:hover {
  background: rgba(59,130,246,.18) !important;
  color: #bfdbfe !important;
}
[data-bs-theme="dark"] tr[style*="var(--blue-soft)"] {
  background: rgba(59,130,246,.14) !important;
  color: #f1f5f9 !important;
}
[data-bs-theme="dark"] tr[style*="var(--blue-soft)"] td { color: #f1f5f9 !important; }

/* Status badges with inline light-bg / dark-text colours (Published,
   Hidden, Pending …) inside admin tables — force-brighten. */
[data-bs-theme="dark"] .badge.rounded-pill[style*="#d1fae5"] { background:rgba(16,185,129,.22) !important; color:#86efac !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#fef3c7"] { background:rgba(245,158,11,.22) !important; color:#fcd34d !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#f1f5f9"] { background:rgba(148,163,184,.22) !important; color:#cbd5e1 !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#dbeafe"] { background:rgba(59,130,246,.22) !important; color:#bfdbfe !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#fee2e2"] { background:rgba(239,68,68,.22) !important; color:#fca5a5 !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#fce7f3"] { background:rgba(236,72,153,.22) !important; color:#fbcfe8 !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#ede9fe"] { background:rgba(139,92,246,.22) !important; color:#ddd6fe !important; }
[data-bs-theme="dark"] .badge.rounded-pill[style*="#cffafe"] { background:rgba(6,182,212,.22) !important; color:#a5f3fc !important; }

/* General brightening for icons inline-styled with brand-dk/brand text
   (covers the small bi-tag, bi-clock, bi-person-circle in countless
   widgets across the admin). */
[data-bs-theme="dark"] .text-primary { color:#93c5fd !important; }
[data-bs-theme="dark"] .text-info    { color:#67e8f9 !important; }
[data-bs-theme="dark"] .text-success { color:#86efac !important; }
[data-bs-theme="dark"] .text-warning { color:#fcd34d !important; }
[data-bs-theme="dark"] .text-danger  { color:#fca5a5 !important; }

/* ---- Status badges: complete dark-mode palette (every keyword present in
   admin.php).  Without these, statuses like "new" / "contacted" / "qualified"
   inherited light-mode `color:#92400e` against the now-dark `--amber-soft`
   variable and rendered as invisible text-on-text. ---- */
[data-bs-theme="dark"] .s-badge.paid,
[data-bs-theme="dark"] .s-badge.delivered,
[data-bs-theme="dark"] .s-badge.sent {
  background:#065f46; color:#a7f3d0;
}
[data-bs-theme="dark"] .s-badge.failed,
[data-bs-theme="dark"] .s-badge.refunded,
[data-bs-theme="dark"] .s-badge.lost,
[data-bs-theme="dark"] .s-badge.cancelled {
  background:#991b1b; color:#fecaca;
}
[data-bs-theme="dark"] .s-badge.queued,
[data-bs-theme="dark"] .s-badge.pending,
[data-bs-theme="dark"] .s-badge.new {
  background:#92400e; color:#fde68a;
}
[data-bs-theme="dark"] .s-badge.contacted {
  background:#1e3a8a; color:#bfdbfe;
}
[data-bs-theme="dark"] .s-badge.qualified,
[data-bs-theme="dark"] .s-badge.active {
  background:#065f46; color:#86efac;
}
[data-bs-theme="dark"] .s-badge.converted {
  background:#155e75; color:#a5f3fc;
}
[data-bs-theme="dark"] .s-badge.inactive {
  background:#475569; color:#e2e8f0;
}
[data-bs-theme="dark"] .s-badge.opened { background:#1e40af; color:#bfdbfe; }
[data-bs-theme="dark"] .btn-soft-blue { background:#1e3a8a; color:#bfdbfe; }
[data-bs-theme="dark"] .btn-soft-blue:hover { background:#1d4ed8; color:#fff; }
[data-bs-theme="dark"] .btn-soft-green { background:#065f46; color:#a7f3d0; }
[data-bs-theme="dark"] .btn-soft-green:hover { background:#047857; color:#fff; }
[data-bs-theme="dark"] .btn-soft-red { background:#7f1d1d; color:#fecaca; }
[data-bs-theme="dark"] .btn-soft-red:hover { background:#991b1b; color:#fff; }
[data-bs-theme="dark"] .btn-soft-gray { background:#475569; color:#e2e8f0; }
[data-bs-theme="dark"] .btn-soft-gray:hover { background:#64748b; color:#fff; }
[data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select {
  background: #1e293b; color:#f1f5f9; border-color: #475569;
}
[data-bs-theme="dark"] .form-control:focus, [data-bs-theme="dark"] .form-select:focus {
  background:#1e293b; color:#f1f5f9; border-color:#3b82f6; box-shadow:0 0 0 .2rem rgba(59,130,246,.25);
}
[data-bs-theme="dark"] .form-control::placeholder { color:#94a3b8; }
[data-bs-theme="dark"] .table { color: #f1f5f9; }
[data-bs-theme="dark"] .table thead th { background: #2d3a52; color:#e2e8f0; border-bottom-color:#475569; }
[data-bs-theme="dark"] .table tbody tr { border-bottom: 1px solid #475569; }
[data-bs-theme="dark"] .table tbody tr:hover { background: rgba(255,255,255,0.03); }
[data-bs-theme="dark"] .card-e { background: var(--card-bg); border-color: var(--border); color: var(--text); }
[data-bs-theme="dark"] .kpi-tile { background: var(--card-bg); border-color: var(--border); }
[data-bs-theme="dark"] .kpi-tile .kpi-label { color: #cbd5e1; }
[data-bs-theme="dark"] .kpi-tile .kpi-value { color:#f1f5f9; }
[data-bs-theme="dark"] .alert-success { background:#065f46; color:#a7f3d0; border-color:#047857; }
[data-bs-theme="dark"] .alert-danger { background:#991b1b; color:#fecaca; border-color:#dc2626; }
[data-bs-theme="dark"] .text-success { color:#6ee7b7 !important; }
[data-bs-theme="dark"] .text-primary { color:#93c5fd !important; }
[data-bs-theme="dark"] .text-danger { color:#fca5a5 !important; }
[data-bs-theme="dark"] .text-warning { color:#fcd34d !important; }
/* Compact card sizing — modern dashboard density */
.card-e { padding: 16px; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04); }
.card-e.p-4 { padding: 18px !important; }
.card-e .card-head { padding: 12px 16px; }
.kpi-tile { padding: 14px; }
.kpi-tile .kpi-value { font-size: 22px; line-height: 1.2; }
.kpi-tile .kpi-label { font-size: 11px; letter-spacing: .5px; }
[data-bs-theme="dark"] .card-e { box-shadow: 0 1px 3px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.18); }

/* Email-template ON/OFF tiny pills (visible in dark mode too) */
.s-badge.active   { background:#d1fae5; color:#065f46; padding:1px 7px; font-size:9px; font-weight:800; letter-spacing:.4px; border-radius:999px; border:1px solid #6ee7b7; }
.s-badge.inactive { background:#fee2e2; color:#991b1b; padding:1px 7px; font-size:9px; font-weight:800; letter-spacing:.4px; border-radius:999px; border:1px solid #fca5a5; }
[data-bs-theme="dark"] .s-badge.active   { background: rgba(16,185,129,.18); color:#6ee7b7; border-color: rgba(16,185,129,.40); }
[data-bs-theme="dark"] .s-badge.inactive { background: rgba(239,68,68,.18);  color:#fca5a5; border-color: rgba(239,68,68,.40); }

/* Template list items — clean hover & active state, dark-mode aware */
.tpl-list-item { color: var(--text); border:1px solid transparent; transition: background .15s, border-color .15s; }
.tpl-list-item:hover { background: var(--bg); color: var(--text); }
.tpl-list-item.active { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.30); }
[data-bs-theme="dark"] .tpl-list-item.active { background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.45); }
/* Template row (clickable item + explicit Edit button) */
.tpl-row { min-height: 50px; }
.tpl-row .btn { align-self: stretch; }
.tpl-row-active .btn { background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.30); }

/* ---------- Email template content editor ---------- */
.tpl-toolbar { gap: 4px; }
.tpl-toolbar .vr { background: var(--border); width:1px; height:24px; align-self:center; margin: 0 2px; }
.tpl-toolbar .btn { padding: 4px 9px; font-size: 13px; line-height: 1; }
.tpl-content-editor { outline: none; }
.tpl-content-editor:focus { box-shadow: 0 0 0 .15rem rgba(59,130,246,.18); border-color: #93c5fd; }
.tpl-content-editor h1, .tpl-content-editor h2 { font-weight:700; margin: .6em 0 .3em; }
.tpl-content-editor p { margin: 0 0 .6em; }
.tpl-content-editor a { color: #2563eb; text-decoration: underline; }
.tpl-content-editor img { max-width: 100%; height: auto; border-radius: 6px; }
/* Variable chips inside the editor — visual badges that the user can delete as a single unit */
.tpl-var-chip {
  display: inline-block;
  background: linear-gradient(135deg, #dbeafe, #e0e7ff);
  color: #1d4ed8;
  padding: 1px 8px 2px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  font-family: 'SF Mono','Menlo','Monaco','Courier New',monospace;
  margin: 0 2px;
  vertical-align: 1px;
  user-select: all;
  border: 1px solid rgba(29,78,216,.25);
  white-space: nowrap;
}
.tpl-var-chip::before { content: "{ "; opacity: .55; }
.tpl-var-chip::after  { content: " }"; opacity: .55; }
[data-bs-theme="dark"] .tpl-var-chip { background: rgba(59,130,246,.22); color:#bfdbfe; border-color: rgba(147,197,253,.35); }

/* API form inputs: smaller + long keys wrap inside the box */
[data-testid^="api-"] .form-control,
[data-testid^="api-"] .form-select {
  font-size: 12px; padding: 5px 9px; line-height: 1.35;
  word-break: break-all; overflow-wrap: anywhere;
}
[data-testid^="api-"] textarea.form-control { min-height: 60px; font-family:'SF Mono','Menlo','Monaco','Courier New',monospace; }
[data-testid^="api-"] .form-label { font-size: 11px; margin-bottom: 2px; }
[data-testid^="api-"] code { word-break: break-all; overflow-wrap: anywhere; display:inline-block; max-width:100%; }
[data-testid^="api-"] input[type="text"], [data-testid^="api-"] input[type="url"], [data-testid^="api-"] input[type="email"] { font-family:'SF Mono','Menlo','Monaco','Courier New',monospace; }

/* Ensure content stays inside boxes — alignment + overflow safety */
.card-e { overflow:hidden; }
.card-e .card-body-p { overflow-x:auto; }
.tbl-e { overflow:auto; max-width:100%; }
.tbl-e table { table-layout:auto; }
.tbl-e td, .tbl-e th { vertical-align: middle; word-break: break-word; }

/* ============================================================
   EMAIL ACTIVITY CENTER — light + dark mode styles
   ============================================================ */
.lk-row { margin: 2px 0; line-height: 1; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
.lk-pill {
  font-size: 10.5px; font-weight: 600;
  background: #eff6ff; color: #1d4ed8;
  padding: 3px 7px; border-radius: 5px;
  border: 1px solid #bfdbfe;
  font-family: 'SF Mono','Menlo','Monaco','Courier New',monospace;
}
.sold-tag {
  font-size: 9px; font-weight: 800;
  color: #065f46; background: #d1fae5;
  padding: 3px 6px; border-radius: 4px;
  letter-spacing: .5px; vertical-align: middle;
  border: 1px solid #6ee7b7;
}
.tpl-chip {
  display: inline-block; font-size: 10px; font-weight: 600;
  padding: 3px 9px; border-radius: 999px; margin-top: 3px;
  color: #2563eb; background: #dbeafe; border: 1px solid #93c5fd;
}
.tpl-chip[data-tpl="review_request"]  { color:#7c3aed; background:#ede9fe; border-color:#c4b5fd; }
.tpl-chip[data-tpl="order_confirmation"] { color:#10b981; background:#d1fae5; border-color:#6ee7b7; }
.tpl-chip[data-tpl="inline"]          { color:#475569; background:#f1f5f9; border-color:#cbd5e1; }

/* DARK MODE — make every pill, tag & chip pop with proper contrast */
[data-bs-theme="dark"] .lk-pill {
  background: rgba(96,165,250,.12); color: #93c5fd; border-color: rgba(96,165,250,.35);
}
[data-bs-theme="dark"] .sold-tag {
  background: rgba(16,185,129,.18); color: #6ee7b7; border-color: rgba(16,185,129,.40);
}
[data-bs-theme="dark"] .tpl-chip {
  background: rgba(59,130,246,.18); color: #93c5fd; border-color: rgba(59,130,246,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="review_request"] {
  background: rgba(167,139,250,.18); color: #c4b5fd; border-color: rgba(167,139,250,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="order_confirmation"] {
  background: rgba(16,185,129,.18); color: #6ee7b7; border-color: rgba(16,185,129,.40);
}
[data-bs-theme="dark"] .tpl-chip[data-tpl="inline"] {
  background: rgba(148,163,184,.18); color: #cbd5e1; border-color: rgba(148,163,184,.40);
}

/* Customer link in Email Activity — readable in both modes */
[data-bs-theme="dark"] a[data-testid^="customer-link-"] { color: #93c5fd !important; }
[data-bs-theme="dark"] a[data-testid^="customer-link-"]:hover { color: #bfdbfe !important; text-decoration: underline; }

/* Resend popover background must match card-bg in dark mode (was hard-coded white) */
[data-bs-theme="dark"] div[id^="editResend"] {
  background: var(--card-bg) !important;
  border: 1px solid var(--border);
  color: var(--text);
}
[data-bs-theme="dark"] div[id^="editResend"] small { color: var(--muted); }

/* KPI tiles in Email Activity header (Sent / Opened / Queued / Failed) — keep
   their accent colour but lighten the value text in dark mode */
[data-bs-theme="dark"] .kpi-tile .kpi-value { color: #f8fafc; }

body { background: var(--bg); color: var(--text); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; font-size: 14px; position:relative; overflow-x: hidden; }

/* Watermark removed per user request — only the animated floating-icons
   layer below provides background ambience.  body::before still exists
   but holds nothing (kept as a hook in case we want a tint later). */
body::before { content: none; }

/* =============================================================
   FLOATING TECH ICONS — animated background layer.
   Larger, more visible glyphs that look like real product icons.
   Drift faster (12-18s/loop) so the screen feels alive.
   ============================================================= */
.adm-floats { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
.adm-floats i {
  position: absolute;
  font-size: 64px;
  opacity: 0.32;
  filter: drop-shadow(0 4px 10px rgba(15,23,42,.15));
  animation: adm-float-drift 16s ease-in-out infinite;
  will-change: transform;
}
[data-bs-theme="dark"] .adm-floats i {
  opacity: 0.40;
  filter: drop-shadow(0 4px 14px rgba(0,0,0,.55));
}
.adm-floats i:nth-child(odd)  { animation-name: adm-float-drift; }
.adm-floats i:nth-child(even) { animation-name: adm-float-drift-rev; animation-duration: 18s; }
.adm-floats i:nth-child(3n)   { animation-duration: 14s; }
.adm-floats i:nth-child(4n)   { animation-duration: 20s; }
.adm-floats i:nth-child(5n)   { animation-duration: 12s; }

/* Per-icon real-product colours so they look like actual product logos */
.adm-floats .ic-win    { color: #0078D4; }     /* Windows blue */
.adm-floats .ic-office { color: #D24726; }     /* Office orange */
.adm-floats .ic-apple  { color: #6b7280; }     /* Apple gray */
.adm-floats .ic-droid  { color: #3DDC84; }     /* Android green */
.adm-floats .ic-shield { color: #DC2626; }     /* security red */
.adm-floats .ic-cloud  { color: #0EA5E9; }     /* cloud sky */
.adm-floats .ic-key    { color: #F59E0B; }     /* key amber */
.adm-floats .ic-cpu    { color: #8B5CF6; }     /* purple */
.adm-floats .ic-mail   { color: #2563EB; }     /* blue */
.adm-floats .ic-card   { color: #10B981; }     /* green */
.adm-floats .ic-globe  { color: #6366F1; }     /* indigo */
.adm-floats .ic-bell   { color: #EAB308; }     /* yellow */

@keyframes adm-float-drift {
  0%   { transform: translate(0, 0)         rotate(0deg)   scale(1); }
  25%  { transform: translate(20vw, -12vh)  rotate(45deg)  scale(1.15); }
  50%  { transform: translate(35vw, 18vh)   rotate(-25deg) scale(0.9); }
  75%  { transform: translate(15vw, 30vh)   rotate(60deg)  scale(1.1); }
  100% { transform: translate(0, 0)         rotate(0deg)   scale(1); }
}
@keyframes adm-float-drift-rev {
  0%   { transform: translate(0, 0)         rotate(0deg)    scale(1); }
  25%  { transform: translate(-18vw, 15vh)  rotate(-60deg)  scale(0.85); }
  50%  { transform: translate(-32vw, -10vh) rotate(40deg)   scale(1.2); }
  75%  { transform: translate(-15vw, -25vh) rotate(-30deg)  scale(1); }
  100% { transform: translate(0, 0)         rotate(0deg)    scale(1); }
}
@media (prefers-reduced-motion: reduce) {
  .adm-floats i { animation: none; }
}

/* Ensure all admin content sits above the watermark */
.adm-top, .adm-shell, .adm-sidebar, .adm-content, main, footer { position: relative; z-index: 1; }

/* ============ ADMIN TOPBAR (no public navbar) ============ */
.adm-top {
  background: var(--card-bg);
  border-bottom: 1px solid var(--border);
  padding: 14px 24px;
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  position: sticky; top:0; z-index: 1030;
  backdrop-filter: blur(6px);
}
.adm-top .brand-center {
  position:absolute; left:50%; transform:translateX(-50%);
  font-size:18px; font-weight:800; letter-spacing:.4px; color: var(--text);
  display:flex; align-items:center; gap:10px;
}
.adm-top .brand-center .m-logo {
  width:34px; height:34px;
  border-radius: var(--vibe-radius, 9px);
  background: linear-gradient(135deg, var(--vibe-g0, #312e81), var(--vibe-g1, #1e40af) 55%, var(--vibe-g2, #06b6d4));
  display:inline-flex;align-items:center;justify-content:center;color:#fff;
  font-weight: var(--vibe-fontw, 800);
  font-size:18px;
  /* Animation driven by `body[data-brand-motion]` so admins can swap
     Bounce / Spin / Pulse / Static from Company Info → Brand Motion. */
  transform-style: preserve-3d;
  box-shadow: 0 6px 18px rgba(29,78,216,.35);
  will-change: transform;
}
.adm-top .brand-center .m-logo-img { border-radius: var(--vibe-radius, 9px) !important; box-shadow: 0 6px 18px rgba(29,78,216,.35); }
/* Vibe CSS variables — mirror style.css so the admin topbar logo + nav
   buttons inherit the chosen vibe.  Keep these in sync with brand_vibes()
   in includes/functions.php. */
body[data-brand-vibe="premium"] { --vibe-g0:#0c0a09; --vibe-g1:#3f3f46; --vibe-g2:#facc15; --vibe-accent:#facc15; --vibe-radius:6px;  --vibe-fontw:800; }
body[data-brand-vibe="classic"] { --vibe-g0:#312e81; --vibe-g1:#1e40af; --vibe-g2:#06b6d4; --vibe-accent:#06b6d4; --vibe-radius:14px; --vibe-fontw:700; }
body[data-brand-vibe="playful"] { --vibe-g0:#f97316; --vibe-g1:#ec4899; --vibe-g2:#a855f7; --vibe-accent:#f97316; --vibe-radius:22px; --vibe-fontw:800; }
body[data-brand-vibe="bold"]    { --vibe-g0:#7c3aed; --vibe-g1:#ec4899; --vibe-g2:#0ea5e9; --vibe-accent:#7c3aed; --vibe-radius:10px; --vibe-fontw:900; }
body[data-brand-motion="bounce"] .adm-top .brand-center .m-logo,
body[data-brand-motion="bounce"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-spin-bounce 3s ease-in-out infinite;
}
body[data-brand-motion="spin"] .adm-top .brand-center .m-logo,
body[data-brand-motion="spin"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-pure-spin 4.5s linear infinite;
}
body[data-brand-motion="pulse"] .adm-top .brand-center .m-logo,
body[data-brand-motion="pulse"] .adm-top .brand-center .m-logo-img {
  animation: m-logo-pulse 2.4s ease-in-out infinite;
}
body[data-brand-motion="static"] .adm-top .brand-center .m-logo,
body[data-brand-motion="static"] .adm-top .brand-center .m-logo-img {
  animation: none;
}
.adm-top .brand-center .m-logo:hover,
.adm-top .brand-center .m-logo-img:hover {
  animation-play-state: paused;
  cursor: pointer;
}
@keyframes m-logo-spin-bounce {
  0%   { transform: translateY(0)    rotateY(0deg)   scale(1); }
  25%  { transform: translateY(-6px) rotateY(90deg)  scale(1.05); }
  50%  { transform: translateY(0)    rotateY(180deg) scale(1); }
  75%  { transform: translateY(-6px) rotateY(270deg) scale(1.05); }
  100% { transform: translateY(0)    rotateY(360deg) scale(1); }
}
@keyframes m-logo-pure-spin {
  0%   { transform: rotateY(0deg); }
  100% { transform: rotateY(360deg); }
}
@keyframes m-logo-pulse {
  0%, 100% { transform: scale(1); box-shadow: 0 6px 18px rgba(29,78,216,.35); }
  50%      { transform: scale(1.10); box-shadow: 0 8px 26px rgba(29,78,216,.55); }
}
@media (prefers-reduced-motion: reduce) {
  .adm-top .brand-center .m-logo, .adm-top .brand-center .m-logo-img { animation: none; }
}
.adm-top .brand-center small { font-size:9px;letter-spacing:1.8px;color:var(--muted);font-weight:600;}
.adm-top .brand-center .adm-brand-cp {
  display: inline-block;
  font-size: 13px;
  letter-spacing: 3px;
  font-weight: 800;
  text-transform: uppercase;
  background: linear-gradient(90deg, #3b82f6 0%, #06b6d4 25%, #8b5cf6 50%, #ec4899 75%, #f59e0b 100%);
  background-size: 200% 100%;
  -webkit-background-clip: text;
          background-clip: text;
  -webkit-text-fill-color: transparent;
  text-shadow: 0 1px 0 rgba(255,255,255,.05);
  animation: adm-brand-cp-shimmer 6s linear infinite;
}
@keyframes adm-brand-cp-shimmer {
  0%   { background-position:   0% 50%; }
  100% { background-position: 200% 50%; }
}
@media (prefers-reduced-motion: reduce) {
  .adm-top .brand-center .adm-brand-cp { animation: none; }
}
.adm-top .left, .adm-top .right { display:flex;align-items:center;gap:10px; z-index:2; }

.adm-pill {
  background: var(--bg);
  border:1px solid var(--border);
  border-radius: 999px;
  padding: 6px 12px;
  font-size:12px; font-weight:600;
  color: var(--text);
  display:inline-flex; align-items:center; gap:6px;
  text-decoration:none;
}
.adm-pill:hover { background: var(--gray-soft); color: var(--text); }
.adm-pill.active { background: var(--brand); color:#fff; border-color: var(--brand); }
.adm-iconbtn {
  width:36px; height:36px; border-radius:50%;
  background: var(--bg); border:1px solid var(--border);
  display:inline-flex; align-items:center; justify-content:center;
  color: var(--text); cursor:pointer; text-decoration:none;
}
.adm-iconbtn:hover { background: var(--gray-soft); color: var(--text); }
.adm-bell { position: relative; }
.adm-bell .adm-bell-badge {
  position: absolute;
  top: -4px; right: -4px;
  min-width: 18px; height: 18px; padding: 0 5px;
  background: linear-gradient(135deg,#ef4444,#b91c1c);
  color: #fff;
  font-size: 10px; font-weight: 800;
  line-height: 18px; text-align: center;
  border-radius: 999px;
  border: 2px solid var(--card-bg, #fff);
  box-shadow: 0 2px 6px rgba(239,68,68,.45);
  letter-spacing: .2px;
}
.adm-bell:has(.adm-bell-badge) .bi { color: #ef4444; animation: adm-bell-shake 1.6s ease-in-out infinite; transform-origin: top center; }
/* Star bell uses amber for "review needs attention" semantics */
.adm-bell-rating:has(.adm-bell-badge) .bi { color: #f59e0b; }
.adm-bell-rating .adm-bell-badge {
  background: linear-gradient(135deg,#f59e0b,#d97706);
  box-shadow: 0 2px 6px rgba(245,158,11,.45);
}
.adm-nav-badge { margin-left:auto; background:#ef4444; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:999px; line-height:1.4; min-width:18px; text-align:center; box-shadow:0 0 0 3px rgba(239,68,68,.18); animation: adm-bell-shake 1.6s ease-in-out infinite; transform-origin: center; }
.adm-sidebar .item { position: relative; display: flex; align-items: center; }
.adm-chat-toast { position:fixed; top:80px; right:22px; background:linear-gradient(135deg,#1e3a8a,#2563eb); color:#fff; padding:14px 18px; border-radius:12px; box-shadow:0 14px 30px rgba(15,23,42,.30); z-index:4000; max-width:340px; cursor:pointer; animation:adm-toast-in .25s cubic-bezier(.16,1,.3,1); }
.adm-chat-toast .ttl { font-weight:700; font-size:13px; margin-bottom:3px; display:flex; align-items:center; gap:6px; }
.adm-chat-toast .msg { font-size:12.5px; opacity:.95; line-height:1.4; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.adm-chat-toast .close { position:absolute; top:6px; right:8px; color:rgba(255,255,255,.7); cursor:pointer; font-size:14px; line-height:1; }
@keyframes adm-toast-in { from{opacity:0; transform:translateX(20px);} to{opacity:1; transform:translateX(0);} }
@keyframes adm-bell-shake {
  0%, 90%, 100% { transform: rotate(0); }
  92%, 96% { transform: rotate(-12deg); }
  94%, 98% { transform: rotate(12deg); }
}

.adm-dropdown { position:relative; }
.adm-dropdown-menu {
  position:absolute; right:0; top:calc(100% + 8px);
  background: var(--card-bg);
  border:1px solid var(--border);
  border-radius:12px;
  min-width: 220px;
  padding: 6px;
  box-shadow: 0 10px 28px rgba(0,0,0,.10);
  display:none;
  z-index: 2000;
}
.adm-dropdown.open .adm-dropdown-menu { display:block; }
.adm-dropdown-menu a {
  display:flex;align-items:center;gap:10px;
  padding:9px 12px; border-radius:8px;
  color: var(--text); text-decoration:none; font-size:13px;
}
.adm-dropdown-menu a:hover { background: var(--bg); }
.adm-dropdown-menu .sep { height:1px; background: var(--border); margin:4px 0; }

/* ============ LAYOUT ============ */
.adm-shell { display:flex; gap:22px; padding:22px; max-width: 1600px; margin: 0 auto; align-items: flex-start; }
.adm-sidebar {
  width: 230px; flex-shrink:0;
  background: var(--card-bg);
  border:1px solid var(--border); border-radius: 14px;
  padding: 12px 0;
  position: sticky; top: 84px;
  /* Cap the sidebar at viewport height and give it an isolated scroll —
     when the sidebar's own content overflows it scrolls internally,
     and `overscroll-behavior: contain` keeps the wheel/touch gesture
     from bubbling up to scroll the whole page behind it. */
  max-height: calc(100vh - 104px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
}
.adm-sidebar::-webkit-scrollbar { width: 6px; }
.adm-sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.adm-sidebar::-webkit-scrollbar-thumb:hover { background: var(--muted); }
.adm-sidebar .side-section {
  padding:8px 18px 6px;
  font-size:10px;letter-spacing:1.5px;color: var(--muted);
  text-transform:uppercase; font-weight:700;
}
/* Collapsible section toggle */
.adm-sidebar .side-toggle {
  width:100%; background:transparent; border:0; cursor:pointer;
  display:flex; align-items:center; justify-content:space-between; gap:8px;
  text-align:left; transition:color .12s ease;
}
.adm-sidebar .side-toggle:hover { color: var(--text); }
.adm-sidebar .side-toggle .side-caret { font-size:11px; transition:transform .18s ease; opacity:.7; }
.adm-sidebar .side-toggle.collapsed .side-caret { transform:rotate(-90deg); }
.adm-sidebar .side-group { overflow:hidden; transition:max-height .2s ease; }
.adm-sidebar .side-group.collapsed { display:none; }
.adm-sidebar .item {
  display:flex; align-items:center; gap:11px;
  padding:9px 18px;
  color: var(--text); font-size:13.5px; font-weight:500;
  text-decoration:none;
  border-left:3px solid transparent;
}
.adm-sidebar .item i { font-size:16px; width:18px; }
.adm-sidebar .item:hover { background: var(--bg); }
.adm-sidebar .item.active {
  background: var(--blue-soft);
  color: var(--brand-dk);
  border-left-color: var(--brand);
  font-weight: 700;
}
[data-bs-theme="dark"] .adm-sidebar .item.active { color:#93c5fd; }
.adm-content { flex:1; min-width:0; }

/* ============ CARDS / TABLES ============ */
.card-e {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.02);
  transition: box-shadow .25s ease, transform .15s ease, border-color .25s ease;
  position: relative;
  overflow: hidden;
  isolation: isolate;
}
/* Premium gradient outline — sits *outside* the card body so the border
   becomes a multi-stop teal→blue→violet glow on hover.  Uses ::before so
   we don't touch the existing border / padding tokens. */
.card-e::before {
  content: "";
  position: absolute;
  inset: -1px;
  border-radius: inherit;
  padding: 1px;
  background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 35%, #8b5cf6 70%, #ec4899 100%);
  -webkit-mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
  -webkit-mask-composite: xor;
          mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
          mask-composite: exclude;
  opacity: 0;
  transition: opacity .25s ease;
  pointer-events: none;
  z-index: 0;
}
.card-e:hover::before { opacity: .55; }
/* 4px brand-color left-accent bar on every card-e, drawn via ::after so
   the existing border doesn't need to change.  Subtle in light mode,
   sharper in dark mode for contrast. */
.card-e::after {
  content: "";
  position: absolute;
  left: 0; top: 12px; bottom: 12px;
  width: 4px; border-radius: 2px;
  background: linear-gradient(180deg, #0ea5e9, #1d4ed8 60%, #4338ca);
  opacity: .85;
  pointer-events: none;
  z-index: 0;
}
[data-bs-theme="dark"] .card-e::after { opacity: 1; box-shadow: 0 0 14px rgba(59,130,246,.45); }
/* Make sure the card's children sit above the ::before / ::after layers. */
.card-e > * { position: relative; z-index: 1; }
.card-e:hover { box-shadow: 0 8px 24px rgba(15,23,42,.10), 0 2px 5px rgba(15,23,42,.06); border-color: transparent; transform: translateY(-1px); }
[data-bs-theme="dark"] .card-e:hover { box-shadow: 0 8px 28px rgba(0,0,0,.45), 0 2px 5px rgba(0,0,0,.30); }
/* Opt-out modifier — let specific callouts (the blue "Where these
   details appear" / red SMTP banner / amber alignment notice) suppress
   the global accent bar and gradient outline so their own coloured
   borders aren't visually duplicated. */
.card-e.card-e--plain::before,
.card-e.card-e--plain::after { content: none; }

/* ---------- Callout / banner variants used across admin tabs ---------- */
.ci-where-card {
  background: linear-gradient(135deg, #eff6ff, #f0f9ff);
  border: 1px solid #bfdbfe !important;
  color: #1e3a8a;
}
.ci-where-card .small { color: #1e3a8a; }
[data-bs-theme="dark"] .ci-where-card {
  background: linear-gradient(135deg, rgba(30,64,175,.22), rgba(14,165,233,.16));
  border-color: rgba(96,165,250,.42) !important;
  color: #dbeafe;
}
[data-bs-theme="dark"] .ci-where-card .small { color: #dbeafe; }
[data-bs-theme="dark"] .ci-where-card strong { color: #93c5fd !important; }

.smtp-banner-critical, .emails-banner-critical {
  background: linear-gradient(90deg, #fee2e2 0%, #fef3c7 100%);
  border: 1px solid #fca5a5 !important;
  border-left: 5px solid #ef4444 !important;
  color: #7f1d1d;
}
[data-bs-theme="dark"] .smtp-banner-critical,
[data-bs-theme="dark"] .emails-banner-critical {
  background: linear-gradient(90deg, rgba(127,29,29,.32) 0%, rgba(120,53,15,.28) 100%);
  border-color: rgba(248,113,113,.55) !important;
  border-left-color: #f87171 !important;
  color: #fecaca;
}
[data-bs-theme="dark"] .smtp-banner-critical strong,
[data-bs-theme="dark"] .emails-banner-critical strong { color:#fecaca; }

.smtp-banner-warn {
  background: linear-gradient(90deg, #fef3c7 0%, #fefce8 100%);
  border: 1px solid #fcd34d !important;
  border-left: 5px solid #f59e0b !important;
  color: #78350f;
}
[data-bs-theme="dark"] .smtp-banner-warn {
  background: linear-gradient(90deg, rgba(120,53,15,.30) 0%, rgba(133,77,14,.20) 100%);
  border-color: rgba(252,211,77,.50) !important;
  border-left-color: #fbbf24 !important;
  color: #fde68a;
}
[data-bs-theme="dark"] .smtp-banner-warn strong { color: #fcd34d; }

.company-info-shell { border-left: 4px solid #3b82f6 !important; }
[data-bs-theme="dark"] .company-info-shell { border-left-color: #60a5fa !important; }

/* ---------- Modern drag-and-drop upload zone ---------- */
.dz-upload {
  position: relative;
  border: 2px dashed var(--border);
  border-radius: 14px;
  padding: 22px 18px;
  background: linear-gradient(135deg, rgba(14,165,233,.04), rgba(99,102,241,.04));
  transition: border-color .2s ease, background .2s ease, transform .2s ease;
}
[data-bs-theme="dark"] .dz-upload {
  background: linear-gradient(135deg, rgba(14,165,233,.10), rgba(99,102,241,.08));
  border-color: rgba(96,165,250,.30);
}
.dz-upload:hover, .dz-upload.dz-hover {
  border-color: #3b82f6;
  background: linear-gradient(135deg, rgba(14,165,233,.10), rgba(99,102,241,.12));
  transform: translateY(-1px);
}
.dz-upload.dz-dragover {
  border-color: #06b6d4;
  background: linear-gradient(135deg, rgba(6,182,212,.18), rgba(14,165,233,.18));
  box-shadow: 0 0 0 4px rgba(14,165,233,.18);
}
.dz-upload input[type="file"] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.dz-upload .dz-body {
  display: flex; gap: 16px; align-items: center; flex-wrap: wrap;
}
.dz-upload .dz-icon {
  width: 54px; height: 54px; flex-shrink: 0;
  background: linear-gradient(135deg, #0ea5e9, #1d4ed8);
  border-radius: 14px;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-size: 24px;
  box-shadow: 0 6px 18px rgba(29,78,216,.30);
}
.dz-upload .dz-label  { font-weight: 700; font-size: 14px; color: var(--text); }
.dz-upload .dz-hint   { font-size: 12px; color: var(--muted); margin-top: 2px; }
.dz-upload .dz-actions { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; position: relative; z-index: 2; }
.dz-upload .dz-btn {
  border: none; font-weight: 600; font-size: 13px;
  border-radius: 999px; padding: 7px 16px;
  display: inline-flex; align-items: center; gap: 6px;
  transition: filter .15s ease, transform .12s ease;
  cursor: pointer;
}
.dz-upload .dz-btn-primary { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; box-shadow: 0 4px 10px rgba(29,78,216,.30); }
.dz-upload .dz-btn-primary:hover { filter: brightness(1.05); transform: translateY(-1px); }
.dz-upload .dz-btn-ghost { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
.dz-upload .dz-btn-ghost:hover { background: var(--gray-soft); }
[data-bs-theme="dark"] .dz-upload .dz-btn-ghost { background: #334155; color:#e2e8f0; border-color:#475569; }
.dz-upload .dz-filename {
  font-size: 12px; color: var(--muted); max-width: 220px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.card-e .card-head {
  display:flex; align-items:center; justify-content:space-between;
  padding: 14px 18px; border-bottom: 1px solid var(--border);
}
.card-e .card-head .ttl { display:flex; align-items:center; gap:10px; font-weight:700; font-size:14px; color:var(--text); }
.card-e .card-head .ttl i { color: var(--brand); font-size:16px; }
.card-e .card-head .sub { font-size:11px; color: var(--muted); }
.card-e .card-body-p { padding: 18px; }

/* KPI tiles — premium */
.kpi-tile {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 18px 18px 16px;
  position: relative;
  overflow: hidden;
  transition: transform .15s ease, box-shadow .2s ease;
}
.kpi-tile:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,.08); }
.kpi-tile .kpi-icon {
  position:absolute; top:14px; right:14px;
  width:38px; height:38px; border-radius:10px;
  display:inline-flex; align-items:center; justify-content:center; font-size:18px;
}
.kpi-tile .kpi-label {
  font-size:11px; letter-spacing:1px; text-transform:uppercase;
  font-weight:700; color: var(--muted);
}
.kpi-tile .kpi-value { font-size:26px; font-weight:800; margin-top:6px; line-height:1.1; color: var(--text); }
.kpi-tile .kpi-delta { font-size:11px; font-weight:600; margin-top:6px; }
.kpi-tile.green  .kpi-icon { background:var(--green-soft); color:#047857; }  .kpi-tile.green  .kpi-value { color:#10b981; }
.kpi-tile.blue   .kpi-icon { background:var(--blue-soft);  color:#1d4ed8; }  .kpi-tile.blue   .kpi-value { color:#3b82f6; }
.kpi-tile.amber  .kpi-icon { background:var(--amber-soft); color:#92400e; }  .kpi-tile.amber  .kpi-value { color:#f59e0b; }
.kpi-tile.purple .kpi-icon { background:#ede9fe; color:#5b21b6; }            .kpi-tile.purple .kpi-value { color:#8b5cf6; }
.kpi-tile.red    .kpi-icon { background:var(--red-soft); color:#b91c1c; }    .kpi-tile.red    .kpi-value { color:#ef4444; }
.kpi-tile.cyan   .kpi-icon { background:#cffafe; color:#0e7490; }            .kpi-tile.cyan   .kpi-value { color:#06b6d4; }
[data-bs-theme="dark"] .kpi-tile.purple .kpi-icon { background:#312e81; color:#c4b5fd; }
[data-bs-theme="dark"] .kpi-tile.cyan   .kpi-icon { background:#155e75; color:#a5f3fc; }
[data-bs-theme="dark"] .kpi-tile.green  .kpi-icon { background:#064e3b; color:#6ee7b7; }
[data-bs-theme="dark"] .kpi-tile.blue   .kpi-icon { background:#1e3a8a; color:#93c5fd; }
[data-bs-theme="dark"] .kpi-tile.amber  .kpi-icon { background:#78350f; color:#fcd34d; }
[data-bs-theme="dark"] .kpi-tile.red    .kpi-icon { background:#7f1d1d; color:#fca5a5; }
/* Brighten the KPI numbers themselves so they pop in dark mode. */
[data-bs-theme="dark"] .kpi-tile.green  .kpi-value { color:#34d399 !important; }
[data-bs-theme="dark"] .kpi-tile.blue   .kpi-value { color:#60a5fa !important; }
[data-bs-theme="dark"] .kpi-tile.amber  .kpi-value { color:#fbbf24 !important; }
[data-bs-theme="dark"] .kpi-tile.red    .kpi-value { color:#f87171 !important; }
[data-bs-theme="dark"] .kpi-tile.purple .kpi-value { color:#a78bfa !important; }
[data-bs-theme="dark"] .kpi-tile.cyan   .kpi-value { color:#22d3ee !important; }

/* ---- Flatpickr calendar — dark-mode polish so the popup matches the
   rest of the admin (slate-700 card bg, slate-100 text, blue brand). */
.flatpickr-calendar { font-family: inherit; border-radius: 12px; box-shadow: 0 8px 28px rgba(15,23,42,.18); }
.flatpickr-calendar .flatpickr-day.selected,
.flatpickr-calendar .flatpickr-day.startRange,
.flatpickr-calendar .flatpickr-day.endRange { background: #3b82f6; border-color: #3b82f6; color:#fff; }
.flatpickr-calendar .flatpickr-day.today { border-color: #3b82f6; color: #3b82f6; }
.flatpickr-input.fp-enhanced + .form-control,
.flatpickr-input.fp-enhanced + .form-control-sm { background-color: var(--card-bg); color: var(--text); border-color: var(--border); cursor: pointer; }
[data-bs-theme="dark"] .flatpickr-calendar {
  background: #1e293b !important; color: #f1f5f9 !important;
  border: 1px solid #334155 !important;
}
[data-bs-theme="dark"] .flatpickr-calendar.arrowTop:before,
[data-bs-theme="dark"] .flatpickr-calendar.arrowBottom:before { border-bottom-color: #334155 !important; border-top-color: #334155 !important; }
[data-bs-theme="dark"] .flatpickr-calendar.arrowTop:after,
[data-bs-theme="dark"] .flatpickr-calendar.arrowBottom:after { border-bottom-color: #1e293b !important; border-top-color: #1e293b !important; }
[data-bs-theme="dark"] .flatpickr-months,
[data-bs-theme="dark"] .flatpickr-month,
[data-bs-theme="dark"] .flatpickr-current-month,
[data-bs-theme="dark"] .flatpickr-current-month input.cur-year,
[data-bs-theme="dark"] .flatpickr-monthDropdown-months,
[data-bs-theme="dark"] .flatpickr-weekday { color: #f1f5f9 !important; fill: #f1f5f9 !important; background: transparent !important; }
[data-bs-theme="dark"] .flatpickr-current-month .numInputWrapper span.arrowUp:after { border-bottom-color: #cbd5e1 !important; }
[data-bs-theme="dark"] .flatpickr-current-month .numInputWrapper span.arrowDown:after { border-top-color: #cbd5e1 !important; }
[data-bs-theme="dark"] .flatpickr-prev-month svg, [data-bs-theme="dark"] .flatpickr-next-month svg { fill: #cbd5e1 !important; }
[data-bs-theme="dark"] .flatpickr-prev-month:hover svg, [data-bs-theme="dark"] .flatpickr-next-month:hover svg { fill: #93c5fd !important; }
[data-bs-theme="dark"] .flatpickr-monthDropdown-months .flatpickr-monthDropdown-month { background: #1e293b !important; color:#f1f5f9 !important; }
[data-bs-theme="dark"] .flatpickr-day {
  color: #e2e8f0 !important; background: transparent !important; border-color: transparent !important;
}
[data-bs-theme="dark"] .flatpickr-day.prevMonthDay, [data-bs-theme="dark"] .flatpickr-day.nextMonthDay { color: #64748b !important; }
[data-bs-theme="dark"] .flatpickr-day:hover, [data-bs-theme="dark"] .flatpickr-day.inRange { background: rgba(59,130,246,.18) !important; border-color: rgba(96,165,250,.30) !important; color:#bfdbfe !important; }
[data-bs-theme="dark"] .flatpickr-day.flatpickr-disabled { color: #475569 !important; }
[data-bs-theme="dark"] .flatpickr-day.today { border-color: #3b82f6 !important; color: #93c5fd !important; }
[data-bs-theme="dark"] .flatpickr-day.selected, [data-bs-theme="dark"] .flatpickr-day.startRange, [data-bs-theme="dark"] .flatpickr-day.endRange { background:#3b82f6 !important; border-color:#3b82f6 !important; color:#fff !important; }
[data-bs-theme="dark"] .flatpickr-time, [data-bs-theme="dark"] .flatpickr-time .numInputWrapper input,
[data-bs-theme="dark"] .flatpickr-time .flatpickr-time-separator,
[data-bs-theme="dark"] .flatpickr-time .flatpickr-am-pm { color: #f1f5f9 !important; background: transparent !important; }
[data-bs-theme="dark"] .flatpickr-time input:hover, [data-bs-theme="dark"] .flatpickr-time .flatpickr-am-pm:hover { background: rgba(59,130,246,.18) !important; }
[data-bs-theme="dark"] .flatpickr-time { border-top: 1px solid #334155 !important; }

/* In-tile Chart.js sparkline (Revenue KPI) — slots between the value
   and the kpi-delta line.  `.has-spark` reserves the canvas height so
   the tile doesn't jump when Chart.js finishes drawing. */
.kpi-tile.has-spark { padding-bottom: 14px; }
.kpi-tile .kpi-spark {
  display: block;
  width: 100% !important;
  height: 42px !important;
  margin: 6px 0 2px;
  cursor: crosshair;
}
@media (max-width: 575px) {
  .kpi-tile .kpi-spark { height: 34px !important; }
}

/* Sparkline / mini chart bars */
.chart-bars { display:flex; align-items:end; gap:3px; height:140px; padding:6px 0; }
.chart-bars .b { flex:1; border-radius:5px 5px 0 0; background:linear-gradient(180deg, var(--brand) 0%, var(--brand-dk) 100%); min-width:5px; transition: opacity .15s; cursor:pointer; }
.chart-bars .b:hover { opacity:.75; }

/* Mini-list rows */
.mini-row {
  display:flex; align-items:center; gap:12px;
  padding: 10px 0; border-top: 1px solid var(--border);
}
.mini-row:first-child { border-top: none; }
.mini-row .rank {
  width:24px; height:24px; border-radius:50%;
  background: var(--blue-soft); color: var(--brand-dk);
  display:inline-flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700; flex-shrink:0;
}
.mini-row .thumb { width:32px; height:32px; object-fit:contain; background:var(--bg); border-radius:6px; padding:3px; flex-shrink:0; }

/* Progress bar */
.prog { height:6px; background:var(--bg); border-radius:3px; overflow:hidden; }
.prog > span { display:block; height:100%; background:linear-gradient(90deg,#10b981,#34d399); border-radius:3px; }
.prog.warn > span { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
.prog.danger > span { background:linear-gradient(90deg,#ef4444,#f87171); }

/* Funnel */
.funnel-row {
  display:flex; align-items:center; gap:12px;
  padding: 10px 0;
}
.funnel-bar {
  flex:1; height: 34px; border-radius:8px;
  background: var(--blue-soft);
  display:flex; align-items:center; padding: 0 12px;
  color: var(--brand-dk); font-weight:700; font-size:13px;
  position: relative; overflow:hidden;
}
.funnel-bar.green { background: var(--green-soft); color:#047857; }
.funnel-bar.amber { background: var(--amber-soft); color:#92400e; }
.funnel-bar.cyan  { background:#cffafe; color:#0e7490; }
.funnel-bar.purple{ background:#ede9fe; color:#5b21b6; }
[data-bs-theme="dark"] .funnel-bar.purple { background:#312e81; color:#c4b5fd; }
[data-bs-theme="dark"] .funnel-bar.cyan   { background:#155e75; color:#a5f3fc; }
.funnel-label { width: 110px; font-size:12px; color: var(--muted); }
.funnel-num { margin-left:auto; font-weight:800; font-size:14px; }
.tbl-e { background: var(--card-bg); border:1px solid var(--border); border-radius: 12px; overflow:hidden; }
.tbl-e table { margin:0; color: var(--text); }
.tbl-e thead th { background: var(--bg); color: var(--muted); text-transform:uppercase; font-size:11px; letter-spacing:.7px; font-weight:600; padding:11px 14px; border:none; }
.tbl-e tbody td { padding:12px 14px; border-top:1px solid var(--border); vertical-align: middle; font-size:13.5px; }
.tbl-e tbody tr:hover { background: var(--bg); }

/* ============ BADGES / BUTTONS ============ */
.s-badge { display:inline-block; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600; white-space: nowrap; }
.s-badge.queued, .s-badge.new      { background: var(--amber-soft); color:#92400e; }
.s-badge.sent, .s-badge.contacted  { background: var(--blue-soft); color:#1d4ed8; }
.s-badge.delivered, .s-badge.paid, .s-badge.qualified, .s-badge.active, .s-badge.opened { background: var(--green-soft); color:#047857; }
.s-badge.failed, .s-badge.lost, .s-badge.refunded, .s-badge.cancelled, .s-badge.inactive { background: var(--red-soft); color:#b91c1c; }
.s-badge.converted { background:#cffafe; color:#0e7490; }

.btn-soft-gray  { background: var(--gray-soft);  color: var(--text);    border:none; }
.btn-soft-green { background: var(--green-soft); color:#047857;         border:none; }
.btn-soft-blue  { background: var(--blue-soft);  color: var(--brand-dk);border:none; }
.btn-soft-red   { background: var(--red-soft);   color:#b91c1c;         border:none; }
.btn-soft-gray:hover, .btn-soft-blue:hover, .btn-soft-green:hover, .btn-soft-red:hover { filter: brightness(.95); }

.btn-add-glow {
  background: linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);
  color:#fff; border:none; border-radius:50%;
  width:48px; height:48px; font-size:22px;
  display:inline-flex; align-items:center; justify-content:center;
  box-shadow:0 0 0 0 rgba(59,130,246,.6);
  animation: glowpulse 2s infinite;
}
.btn-add-glow:hover { transform: scale(1.06); color:#fff; }
@keyframes glowpulse {
  0%,100% { box-shadow:0 0 0 0 rgba(59,130,246,.55),0 4px 12px rgba(59,130,246,.35); }
  50%     { box-shadow:0 0 0 12px rgba(59,130,246,0),0 4px 12px rgba(59,130,246,.35); }
}

.key-stats { display:flex; gap:10px; }
.key-stats .key-pill { flex:1; background: var(--card-bg); border:1px solid var(--border); border-radius:10px; padding:12px 14px; text-align:center; }
.key-stats .key-pill .num { font-size:22px; font-weight:700; }
.key-stats .key-pill .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color: var(--muted); margin-top:3px;}
.key-stats .key-pill.avail { border-left:4px solid var(--green); }
.key-stats .key-pill.sold  { border-left:4px solid var(--brand); }

/* ============ VIBE PERFORMANCE WIDGET ============ */
.vh-range-bar { flex-wrap: wrap; }
.vh-range-bar input[type="date"] { min-width: 140px; }
.vh-quick { display: flex; gap: 4px; padding-bottom: 1px; }
.vh-quick-pill {
  font-size: 11.5px; font-weight: 600;
  padding: 5px 10px; border-radius: 999px;
  background: var(--bg); border: 1px solid var(--border); color: var(--text);
  text-decoration: none; transition: background .15s ease, border-color .15s ease;
}
.vh-quick-pill:hover { background: var(--gray-soft); }
.vh-quick-pill.active { background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-color: transparent; color: #fff; box-shadow: 0 2px 6px rgba(29,78,216,.30); }
[data-bs-theme="dark"] .vh-quick-pill { background:#334155; border-color:#475569; }
[data-bs-theme="dark"] .vh-quick-pill:hover { background:#475569; }

.vh-insight {
  display: flex; gap: 10px; align-items: center;
  padding: 10px 14px;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--vibe-g0), var(--vibe-g1) 55%, var(--vibe-g2));
  color: #fff;
  font-size: 13px;
  box-shadow: 0 4px 14px rgba(15,23,42,.10);
}
.vh-insight .bi { font-size: 18px; color: #fef08a; }

.vh-bars {
  display: flex; align-items: flex-end;
  gap: 2px;
  height: 110px;
  padding: 6px 4px 0;
  background: linear-gradient(180deg, transparent 0%, rgba(15,23,42,.03) 100%);
  border-radius: 10px;
  border: 1px solid var(--border);
}
.vh-bar-wrap { flex: 1; height: 100%; display: flex; align-items: flex-end; min-width: 4px; }
.vh-bar {
  width: 100%; border-radius: 3px 3px 0 0;
  transition: transform .15s ease, filter .15s ease;
  cursor: help;
  min-height: 3px;
}
.vh-bar-wrap:hover .vh-bar { filter: brightness(1.15); transform: scaleY(1.04); transform-origin: bottom; }
.vh-axis { display: flex; padding: 4px 6px 0; font-size: 11px; }
[data-bs-theme="dark"] .vh-bars { background: rgba(255,255,255,.03); }

.vh-vibe-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
  gap: 10px;
}
.vh-vibe-card {
  padding: 12px 14px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 12px;
  position: relative;
  overflow: hidden;
}
.vh-vibe-card::before {
  content: "";
  position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
  background: linear-gradient(180deg, var(--vibe-g0), var(--vibe-g1) 55%, var(--vibe-g2));
}
.vh-vibe-card.is-dim { opacity: .45; }
[data-bs-theme="dark"] .vh-vibe-card { background:#1e293b; }
.vh-vibe-card-head { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.vh-vibe-dot {
  width: 10px; height: 10px; border-radius: 50%;
  background: var(--vibe-accent);
  box-shadow: 0 0 0 3px rgba(0,0,0,.06);
}
[data-bs-theme="dark"] .vh-vibe-dot { box-shadow: 0 0 0 3px rgba(255,255,255,.08); }
.vh-vibe-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 8px; }
.vh-vibe-stats > div { display: flex; flex-direction: column; }
.vh-stat-n { font-weight: 700; font-size: 14px; color: var(--text); line-height: 1.2; }
.vh-stat-n.vh-stat-accent { color: var(--vibe-accent); }
.vh-vibe-stats small { font-size: 10.5px; color: var(--muted); letter-spacing: .5px; text-transform: uppercase; }

a { color: var(--brand-dk); }
a:hover { color: var(--brand); }
.form-control, .form-select { background: var(--card-bg); color: var(--text); border-color: var(--border); }
.form-control:focus, .form-select:focus { background: var(--card-bg); color: var(--text); }
hr { border-color: var(--border); opacity:.5; }

/* ============ TIMELINE ============ */
.timeline { padding-left:0; list-style:none; }
.timeline li { position:relative; padding:10px 0 10px 36px; border-left:2px solid var(--border); margin-left:12px; }
.timeline li::before {
  content:''; position:absolute; left:-9px; top:14px;
  width:16px;height:16px;border-radius:50%;
  background: var(--gray-soft); border:3px solid var(--card-bg);
}
.timeline li.done::before { background: var(--green); }
.timeline li.fail::before { background: var(--red); }
.timeline li .ttitle { font-weight:600; }
.timeline li .tdate { font-size:11px; color: var(--muted); }

/* ============ INSTALL GUIDE STEPS ============ */
.step-card { display:flex; gap:14px; padding:14px 16px; background: var(--card-bg); border:1px solid var(--border); border-radius:10px; margin-bottom:10px; }
.step-num {
  width:38px; height:38px; flex-shrink:0;
  border-radius:50%; background: linear-gradient(135deg,#3b82f6,#1d4ed8);
  color:#fff; display:inline-flex; align-items:center; justify-content:center;
  font-weight:700; font-size:14px;
}
.step-icon {
  width:42px; height:42px; flex-shrink:0;
  border-radius:10px; background: var(--blue-soft); color: var(--brand-dk);
  display:inline-flex; align-items:center; justify-content:center; font-size:20px;
}
.step-body .ttitle { font-weight:700; margin-bottom:2px; }
.step-body small { color: var(--muted); }

@media (max-width: 991px) {
  .adm-top .brand-center { position:static; transform:none; }
  .adm-top .brand-center small { display:none; }
  .adm-shell { flex-direction:column; padding:14px; gap:14px; }
  .adm-sidebar { width:260px; position:fixed; top:0; left:-280px; height:100vh; z-index:2500; border-radius:0; padding-top:60px; transition:left .25s ease; box-shadow:0 0 20px rgba(0,0,0,.25); overflow-y:auto; }
  .adm-sidebar.open { left:0; }
  .adm-sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2400; }
  .adm-sidebar.open ~ .adm-sidebar-overlay,
  .adm-sidebar.open + .adm-sidebar-overlay { display:block; }
  .adm-hamburger { display:inline-flex !important; }
  .adm-pill { padding:5px 9px; font-size:11px; }
  .adm-pill .ms-1 { display:none; }
}
.adm-hamburger {
  display:none;
  width:36px; height:36px; border-radius:9px;
  background: var(--bg); border:1px solid var(--border);
  align-items:center; justify-content:center;
  color: var(--text); cursor:pointer; font-size:20px;
}
.adm-hamburger:hover { background: var(--gray-soft); }

/* Mobile-friendly tables (admin) */
@media (max-width: 768px) {
  .tbl-e { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .tbl-e table { min-width: 600px; }
  .card-e { border-radius:10px; }
  .card-e.p-3 { padding:12px !important; }
  .kpi-tile { padding:14px 14px 12px; }
  .kpi-tile .kpi-value { font-size:20px; }
  .row.g-3 > [class^="col-"], .row.g-4 > [class^="col-"] { margin-bottom:8px; }
  h5.fw-bold { font-size:16px; }

  /* Adm-content padding tightens up on small screens */
  .adm-content { padding: 12px !important; }
  .adm-top { padding: 0 12px; }

  /* Topbar — collapse the verbose widgets so the icon row never overflows */
  .adm-top .right { gap: 6px; }
  .adm-top .right [data-testid="adm-gw-mode-pill"] { display:none !important; }
  .adm-top .right .adm-install-label { display:none !important; }
  .adm-top .right #ddRegion .adm-pill { font-size:0 !important; padding:6px 8px !important; }
  .adm-top .right #ddRegion .adm-pill i { font-size:14px !important; }
  .adm-top .right .adm-iconbtn { width:34px; height:34px; }

  /* All filter pills & toolbars wrap nicely without horizontal scroll */
  .nav.nav-pills { flex-wrap:wrap !important; }
  .vis-filter-bar { padding: 8px 10px; gap: 6px; }
  .vis-filter-group { flex: 1 1 100%; }
  .vis-filter-group:last-child { justify-content: flex-start; }
  .vis-filter-group input[type="date"], .vis-filter-group select { max-width: 100%; flex: 1; }

  /* Email-activity cards stack their meta + buttons on small screens */
  .ec-head { flex-direction: column; align-items: flex-start; gap: 6px; }
  .ec-actions { flex-wrap: wrap; gap: 6px; }
  .ec-actions .btn { font-size: 11.5px; padding: 5px 10px; }

  /* Lead-management table cells wrap content rather than overflowing */
  table.table { font-size: 12.5px; }
  table.table td, table.table th { padding: 8px 6px; }
}

/* Extra-narrow phones — go even tighter */
@media (max-width: 480px) {
  .adm-content { padding: 8px !important; }
  .adm-top .adm-brand-cp { font-size: 9px !important; letter-spacing: 1px !important; }
  .vrange-pills, .vrange-pill { font-size: 11px; }
  .vis-num { font-size: 30px; }
  .vis-flag-chip { font-size: 11px; padding: 4px 8px; }
  .ec-actions .btn span:not(.spinner-border) { display:inline; }
  /* On tiny screens drop the secondary widgets so the essential
     hamburger + activity bell + theme + avatar always fit on one row. */
  .adm-top .right #ddRegion,
  .adm-top .right .adm-bell-rating,
  .adm-top .right .adm-install-btn { display:none !important; }
  .adm-top .right { gap: 4px; }
  .adm-top { padding: 0 8px; }
}

/* ============ MOBILE OVERFLOW + DARK-MODE READABILITY ============
   Fixes the "background scrolls instead of menu" bug + ensures all
   text in dark-mode stays high-contrast on phones.
*/
@media (max-width: 991px) {
  /* Cards never push past the viewport.  Forces .row.g-3 children to
     respect the screen width so KPI tiles no longer clip on the right. */
  .adm-content { max-width: 100%; box-sizing: border-box; }
  .adm-content .row { margin-left: 0 !important; margin-right: 0 !important; }
  .adm-content .row > [class^="col-"], .adm-content .row > [class*=" col-"] { padding-left: 6px; padding-right: 6px; }
  .card-e, .kpi-tile { max-width: 100%; box-sizing: border-box; }

  /* The .adm-shell + .adm-content are inside body which already has
     overflow-x:hidden, but explicitly clip here for safety so admins
     don't see the empty horizontal gutter on iOS Safari. */
  .adm-shell { overflow-x: clip; width: 100%; }
}
@media (max-width: 768px) {
  /* Background floating-icon layer becomes too noisy on small screens
     in dark mode — keep it subtle for better content readability. */
  .adm-floats i { font-size: 38px; opacity: 0.10; }
  [data-bs-theme="dark"] .adm-floats i { opacity: 0.12; }

  /* Dark-mode high-contrast text on phones (the KPI label & sub-headings
     previously appeared washed-out on small AMOLED panels). */
  [data-bs-theme="dark"] .kpi-tile .kpi-label,
  [data-bs-theme="dark"] .text-muted,
  [data-bs-theme="dark"] small.text-muted { color: #e2e8f0 !important; }
  [data-bs-theme="dark"] .card-e { background: #1f2a3d; }
  [data-bs-theme="dark"] .adm-top { background: #1e293b; }
  [data-bs-theme="dark"] .adm-sidebar { background: #1f2a3d; }

  /* Topbar pills/icons get bigger tap targets and clear background fills
     so they don't blend into the navy bar on mobile dark mode. */
  [data-bs-theme="dark"] .adm-iconbtn { background: #334155; border-color:#475569; color:#f1f5f9; }
  [data-bs-theme="dark"] .adm-iconbtn:hover { background:#475569; }
  [data-bs-theme="dark"] .adm-pill { background:#334155; border-color:#475569; color:#f1f5f9; }
}
</style>
</head>
<body class="adm" data-brand-motion="<?= esc(setting_get('company_logo_motion', 'bounce')) ?>" data-brand-vibe="<?= esc(setting_get('company_brand_vibe', 'classic')) ?>">

<!-- ============================================================
     Floating tech icons — real product-style icons drift across
     the background.  Faster animation, bigger size, real colours.
     ============================================================ -->
<div class="adm-floats" aria-hidden="true" data-testid="adm-floats">
  <i class="bi bi-windows      ic-win"    style="left:5%;  top:8%;  animation-delay: 0s;"></i>
  <i class="bi bi-microsoft    ic-office" style="left:18%; top:62%; animation-delay: -2s;"></i>
  <i class="bi bi-shield-lock  ic-shield" style="left:32%; top:18%; animation-delay: -4s;"></i>
  <i class="bi bi-key-fill     ic-key"    style="left:46%; top:75%; animation-delay: -6s;"></i>
  <i class="bi bi-cloud-fill   ic-cloud"  style="left:60%; top:30%; animation-delay: -1s;"></i>
  <i class="bi bi-laptop       ic-win"    style="left:74%; top:55%; animation-delay: -3s;"></i>
  <i class="bi bi-fingerprint  ic-shield" style="left:88%; top:12%; animation-delay: -5s;"></i>
  <i class="bi bi-cpu-fill     ic-cpu"    style="left:10%; top:42%; animation-delay: -7s;"></i>
  <i class="bi bi-envelope-paper ic-mail" style="left:28%; top:88%; animation-delay: -8s;"></i>
  <i class="bi bi-bag-check    ic-card"   style="left:52%; top:8%;  animation-delay: -9s;"></i>
  <i class="bi bi-graph-up     ic-cpu"    style="left:68%; top:85%; animation-delay: -10s;"></i>
  <i class="bi bi-globe2       ic-globe"  style="left:82%; top:38%; animation-delay: -11s;"></i>
  <i class="bi bi-credit-card-2-front ic-card" style="left:38%; top:48%; animation-delay: -12s;"></i>
  <i class="bi bi-bell-fill    ic-bell"   style="left:90%; top:72%; animation-delay: -13s;"></i>
  <i class="bi bi-apple        ic-apple"  style="left:2%;  top:78%; animation-delay: -14s;"></i>
  <i class="bi bi-android2     ic-droid"  style="left:42%; top:32%; animation-delay: -15s;"></i>
  <i class="bi bi-shield-check ic-shield" style="left:65%; top:65%; animation-delay: -2.5s;"></i>
  <i class="bi bi-window-stack ic-win"    style="left:22%; top:25%; animation-delay: -4.5s;"></i>
</div>

<header class="adm-top" data-testid="adm-topbar">
  <div class="left">
    <button class="adm-hamburger" data-testid="sidebar-toggle" onclick="document.querySelector('.adm-sidebar').classList.toggle('open')" title="Menu">
      <i class="bi bi-list"></i>
    </button>
  </div>

  <div class="brand-center logo-3d" data-testid="adm-brand">
    <?php if ($adm_brand_logo !== ''): ?>
      <img src="<?= esc($adm_brand_logo) ?>" alt="<?= esc($adm_brand_name) ?>" class="m-logo-img" style="height:34px;width:auto;max-width:120px;object-fit:contain;border-radius:9px;">
    <?php else: ?>
      <span class="m-logo brand-mark" data-testid="adm-brand-letter"><?= esc($adm_brand_letter) ?></span>
    <?php endif; ?>
    <div>
      <small class="adm-brand-cp">ADMIN CONTROL PANEL</small>
    </div>
  </div>

  <div class="right">
    <?php $gwModeNow = setting_get('gw_mode', 'test'); ?>
    <?php if ($gwModeNow !== 'live'): ?>
      <a href="?tab=api&gw=toggles" class="adm-pill" title="Currently in Test mode — no real payments are processed. Click to switch to Live." data-testid="adm-gw-mode-pill"
         style="background:linear-gradient(135deg,#f59e0b,#ea580c);color:#fff;font-weight:700;letter-spacing:.8px;text-transform:uppercase;font-size:11px;border:0;">
        <i class="bi bi-flask"></i> Test mode
      </a>
    <?php else: ?>
      <a href="?tab=api&gw=toggles" class="adm-pill" title="Live mode — real payments are being processed." data-testid="adm-gw-mode-pill"
         style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;letter-spacing:.8px;text-transform:uppercase;font-size:11px;border:0;">
        <i class="bi bi-broadcast"></i> Live
      </a>
    <?php endif; ?>
    <div class="adm-dropdown" id="ddRegion" data-testid="region-dropdown">
      <button class="adm-pill" onclick="document.getElementById('ddRegion').classList.toggle('open')" title="Switch region / currency">
        <i class="bi bi-globe"></i> <?= esc($rg['code']) ?> · <?= esc($rg['currency_symbol']) ?>
        <i class="bi bi-chevron-down ms-1"></i>
      </button>
      <div class="adm-dropdown-menu">
        <?php foreach (all_regions() as $r): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['region' => $r['code']])) ?>" data-testid="region-<?= esc($r['code']) ?>">
            <i class="bi bi-flag<?= $r['code']===$rg['code']?'-fill text-primary':'' ?>"></i>
            <div><div class="fw-semibold"><?= esc($r['name']) ?></div><small class="text-muted"><?= esc($r['currency_symbol']) ?> <?= esc($r['currency']) ?> · Tax <?= number_format($r['tax_rate']*100,1) ?>%</small></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- PWA install button — ALWAYS visible.  Clicking it triggers the
         browser's native install prompt when available, or shows a clear
         per-platform "Add to Home Screen" instruction modal otherwise.
         Hidden ONLY when the admin is already running INSIDE the PWA
         (standalone display-mode), because installing the installed app
         a second time makes no sense. -->
    <button class="adm-iconbtn adm-install-btn" id="admInstallBtn"
            title="Install Maventech Admin as an app on this device"
            data-testid="adm-install-btn">
      <i class="bi bi-download"></i>
      <span class="adm-install-label">Install App</span>
    </button>

    <!-- Activity bell — pushes notifications for new orders, leads,
         reviews, installs, emails, sales-detail, templates. -->
    <div class="adm-dropdown" id="ddActivity" data-testid="adm-activity-dropdown">
      <button class="adm-iconbtn adm-bell adm-bell-activity"
              onclick="document.getElementById('ddActivity').classList.toggle('open'); markActivityRead();"
              title="Activity — orders, leads, reviews, installs, emails"
              data-testid="adm-bell-activity">
        <i class="bi bi-broadcast-pin"></i>
        <span class="adm-bell-badge d-none" id="admActivityBadge" data-testid="adm-activity-badge">0</span>
      </button>
      <div class="adm-dropdown-menu" style="min-width:340px;max-width:90vw;max-height:480px;overflow-y:auto;padding:6px 0;">
        <div class="px-3 py-2 d-flex justify-content-between align-items-center" style="border-bottom:1px solid var(--border);">
          <strong style="font-size:13px;">Activity</strong>
          <div class="d-flex align-items-center gap-2">
            <button class="adm-bell-mute" id="admBellMuteBtn" type="button" title="Toggle notification sound" data-testid="adm-bell-mute">
              <i class="bi bi-volume-up-fill" id="admBellMuteIcon"></i> <span id="admBellMuteLabel">Sound on</span>
            </button>
            <a href="#" class="text-decoration-none" style="font-size:11.5px;" onclick="event.preventDefault();markActivityRead(true);return false;" data-testid="adm-activity-mark-all">Mark all read</a>
          </div>
        </div>
        <div id="admActivityList" style="font-size:13px;">
          <div class="text-center py-4 text-muted" style="font-size:12px;">
            <i class="bi bi-broadcast-pin d-block mb-2" style="font-size:24px;"></i>
            <em>Listening for activity…</em>
          </div>
        </div>
      </div>
    </div>

    <?php
    // Compute failed email count for the notification bell — scoped to
    // POST-PURCHASE emails only (license delivery, order confirmation,
    // payment pending, refund) so the bell never alerts on review-request
    // or marketing failures. Matches the Email Activity Center scope.
    try {
        $failedCount = (int)db()->query("SELECT COUNT(*) FROM email_outbox
            WHERE status IN ('failed','bounced')
              AND template_code IN ('order_delivery','order_confirmation','order_pending','refund_confirm')")->fetchColumn();
    } catch (Throwable $e) { $failedCount = 0; }
    // Lead Management sidebar badge — counts leads that NEED ATTENTION so a
    // tiny red sign appears the moment anyone inquires: any lead with unread
    // customer messages, OR a brand-new callback/ProAssist lead the admin
    // hasn't opened yet (admin_seen_at IS NULL).
    try {
        $chatUnread = (int)db()->query("
            SELECT COUNT(*) FROM chat_leads l
            WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.lead_id=l.id AND m.sender='customer' AND m.read_at IS NULL)
               OR (l.callback_requested=1 AND l.admin_seen_at IS NULL)
        ")->fetchColumn();
    } catch (Throwable $e) { $chatUnread = 0; }
    // Unhappy-customer alerts — reviews of 3 stars or less that the admin
    // hasn't acknowledged yet.  Drives the star-shaped notification bell.
    try {
        $lowRatingUnread = (int)db()->query("SELECT COUNT(*) FROM customer_reviews WHERE rating IS NOT NULL AND rating <= 3 AND admin_seen_at IS NULL")->fetchColumn();
    } catch (Throwable $e) { $lowRatingUnread = 0; }
    // Install-Schedule pending count — drives the sidebar badge AND the
    // bell icon turns red when a customer just booked a ProAssist install
    // that the team hasn't dispatched yet.  Threshold: status='pending' AND
    // the scheduled_utc is still in the future OR within the last 60 min
    // so even just-missed slots stay visible.
    try {
        $installPending = (int)db()->query("SELECT COUNT(*) FROM proassist_schedules
            WHERE status='pending'
              AND scheduled_utc >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)")->fetchColumn();
    } catch (Throwable $e) { $installPending = 0; }
    ?>
    <a class="adm-iconbtn adm-bell adm-bell-rating" href="admin.php?tab=reviews&status=hidden" title="<?= $lowRatingUnread?($lowRatingUnread.' new low-rating review(s) — needs attention'):'No new low-rating reviews' ?>" data-testid="adm-bell-rating">
      <i class="bi bi-star<?= $lowRatingUnread?'-fill':'' ?>"></i>
      <?php if ($lowRatingUnread > 0): ?>
        <span class="adm-bell-badge" data-testid="adm-bell-rating-badge"><?= $lowRatingUnread > 99 ? '99+' : $lowRatingUnread ?></span>
      <?php endif; ?>
    </a>
    <a class="adm-iconbtn adm-bell" href="admin.php?tab=emails&filter=failed" title="<?= $failedCount?($failedCount.' failed email(s) need attention'):'No failed emails' ?>" data-testid="adm-bell">
      <i class="bi bi-bell<?= $failedCount?'-fill':'' ?>"></i>
      <?php if ($failedCount > 0): ?>
        <span class="adm-bell-badge" data-testid="adm-bell-badge"><?= $failedCount > 99 ? '99+' : $failedCount ?></span>
      <?php endif; ?>
    </a>
    <a class="adm-iconbtn" href="#" title="Toggle theme" data-testid="theme-toggle" onclick="toggleAdmTheme(event)">
      <i id="admThemeIcon" class="bi <?= $adminMode==='dark'?'bi-sun':'bi-moon-stars' ?>"></i>
    </a>
    <div class="adm-dropdown" id="ddUser">
      <button class="adm-iconbtn" onclick="document.getElementById('ddUser').classList.toggle('open')" data-testid="user-menu">
        <i class="bi bi-person-circle"></i>
      </button>
      <div class="adm-dropdown-menu">
        <a href="#" style="pointer-events:none;">
          <i class="bi bi-person"></i>
          <div><div style="font-weight:600;"><?= esc($admin['email'] ?? '—') ?></div><small class="text-muted">Administrator</small></div>
        </a>
        <div class="sep"></div>
        <a href="admin.php?tab=settings"><i class="bi bi-gear"></i> Settings</a>
        <a href="admin.php?tab=api"><i class="bi bi-plug"></i> API Management</a>
        <div class="sep"></div>
        <a href="logout.php" data-testid="user-logout"><i class="bi bi-box-arrow-right text-danger"></i> Sign out</a>
      </div>
    </div>
  </div>
</header>

<?php
// Theme toggle is now handled at the top of this file BEFORE HTML output.
?>

<div class="adm-shell">
  <aside class="adm-sidebar" data-testid="adm-sidebar">
    <?php
      // Sidebar respects per-user permissions — staff only see panels they
      // have been granted (the super admin sees everything).
      $navGroups = [
        'Overview'      => ['dashboard','users','subscription','ai-blogger','company','regions','inventory'],
        'Catalog'       => ['products'],
        'Commerce'      => ['orders','sales','leads','schedule'],
        'Communication' => ['emails','reviews','templates'],
        'System'        => ['gateways','smtp'],
      ];
      // Which section contains the currently-active panel (kept open on load).
      $activeSectionId = '';
      foreach ($navGroups as $secName => $ks) {
        if (in_array($adminActive, $ks, true)) { $activeSectionId = preg_replace('/[^a-z0-9]+/', '-', strtolower($secName)); break; }
      }
    ?>
    <?php foreach ($navGroups as $secName => $secKeys): ?>
      <?php $allowed = array_values(array_filter($secKeys, fn($k) => admin_can($k))); if (!$allowed) continue; ?>
      <?php $secId = preg_replace('/[^a-z0-9]+/', '-', strtolower($secName)); ?>
      <button type="button" class="side-section side-toggle" data-section="<?= esc($secId) ?>" data-testid="side-section-<?= esc($secId) ?>" aria-expanded="true" onclick="admToggleSection('<?= esc($secId) ?>')">
        <span><?= esc($secName) ?></span><i class="bi bi-chevron-down side-caret"></i>
      </button>
      <div class="side-group" id="side-group-<?= esc($secId) ?>" data-section-items="<?= esc($secId) ?>">
        <?php foreach ($allowed as $k): $i = $navItems[$k]; ?>
          <a class="item <?= $adminActive===$k?'active':'' ?>" href="<?= esc($i['href']) ?>" data-testid="adm-nav-<?= $k ?>">
            <i class="bi <?= esc($i['icon']) ?>"></i><?= esc($i['label']) ?>
            <?php if ($k === 'ai-blogger'): ?>
              <span class="adm-nav-badge" style="background:#7c3aed;box-shadow:none;animation:none;letter-spacing:.4px;">AUTO</span>
            <?php elseif ($k==='leads' && $chatUnread > 0): ?>
              <span class="adm-nav-badge" id="navChatBadge" data-testid="adm-nav-leads-badge"><?= $chatUnread > 99 ? '99+' : $chatUnread ?></span>
            <?php elseif ($k==='leads'): ?>
              <span class="adm-nav-badge" id="navChatBadge" data-testid="adm-nav-leads-badge" style="display:none;">0</span>
            <?php elseif ($k==='schedule' && $installPending > 0): ?>
              <span class="adm-nav-badge" id="navInstallBadge" data-testid="adm-nav-schedule-badge"><?= $installPending > 99 ? '99+' : $installPending ?></span>
            <?php elseif ($k==='schedule'): ?>
              <span class="adm-nav-badge" id="navInstallBadge" data-testid="adm-nav-schedule-badge" style="display:none;">0</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>
  <script>
  // Collapsible sidebar sections — state persisted per section in localStorage.
  function admToggleSection(id){
    var g=document.getElementById('side-group-'+id);
    var b=document.querySelector('.side-toggle[data-section="'+id+'"]');
    if(!g||!b) return;
    var collapsed=g.classList.toggle('collapsed');
    b.classList.toggle('collapsed', collapsed);
    b.setAttribute('aria-expanded', collapsed?'false':'true');
    try{ var s=JSON.parse(localStorage.getItem('adm_nav_collapsed')||'{}'); s[id]=collapsed; localStorage.setItem('adm_nav_collapsed', JSON.stringify(s)); }catch(e){}
  }
  (function(){
    var activeSection = <?= json_encode($activeSectionId) ?>;
    var s={}; try{ s=JSON.parse(localStorage.getItem('adm_nav_collapsed')||'{}'); }catch(e){}
    Object.keys(s).forEach(function(id){
      if(s[id] && id!==activeSection){
        var g=document.getElementById('side-group-'+id), b=document.querySelector('.side-toggle[data-section="'+id+'"]');
        if(g) g.classList.add('collapsed'); if(b){ b.classList.add('collapsed'); b.setAttribute('aria-expanded','false'); }
      }
    });
  })();
  </script>
  <div class="adm-sidebar-overlay" onclick="document.querySelector('.adm-sidebar').classList.remove('open')"></div>

  <!-- ===================== STAFF CONSOLE WIDGET ===================== -->
  <style>
    .staff-chat-btn { position:fixed; right:20px; bottom:20px; z-index:3000; width:54px; height:54px; border-radius:50%;
      border:0; background:linear-gradient(135deg,#1d4ed8,#06b6d4); color:#fff; font-size:22px; cursor:pointer;
      box-shadow:0 10px 28px rgba(29,78,216,.45); display:inline-flex; align-items:center; justify-content:center; transition:transform .15s ease; }
    .staff-chat-btn:hover { transform:scale(1.06); }
    .staff-chat-badge { position:absolute; top:-3px; right:-3px; min-width:20px; height:20px; padding:0 5px; border-radius:999px;
      background:#ef4444; color:#fff; font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px #fff; }
    .staff-chat-panel { position:fixed; right:20px; bottom:84px; z-index:3000; width:360px; max-width:calc(100vw - 32px); height:520px; max-height:calc(100vh - 120px);
      background:var(--card-bg,#fff); border:1px solid var(--border,#e2e8f0); border-radius:16px; box-shadow:0 18px 48px rgba(2,6,23,.3);
      display:none; flex-direction:column; overflow:hidden; }
    .staff-chat-panel.open { display:flex; }
    .scp-head { background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; }
    .scp-title { font-weight:700; font-size:14px; }
    .scp-x { background:transparent; border:0; color:#fff; font-size:16px; cursor:pointer; opacity:.85; }
    .scp-tabs { display:flex; border-bottom:1px solid var(--border,#e2e8f0); }
    .scp-tab { flex:1; background:transparent; border:0; padding:9px 8px; font-size:12.5px; font-weight:600; color:var(--text-soft,#64748b); cursor:pointer; border-bottom:2px solid transparent; }
    .scp-tab.active { color:#1d4ed8; border-bottom-color:#1d4ed8; }
    .scp-count { background:#e2e8f0; color:#334155; border-radius:999px; font-size:10px; padding:1px 6px; margin-left:3px; }
    .scp-body { flex:1; overflow:hidden; display:flex; flex-direction:column; }
    .scp-list { flex:1; overflow-y:auto; padding:6px; }
    .scp-item { display:flex; gap:8px; padding:9px 8px; border-radius:10px; cursor:pointer; align-items:flex-start; }
    .scp-item:hover { background:var(--bg,#f1f5f9); }
    .scp-ava { width:32px; height:32px; border-radius:50%; background:#dbeafe; color:#1e3a8a; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
    .scp-item-body { flex:1; min-width:0; }
    .scp-item-name { font-size:12.5px; font-weight:700; color:var(--text,#0f172a); display:flex; align-items:center; gap:5px; }
    .scp-item-msg { font-size:11.5px; color:var(--text-soft,#64748b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .scp-unread { background:#ef4444; color:#fff; border-radius:999px; font-size:10px; font-weight:700; padding:1px 6px; }
    .scp-dot { width:8px; height:8px; border-radius:50%; background:#22c55e; display:inline-block; }
    .scp-empty { text-align:center; color:var(--text-soft,#94a3b8); font-size:12px; padding:30px 10px; }
    .scp-thread { position:absolute; inset:0; top:96px; background:var(--card-bg,#fff); display:none; flex-direction:column; }
    .scp-thread.open { display:flex; }
    .scp-thread-head { display:flex; align-items:center; gap:8px; padding:8px 10px; border-bottom:1px solid var(--border,#e2e8f0); }
    .scp-back { background:transparent; border:0; font-size:16px; color:#1d4ed8; cursor:pointer; }
    .scp-thread-name { font-weight:700; font-size:13px; }
    .scp-thread-msgs { flex:1; overflow-y:auto; padding:10px; display:flex; flex-direction:column; gap:6px; background:var(--bg,#f8fafc); }
    .scp-msg { max-width:80%; padding:7px 10px; border-radius:12px; font-size:12.5px; line-height:1.4; word-wrap:break-word; }
    .scp-msg.customer { align-self:flex-start; background:#e2e8f0; color:#0f172a; border-bottom-left-radius:4px; }
    .scp-msg.admin { align-self:flex-end; background:linear-gradient(135deg,#1d4ed8,#06b6d4); color:#fff; border-bottom-right-radius:4px; }
    .scp-thread-form { display:flex; gap:6px; padding:8px; border-top:1px solid var(--border,#e2e8f0); }
    .scp-thread-input { flex:1; border:1px solid var(--border,#cbd5e1); border-radius:999px; padding:7px 12px; font-size:12.5px; background:var(--bg,#fff); color:var(--text,#0f172a); outline:none; }
    .scp-send { width:36px; height:36px; border-radius:50%; border:0; background:linear-gradient(135deg,#1d4ed8,#06b6d4); color:#fff; cursor:pointer; flex-shrink:0; }
  </style>
  <button id="staffChatBtn" class="staff-chat-btn" onclick="staffChatToggle()" data-testid="staff-chat-btn" aria-label="Open staff console" title="Leads & installs">
    <i class="bi bi-headset"></i><span id="staffChatBadge" class="staff-chat-badge" style="display:none;">0</span>
  </button>
  <div id="staffChatPanel" class="staff-chat-panel" data-testid="staff-chat-panel">
    <div class="scp-head"><div class="scp-title"><i class="bi bi-headset me-1"></i>Staff Console</div>
      <button class="scp-x" onclick="staffChatToggle()" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="scp-tabs">
      <button class="scp-tab active" data-tab="leads" onclick="staffChatTab('leads')" data-testid="scp-tab-leads">Leads <span id="scpLeadsCount" class="scp-count">0</span></button>
      <button class="scp-tab" data-tab="installs" onclick="staffChatTab('installs')" data-testid="scp-tab-installs">Installs <span id="scpInstallsCount" class="scp-count">0</span></button>
    </div>
    <div class="scp-body" style="position:relative;">
      <div id="scpLeadsList" class="scp-list" data-testid="scp-leads-list"></div>
      <div id="scpInstallsList" class="scp-list" style="display:none;" data-testid="scp-installs-list"></div>
      <div id="scpThread" class="scp-thread" data-testid="scp-thread">
        <div class="scp-thread-head"><button class="scp-back" onclick="staffChatBack()"><i class="bi bi-chevron-left"></i></button>
          <span id="scpThreadName" class="scp-thread-name"></span></div>
        <div id="scpThreadMsgs" class="scp-thread-msgs"></div>
        <form class="scp-thread-form" onsubmit="return staffChatSend(event)">
          <input id="scpThreadInput" class="scp-thread-input" placeholder="Type a reply…" autocomplete="off" data-testid="scp-thread-input">
          <button class="scp-send" type="submit" data-testid="scp-thread-send"><i class="bi bi-send-fill"></i></button>
        </form>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var API = (window.MAVEN_BASE||'/') + 'ajax/chat-admin.php';
    var openState=false, curLead=0, pollTimer=null;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    window.staffChatToggle=function(){
      var p=document.getElementById('staffChatPanel'); if(!p) return;
      openState=!p.classList.contains('open'); p.classList.toggle('open', openState);
      if(openState){ staffChatBack(); staffChatLoad(); }
    };
    window.staffChatTab=function(t){
      document.querySelectorAll('.scp-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab===t); });
      document.getElementById('scpLeadsList').style.display = t==='leads'?'block':'none';
      document.getElementById('scpInstallsList').style.display = t==='installs'?'block':'none';
    };
    window.staffChatBack=function(){
      curLead=0; var th=document.getElementById('scpThread'); if(th) th.classList.remove('open');
    };
    function renderLeads(leads){
      var el=document.getElementById('scpLeadsList'); if(!el) return;
      document.getElementById('scpLeadsCount').textContent=leads.length;
      if(!leads.length){ el.innerHTML='<div class="scp-empty"><i class="bi bi-inbox" style="font-size:26px;opacity:.4;"></i><div class="mt-2">No leads yet.</div></div>'; return; }
      el.innerHTML=leads.map(function(l){
        var initial=(l.name||l.email||'?').trim().charAt(0).toUpperCase();
        var sub = l.last_message ? esc(l.last_message) : (l.requested_product? esc(l.requested_product) : esc(l.email||''));
        return '<div class="scp-item" onclick="staffChatOpenLead('+l.id+',\''+esc((l.name||l.email||'Lead')).replace(/'/g,"\\'")+'\')" data-testid="scp-lead-'+l.id+'">'
          + '<span class="scp-ava">'+esc(initial)+'</span>'
          + '<div class="scp-item-body"><div class="scp-item-name">'+esc(l.name||l.email||'Lead')
          + (l.online?' <span class="scp-dot" title="online"></span>':'')+(l.unread>0?' <span class="scp-unread">'+l.unread+'</span>':'')+'</div>'
          + '<div class="scp-item-msg">'+sub+'</div></div></div>';
      }).join('');
    }
    function renderInstalls(items){
      var el=document.getElementById('scpInstallsList'); if(!el) return;
      document.getElementById('scpInstallsCount').textContent=items.length;
      if(!items.length){ el.innerHTML='<div class="scp-empty"><i class="bi bi-calendar-check" style="font-size:26px;opacity:.4;"></i><div class="mt-2">No upcoming installs.</div></div>'; return; }
      el.innerHTML=items.map(function(it){
        return '<div class="scp-item" data-testid="scp-install-'+it.id+'">'
          + '<span class="scp-ava" style="background:#fef3c7;color:#92400e;"><i class="bi bi-tools"></i></span>'
          + '<div class="scp-item-body"><div class="scp-item-name">'+esc(it.name||'Customer')+' <span class="scp-count">'+esc(it.status)+'</span></div>'
          + '<div class="scp-item-msg">'+esc(it.when||'')+(it.phone?' · '+esc(it.phone):'')+'</div>'
          + (it.order?'<div class="scp-item-msg">Order #'+esc(it.order)+'</div>':'')+'</div></div>';
      }).join('');
    }
    function updateBadge(n){
      var b=document.getElementById('staffChatBadge'); if(!b) return;
      if(n>0){ b.style.display='inline-flex'; b.textContent=n>99?'99+':n; } else { b.style.display='none'; }
    }
    window.staffChatLoad=function(){
      fetch(API+'?action=widget',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'widget'}),credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(j){
          if(!j||!j.ok) return;
          updateBadge(j.unread||0);
          if(openState && !curLead){ renderLeads(j.leads||[]); renderInstalls(j.installs||[]); }
        }).catch(function(){});
    };
    window.staffChatOpenLead=function(id,name){
      curLead=id;
      document.getElementById('scpThreadName').textContent=name;
      document.getElementById('scpThread').classList.add('open');
      loadThread();
    };
    function loadThread(){
      if(!curLead) return;
      fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'thread',lead_id:curLead}),credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(j){
          if(!j||!j.ok) return;
          var box=document.getElementById('scpThreadMsgs');
          box.innerHTML=(j.messages||[]).map(function(m){
            var body = m.attachment_url
              ? (m.attachment_type==='image' ? '<a href="'+esc(m.attachment_url)+'" target="_blank"><img src="'+esc(m.attachment_url)+'" style="max-width:140px;border-radius:8px;"></a>'
                 : (m.attachment_type==='audio' ? '<audio controls src="'+esc(m.attachment_url)+'" style="max-width:180px;"></audio>'
                 : '<a href="'+esc(m.attachment_url)+'" target="_blank">'+esc(m.attachment_name||'attachment')+'</a>'))
              : esc(m.message);
            return '<div class="scp-msg '+(m.sender==='admin'?'admin':'customer')+'">'+body+'</div>';
          }).join('');
          box.scrollTop=box.scrollHeight;
        }).catch(function(){});
    }
    window.staffChatSend=function(e){
      e.preventDefault();
      var inp=document.getElementById('scpThreadInput'); var msg=(inp.value||'').trim();
      if(!msg||!curLead) return false;
      inp.value='';
      fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',lead_id:curLead,message:msg}),credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(){ loadThread(); }).catch(function(){});
      return false;
    };
    // Poll: thread if open, else the widget feed (keeps badge fresh).
    pollTimer=setInterval(function(){
      if(openState && curLead) loadThread();
      else staffChatLoad();
    }, 7000);
    // Prime the badge on load.
    setTimeout(staffChatLoad, 1200);
  })();
  </script>
  <!-- =================== /STAFF CONSOLE WIDGET ===================== -->


  <main class="adm-content">

<script>
document.addEventListener('click', function(e){
  document.querySelectorAll('.adm-dropdown.open').forEach(function(d){
    if (!d.contains(e.target)) d.classList.remove('open');
  });
});

// ============================================================================
// SCROLL-POSITION PRESERVATION (sidebar nav + same-page link clicks)
// Saves window.scrollY in sessionStorage keyed by the destination URL just
// before the navigation fires, then restores it on the next page load.
// Result: clicking "Orders → Sales Detail → Orders" returns the admin to
// where they were inside the Orders tab instead of bouncing back to the top.
// Skips anchor links (#hash), AJAX/JS-handled links (data-no-scroll-save),
// and external destinations.
// ============================================================================
(function(){
  // Tell the browser we'll manage scroll restoration ourselves so back/forward
  // navigation doesn't fight with our sessionStorage restore.
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

  const KEY = 'mv_admin_scroll:';
  const TTL_MS = 30 * 60 * 1000; // forget positions older than 30 minutes

  function keyFor(url){
    try {
      // Normalise relative URLs against the current location so the same tab
      // produces the same key regardless of how the href was written.
      const u = new URL(url, window.location.href);
      return KEY + u.pathname + u.search;
    } catch (_) { return KEY + url; }
  }

  function save(url){
    try {
      sessionStorage.setItem(keyFor(url), JSON.stringify({ y: window.scrollY, t: Date.now() }));
    } catch (_) {}
  }

  function restore(){
    try {
      const raw = sessionStorage.getItem(keyFor(window.location.href));
      if (!raw) return;
      const rec = JSON.parse(raw);
      if (!rec || typeof rec.y !== 'number') return;
      if (Date.now() - (rec.t || 0) > TTL_MS) {
        sessionStorage.removeItem(keyFor(window.location.href));
        return;
      }
      // Defer to next frame so the layout has settled before scrolling.
      requestAnimationFrame(function(){
        window.scrollTo({ top: rec.y, left: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      });
    } catch (_) {}
  }

  // Save scroll position for any sidebar / in-shell link click that triggers
  // a same-window full-page navigation.
  document.addEventListener('click', function(e){
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    // Skip new-tab, mod-click, download, hash-only, mailto, tel, javascript:.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target && a.target !== '' && a.target !== '_self') return;
    if (a.hasAttribute('download') || a.hasAttribute('data-no-scroll-save')) return;
    const href = a.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')
        || href.startsWith('mailto:') || href.startsWith('tel:')) return;
    // Skip cross-origin destinations — sessionStorage is origin-scoped anyway,
    // but no point saving for a URL we'll never restore.
    try {
      const u = new URL(href, window.location.href);
      if (u.origin !== window.location.origin) return;
    } catch (_) { return; }
    // Persist the CURRENT scroll under the CURRENT URL — so when we come
    // back to this tab later, we land where the admin left off.
    save(window.location.href);
  }, true);

  // Also persist on form submit + before unload so the position is captured
  // even when the navigation is triggered by something other than a link.
  window.addEventListener('beforeunload', function(){ save(window.location.href); });

  // Restore as soon as the DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore);
  } else {
    restore();
  }
})();


// ============================================================================
// LIVE CHAT GLOBAL POLLER
// Polls /ajax/chat-admin.php?action=unread every 8s.  Updates the sidebar
// "Lead Management" badge and pops a toast when a new customer message arrives.
// ============================================================================
(function(){
  if (window.__admChatPollerStarted) return; window.__admChatPollerStarted = true;
  let prev = parseInt(document.getElementById('navChatBadge')?.textContent || '0', 10) || 0;
  let prevLatestId = 0;

  function updateBadge(n){
    const b = document.getElementById('navChatBadge'); if (!b) return;
    if (n > 0) { b.textContent = n > 99 ? '99+' : n; b.style.display = ''; }
    else { b.style.display = 'none'; }
  }

  function showToast(latest){
    if (!latest) return;
    // Don't double-toast the same message
    if (latest.lead_id && latest.message && prevLatestId === (latest.lead_id+'|'+latest.message)) return;
    prevLatestId = latest.lead_id+'|'+latest.message;
    // Suppress toast if the admin already has that lead's chat open
    if (typeof window.admChatCurrentLeadId === 'function' && window.admChatCurrentLeadId() === parseInt(latest.lead_id,10)) return;
    const old = document.querySelector('.adm-chat-toast'); if (old) old.remove();
    const t = document.createElement('div');
    t.className = 'adm-chat-toast';
    t.setAttribute('data-testid', 'chat-toast');
    const safe = (s) => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    t.innerHTML = '<span class="close">&times;</span>'
      + '<div class="ttl"><i class="bi bi-chat-dots-fill"></i> New message · '+safe(latest.name||'Customer')+'</div>'
      + '<div class="msg">'+safe(latest.message)+'</div>';
    t.addEventListener('click', function(e){
      if (e.target.classList.contains('close')) { t.remove(); return; }
      // Navigate to leads + auto-open chat
      window.location.href = 'admin.php?tab=leads&autochat=' + encodeURIComponent(latest.lead_id);
    });
    document.body.appendChild(t);
    setTimeout(()=> t.remove(), 8000);
    // Subtle ping sound via WebAudio (no external asset)
    try {
      const ac = new (window.AudioContext||window.webkitAudioContext)();
      const o = ac.createOscillator(), g = ac.createGain();
      o.connect(g); g.connect(ac.destination);
      o.frequency.value = 880; g.gain.value = 0.04;
      o.start(); o.frequency.exponentialRampToValueAtTime(1320, ac.currentTime + 0.12);
      g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.25);
      setTimeout(()=> { o.stop(); ac.close(); }, 300);
    } catch(e){}
  }

  async function tick(){
    try {
      const r = await fetch((window.MAVEN_BASE||'/') + 'ajax/chat-admin.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
        body: JSON.stringify({action:'unread'})
      });
      if (!r.ok) return;
      const j = await r.json();
      if (!j || !j.ok) return;
      const n = parseInt(j.unread||0, 10);
      updateBadge(n);
      if (n > prev && j.latest) showToast(j.latest);
      prev = n;
    } catch(e){ /* offline; try later */ }
  }
  // Let other parts of the admin force an immediate badge recount — e.g. the
  // moment an agent opens a lead's chat (which marks it read/seen), so the
  // "Lead Management" number drops right away instead of waiting for the poll.
  window.admRefreshLeadBadge = tick;

  // Secondary poller — drives the Install Schedule sidebar badge so the
  // amber counter goes up the moment a customer books a ProAssist call
  // (no page reload required).  Cheap query, same 8 s cadence.
  let prevInstall = parseInt(document.getElementById('navInstallBadge')?.textContent || '0', 10) || 0;
  async function tickInstall(){
    try {
      const r = await fetch((window.MAVEN_BASE||'/') + 'ajax/leads-online.php', {credentials:'same-origin'});
      if (!r.ok) return;
      const j = await r.json();
      if (!j || !j.ok) return;
      const ip = parseInt(j.install_pending||0, 10);
      const b  = document.getElementById('navInstallBadge');
      if (b) {
        if (ip > 0) { b.textContent = ip > 99 ? '99+' : ip; b.style.display = ''; }
        else { b.style.display = 'none'; }
      }
      // Tiny celebratory toast when a NEW install lands (count went up)
      if (ip > prevInstall && prevInstall > -1) {
        const old = document.querySelector('.adm-install-toast'); if (old) old.remove();
        const t = document.createElement('div');
        t.className = 'adm-chat-toast adm-install-toast';
        t.setAttribute('data-testid', 'install-toast');
        t.style.borderLeft = '4px solid #f59e0b';
        t.innerHTML = '<span class="close">&times;</span>'
          + '<div class="ttl"><i class="bi bi-clock-history" style="color:#f59e0b;"></i> Install pending</div>'
          + '<div class="msg">A customer just booked a ProAssist call — open Install Schedule to confirm the slot.</div>';
        t.addEventListener('click', function(e){
          if (e.target.classList.contains('close')) { t.remove(); return; }
          window.location.href = 'admin.php?tab=schedule&st=pending';
        });
        document.body.appendChild(t);
        setTimeout(()=> t.remove(), 8000);
      }
      prevInstall = ip;
    } catch(e){ /* offline; try later */ }
  }
  setTimeout(tickInstall, 1800);
  setInterval(tickInstall, 8000);

  // Kick off after a small delay; then every 8s
  setTimeout(tick, 1500);
  setInterval(tick, 8000);
})();
</script>

<!-- ===== Admin scroll + open-section preserver =====
     Saves the viewport scrollY + the IDs of every open <details> block
     to sessionStorage right before the operator submits a form or
     clicks any admin link.  On the next page load (within 30 s), we
     restore both.  Result: clicking "Write One Post", "Submit Sitemap",
     "Save Settings", flipping a toggle, etc. lands the operator back
     EXACTLY where they were — no more snap-to-top after every action. -->
<script>
(function(){
  var K_Y     = 'adm_state_y';
  var K_OPEN  = 'adm_state_open';
  var K_TS    = 'adm_state_ts';
  var K_TAB   = 'adm_state_tab';

  function currentTab() {
    var m = location.search.match(/[?&]tab=([^&]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function saveState() {
    try {
      var openIds = [];
      document.querySelectorAll('details[open]').forEach(function(d){
        if (d.id) openIds.push(d.id);
      });
      sessionStorage.setItem(K_Y,    String(window.scrollY || window.pageYOffset || 0));
      sessionStorage.setItem(K_OPEN, JSON.stringify(openIds));
      sessionStorage.setItem(K_TS,   String(Date.now()));
      sessionStorage.setItem(K_TAB,  currentTab());
    } catch(e){}
  }
  function restoreState() {
    try {
      var ts = parseInt(sessionStorage.getItem(K_TS) || '0', 10);
      if (!ts || (Date.now() - ts) > 30000) {
        // Stale state — wipe + bail so we don't restore on cold loads.
        ['adm_state_y','adm_state_open','adm_state_ts','adm_state_tab'].forEach(function(k){
          sessionStorage.removeItem(k);
        });
        return;
      }
      // Same tab only — switching tabs SHOULD jump to the top.
      var savedTab = sessionStorage.getItem(K_TAB) || '';
      if (savedTab !== currentTab()) {
        ['adm_state_y','adm_state_open','adm_state_ts','adm_state_tab'].forEach(function(k){
          sessionStorage.removeItem(k);
        });
        return;
      }
      // Re-open the same <details> blocks first so the scroll target exists.
      var openIds = [];
      try { openIds = JSON.parse(sessionStorage.getItem(K_OPEN) || '[]'); } catch(e){ openIds = []; }
      openIds.forEach(function(id){
        var d = document.getElementById(id);
        if (d && d.tagName === 'DETAILS') d.open = true;
      });
      // Restore scroll a few times to survive lazy-loaded images and
      // accordions snapping into shape.
      var y = parseInt(sessionStorage.getItem(K_Y) || '0', 10);
      if (y > 0) {
        var restore = function(){ window.scrollTo({ top: y, left: 0, behavior: 'instant' }); };
        restore();
        setTimeout(restore, 50);
        setTimeout(restore, 200);
        setTimeout(restore, 600);
      }
      // One-shot — clear after restore so the very next cold load is fresh.
      setTimeout(function(){
        sessionStorage.removeItem(K_Y);
        sessionStorage.removeItem(K_OPEN);
        sessionStorage.removeItem(K_TS);
        sessionStorage.removeItem(K_TAB);
      }, 700);
    } catch(e){}
  }

  // Save state right before every form submit (capture phase so it runs
  // even if a child handler calls e.preventDefault later in the chain).
  document.addEventListener('submit', function(e){
    if (e.target && e.target.tagName === 'FORM') saveState();
  }, true);

  // Expose a manual hook so onchange="this.form.submit()" handlers can
  // call admPreserveState() right before triggering a programmatic
  // submit (programmatic .submit() does NOT fire the submit event).
  window.admPreserveState = saveState;

  // Save state right before ANY change to an input/select with
  // onchange that auto-submits its form.  This catches the
  // auto-weekly toggle, the country pickers, the sort dropdowns and
  // anything else that posts on change.
  document.addEventListener('change', function(e){
    var t = e.target;
    if (!t || !t.form) return;
    var onchange = t.getAttribute('onchange') || '';
    if (onchange.indexOf('form.submit()') >= 0 || onchange.indexOf('this.form.submit') >= 0) {
      saveState();
    }
  }, true);

  // Save state on link clicks that navigate within the admin.  Ignores
  // pure in-page anchors (#section) since those don't reload.
  document.addEventListener('click', function(e){
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    // Cover admin.php?tab=…, hub/…, page.php?…, etc. — basically any
    // admin-shell-internal navigation that would otherwise snap to top.
    if (/^(admin\.php|\?tab=|\.\.?\/admin)/.test(href) || href.indexOf('admin.php') >= 0) {
      saveState();
    }
  }, true);

  // beforeunload catches the soft-reload too (e.g. when JS submits a
  // hidden form via this.form.submit()).
  window.addEventListener('beforeunload', saveState);

  // Run the restore on initial DOMContentLoaded.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreState);
  } else {
    restoreState();
  }
})();
</script>


<!-- ============================================================
     PWA + Activity Notifications — installs the service worker,
     wires the Install button + the activity bell, and starts a
     30-second polling loop for new admin events.
     ============================================================ -->
<style>
.adm-install-btn {
  /* Override the .adm-iconbtn round 36×36 default so the label has room
     to sit next to the icon as a proper pill. */
  width: auto !important;
  height: auto !important;
  border-radius: 999px !important;
  padding: 8px 14px !important;
  background: linear-gradient(135deg, #06b6d4, #0ea5e9) !important;
  color: #fff !important;
  border: 0 !important;
  font-weight: 700;
  font-size: 12px;
  letter-spacing: .3px;
  gap: 6px;
  box-shadow: 0 4px 10px rgba(14,165,233,.30);
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}
.adm-install-btn .adm-install-label { display: inline-block; font-size: 12px; line-height: 1; }
.adm-install-btn:hover { filter: brightness(1.08); transform: translateY(-1px); color:#fff !important; }
.adm-install-btn::before {
  /* Soft inner glow on hover — sells the "tap to install" affordance. */
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,.20), rgba(255,255,255,0));
  opacity: 0; transition: opacity .25s;
}
.adm-install-btn:hover::before { opacity: 1; }
/* Phones — collapse the label so the icon stays visible without crowding. */
@media (max-width: 575px) {
  .adm-install-btn .adm-install-label { display: none; }
  .adm-install-btn { padding: 8px 10px !important; }
}
.adm-bell-activity { position: relative; }
.adm-bell-activity .adm-bell-badge { background: #ef4444; color: #fff; right: 0; top: 0;
  font-weight: 700; font-size: 10px; padding: 0 5px; border-radius: 999px; min-width: 16px;
  text-align: center; position: absolute; }
.adm-activity-item { display: flex; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border);
  text-decoration: none; color: var(--text); transition: background .12s; }
.adm-activity-item:hover { background: rgba(99,102,241,.06); }
.adm-activity-item .icon { flex-shrink: 0; width: 32px; height: 32px; border-radius: 8px;
  background: rgba(6,182,212,.12); color: #06b6d4; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.adm-activity-item.unread { background: rgba(239,68,68,.04); }
.adm-activity-item.unread .icon { background: rgba(239,68,68,.12); color: #ef4444; }
.adm-activity-title { font-weight: 600; font-size: 12.5px; line-height: 1.3; margin: 0; }
.adm-activity-body  { font-size: 11.5px; color: var(--muted); margin-top: 2px; line-height: 1.4; }
.adm-activity-time  { font-size: 10.5px; color: var(--muted); margin-top: 4px; }
/* Bell buzz: short shake when a new notification arrives, paired with chime. */
@keyframes adm-bell-buzz {
  0%, 100% { transform: rotate(0); }
  15%      { transform: rotate(-12deg); }
  30%      { transform: rotate(10deg); }
  45%      { transform: rotate(-8deg); }
  60%      { transform: rotate(6deg); }
  75%      { transform: rotate(-3deg); }
}
.adm-bell-buzz { animation: adm-bell-buzz 1.1s cubic-bezier(.36,.07,.19,.97); transform-origin: center 30%; }
/* Tiny mute toggle inside the activity dropdown header. */
.adm-bell-mute { background:none; border:0; padding:2px 6px; color:var(--muted); cursor:pointer; font-size:11px; line-height:1; }
.adm-bell-mute:hover { color:#06b6d4; }
.adm-bell-mute.muted { color:#ef4444; }
</style>
<script>
(function () {
  // ---- 1) Register the service worker so the manifest is recognised ----
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/admin-sw.js', { scope: '/' })
        .then((reg) => {
          // Try to opt into periodic background sync (Chrome/Edge only — silently no-ops elsewhere).
          if ('periodicSync' in reg) {
            navigator.permissions.query({ name: 'periodic-background-sync' }).then((p) => {
              if (p.state === 'granted') {
                reg.periodicSync.register('maventech-admin-poll', { minInterval: 30 * 1000 }).catch(() => {});
              }
            }).catch(() => {});
          }
        }).catch(() => {});
    });
  }

  // ---- 2) "Install" button — wired to beforeinstallprompt ----
  let deferredPrompt = null;
  const installBtn = document.getElementById('admInstallBtn');
  // Hide the button outright if the admin is ALREADY running the PWA
  // standalone — installing the already-installed app makes no sense.
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
                       || window.matchMedia('(display-mode: minimal-ui)').matches
                       || window.navigator.standalone === true;
  if (installBtn && isStandalone) {
    installBtn.style.display = 'none';
  }
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    // Always-visible pill — the button is already in the DOM; this
    // listener just stores the prompt for the click handler below.
    if (installBtn && !isStandalone) installBtn.style.display = '';
  });
  if (installBtn) {
    installBtn.addEventListener('click', async () => {
      if (!deferredPrompt) {
        // iOS Safari + browsers without beforeinstallprompt — show instructions.
        alert('To install Maventech Admin:\n\n• iPhone / iPad: tap the Share button → Add to Home Screen.\n• Android: open in Chrome → menu → Install app.\n• Desktop: click the install icon in the address bar.');
        return;
      }
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      if (choice.outcome === 'accepted') installBtn.style.display = 'none';
      deferredPrompt = null;
    });
  }
  window.addEventListener('appinstalled', () => {
    if (installBtn) installBtn.style.display = 'none';
  });

  // ---- 3) Notification permission — silently request once after install ----
  if ('Notification' in window && Notification.permission === 'default') {
    // Don't nag — only ask after the first user click anywhere on the page.
    const ask = () => { Notification.requestPermission().catch(() => {}); document.removeEventListener('click', ask); };
    document.addEventListener('click', ask, { once: true });
  }

  // ---- 4) Activity bell — fetch unread count + dropdown items every 30s ----
  const listEl  = document.getElementById('admActivityList');
  const badgeEl = document.getElementById('admActivityBadge');
  if (!listEl || !badgeEl) return;

  // ---- 5) Notification chime (Web Audio — no asset to download) ----
  // Pleasant two-note descending tone. Honours user's mute preference
  // stored in localStorage ("mv_admin_mute"=1 silences) so they can
  // dial it down without coding.
  let _ac = null;
  function chime() {
    try {
      if (localStorage.getItem('mv_admin_mute') === '1') return;
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      _ac = _ac || new AC();
      // Resume the context if it's been suspended (autoplay policy).
      if (_ac.state === 'suspended') _ac.resume();
      const now = _ac.currentTime;
      // Two short overlapping tones — A5 then C#6 — feels modern + crisp.
      [[880, 0.00, 0.18], [1108.73, 0.10, 0.22]].forEach(([f, off, dur]) => {
        const o = _ac.createOscillator(), g = _ac.createGain();
        o.type = 'sine'; o.frequency.value = f;
        o.connect(g); g.connect(_ac.destination);
        g.gain.setValueAtTime(0.0001, now + off);
        g.gain.exponentialRampToValueAtTime(0.16, now + off + 0.03);
        g.gain.exponentialRampToValueAtTime(0.0001, now + off + dur);
        o.start(now + off); o.stop(now + off + dur + 0.02);
      });
    } catch (e) { /* silent */ }
  }
  // Expose so the "Mute" toggle button can call playPing() to preview.
  window._mvChime = chime;

  const TIMEAGO = (iso) => {
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    const s = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000));
    if (s < 60)         return s + 's ago';
    if (s < 3600)       return Math.floor(s / 60) + 'm ago';
    if (s < 86400)      return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  };
  const ICONS = {
    order: 'bi-cart-check', sale: 'bi-graph-up', lead: 'bi-person-plus',
    install: 'bi-tools', email: 'bi-envelope', review: 'bi-star',
    template: 'bi-file-earmark-text', default: 'bi-bell',
  };

  let _lastUnread = -1;
  async function refresh() {
    try {
      const r = await fetch('/admin.php?ajax=notif_poll', { credentials: 'same-origin' });
      const j = await r.json();
      if (!j || !j.ok) return;
      const unread = j.unread || 0;
      // Play the chime ONLY when the unread count actually increased
      // (e.g. -1 → 0 on first load doesn't ring; 2 → 5 does).
      if (_lastUnread >= 0 && unread > _lastUnread) {
        chime();
        // Subtle pulse on the bell so the visual matches the audio.
        const bell = document.querySelector('.adm-bell-activity');
        if (bell) {
          bell.classList.add('adm-bell-buzz');
          setTimeout(() => bell.classList.remove('adm-bell-buzz'), 1200);
        }
      }
      _lastUnread = unread;
      if (unread > 0) {
        badgeEl.textContent = unread > 99 ? '99+' : String(unread);
        badgeEl.classList.remove('d-none');
      } else {
        badgeEl.classList.add('d-none');
      }
      if (!j.items.length) {
        listEl.innerHTML = '<div class="text-center py-4 text-muted" style="font-size:12px;"><i class="bi bi-check2-circle d-block mb-2" style="font-size:24px;color:#10b981;"></i>All caught up</div>';
        return;
      }
      listEl.innerHTML = j.items.map((n) => {
        const icon = ICONS[n.type] || ICONS.default;
        const unreadCls = n.read_at ? '' : 'unread';
        const safeTitle = (n.title || '').replace(/[<>&]/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
        const safeBody  = (n.body  || '').replace(/[<>&]/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
        return '<a class="adm-activity-item ' + unreadCls + '" href="' + (n.link || '/admin.php') + '" data-id="' + n.id + '">'
             + '<span class="icon"><i class="bi ' + icon + '"></i></span>'
             + '<div style="flex:1;min-width:0;">'
             + '  <p class="adm-activity-title">' + safeTitle + '</p>'
             + (safeBody ? '<p class="adm-activity-body">' + safeBody + '</p>' : '')
             + '  <p class="adm-activity-time">' + TIMEAGO(n.created_at) + '</p>'
             + '</div></a>';
      }).join('');
    } catch (e) { /* silent — admin will retry next interval */ }
  }
  window.markActivityRead = async function (all) {
    if (all) {
      await fetch('/admin.php?ajax=notif_mark', { method: 'POST', credentials: 'same-origin' });
    }
    refresh();
  };
  // Mute toggle inside the bell dropdown.
  (function () {
    const btn   = document.getElementById('admBellMuteBtn');
    const icon  = document.getElementById('admBellMuteIcon');
    const label = document.getElementById('admBellMuteLabel');
    if (!btn) return;
    function render() {
      const muted = localStorage.getItem('mv_admin_mute') === '1';
      btn.classList.toggle('muted', muted);
      icon.className = muted ? 'bi bi-volume-mute-fill' : 'bi bi-volume-up-fill';
      label.textContent = muted ? 'Sound off' : 'Sound on';
    }
    render();
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      const wasMuted = localStorage.getItem('mv_admin_mute') === '1';
      localStorage.setItem('mv_admin_mute', wasMuted ? '0' : '1');
      render();
      if (!wasMuted) return;       // just muted → no preview
      if (window._mvChime) window._mvChime();   // un-muted → preview the chime
    });
  })();
  refresh();
  setInterval(refresh, 30000);

  // Ping the SW so it polls in the background too (covers periodicsync gaps).
  setInterval(() => {
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage('admin-poll');
    }
  }, 30000);
})();

// ── Installed-PWA auto-refresh ───────────────────────────────────────────────
// In the INSTALLED app only, reload the page every 30s so new leads/orders
// surface automatically. It NEVER reloads while the operator is typing, has
// recently interacted (last 20s), has text selected, or a modal/offcanvas/chat
// is open — so it's non-disruptive and won't wipe anything being typed.
(function(){
  var isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                  || window.navigator.standalone === true;
  if (!isStandalone) return;  // only inside the installed app

  var lastActivity = Date.now();
  ['keydown','mousedown','pointerdown','input','change','touchstart','wheel'].forEach(function(ev){
    document.addEventListener(ev, function(){ lastActivity = Date.now(); }, true);
  });

  function isTyping(){
    var el = document.activeElement;
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    return false;
  }
  function blocked(){
    if (document.hidden) return true;                       // tab/app not visible
    if (isTyping()) return true;                            // focused on a field
    if (Date.now() - lastActivity < 20000) return true;     // active in last 20s
    try { if (window.getSelection && window.getSelection().toString().trim()) return true; } catch(e){}
    if (document.querySelector('.modal.show, .offcanvas.show')) return true;  // dialog open
    var chat = document.getElementById('chat-panel');
    if (chat && chat.classList.contains('open')) return true;                 // chat open
    return false;
  }

  // Baseline unread count captured at page load so we can tell whether NEW
  // activity arrived since (to show a heads-up toast before the reload).
  var baselineUnread = null;
  fetch('/admin.php?ajax=notif_count', { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d && d.ok) baselineUnread = d.unread; })
    .catch(function(){});

  function leadToast(){
    try { if (window._mvChime) window._mvChime(); } catch(e){}   // soft chime (honours mute toggle)
    var t = document.createElement('div');
    t.innerHTML = '🔔 New lead just arrived &nbsp;<span style="opacity:.85;font-weight:600;">— tap to view</span>';
    t.setAttribute('data-testid', 'new-lead-refresh-toast');
    t.title = 'Open Lead Management';
    t.style.cssText = 'position:fixed;top:18px;left:50%;transform:translateX(-50%) translateY(-8px);'
      + 'background:#0f172a;color:#fff;padding:12px 22px;border-radius:999px;font-weight:700;font-size:14px;'
      + 'z-index:4000;box-shadow:0 12px 34px rgba(0,0,0,.4);border-left:3px solid #06b6d4;opacity:0;cursor:pointer;'
      + 'transition:opacity .25s ease, transform .25s ease;';
    t.addEventListener('click', function(){
      if (window._mvLeadReloadTimer) { clearTimeout(window._mvLeadReloadTimer); window._mvLeadReloadTimer = null; }
      window.location.href = 'admin.php?tab=leads';
    });
    document.body.appendChild(t);
    requestAnimationFrame(function(){ t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(0)'; });
  }

  setInterval(function(){
    if (blocked()) return;
    fetch('/admin.php?ajax=notif_count', { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        var now = (d && d.ok) ? d.unread : null;
        if (now !== null && baselineUnread !== null && now > baselineUnread) {
          leadToast();                                   // heads-up + chime; tap to open Leads
          // Give the team a few seconds to tap the toast before the refresh.
          window._mvLeadReloadTimer = setTimeout(function(){ location.reload(); }, 5000);
        } else {
          location.reload();                             // routine refresh
        }
      })
      .catch(function(){ location.reload(); });
  }, 30000);
})();

</script>

