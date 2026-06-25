<?php
/*
 * Visitor stats — admin-only AJAX partial.
 *   GET /ajax/visitor-stats.php?from=YYYY-MM-DD&to=YYYY-MM-DD
 *       &os=<os>&device=<device>&country=<XX>
 *   Returns the HTML fragment for the #visitorsBody div.  All filters
 *   compose with each other (AND).  Empty filter values = no constraint.
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin();
header('Content-Type: text/html; charset=utf-8');

$pdo  = db();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to']   ?? '')) ? $_GET['to']   : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$os      = trim((string)($_GET['os']      ?? ''));
$device  = trim((string)($_GET['device']  ?? ''));
$country = trim((string)($_GET['country'] ?? ''));

// Build the WHERE clause (always range-bounded + non-empty session_id) and a
// list of params so subqueries stay parameterised.
function buildWhere(string $from, string $to, string $os, string $device, string $country): array {
    $w = "DATE(visited_at) BETWEEN ? AND ? AND session_id<>''"; $p = [$from, $to];
    if ($os      !== '') { $w .= " AND os=?";      $p[] = $os; }
    if ($device  !== '') { $w .= " AND device=?";  $p[] = $device; }
    if ($country !== '') { $w .= " AND country=?"; $p[] = $country; }
    return [$w, $p];
}
[$w, $p] = buildWhere($from, $to, $os, $device, $country);

// Previous period of equal length for the % delta.
$rangeDays = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
$prevFrom  = date('Y-m-d', strtotime($from) - 86400 * $rangeDays);
$prevTo    = date('Y-m-d', strtotime($from) - 86400);
[$prevW, $prevP] = buildWhere($prevFrom, $prevTo, $os, $device, $country);

$q = function(string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st; };

$uniq   = (int)$q("SELECT COUNT(DISTINCT session_id) FROM visitor_log WHERE $w", $p)->fetchColumn();
$hits   = (int)$q("SELECT COUNT(*) FROM visitor_log WHERE $w", $p)->fetchColumn();
$prev   = (int)$q("SELECT COUNT(DISTINCT session_id) FROM visitor_log WHERE $prevW", $prevP)->fetchColumn();
$delta  = $prev > 0 ? round((($uniq - $prev) / $prev) * 100) : ($uniq > 0 ? 100 : 0);

$osRows  = $q("SELECT os, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $w GROUP BY os ORDER BY c DESC LIMIT 8", $p)->fetchAll();
$devRows = $q("SELECT device, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $w GROUP BY device ORDER BY c DESC", $p)->fetchAll();
$ctyRows = $q("SELECT country, COUNT(DISTINCT session_id) c FROM visitor_log WHERE $w AND country<>'' GROUP BY country ORDER BY c DESC LIMIT 4", $p)->fetchAll();

// Trend chart (daily for ≤60d, weekly otherwise).
$useDay = $rangeDays <= 60;
if ($useDay) {
    $n = min(31, max(7, $rangeDays));
    $rows = $q("SELECT DATE(visited_at) d, COUNT(DISTINCT session_id) c
                FROM visitor_log WHERE $w GROUP BY DATE(visited_at)", $p)->fetchAll();
    $map = []; foreach ($rows as $r) $map[$r['d']] = (int)$r['c'];
    $trend = [];
    $fromTs = strtotime($from);
    for ($i = 0; $i < $n; $i++) { $d = date('Y-m-d', $fromTs + $i*86400); if ($d > $to) break; $trend[] = ['d'=>$d, 'lbl'=>date('d', strtotime($d)), 'c'=>(int)($map[$d] ?? 0)]; }
} else {
    $n = 14;
    $rows = $q("SELECT YEARWEEK(visited_at, 3) yw, MIN(DATE(visited_at)) d, COUNT(DISTINCT session_id) c
                FROM visitor_log WHERE $w GROUP BY YEARWEEK(visited_at, 3) ORDER BY yw ASC", $p)->fetchAll();
    $trend = [];
    foreach ($rows as $r) $trend[] = ['d'=>$r['d'], 'lbl'=>'W'.((int)substr($r['yw'],-2)), 'c'=>(int)$r['c']];
    while (count($trend) < $n) array_unshift($trend, ['d'=>'', 'lbl'=>'', 'c'=>0]);
}
$trendMax = max(array_column($trend,'c')) ?: 1;

// Helper — country code → emoji flag.  Uses unicode regional indicator chars.
$flag = function(string $cc): string {
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2) return '🌐';
    $A = 0x1F1E6;
    return mb_chr($A + (ord($cc[0]) - ord('A'))) . mb_chr($A + (ord($cc[1]) - ord('A')));
};

$osIcons = [
    'Windows 10/11'=>['bi-windows','#0078D4'], 'Windows 8.1'=>['bi-windows','#0078D4'],
    'Windows 8'=>['bi-windows','#0078D4'], 'Windows 7'=>['bi-windows','#0078D4'],
    'Windows'=>['bi-windows','#0078D4'],
    'macOS'=>['bi-apple','#1d1d1f'], 'iOS'=>['bi-apple','#1d1d1f'],
    'Android'=>['bi-android2','#3DDC84'], 'Linux'=>['bi-ubuntu','#E95420'],
    'Chrome OS'=>['bi-google','#FBBC05'], 'Unknown'=>['bi-question-circle','#9ca3af'],
];
$devIcons = ['Desktop'=>['bi-display','#3b82f6'], 'Mobile'=>['bi-phone','#10b981'], 'Tablet'=>['bi-tablet','#f59e0b']];

$activeChips = [];
if ($os !== '')      $activeChips[] = ['os', $os, $os];
if ($device !== '')  $activeChips[] = ['device', $device, $device];
if ($country !== '') $activeChips[] = ['country', $country, $flag($country) . ' ' . $country];
?>
<div class="vis-header-row">
  <div class="ttl"><i class="bi bi-people-fill"></i> Visitors
    <span class="vis-range-lbl"><?= esc(date('M j', strtotime($from))) ?> → <?= esc(date('M j, Y', strtotime($to))) ?> · real humans · bots filtered</span>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php foreach ($activeChips as $chip): ?>
      <span class="vis-active-chip" data-clear="<?= esc($chip[0]) ?>" data-testid="vis-active-<?= esc($chip[0]) ?>"><?= esc($chip[2]) ?> <i class="bi bi-x-circle-fill ms-1"></i></span>
    <?php endforeach; ?>
    <span class="badge bg-success-subtle text-success" style="font-size:11px;"><i class="bi bi-eye-fill me-1"></i><?= number_format($hits) ?> page-views</span>
  </div>
</div>

<div class="card-body-p">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="vis-headline">
        <div class="vis-num" data-testid="visitors-today-unique"><?= number_format($uniq) ?></div>
        <div class="vis-lbl">unique visitors</div>
        <div class="vis-delta <?= $delta>=0?'up':'down' ?>">
          <i class="bi bi-arrow-<?= $delta>=0?'up':'down' ?>-right"></i>
          <?= $delta>=0?'+':'' ?><?= $delta ?>%
          <span class="text-muted ms-1">vs previous (<?= number_format($prev) ?>)</span>
        </div>
        <div class="vis-spark">
          <?php foreach ($trend as $tt):
            $h = max(8, ($tt['c']/$trendMax)*100);
            $isCurrent = $tt['d'] === date('Y-m-d');
          ?>
            <div class="vis-spark-bar <?= $isCurrent?'today':'' ?>" style="height:<?= $h ?>%;" title="<?= esc($tt['d']) ?>: <?= $tt['c'] ?> visitors">
              <span class="vis-spark-val"><?= $tt['c']>0 ? $tt['c'] : '' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="vis-spark-x">
          <?php foreach ($trend as $tt): ?><span><?= esc($tt['lbl'] ?? '') ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4 col-md-6">
      <div class="vis-block">
        <div class="vis-block-ttl"><i class="bi bi-display me-1"></i> Operating System <span class="text-muted small fw-normal">— click to filter</span></div>
        <?php if (empty($osRows)): ?>
          <div class="text-muted small py-3 text-center">No data in this range.</div>
        <?php else: foreach ($osRows as $row):
          $pct = $uniq>0 ? round(((int)$row['c']/$uniq)*100) : 0;
          $ic = $osIcons[$row['os']] ?? ['bi-pc-display','#6b7280'];
        ?>
          <button type="button" class="vis-row vis-filter-row <?= $os===$row['os']?'is-active':'' ?>" data-filter-key="os" data-filter-val="<?= esc($row['os']) ?>" data-testid="vis-os-<?= esc(strtolower(str_replace(['/',' '],'',$row['os']))) ?>">
            <i class="bi <?= esc($ic[0]) ?>" style="color:<?= esc($ic[1]) ?>;font-size:16px;flex-shrink:0;"></i>
            <div class="vis-row-body">
              <div class="vis-row-head">
                <span class="vis-row-name"><?= esc($row['os']) ?></span>
                <span class="vis-row-num"><?= (int)$row['c'] ?> <span class="vis-pct">· <?= $pct ?>%</span></span>
              </div>
              <div class="vis-bar"><span style="width:<?= $pct ?>%;background:<?= esc($ic[1]) ?>;"></span></div>
            </div>
          </button>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="col-lg-4 col-md-6">
      <div class="vis-block">
        <div class="vis-block-ttl"><i class="bi bi-phone me-1"></i> Device <span class="text-muted small fw-normal">— click to filter</span></div>
        <?php if (empty($devRows)): ?>
          <div class="text-muted small py-2">—</div>
        <?php else: foreach ($devRows as $row):
          $pct = $uniq>0 ? round(((int)$row['c']/$uniq)*100) : 0;
          $ic = $devIcons[$row['device']] ?? ['bi-question-circle','#9ca3af'];
        ?>
          <button type="button" class="vis-row vis-filter-row <?= $device===$row['device']?'is-active':'' ?>" data-filter-key="device" data-filter-val="<?= esc($row['device']) ?>" data-testid="vis-device-<?= esc(strtolower($row['device'])) ?>">
            <i class="bi <?= esc($ic[0]) ?>" style="color:<?= esc($ic[1]) ?>;font-size:16px;flex-shrink:0;"></i>
            <div class="vis-row-body">
              <div class="vis-row-head">
                <span class="vis-row-name"><?= esc($row['device']) ?></span>
                <span class="vis-row-num"><?= (int)$row['c'] ?> <span class="vis-pct">· <?= $pct ?>%</span></span>
              </div>
              <div class="vis-bar"><span style="width:<?= $pct ?>%;background:<?= esc($ic[1]) ?>;"></span></div>
            </div>
          </button>
        <?php endforeach; endif; ?>

        <div class="vis-block-ttl mt-3"><i class="bi bi-globe2 me-1"></i> Top Countries <span class="text-muted small fw-normal">— click to filter</span></div>
        <?php if (empty($ctyRows)): ?>
          <div class="text-muted small py-2">—</div>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($ctyRows as $c):
              $pct = $uniq>0 ? round(((int)$c['c']/$uniq)*100) : 0;
            ?>
              <button type="button" class="vis-flag-chip <?= $country===$c['country']?'is-active':'' ?>" data-filter-key="country" data-filter-val="<?= esc($c['country']) ?>" data-testid="vis-country-<?= esc(strtolower($c['country'])) ?>">
                <span class="vis-flag"><?= $flag($c['country']) ?></span>
                <span class="vis-flag-cc"><?= esc($c['country']) ?></span>
                <span class="vis-flag-num"><?= (int)$c['c'] ?> · <?= $pct ?>%</span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
