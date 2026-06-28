<?php
/* ============================================================================
 *  Batch-write proper storefront descriptions for EVERY product that doesn't
 *  have one yet, using the same AI writer as the admin "Generate with AI"
 *  button (includes/ai-product-description.php).
 *
 *  IDEMPOTENT: by default only fills products whose description is empty or
 *  very short (< 40 chars), so re-runs are safe and never clobber edited copy.
 *
 *  Usage:
 *    php scripts/seed-descriptions.php            # fill missing descriptions
 *    php scripts/seed-descriptions.php --force    # regenerate ALL products
 *    php scripts/seed-descriptions.php --only=some-slug
 *  ========================================================================== */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai-product-description.php';

$FORCE = in_array('--force', $argv, true);
$ONLY  = '';
foreach ($argv as $a) { if (strpos($a, '--only=') === 0) $ONLY = substr($a, 7); }

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    fwrite(STDERR, "[seed-descriptions] No LLM key configured (OPENAI_API_KEY/EMERGENT_LLM_KEY). Aborting.\n");
    exit(1);
}

$db   = db();
$sql  = "SELECT id, slug, name, brand, category, apps, platform, year, license_type, description FROM products";
$args = [];
if ($ONLY !== '') { $sql .= " WHERE slug = ?"; $args[] = $ONLY; }
$sql .= " ORDER BY id";
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$upd = $db->prepare("UPDATE products SET description = :d WHERE id = :id");

$written = 0; $skipped = 0; $failed = 0;
foreach ($rows as $r) {
    $cur = trim((string)($r['description'] ?? ''));
    if (!$FORCE && mb_strlen($cur) >= 40) {       // already has a real description
        $skipped++;
        continue;
    }
    $res = ai_write_product_description([
        'name'         => $r['name'],
        'brand'        => $r['brand'],
        'category'     => $r['category'],
        'apps'         => $r['apps'],
        'platform'     => $r['platform'],
        'year'         => $r['year'],
        'license_type' => $r['license_type'],
    ]);
    if (!empty($res['ok']) && trim((string)$res['description']) !== '') {
        $upd->execute([':d' => $res['description'], ':id' => $r['id']]);
        $written++;
        echo sprintf("  [ok]   %-60s (%d chars)\n", $r['slug'], mb_strlen($res['description']));
    } else {
        $failed++;
        echo sprintf("  [FAIL] %-60s %s\n", $r['slug'], $res['error'] ?? 'unknown error');
    }
    usleep(350000); // be gentle on the API
}

echo sprintf(
    "[seed-descriptions] done - %d written, %d already had one (skipped), %d failed.\n",
    $written, $skipped, $failed
);
