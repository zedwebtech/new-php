<?php
/*
 * One-shot deployment sanity check + auto-repair.
 *
 *   Visit  https://your-domain.com/setup-check.php  (admin login required)
 *   to see whether every required table/column exists, which AJAX endpoints
 *   reachable, and what base URL the panel is running under.  Anything
 *   missing is auto-created (idempotent) so the panel becomes functional
 *   without shell access to the live server.
 */
require_once __DIR__ . '/includes/functions.php';
require_admin();

$pdo = db();

$reportTable = function(string $name) use ($pdo): array {
    try {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return ['name' => $name, 'exists' => (bool)$st->fetchColumn()];
    } catch (Throwable $e) { return ['name' => $name, 'exists' => false, 'error' => $e->getMessage()]; }
};
$reportColumn = function(string $table, string $column) use ($pdo): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        return ['table' => $table, 'column' => $column, 'exists' => (bool)$st->fetchColumn()];
    } catch (Throwable $e) { return ['table' => $table, 'column' => $column, 'exists' => false, 'error' => $e->getMessage()]; }
};

// Force a re-run of the auto-migration (it's idempotent).
ensure_db_schema();

$tables = array_map($reportTable, [
    // Core storefront
    'products','orders','order_items','users','license_keys','settings',
    // Admin panels reported missing on a recent cPanel deploy
    'chat_leads','chat_messages','lead_notes',     // Lead Management
    'proassist_schedules',                         // Install Schedules
    'email_outbox','email_templates','email_template_versions', // Email Activity + Templates
    'customer_reviews','reviews',                  // Customer Reviews
    'subscription_plans','customer_subscriptions','subscription_notes', // Subscriptions
    'transaction_logs','stripe_events',            // Sales History + API / Payment Gateways
    'refund_requests','visitor_log','password_resets',
    'blog_posts','topic_hubs','seo_runs','ai_citations','dmca_findings',
    'gsc_queries','vibe_schedule','vibe_history','stock_notifications',
    'product_ai_chats',
]);
$cols   = [
    // chat
    $reportColumn('chat_leads','last_seen'),
    $reportColumn('chat_leads','chat_token'),
    $reportColumn('chat_leads','admin_seen_at'),
    $reportColumn('chat_leads','agent_name'),
    $reportColumn('chat_messages','attachment_url'),
    // orders — root cause of the production "Unknown column 'delivery_status'"
    // crash on checkout.  All three are added by ensure_db_schema() on first
    // request after upload, but if any one is MISSING here, /checkout.php is
    // broken until somebody loads the admin once.
    $reportColumn('orders','delivery_status'),
    $reportColumn('orders','gw_mode'),
    $reportColumn('orders','payment_intent_id'),
    // products
    $reportColumn('products','activation_url'),
    $reportColumn('products','install_guide_url'),
    $reportColumn('products','installer_url'),
    $reportColumn('products','gtin'),
    // users (staff RBAC)
    $reportColumn('users','username'),
    $reportColumn('users','department'),
    $reportColumn('users','active'),
    // subscriptions
    $reportColumn('customer_subscriptions','assigned_department'),
    $reportColumn('customer_subscriptions','assigned_user_id'),
    // email outbox (retry pipeline)
    $reportColumn('email_outbox','tracking_token'),
    $reportColumn('email_outbox','attachments_json'),
];
$ajaxFiles = ['ajax/chat-customer.php','ajax/chat-admin.php','ajax/email-resend.php','ajax/smtp-test-recipient.php','ajax/visitor-stats.php','ajax/lead.php','ajax/cart.php','ajax/chat.php'];

// -----------------------------------------------------------------------
// Public-domain hygiene check
//
// Detects any URL-shaped setting (main_url, site_domain_url, company_logo,
// AI-image URLs, etc.) that was saved while the codebase was running on an
// Emergent preview hostname and would otherwise leak the dev URL into the
// production site (QR codes on receipts, <img src> for the logo, every
// absolute link in emails). The auto-heal layer in setting_get() rewrites
// these on read, but surfacing them here lets the operator clean the DB
// for SEO / canonical-URL purity.
//
// IMPORTANT: keep the allowlist below in sync with the $urlKeys map inside
// setting_get() (includes/settings.php). Only these keys are treated as
// URLs — without an allowlist, JSON-blob rows like `seo_health_probe_cache`
// would be falsely flagged because they happen to embed a preview URL
// inside a JSON string, and the cleanup regex (anchored to `^https?://`)
// could never remove it, leaving the warning stuck forever.
// -----------------------------------------------------------------------
$urlSettingKeys = ['main_url', 'site_domain_url', 'company_logo',
                   'site_url', 'public_url', 'base_url'];
$currentHost    = (string)($_SERVER['HTTP_HOST'] ?? '');
$onPreviewHost  = (bool)preg_match('/\.preview\.emergentagent\.com$/i', $currentHost);

