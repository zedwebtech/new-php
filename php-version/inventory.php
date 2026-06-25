<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
ensure_admin();
$admin = require_admin();
$pdo = db();

$pageTitle = 'Inventory Management | ' . SITE_BRAND;
$view = $_GET['view'] ?? 'home';
$flash = $_GET['msg'] ?? '';

// ============================================================
// PARENT CATEGORY GROUPS (hierarchical structure)
// ============================================================
$groups = [
    'office-pc' => [
        'name'  => 'Office for PC',
        'icon'  => 'bi-microsoft',
        'color' => '#0078d4',
        'desc'  => 'Microsoft Office editions for Windows',
        'children' => [
            ['slug' => 'office-2024-pc', 'name' => 'Office 2024'],
            ['slug' => 'office-2021-pc', 'name' => 'Office 2021'],
            ['slug' => 'office-2019-pc', 'name' => 'Office 2019'],
            ['slug' => 'all:office-pc',  'name' => 'All Office for PC', 'all' => ['office-2024-pc','office-2021-pc','office-2019-pc']],
        ],
    ],
    'office-mac' => [
        'name'  => 'Office for Mac',
        'icon'  => 'bi-apple',
        'color' => '#1d1d1f',
        'desc'  => 'Microsoft Office editions for macOS',
        'children' => [
            ['slug' => 'office-2024-mac', 'name' => 'Office 2024 for Mac'],
            ['slug' => 'office-2021-mac', 'name' => 'Office 2021 for Mac'],
            ['slug' => 'office-2019-mac', 'name' => 'Office 2019 for Mac'],
            ['slug' => 'all:office-mac',  'name' => 'All Office for Mac', 'all' => ['office-2024-mac','office-2021-mac','office-2019-mac']],
        ],
    ],
    'windows' => [
        'name'  => 'Windows',
        'icon'  => 'bi-windows',
        'color' => '#00a4ef',
        'desc'  => 'Windows operating system licenses',
        'children' => [
            ['slug' => 'windows-11', 'name' => 'Windows 11'],
            ['slug' => 'windows-10', 'name' => 'Windows 10'],
            ['slug' => 'all:windows', 'name' => 'All Windows', 'all' => ['windows-10','windows-11']],
        ],
    ],
    'apps' => [
        'name'  => 'Microsoft Apps',
        'icon'  => 'bi-grid-3x3-gap-fill',
        'color' => '#7719aa',
        'desc'  => 'Project, Visio and other Microsoft apps',
        'children' => [
            ['slug' => 'microsoft-project', 'name' => 'Microsoft Project'],
            ['slug' => 'microsoft-visio',   'name' => 'Microsoft Visio'],
            ['slug' => 'all:apps',          'name' => 'All Microsoft Apps', 'all' => ['microsoft-project','microsoft-visio']],
        ],
    ],
    'antivirus' => [
        'name'  => 'Antivirus',
        'icon'  => 'bi-shield-check',
        'color' => '#d83b01',
        'desc'  => 'Antivirus & security software',
        'children' => [
            ['slug' => 'bitdefender', 'name' => 'Bitdefender'],
            ['slug' => 'mcafee',      'name' => 'McAfee'],
            ['slug' => 'all:antivirus','name' => 'All Antivirus', 'all' => ['bitdefender','mcafee']],
        ],
    ],
];

function inv_resolve_categories(string $cat, array $groups): array {
    // Returns array of real category slugs to query for products.
    if (strpos($cat, 'all:') === 0) {
        $g = substr($cat, 4);
        foreach ($groups as $key => $grp) {
            if ($key === $g) {
                foreach ($grp['children'] as $c) {
                    if (!empty($c['all'])) return $c['all'];
                }
            }
        }
    }
    return [$cat];
}

