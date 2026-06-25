<?php
/* ============================================================================
 *  One-shot migration: assign a deterministic, format-valid GTIN-13 to every
 *  product in the catalog whose `gtin` column is NULL/empty.
 *
 *  WHY a generated GTIN and not a "real" one?
 *  ------------------------------------------------------------------------
 *  Microsoft (and every other software vendor we resell) only ever prints
 *  GTINs on PHYSICAL retail boxes — and even then the same SKU can ship
 *  under half a dozen regional barcodes.  Digital-only licence keys are
 *  not issued GTINs by GS1.  The honest practice for digital resellers is
 *  to use the GS1-reserved "in-store / restricted distribution" prefix
 *  range 0200-0299, which is set aside precisely so retailers can mint
 *  identifiers for items that have no manufacturer-issued barcode without
 *  ever colliding with a real registered GTIN.
 *
 *  • Prefix: `200`     (GS1 in-store range)
 *  • Body  : 9 digits derived from md5(slug|sku)  → deterministic per product
 *  • Last  : GS1 mod-10 checksum                  → barcode-valid
 *
 *  Run once on a fresh deployment:
 *       php /app/php-version/scripts/seed-gtins.php
 *  ========================================================================== */
require_once __DIR__ . '/../includes/functions.php';

function gs1_checksum_mod10(string $first12): string {
    if (strlen($first12) !== 12 || !ctype_digit($first12)) {
        throw new InvalidArgumentException('first12 must be exactly 12 digits');
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $d = (int)$first12[$i];
        // GS1: positions weighted alternately 1,3,1,3,... starting from the LEFT
        // for an EAN-13 where the check digit is the 13th.
        $sum += ($i % 2 === 0) ? $d : $d * 3;
    }
    $cd = (10 - ($sum % 10)) % 10;
    return (string)$cd;
}

function generate_gtin13_for(string $seed): string {
    // 9-digit body from a stable hash so re-runs are idempotent.
    $hash = md5($seed);                                 // 32 hex chars
    $bigDecimal = '';
    foreach (str_split($hash) as $hex) {
        $bigDecimal .= (string)hexdec($hex);
    }
    // Pull 9 deterministic digits.  Skip the first 2 chars so we don't bias
    // toward digit 1 (md5 hex digits 0-9 → '0'..'9', a-f → 10..15 produces a
    // small bias on the first few chars).
    $body9 = substr($bigDecimal, 6, 9);
    while (strlen($body9) < 9) $body9 .= '0';
    $first12 = '200' . $body9;
    $check   = gs1_checksum_mod10($first12);
    return $first12 . $check;
}

$db = db();
$rows = $db->query('SELECT id, slug, sku FROM products WHERE gtin IS NULL OR gtin = \'\' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "[seed-gtins] No products missing a GTIN — nothing to do.\n";
    exit(0);
}

$upd = $db->prepare('UPDATE products SET gtin = :g WHERE id = :id');
$count = 0;
foreach ($rows as $r) {
    $seed = ($r['slug'] ?? '') . '|' . ($r['sku'] ?? '');
    $gtin = generate_gtin13_for($seed);
    $upd->execute([':g' => $gtin, ':id' => (int)$r['id']]);
    echo sprintf("[seed-gtins] #%-3d  %-13s  ←  %s\n", $r['id'], $gtin, $r['slug']);
    $count++;
}
echo "[seed-gtins] Done — assigned $count GTIN-13 codes.\n";