// Run any auto-fix BEFORE any HTML is emitted so the redirect can fire.
if (($_GET['fix'] ?? '') === 'public_urls' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fixed = 0;
    try {
        $ph = implode(',', array_fill(0, count($urlSettingKeys), '?'));
        $st = $pdo->prepare("SELECT k,v FROM settings
                             WHERE k IN ($ph) AND v LIKE ?");
        $st->execute(array_merge($urlSettingKeys, ['%preview.emergentagent.com%']));
        foreach ($st->fetchAll() as $r) {
            if (in_array($r['k'], ['main_url', 'site_domain_url', 'site_url', 'public_url', 'base_url'], true)) {
                // Empty value → site_url() falls back to live HTTP_HOST.
                $pdo->prepare("UPDATE settings SET v='' WHERE k=?")->execute([$r['k']]);
            } else {
                // Strip the preview host + scheme, keep the remaining path
                // (e.g. company_logo: keep "/uploads/company/logo-xx.png").
                $rel = preg_replace('~https?://[^/\s"\']*\.preview\.emergentagent\.com~i',
                                    '', (string)$r['v']);
                $pdo->prepare("UPDATE settings SET v=? WHERE k=?")->execute([$rel, $r['k']]);
            }
            $fixed++;
        }
        setting_get('__flush__');   // bust the in-process settings cache
    } catch (Throwable $e) { /* ignore — fix is best-effort */ }
    header('Location: setup-check.php?cleaned=' . $fixed);
    exit;
}

// Detect remaining leaks (allowlisted keys only).
$leakyRows = [];
try {
    $ph = implode(',', array_fill(0, count($urlSettingKeys), '?'));
    $st = $pdo->prepare("SELECT k,v FROM settings
                         WHERE k IN ($ph) AND v LIKE ?");
    $st->execute(array_merge($urlSettingKeys, ['%preview.emergentagent.com%']));
    foreach ($st->fetchAll() as $r) {
        $leakyRows[] = ['k' => $r['k'], 'v' => $r['v']];
    }
} catch (Throwable $e) { /* ignore */ }

include __DIR__ . '/includes/admin-shell.php';
?>
<div class="adm-content">
<h2 class="mb-3"><i class="bi bi-tools me-2"></i>Setup &amp; Deployment Check</h2>
<p class="text-muted">Run this page after uploading to a new server.  Anything that's red here is the most likely cause of "feature X isn't working".</p>

<?php if (isset($_GET['cleaned'])) : ?>
  <div class="alert alert-success small" data-testid="hygiene-cleaned-flash">
    <i class="bi bi-check-circle-fill me-1"></i>Cleaned <?= (int)$_GET['cleaned'] ?> setting<?= ((int)$_GET['cleaned']===1?'':'s') ?>. Every absolute link on the public site now resolves to your real domain.
  </div>
<?php endif; ?>

<div class="card-e p-3 mb-3" data-testid="public-domain-hygiene-card">
  <div class="fw-bold mb-2"><i class="bi bi-globe2 me-2"></i>Public domain hygiene</div>
  <table class="table table-sm mb-2">
    <tr>
      <td><strong>Current request host</strong></td>
      <td><code data-testid="hygiene-current-host"><?= esc($currentHost) ?></code> <?= $onPreviewHost
            ? '<span class="text-warning"><i class="bi bi-info-circle"></i> You are viewing this on an Emergent preview hostname — that&apos;s expected during development.</span>'
            : '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Real production domain.</span>' ?></td>
    </tr>
    <tr>
      <td><strong>site_url()</strong> (used by QR / canonical / og:url)</td>
      <td><code data-testid="hygiene-site-url"><?= esc(site_url()) ?></code></td>
    </tr>
  </table>
  <?php if ($leakyRows): ?>
    <div class="alert alert-warning small mb-0 mt-2" data-testid="hygiene-leak-warning">
      <strong><i class="bi bi-exclamation-triangle-fill me-1"></i><?= count($leakyRows) ?> setting<?= count($leakyRows)===1?'':'s' ?> still contain<?= count($leakyRows)===1?'s':'' ?> a preview URL.</strong>
      The auto-heal layer rewrites these on every request, so the public site is already clean — but cleaning the DB row gives you a tidier admin panel and cleaner exports.
      <table class="table table-sm mt-2 mb-0">
        <thead><tr><th>Key</th><th>Stored value</th><th>What it becomes on this domain</th></tr></thead>
        <tbody>
        <?php foreach ($leakyRows as $r): ?>
          <tr data-testid="hygiene-leak-row">
            <td><code><?= esc($r['k']) ?></code></td>
            <td class="text-truncate" style="max-width:340px;"><code><?= esc(mb_strimwidth((string)$r['v'], 0, 100, '…')) ?></code></td>
            <td><code><?= esc((string)setting_get($r['k'])) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" action="setup-check.php?fix=public_urls" class="mt-2 mb-0" onsubmit="return confirm('Strip preview hostnames from these <?= count($leakyRows) ?> settings? The site will still work either way thanks to the auto-heal layer.');">
        <button type="submit" class="btn btn-sm btn-warning" data-testid="hygiene-fix-btn"><i class="bi bi-magic me-1"></i>Strip preview hostnames now</button>
      </form>
    </div>
  <?php else: ?>
    <div class="text-success small" data-testid="hygiene-clean-state"><i class="bi bi-check-circle-fill me-1"></i>No preview URLs leaking through any setting — every public link will resolve to <code><?= esc($currentHost ?: 'your production domain') ?></code>.</div>
  <?php endif; ?>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-globe2 me-2"></i>Environment</div>
  <table class="table table-sm mb-0">
    <tr><td><strong>Detected base URL</strong></td><td><code><?= esc(base_url()) ?></code></td></tr>
    <tr><td><strong>PHP version</strong></td><td><code><?= esc(PHP_VERSION) ?></code> <?= version_compare(PHP_VERSION,'8.0','>=')?'<span class="text-success"><i class="bi bi-check-circle-fill"></i> OK</span>':'<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Needs PHP 8.0+</span>' ?></td></tr>
    <tr><td><strong>HTTPS</strong></td><td><?= !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https' ? '<span class="text-success"><i class="bi bi-shield-check"></i> Secure</span>' : '<span class="text-warning">Plain HTTP — some browsers block AJAX from HTTPS pages</span>' ?></td></tr>
    <tr><td><strong>session.save_path writable</strong></td><td><?= is_writable(session_save_path() ?: sys_get_temp_dir()) ? '<span class="text-success">OK</span>' : '<span class="text-danger">NOT writable — sessions may not persist</span>' ?></td></tr>
    <tr><td><strong>PDO MySQL driver</strong></td><td><?= in_array('mysql', PDO::getAvailableDrivers(), true) ? '<span class="text-success">OK</span>' : '<span class="text-danger">Missing</span>' ?></td></tr>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-table me-2"></i>Database tables</div>
  <table class="table table-sm mb-0">
    <?php foreach ($tables as $t): ?>
      <tr>
        <td><code><?= esc($t['name']) ?></code></td>
        <td><?= $t['exists'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> exists</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> MISSING</span>' ?></td>
        <?php if (!$t['exists']): ?><td class="small text-muted">Will be re-created by ensure_db_schema() on next admin load. Error: <code><?= esc($t['error'] ?? '—') ?></code></td><?php else: ?><td></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-columns me-2"></i>Required columns</div>
  <table class="table table-sm mb-0">
    <?php foreach ($cols as $c): ?>
      <tr>
        <td><code><?= esc($c['table']) ?>.<?= esc($c['column']) ?></code></td>
        <td><?= $c['exists'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ok</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> MISSING</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card-e p-3 mb-3">
  <div class="fw-bold mb-2"><i class="bi bi-link-45deg me-2"></i>AJAX endpoint files</div>
  <table class="table table-sm mb-0">
    <?php foreach ($ajaxFiles as $f): $abs = __DIR__ . '/' . $f; ?>
      <tr>
        <td><code><?= esc($f) ?></code></td>
        <td><?= file_exists($abs) ? '<span class="text-success">file exists</span>' : '<span class="text-danger">MISSING — re-upload it</span>' ?></td>
        <td><?= file_exists($abs) && is_readable($abs) ? '<span class="text-success">readable</span>' : '<span class="text-warning">check perms (644)</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <div class="small text-muted mt-2">JS calls these via <code>window.MAVEN_BASE + 'ajax/&lt;file&gt;.php'</code>.  Current MAVEN_BASE = <code><?= esc(base_url()) ?></code>.  If your hosting puts the project in a different folder, just upload everything and visit the admin panel — MAVEN_BASE auto-detects from <code>$_SERVER['SCRIPT_NAME']</code>.</div>
</div>

<div class="card-e p-3">
  <div class="fw-bold mb-2"><i class="bi bi-activity me-2"></i>Quick AJAX probe</div>
  <div id="probeResults" class="small">Click "Run probe" to check that every endpoint responds correctly from the browser…</div>
  <button class="btn btn-primary mt-2" onclick="runProbe()"><i class="bi bi-play-fill me-1"></i>Run probe</button>
</div>
</div>

<script>
async function runProbe(){
  const out = document.getElementById('probeResults'); out.innerHTML = '';
  const ep = [
    ['POST', 'ajax/chat-admin.php',         {action:'unread'}],
    ['GET',  'ajax/visitor-stats.php?from='+new Date().toISOString().slice(0,10)+'&to='+new Date().toISOString().slice(0,10), null],
    ['POST', 'ajax/smtp-test-recipient.php', {email:'test@example.com'}],
  ];
  for (const [method, path, body] of ep) {
    const url = (window.MAVEN_BASE||'/') + path;
    let line = '<div>' + method + ' <code>' + url + '</code> … ';
    try {
      const opts = {method};
      if (body) { opts.headers = {'Content-Type':'application/json'}; opts.body = JSON.stringify(body); }
      const r = await fetch(url, opts);
      const txt = await r.text();
      const looksOk = r.ok && (txt.includes('"ok":true') || txt.length > 50);
      line += '<span class="' + (looksOk?'text-success':'text-danger') + '">HTTP ' + r.status + (looksOk?' ✓':' ✗') + '</span></div>';
    } catch(e) {
      line += '<span class="text-danger">ERROR: ' + e.message + '</span></div>';
    }
    out.insertAdjacentHTML('beforeend', line);
  }
}
</script>