function inv_friendly_cat(string $cat, array $groups): string {
    foreach ($groups as $g) {
        foreach ($g['children'] as $c) {
            if ($c['slug'] === $cat) return $c['name'];
        }
    }
    $row = db()->prepare('SELECT name FROM categories WHERE slug=?');
    $row->execute([$cat]);
    return $row->fetchColumn() ?: $cat;
}

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_keys') {
        $slug = $_POST['product_slug'];
        $keys = array_filter(array_map('trim', explode("\n", $_POST['keys'] ?? '')));
        $stmt = $pdo->prepare('INSERT INTO license_keys (product_slug, license_key) VALUES (?, ?)');
        $added = 0;
        foreach ($keys as $k) { try { $stmt->execute([$slug, $k]); $added++; } catch (Exception $e) {} }
        header('Location: inventory.php?view=product&slug=' . urlencode($slug) . '&tab=keys&msg=' . urlencode("$added key(s) added."));
        exit;
    }
    if ($action === 'delete_key') {
        $id = (int)$_POST['key_id'];
        $pdo->prepare('DELETE FROM license_keys WHERE id=? AND status="available"')->execute([$id]);
        header('Location: ' . ($_POST['back'] ?? 'inventory.php'));
        exit;
    }
    if ($action === 'expire_key') {
        $id = (int)$_POST['key_id'];
        $pdo->prepare('UPDATE license_keys SET status="expired" WHERE id=?')->execute([$id]);
        header('Location: ' . ($_POST['back'] ?? 'inventory.php'));
        exit;
    }
    if ($action === 'send_email_now') {
        $id = (int)$_POST['email_id'];
        $row = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
        $row->execute([$id]);
        $em = $row->fetch();
        if ($em) {
            $ok = function_exists('send_email_resend')
                ? @send_email_resend($em['recipient'], $em['subject'], $em['html'])
                : false;
            $pdo->prepare('UPDATE email_outbox SET status=?, note=? WHERE id=?')
                ->execute([$ok ? 'sent' : 'failed', $ok ? 'Sent via Resend' : 'No API key / send failed', $id]);
        }
        header('Location: inventory.php?view=emails&msg=' . urlencode('Email processed.'));
        exit;
    }
}

$adminActive = 'inventory';
include __DIR__ . '/includes/admin-shell.php';
?>

