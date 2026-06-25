<?php
// Shared admin layout: vertical sidebar nav + content area.
// Include after `include header.php`. The page must define $adminActive (key from $navItems).

$navItems = [
    'dashboard'   => ['icon' => 'bi-speedometer2',  'label' => 'Dashboard',         'href' => 'admin.php?tab=dashboard'],
    'ai-blogger'  => ['icon' => 'bi-robot',         'label' => 'AI Auto-Blogger',   'href' => 'admin.php?tab=ai-blogger'],
    'company'     => ['icon' => 'bi-building',      'label' => 'Company Info',      'href' => 'admin.php?tab=company'],
    'inventory'   => ['icon' => 'bi-boxes',         'label' => 'Inventory Mgmt',    'href' => 'inventory.php'],
    'products'    => ['icon' => 'bi-box-seam',      'label' => 'Products / Key Inventory', 'href' => 'admin.php?tab=products'],
    'orders'      => ['icon' => 'bi-receipt',       'label' => 'Orders',            'href' => 'admin.php?tab=orders'],
    'sales'       => ['icon' => 'bi-graph-up-arrow','label' => 'Sales Detail',      'href' => 'admin.php?tab=sales'],
    'leads'       => ['icon' => 'bi-person-lines-fill','label' => 'Leads',          'href' => 'admin.php?tab=leads'],
    'emails'      => ['icon' => 'bi-envelope',      'label' => 'Emails',            'href' => 'admin.php?tab=emails'],
    'template'    => ['icon' => 'bi-file-earmark-richtext', 'label' => 'Email Template', 'href' => 'admin.php?tab=template'],
    'settings'    => ['icon' => 'bi-gear',          'label' => 'Settings',          'href' => 'admin.php?tab=settings'],
];
$adminActive = $adminActive ?? '';
?>
<style>
/* ============ ADMIN LAYOUT ============ */
body { background: #f7f8fa !important; }
.admin-shell { display: flex; gap: 24px; align-items: flex-start; padding: 24px 12px; max-width: 1500px; margin: 0 auto; }
.admin-sidebar {
  width: 240px; flex-shrink: 0;
  background: #ffffff;
  border: 1px solid #e9ecef;
  border-radius: 14px;
  padding: 16px 0;
  position: sticky; top: 90px;
  box-shadow: 0 1px 2px rgba(15,23,42,.03);
}
.admin-sidebar .side-title {
  padding: 4px 18px 14px;
  font-size: 11px; letter-spacing: 1.5px;
  color: #94a3b8; text-transform: uppercase; font-weight: 700;
  border-bottom: 1px solid #f1f3f5; margin-bottom: 8px;
}
.admin-sidebar .nav-item-link {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 18px;
  color: #475569; font-size: 14px; font-weight: 500;
  text-decoration: none;
  border-left: 3px solid transparent;
  transition: background .15s, color .15s, border-color .15s;
}
.admin-sidebar .nav-item-link i { font-size: 17px; width: 20px; }
.admin-sidebar .nav-item-link:hover { background: #f1f5f9; color: #0f172a; }
.admin-sidebar .nav-item-link.active {
  background: #eff6ff;
  border-left-color: #3b82f6;
  color: #1d4ed8; font-weight: 600;
}
.admin-content { flex: 1; min-width: 0; }

/* ============ ELEGANT TABLES ============ */
.tbl-elegant {
  background: #fff; border: 1px solid #eef0f3; border-radius: 12px; overflow: hidden;
}
.tbl-elegant table { margin: 0; }
.tbl-elegant thead th {
  background: #f8fafc; color: #64748b;
  text-transform: uppercase; font-size: 11px; letter-spacing: .8px;
  font-weight: 600; padding: 12px 14px; border: none;
}
.tbl-elegant tbody td { padding: 13px 14px; border-top: 1px solid #f1f3f5; vertical-align: middle; font-size: 13.5px; }
.tbl-elegant tbody tr:hover { background: #f8fafc; }

/* ============ BUTTON PALETTE ============ */
.btn-soft-gray { background:#e5e7eb; color:#374151; border:none; }
.btn-soft-gray:hover { background:#d1d5db; color:#111827; }
.btn-soft-green { background:#d1fae5; color:#047857; border:none; }
.btn-soft-green:hover { background:#a7f3d0; color:#065f46; }
.btn-soft-blue { background:#dbeafe; color:#1d4ed8; border:none; }
.btn-soft-blue:hover { background:#bfdbfe; color:#1e3a8a; }
.btn-soft-red { background:#fee2e2; color:#b91c1c; border:none; }
.btn-soft-red:hover { background:#fecaca; color:#7f1d1d; }

/* ============ STATUS BADGES ============ */
.s-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; letter-spacing:.3px; }
.s-badge.queued    { background:#fef3c7; color:#92400e; }
.s-badge.sent      { background:#dbeafe; color:#1d4ed8; }
.s-badge.delivered { background:#d1fae5; color:#047857; }
.s-badge.opened    { background:#cffafe; color:#0e7490; }
.s-badge.failed    { background:#fee2e2; color:#b91c1c; }
.s-badge.bounced   { background:#fee2e2; color:#b91c1c; }
.s-badge.paid      { background:#d1fae5; color:#047857; }
.s-badge.pending   { background:#fef3c7; color:#92400e; }
.s-badge.refunded  { background:#e5e7eb; color:#374151; }

/* ============ PRODUCT KEY CARDS ============ */
.key-stats { display:flex; gap:10px; }
.key-stats .key-pill {
  flex:1; background:#fff; border:1px solid #e9ecef; border-radius:10px;
  padding:12px 14px; text-align:center;
}
.key-stats .key-pill .num { font-size:22px; font-weight:700; line-height:1; }
.key-stats .key-pill .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#64748b; margin-top:4px; }
.key-stats .key-pill.avail  { border-left:4px solid #10b981; }
.key-stats .key-pill.sold   { border-left:4px solid #3b82f6; }

/* ============ ADD BUTTON GLOW ============ */
.btn-add-glow {
  background: linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);
  color:#fff; border:none; border-radius:50%;
  width:56px; height:56px; font-size:24px;
  display:inline-flex; align-items:center; justify-content:center;
  box-shadow: 0 0 0 0 rgba(59,130,246,.6);
  animation: glowpulse 2s infinite;
  transition: transform .2s;
  cursor:pointer;
}
.btn-add-glow:hover { transform: scale(1.08); color:#fff; }
@keyframes glowpulse {
  0%,100% { box-shadow:0 0 0 0 rgba(59,130,246,.55), 0 4px 12px rgba(59,130,246,.35); }
  50%     { box-shadow:0 0 0 12px rgba(59,130,246,0),   0 4px 12px rgba(59,130,246,.35); }
}

.card-elegant { background:#fff; border:1px solid #eef0f3; border-radius:12px; }

@media (max-width: 991px) {
  .admin-shell { flex-direction: column; padding: 16px; }
  .admin-sidebar { width: 100%; position: static; }
  .admin-sidebar .nav-item-link { border-left: none; border-bottom: 2px solid transparent; }
  .admin-sidebar .nav-item-link.active { border-left: none; border-bottom-color:#3b82f6; }
}
</style>

<div class="admin-shell">
  <aside class="admin-sidebar" data-testid="admin-sidebar">
    <div class="side-title">Admin Panel</div>
    <?php foreach ($navItems as $key => $it): ?>
      <a class="nav-item-link <?= $adminActive === $key ? 'active' : '' ?>" href="<?= esc($it['href']) ?>" data-testid="adm-nav-<?= $key ?>">
        <i class="bi <?= esc($it['icon']) ?>"></i>
        <span><?= esc($it['label']) ?></span>
      </a>
    <?php endforeach; ?>
    <div class="side-title" style="border-top:1px solid #f1f3f5;border-bottom:none;margin-top:14px;padding-top:14px;">Account</div>
    <a class="nav-item-link" href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
  </aside>
  <div class="admin-content">
