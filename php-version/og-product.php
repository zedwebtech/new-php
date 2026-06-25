<?php
/* ===========================================================================
 *  PER-PRODUCT OG IMAGE GENERATOR  —  /og-product.png?slug=<slug>
 *  ---------------------------------------------------------------------------
 *  Returns a 1200×630 share card for the requested product:
 *    • Brand banner (same blue gradient as /og-default.png)
 *    • Product image scaled into the left half
 *    • Product name + price + "Genuine — Lifetime — Instant" CTA on the right
 *
 *  Cached to disk at /uploads/og/<slug>.png after first build so repeat
 *  social-bot requests don't recompute.  Cache rebuilds when the cached
 *  file is older than the product's updated_at (= price changed, image
 *  swapped, etc.).
 *
 *  Falls back to /og-default.png if the slug is unknown or GD chokes.
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

$slug = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: /og-default.png', true, 302);
    exit;
}

// 1) Look up the product.
try {
    $stmt = db()->prepare("SELECT slug, name, price, original_price, image,
                                  seo_refreshed_at AS mtime
                             FROM products
                            WHERE slug = ? AND is_active = 1
                            LIMIT 1");
    $stmt->execute([$slug]);
    $product = $stmt->fetch();
} catch (Throwable $e) {
    @error_log('[og-product] DB error: ' . $e->getMessage());
    $product = null;
}

if (!$product) {
    header('Location: /og-default.png', true, 302);
    exit;
}

// 2) Cache: serve from disk if newer than the product row's mtime.
$cacheDir  = __DIR__ . '/uploads/og';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cachePath = $cacheDir . '/' . $slug . '.png';
$prodMtime = strtotime((string)$product['mtime']) ?: 0;

if (is_file($cachePath) && filemtime($cachePath) >= $prodMtime
    && filemtime($cachePath) > time() - 7 * 86400) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('X-OG-Source: cache');
    readfile($cachePath);
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    header('Location: /og-default.png', true, 302);
    exit;
}

// 3) Pick a bold TTF that's reliably present on the host.
$fontBold = '';
foreach ([
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
] as $_f) { if (is_file($_f)) { $fontBold = $_f; break; } }

// 4) Compose the 1200×630 image.
$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);

// Gradient background (same palette as og-default.php).
for ($y = 0; $y < $H; $y++) {
    $r = (int)(0x0b + (0x00 - 0x0b) * ($y / $H));
    $g = (int)(0x12 + (0x66 - 0x12) * ($y / $H));
    $b = (int)(0x20 + (0xCC - 0x20) * ($y / $H));
    for ($x = 0; $x < $W; $x++) {
        $hk = $x / $W;
        $rr = (int)($r + ($hk * 0x10));
        $gg = (int)($g + ($hk * 0x18));
        $bb = (int)($b + ($hk * 0x10));
        imagesetpixel($im, $x, $y, imagecolorallocate($im, min($rr,255), min($gg,255), min($bb,255)));
    }
}

// 4a) Product image on the left, fit into a 460×460 box centred at (260, 315).
$prodLocal = $product['image'];
if ($prodLocal && !preg_match('~^https?://~i', $prodLocal)) {
    $prodLocal = __DIR__ . '/' . ltrim($prodLocal, '/');
}
if ($prodLocal && is_file($prodLocal)) {
    $src = null;
    $ext = strtolower(pathinfo($prodLocal, PATHINFO_EXTENSION));
    try {
        if ($ext === 'png')                       $src = @imagecreatefrompng($prodLocal);
        elseif ($ext === 'webp')                  $src = @imagecreatefromwebp($prodLocal);
        elseif ($ext === 'jpg' || $ext === 'jpeg') $src = @imagecreatefromjpeg($prodLocal);
    } catch (Throwable $e) { $src = null; }
    if ($src) {
        $sw = imagesx($src); $sh = imagesy($src);
        $tgt = 460;
        $scale = min($tgt / $sw, $tgt / $sh);
        $dw = (int)($sw * $scale); $dh = (int)($sh * $scale);
        $dx = 260 - intdiv($dw, 2);
        $dy = 315 - intdiv($dh, 2);
        // White rounded card behind the product
        $card = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 50, 85, 470, 545, $card);
        imagealphablending($im, true);
        imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
    }
}

// 4b) Right column — brand pill, product name, price.
$white   = imagecolorallocate($im, 255, 255, 255);
$accent  = imagecolorallocate($im, 0x60, 0xa5, 0xfa);
$green   = imagecolorallocate($im, 0x22, 0xc5, 0x5e);
$muted   = imagecolorallocate($im, 0xcb, 0xe2, 0xff);

$ci    = function_exists('company_info') ? company_info() : [];
$brand = $ci['name'] ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');

if ($fontBold) {
    // Tiny brand pill
    imagettftext($im, 20, 0, 530, 115, $accent, $fontBold, strtoupper($brand));

    // Product name — wrap at ~20 chars per line, max 3 lines, 44pt bold.
    $name = (string)$product['name'];
    $lines = [];
    $words = preg_split('/\s+/', $name);
    $curr  = '';
    foreach ($words as $w) {
        $candidate = $curr === '' ? $w : "$curr $w";
        if (mb_strlen($candidate) <= 20) {
            $curr = $candidate;
        } else {
            if ($curr !== '') $lines[] = $curr;
            $curr = $w;
            if (count($lines) >= 3) break;
        }
    }
    if ($curr !== '' && count($lines) < 4) $lines[] = $curr;
    $y = 200;
    foreach (array_slice($lines, 0, 3) as $ln) {
        imagettftext($im, 44, 0, 530, $y, $white, $fontBold, $ln);
        $y += 60;
    }

    // Price + strike-through retail (retail on its own line above so the
    // two don't visually collide at small social-card sizes).
    $priceTxt = '$' . number_format((float)$product['price'], 2);
    if (!empty($product['original_price']) && (float)$product['original_price'] > (float)$product['price']) {
        $retail = 'Reg. $' . number_format((float)$product['original_price'], 2);
        $box = imagettftext($im, 26, 0, 530, 462, $muted, $fontBold, $retail);
        // Strike-through line across the dollar amount only (skip "Reg. ")
        $strikeFrom = $box[6] + 90; // rough offset past "Reg. "
        $strike = imagecolorallocate($im, 0xff, 0x88, 0x88);
        imagefilledrectangle($im, $strikeFrom, 448, $box[2], 452, $strike);
    }
    imagettftext($im, 60, 0, 530, 525, $green, $fontBold, $priceTxt);

    // Bottom CTA strip
    imagettftext($im, 22, 0, 530, 570, $white, $fontBold, 'GENUINE  ·  ONE-TIME PURCHASE  ·  INSTANT DELIVERY');
} else {
    imagestring($im, 5, 530, 200, $product['name'], $white);
    imagestring($im, 5, 530, 260, '$' . number_format((float)$product['price'], 2), $green);
}

// 5) Save to cache + stream to client.
@imagepng($im, $cachePath, 6);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-OG-Source: live');
imagepng($im, null, 6);
imagedestroy($im);