<div data-testid="inventory-page">

  <?php
  // ============================================================
  // BREADCRUMB
  // ============================================================
  $crumbs = [['Admin', 'admin.php'], ['Inventory Management', 'inventory.php']];
  if ($view === 'category' && isset($_GET['parent']) && isset($groups[$_GET['parent']])) {
      $crumbs[] = [$groups[$_GET['parent']]['name'], 'inventory.php?view=category&parent=' . $_GET['parent']];
  }
  if ($view === 'products' && !empty($_GET['cat'])) {
      $crumbs[] = [inv_friendly_cat($_GET['cat'], $groups), '#'];
  }
  if ($view === 'product' && !empty($_GET['slug'])) {
      $crumbs[] = ['Product Details', '#'];
  }
  if ($view === 'reports') $crumbs[] = ['Sales & Revenue Reports', '#'];
  if ($view === 'emails')  $crumbs[] = ['Automated Email Delivery', '#'];
  ?>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
      <?php foreach ($crumbs as $i => $c): $last = ($i === count($crumbs)-1); ?>
        <li class="breadcrumb-item <?= $last ? 'active' : '' ?>">
          <?= $last ? esc($c[0]) : '<a href="'.esc($c[1]).'">'.esc($c[0]).'</a>' ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <?php if ($flash): ?>
    <div class="alert alert-success py-2 small" data-testid="inv-flash"><?= esc($flash) ?></div>
  <?php endif; ?>

  <?php
  // ============================================================
  // VIEW 1: HOME — Parent groups
  // ============================================================
  if ($view === 'home'):
      // headline KPIs
      $kpiSold      = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="sold"')->fetchColumn();
      $kpiAvailable = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="available"')->fetchColumn();
      $kpiRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered')")->fetchColumn();
      $kpiProducts  = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
      <div>
        <h1 class="h4 fw-bold mb-1"><i class="bi bi-boxes text-primary me-2"></i>Inventory Management</h1>
        <p class="text-secondary mb-0 small">Browse by category, manage license keys, view allocations and reports.</p>
      </div>
      <div class="d-flex gap-2">
        <a href="inventory.php?view=reports" class="btn btn-outline-primary btn-sm"><i class="bi bi-graph-up me-1"></i>Sales &amp; Revenue Reports</a>
        <a href="inventory.php?view=emails" class="btn btn-outline-primary btn-sm"><i class="bi bi-envelope-paper me-1"></i>Automated Email Delivery</a>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Total Products</small><div class="fs-3 fw-bold"><?= $kpiProducts ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Keys Available</small><div class="fs-3 fw-bold text-success"><?= $kpiAvailable ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Keys Sold</small><div class="fs-3 fw-bold text-primary"><?= $kpiSold ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Total Revenue</small><div class="fs-3 fw-bold">$<?= number_format($kpiRevenue, 0) ?></div></div></div>
    </div>

    <h5 class="fw-bold mb-3">Category Selection</h5>
    <div class="row g-3" data-testid="parent-groups">
      <?php foreach ($groups as $key => $g):
          // Compute totals per parent group
          $allCats = [];
          foreach ($g['children'] as $c) { if (!empty($c['all'])) { $allCats = $c['all']; break; } }
          if (!$allCats) $allCats = array_column($g['children'], 'slug');
          $in = implode(',', array_fill(0, count($allCats), '?'));
          $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category IN ($in)");
          $stmt->execute($allCats);
          $pcnt = (int)$stmt->fetchColumn();
          $stmt = $pdo->prepare("SELECT
              SUM(lk.status='available') AS a,
              SUM(lk.status='sold') AS s
              FROM license_keys lk JOIN products p ON p.slug=lk.product_slug WHERE p.category IN ($in)");
          $stmt->execute($allCats);
          $kk = $stmt->fetch() ?: ['a'=>0,'s'=>0];
      ?>
        <div class="col-md-6 col-lg-4">
          <a href="inventory.php?view=category&parent=<?= esc($key) ?>" class="card p-4 text-decoration-none text-body category-card h-100" data-testid="parent-<?= esc($key) ?>"
             style="border-left:5px solid <?= esc($g['color']) ?>;transition:transform .15s,box-shadow .15s;">
            <div class="d-flex align-items-start gap-3">
              <div style="width:54px;height:54px;border-radius:12px;background:<?= esc($g['color']) ?>15;display:flex;align-items:center;justify-content:center;color:<?= esc($g['color']) ?>;font-size:26px;">
                <i class="bi <?= esc($g['icon']) ?>"></i>
              </div>
              <div class="flex-grow-1">
                <h6 class="fw-bold mb-1"><?= esc($g['name']) ?></h6>
                <small class="text-secondary d-block mb-2"><?= esc($g['desc']) ?></small>
                <div class="d-flex gap-3 small">
                  <span><span class="text-secondary">Products:</span> <strong><?= $pcnt ?></strong></span>
                  <span><span class="text-secondary">Available:</span> <strong class="text-success"><?= (int)$kk['a'] ?></strong></span>
                  <span><span class="text-secondary">Sold:</span> <strong class="text-primary"><?= (int)$kk['s'] ?></strong></span>
                </div>
              </div>
              <i class="bi bi-chevron-right text-secondary"></i>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

  <?php
  // ============================================================
  // VIEW 2: CATEGORY SELECTION (subcategories)
  // ============================================================
  elseif ($view === 'category' && isset($groups[$_GET['parent'] ?? ''])):
      $p = $groups[$_GET['parent']];
  ?>
    <h1 class="h4 fw-bold mb-3">
      <i class="bi <?= esc($p['icon']) ?> me-2" style="color:<?= esc($p['color']) ?>"></i><?= esc($p['name']) ?>
    </h1>
    <p class="text-secondary mb-4 small">Pick a sub-category to view its products.</p>

    <div class="row g-3" data-testid="subcategories">
      <?php foreach ($p['children'] as $c):
          $cats = inv_resolve_categories($c['slug'], $groups);
          $in = implode(',', array_fill(0, count($cats), '?'));
          $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category IN ($in)");
          $stmt->execute($cats);
          $pcnt = (int)$stmt->fetchColumn();
          $stmt = $pdo->prepare("SELECT SUM(lk.status='available') a, SUM(lk.status='sold') s
              FROM license_keys lk JOIN products p ON p.slug=lk.product_slug WHERE p.category IN ($in)");
          $stmt->execute($cats);
          $kk = $stmt->fetch() ?: ['a'=>0,'s'=>0];
          $isAll = !empty($c['all']);
      ?>
        <div class="col-md-6 col-lg-4">
          <a href="inventory.php?view=products&cat=<?= esc($c['slug']) ?>"
             class="card p-3 text-decoration-none text-body subcategory-card h-100 <?= $isAll?'border-primary border-2':'' ?>"
             data-testid="subcat-<?= esc($c['slug']) ?>">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="fw-bold mb-1"><?= $isAll ? '<i class="bi bi-collection me-1"></i>' : '' ?><?= esc($c['name']) ?></h6>
                <small class="text-secondary"><?= $pcnt ?> products · <?= (int)$kk['a'] ?> keys available</small>
              </div>
              <i class="bi bi-chevron-right text-secondary"></i>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

  <?php
  // ============================================================
  // VIEW 3: PRODUCT LIST WITH IMAGES
  // ============================================================
  elseif ($view === 'products' && !empty($_GET['cat'])):
      $cat = $_GET['cat'];
      $cats = inv_resolve_categories($cat, $groups);
      $in = implode(',', array_fill(0, count($cats), '?'));
      $stmt = $pdo->prepare("SELECT * FROM products WHERE category IN ($in) ORDER BY platform, name");
      $stmt->execute($cats);
      $prods = $stmt->fetchAll();
      $title = inv_friendly_cat($cat, $groups);
  ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3 gap-2">
      <h1 class="h4 fw-bold mb-0"><i class="bi bi-grid me-2"></i><?= esc($title) ?> <span class="badge bg-light text-dark ms-2"><?= count($prods) ?> products</span></h1>
    </div>

    <?php if (empty($prods)): ?>
      <div class="alert alert-warning small">No products in this category.</div>
    <?php else: ?>
    <div class="row g-3" data-testid="product-list">
      <?php foreach ($prods as $pr):
          $av = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE product_slug='.$pdo->quote($pr['slug']).' AND status="available"')->fetchColumn();
          $so = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE product_slug='.$pdo->quote($pr['slug']).' AND status="sold"')->fetchColumn();
          $low = $av < 5;
      ?>
        <div class="col-md-6 col-lg-4">
          <a href="inventory.php?view=product&slug=<?= esc($pr['slug']) ?>" class="card text-decoration-none text-body h-100 product-card"
             data-testid="prod-<?= esc($pr['slug']) ?>" style="transition:transform .15s,box-shadow .15s;">
            <div style="height:160px;background:#f8f9fb;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:center;border-radius:8px 8px 0 0;overflow:hidden;">
              <?php if ($pr['image']): ?>
                <img src="<?= esc($pr['image']) ?>" alt="<?= esc($pr['name']) ?>" style="max-width:90%;max-height:140px;object-fit:contain;">
              <?php else: ?>
                <i class="bi bi-box-seam" style="font-size:48px;color:#cbd5e1;"></i>
              <?php endif; ?>
            </div>
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0 me-2 small"><?= esc($pr['name']) ?></h6>
                <span class="badge bg-secondary"><?= esc($pr['platform']) ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold text-primary">$<?= number_format($pr['price'], 2) ?></div>
                <?php if ($pr['original_price']): ?>
                  <small class="text-secondary text-decoration-line-through">$<?= number_format($pr['original_price'],2) ?></small>
                <?php endif; ?>
              </div>
              <div class="d-flex justify-content-between small">
                <span><span class="text-secondary">Keys avail:</span>
                  <strong class="<?= $low?'text-danger':'text-success' ?>"><?= $av ?></strong>
                  <?php if ($low): ?><i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Low stock"></i><?php endif; ?>
                </span>
                <span><span class="text-secondary">Sold:</span> <strong><?= $so ?></strong></span>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php
  // ============================================================
  // VIEW 4: PRODUCT DETAILS + License Keys + Allocation History
  // ============================================================
  elseif ($view === 'product' && !empty($_GET['slug'])):
      $slug = $_GET['slug'];
      $st = $pdo->prepare('SELECT * FROM products WHERE slug=?');
      $st->execute([$slug]);
      $pr = $st->fetch();
      if (!$pr) { echo '<div class="alert alert-danger">Product not found.</div>'; include __DIR__ . '/includes/footer.php'; exit; }

      $tab = $_GET['tab'] ?? 'overview';

      // Key counts
      $keyCounts = $pdo->prepare("SELECT
          SUM(status='available') AS available,
          SUM(status='sold')      AS sold,
          SUM(status='expired')   AS expired,
          COUNT(*) AS total
          FROM license_keys WHERE product_slug=?");
      $keyCounts->execute([$slug]);
      $kc = $keyCounts->fetch();

      // Keys listing
      $keys = $pdo->prepare('SELECT lk.*, o.order_number, o.email, o.first_name, o.last_name
          FROM license_keys lk LEFT JOIN orders o ON o.id = lk.order_id
          WHERE lk.product_slug=? ORDER BY lk.created_at DESC LIMIT 500');
      $keys->execute([$slug]);
      $keys = $keys->fetchAll();

      // Allocation history (only sold)
      $hist = $pdo->prepare("SELECT lk.license_key, lk.assigned_at, o.order_number, o.email, o.first_name, o.last_name, o.total, o.status
          FROM license_keys lk JOIN orders o ON o.id=lk.order_id
          WHERE lk.product_slug=? AND lk.status='sold' ORDER BY lk.assigned_at DESC");
      $hist->execute([$slug]);
      $hist = $hist->fetchAll();
  ?>
    <div class="row g-4 mb-3">
      <div class="col-md-3">
        <div class="card p-3" style="height:200px;display:flex;align-items:center;justify-content:center;background:#f8f9fb;">
          <?php if ($pr['image']): ?>
            <img src="<?= esc($pr['image']) ?>" alt="<?= esc($pr['name']) ?>" style="max-width:100%;max-height:160px;object-fit:contain;">
          <?php else: ?>
            <i class="bi bi-box-seam" style="font-size:64px;color:#cbd5e1;"></i>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-9">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <h1 class="h4 fw-bold mb-1" data-testid="product-title"><?= esc($pr['name']) ?></h1>
            <div class="d-flex gap-2 mb-2 flex-wrap">
              <span class="badge bg-secondary"><?= esc($pr['platform']) ?></span>
              <span class="badge bg-light text-dark"><?= esc(inv_friendly_cat($pr['category'], $groups)) ?></span>
              <?php if ($pr['badge']): ?><span class="badge bg-warning text-dark"><?= esc($pr['badge']) ?></span><?php endif; ?>
            </div>
            <div class="fs-4 fw-bold text-primary mb-2">$<?= number_format($pr['price'],2) ?>
              <?php if ($pr['original_price']): ?>
                <small class="text-secondary text-decoration-line-through fs-6">$<?= number_format($pr['original_price'],2) ?></small>
              <?php endif; ?>
            </div>
            <p class="text-secondary mb-1 small">SKU/Slug: <code><?= esc($pr['slug']) ?></code></p>
            <p class="text-secondary mb-0 small">Apps: <?= esc($pr['apps']) ?: '—' ?> · Rating: <?= esc($pr['rating']) ?>/5 (<?= (int)$pr['reviews'] ?> reviews)</p>
          </div>
          <div class="row g-2" style="max-width:340px;">
            <div class="col-4"><div class="card p-2 text-center"><div class="fs-5 fw-bold text-success"><?= (int)$kc['available'] ?></div><small class="text-secondary">Available</small></div></div>
            <div class="col-4"><div class="card p-2 text-center"><div class="fs-5 fw-bold text-primary"><?= (int)$kc['sold'] ?></div><small class="text-secondary">Sold</small></div></div>
            <div class="col-4"><div class="card p-2 text-center"><div class="fs-5 fw-bold text-warning"><?= (int)$kc['expired'] ?></div><small class="text-secondary">Expired</small></div></div>
          </div>
        </div>
      </div>
    </div>

    <ul class="nav nav-pills gap-1 mb-3" data-testid="product-tabs">
      <?php foreach (['overview'=>'Overview','keys'=>'License Key Inventory','history'=>'Customer Allocation History'] as $t=>$lbl): ?>
        <li class="nav-item"><a class="nav-link <?= $tab===$t?'active':'' ?>" href="inventory.php?view=product&slug=<?= esc($slug) ?>&tab=<?= $t ?>" data-testid="tab-<?= $t ?>"><?= esc($lbl) ?></a></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'overview'): ?>
      <div class="card p-4">
        <h6 class="fw-bold mb-2">Edit Product</h6>
        <form method="post" action="admin.php?tab=products" class="row g-2">
          <input type="hidden" name="action" value="update_product">
          <input type="hidden" name="slug" value="<?= esc($pr['slug']) ?>">
          <div class="col-md-3"><label class="form-label small">Price</label><input class="form-control form-control-sm" name="price" type="number" step="0.01" value="<?= esc($pr['price']) ?>"></div>
          <div class="col-md-3"><label class="form-label small">Original Price</label><input class="form-control form-control-sm" name="original_price" type="number" step="0.01" value="<?= esc($pr['original_price']) ?>"></div>
          <div class="col-md-3"><label class="form-label small">Badge</label><input class="form-control form-control-sm" name="badge" value="<?= esc($pr['badge']) ?>"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100">Save</button></div>
        </form>
      </div>

    <?php elseif ($tab === 'keys'): ?>
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-3">
            <h6 class="fw-bold mb-2"><i class="bi bi-key me-1"></i> Add License Keys</h6>
            <form method="post" data-testid="add-keys-form">
              <input type="hidden" name="action" value="add_keys">
              <input type="hidden" name="product_slug" value="<?= esc($slug) ?>">
              <textarea name="keys" class="form-control form-control-sm font-monospace" rows="9" placeholder="One key per line:&#10;XXXXX-XXXXX-XXXXX-XXXXX&#10;YYYYY-YYYYY-YYYYY-YYYYY" required></textarea>
              <button class="btn btn-primary btn-sm w-100 mt-2">Add to Inventory</button>
            </form>
            <hr>
            <small class="text-secondary">Keys are auto-assigned to a customer when their order is marked Paid; the customer then receives an automated email with the key + installation guide.</small>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <strong>License Keys (<?= (int)$kc['total'] ?>)</strong>
            </div>
            <div class="table-responsive">
              <table class="table table-sm mb-0 align-middle" data-testid="keys-table">
                <thead class="table-light">
                  <tr><th>License Key</th><th>Status</th><th>Assigned To</th><th>Order</th><th>Date</th><th></th></tr>
                </thead>
                <tbody>
                  <?php if (empty($keys)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">No keys yet. Add some on the left.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($keys as $k):
                      $statusColor = ['available'=>'success','sold'=>'primary','expired'=>'secondary'][$k['status']] ?? 'dark';
                  ?>
                    <tr>
                      <td><code style="font-size:12px;"><?= esc($k['license_key']) ?></code></td>
                      <td><span class="badge bg-<?= $statusColor ?>"><?= esc($k['status']) ?></span></td>
                      <td><?= $k['email'] ? esc(trim($k['first_name'].' '.$k['last_name'])).'<br><small class="text-secondary">'.esc($k['email']).'</small>' : '<span class="text-secondary">—</span>' ?></td>
                      <td><?= $k['order_number'] ? '<a href="admin.php?tab=orders">'.esc($k['order_number']).'</a>' : '—' ?></td>
                      <td><small><?= esc(date('M j, Y', strtotime($k['assigned_at'] ?: $k['created_at']))) ?></small></td>
                      <td class="text-end">
                        <?php if ($k['status']==='available'): ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete key?')">
                            <input type="hidden" name="action" value="delete_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <input type="hidden" name="back" value="inventory.php?view=product&slug=<?= esc($slug) ?>&tab=keys">
                            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($k['status']!=='expired'): ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Mark as expired?')">
                            <input type="hidden" name="action" value="expire_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <input type="hidden" name="back" value="inventory.php?view=product&slug=<?= esc($slug) ?>&tab=keys">
                            <button class="btn btn-sm btn-outline-warning py-0 px-2"><i class="bi bi-clock"></i></button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'history'): ?>
      <div class="card p-0">
        <div class="card-header bg-white">
          <strong>Customer Allocation History</strong> <small class="text-secondary">(keys sold to customers)</small>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle" data-testid="history-table">
            <thead class="table-light">
              <tr><th>Date</th><th>Customer</th><th>Email</th><th>Order#</th><th>License Key</th><th>Amount</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php if (empty($hist)): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">No customer allocations for this product yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($hist as $h): ?>
                <tr>
                  <td><small><?= esc(date('M j, Y H:i', strtotime($h['assigned_at']))) ?></small></td>
                  <td><?= esc(trim($h['first_name'].' '.$h['last_name'])) ?></td>
                  <td><small><?= esc($h['email']) ?></small></td>
                  <td><code><?= esc($h['order_number']) ?></code></td>
                  <td><code style="font-size:12px;"><?= esc($h['license_key']) ?></code></td>
                  <td>$<?= number_format($h['total'],2) ?></td>
                  <td><span class="badge bg-<?= $h['status']==='paid'||$h['status']==='delivered'?'success':'secondary' ?>"><?= esc($h['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  <?php
  // ============================================================
  // VIEW 5: SALES & REVENUE REPORTS
  // ============================================================
  elseif ($view === 'reports'):
      $today = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND DATE(created_at)=CURDATE()")->fetchColumn();
      $week  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
      $month = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
      $year  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

      $byCategory = $pdo->query("SELECT p.category, SUM(oi.qty) AS units, SUM(oi.price*oi.qty) AS rev
          FROM order_items oi
          JOIN orders o ON o.id=oi.order_id
          JOIN products p ON p.slug=oi.product_slug
          WHERE o.status IN ('paid','delivered')
          GROUP BY p.category ORDER BY rev DESC")->fetchAll();

      $byProduct = $pdo->query("SELECT oi.product_slug, oi.name, p.image, SUM(oi.qty) units, SUM(oi.qty*oi.price) revenue
          FROM order_items oi
          JOIN orders o ON o.id=oi.order_id
          LEFT JOIN products p ON p.slug=oi.product_slug
          WHERE o.status IN ('paid','delivered')
          GROUP BY oi.product_slug, oi.name, p.image
          ORDER BY revenue DESC LIMIT 15")->fetchAll();

      $recent = $pdo->query("SELECT o.*, COUNT(oi.id) items_count FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id
          GROUP BY o.id ORDER BY o.created_at DESC LIMIT 30")->fetchAll();
  ?>
    <h1 class="h4 fw-bold mb-3"><i class="bi bi-graph-up me-2"></i>Sales &amp; Revenue Reports</h1>

    <div class="row g-3 mb-4" data-testid="kpi-tiles">
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Today</small><div class="fs-4 fw-bold">$<?= number_format($today,2) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Last 7 days</small><div class="fs-4 fw-bold">$<?= number_format($week,2) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">This Month</small><div class="fs-4 fw-bold">$<?= number_format($month,2) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">This Year</small><div class="fs-4 fw-bold">$<?= number_format($year,2) ?></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card p-3">
          <h6 class="fw-bold mb-3">Revenue by Category</h6>
          <table class="table table-sm">
            <thead class="table-light"><tr><th>Category</th><th class="text-end">Units</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
              <?php if (empty($byCategory)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No sales yet.</td></tr><?php endif; ?>
              <?php foreach ($byCategory as $c): ?>
                <tr>
                  <td><?= esc(inv_friendly_cat($c['category'], $groups)) ?></td>
                  <td class="text-end"><?= (int)$c['units'] ?></td>
                  <td class="text-end fw-semibold">$<?= number_format($c['rev'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-3">
          <h6 class="fw-bold mb-3">Top Products by Revenue</h6>
          <table class="table table-sm">
            <thead class="table-light"><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
              <?php if (empty($byProduct)): ?><tr><td colspan="3" class="text-center text-secondary py-3">No sales yet.</td></tr><?php endif; ?>
              <?php foreach ($byProduct as $p): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <?php if ($p['image']): ?><img src="<?= esc($p['image']) ?>" style="width:28px;height:28px;object-fit:contain;"><?php endif; ?>
                      <a href="inventory.php?view=product&slug=<?= esc($p['product_slug']) ?>" class="small"><?= esc($p['name']) ?></a>
                    </div>
                  </td>
                  <td class="text-end"><?= (int)$p['units'] ?></td>
                  <td class="text-end fw-semibold">$<?= number_format($p['revenue'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card mt-3 p-0">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Recent Orders</strong>
        <a href="admin.php?tab=orders" class="small">View all orders →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Order#</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($recent)): ?><tr><td colspan="6" class="text-center text-secondary py-3">No orders yet.</td></tr><?php endif; ?>
            <?php foreach ($recent as $o): ?>
              <tr>
                <td><code><?= esc($o['order_number']) ?></code></td>
                <td><?= esc(trim($o['first_name'].' '.$o['last_name'])) ?><br><small class="text-secondary"><?= esc($o['email']) ?></small></td>
                <td><?= (int)$o['items_count'] ?></td>
                <td>$<?= number_format($o['total'],2) ?></td>
                <td><span class="badge bg-<?= in_array($o['status'],['paid','delivered'])?'success':'secondary' ?>"><?= esc($o['status']) ?></span></td>
                <td><small><?= esc(date('M j, Y', strtotime($o['created_at']))) ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php
  // ============================================================
  // VIEW 6: AUTOMATED EMAIL DELIVERY SYSTEM
  // ============================================================
  elseif ($view === 'emails'):
      $statusFilter = $_GET['status'] ?? '';
      $where = ''; $args = [];
      if (in_array($statusFilter, ['queued','sent','failed'], true)) { $where='WHERE status=?'; $args=[$statusFilter]; }
      $st = $pdo->prepare("SELECT * FROM email_outbox $where ORDER BY created_at DESC LIMIT 200");
      $st->execute($args);
      $emails = $st->fetchAll();

      $counts = $pdo->query("SELECT
          SUM(status='queued') AS queued,
          SUM(status='sent')   AS sent,
          SUM(status='failed') AS failed,
          COUNT(*) AS total
          FROM email_outbox")->fetch() ?: ['queued'=>0,'sent'=>0,'failed'=>0,'total'=>0];

      $hasResend = !empty(getenv('RESEND_API_KEY')) || (defined('RESEND_API_KEY') && RESEND_API_KEY !== '');
  ?>
    <h1 class="h4 fw-bold mb-3"><i class="bi bi-envelope-paper me-2"></i>Automated Email Delivery System</h1>

    <?php if (!$hasResend): ?>
      <div class="alert alert-warning small d-flex align-items-center gap-2">
        <i class="bi bi-info-circle"></i>
        <div>Resend API key not configured. Emails are queued in the database when orders are paid. Set <code>RESEND_API_KEY</code> in <code>/app/backend/.env</code> for automatic delivery.</div>
      </div>
    <?php else: ?>
      <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>Resend is configured — license-key emails send automatically when orders are paid.</div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Total Emails</small><div class="fs-4 fw-bold"><?= (int)$counts['total'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Sent</small><div class="fs-4 fw-bold text-success"><?= (int)$counts['sent'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Queued</small><div class="fs-4 fw-bold text-warning"><?= (int)$counts['queued'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card p-3"><small class="text-secondary">Failed</small><div class="fs-4 fw-bold text-danger"><?= (int)$counts['failed'] ?></div></div></div>
    </div>

    <div class="d-flex gap-2 mb-3">
      <?php foreach (['' => 'All', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'] as $k => $lbl): ?>
        <a class="btn btn-sm <?= $statusFilter===$k?'btn-primary':'btn-outline-secondary' ?>" href="inventory.php?view=emails<?= $k?'&status='.$k:'' ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0" data-testid="emails-table">
          <thead class="table-light"><tr><th>Recipient</th><th>Subject</th><th>Order</th><th>Status</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($emails)): ?>
              <tr><td colspan="6" class="text-center text-secondary py-4">No emails in outbox.</td></tr>
            <?php endif; ?>
            <?php foreach ($emails as $em): ?>
              <tr>
                <td><?= esc($em['recipient']) ?></td>
                <td><small><?= esc($em['subject']) ?></small></td>
                <td><?php
                    if ($em['order_id']) {
                        $on = $pdo->prepare('SELECT order_number FROM orders WHERE id=?');
                        $on->execute([$em['order_id']]);
                        echo '<code>'.esc($on->fetchColumn() ?: '#'.$em['order_id']).'</code>';
                    } else echo '—';
                ?></td>
                <td><span class="badge bg-<?= ['sent'=>'success','queued'=>'warning','failed'=>'danger'][$em['status']] ?? 'secondary' ?>"><?= esc($em['status']) ?></span>
                  <?php if ($em['note']): ?><br><small class="text-secondary"><?= esc($em['note']) ?></small><?php endif; ?>
                </td>
                <td><small><?= esc(date('M j, Y H:i', strtotime($em['created_at']))) ?></small></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" href="admin-email-preview.php?id=<?= (int)$em['id'] ?>"><i class="bi bi-eye"></i></a>
                  <?php if ($em['status'] !== 'sent'): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="send_email_now">
                      <input type="hidden" name="email_id" value="<?= (int)$em['id'] ?>">
                      <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Send now"><i class="bi bi-send"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-3 p-3">
      <h6 class="fw-bold mb-2">How automated delivery works</h6>
      <ol class="small text-secondary mb-0">
        <li>Customer places an order via <code>checkout.php</code>.</li>
        <li>Payment is processed (Stripe in production; auto-paid in demo mode).</li>
        <li>System fetches the next <strong>available</strong> license key for each product in the order.</li>
        <li>Key is assigned to the order &amp; marked <strong>sold</strong>.</li>
        <li>Email is composed (product name, license key, installation guide, amount paid, order details, support contact, company logo) and added to <code>email_outbox</code>.</li>
        <li>If <code>RESEND_API_KEY</code> is set, the email is sent immediately. Otherwise it sits as <em>queued</em> for manual send via this page.</li>
      </ol>
    </div>

  <?php else: ?>
    <div class="alert alert-warning">Unknown view. <a href="inventory.php">Return to Inventory Management →</a></div>
  <?php endif; ?>

</div>

<style>
.category-card:hover, .subcategory-card:hover, .product-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0,0,0,.08);
}
.breadcrumb-item a { text-decoration: none; }
</style>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
