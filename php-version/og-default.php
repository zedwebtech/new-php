<?php
/* ===========================================================================
 *  DEFAULT OG IMAGE GENERATOR  —  /og-default.png
 *  ---------------------------------------------------------------------------
 *  Builds the 1200×630 social-share image used as the fallback for every page
 *  that doesn't override `$ogImage`.  Drawn on every request using PHP-GD so
 *  the brand name + tagline always reflect live Company Info; output is
 *  HTTP-cached for 24h so social-bots that re-crawl get a hit on the CDN.
 *
 *  Spec follows the Facebook/Twitter/LinkedIn unified recommendation:
 *  1200×630 PNG, <300 KB, <5 MB max, brand-safe centred composition.
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400'); // 24h CDN cache

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    echo "GD not available";
    exit;
}

$brand   = function_exists('company_info') ? (company_info()['name'] ?? SITE_BRAND) : SITE_BRAND;
$tagline = 'Genuine Microsoft Office & Windows 11 License Keys';
// Use "-" instead of "·" because PHP-GD's TTF rasteriser sometimes fails on
// rarer Unicode glyphs when no full Unicode fallback font is in the chain.
$cta     = 'Instant delivery  -  One-time purchase';

// Locate a bold TTF that's reliably present on Debian/Ubuntu containers.
$fontBold = '';
foreach ([
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
] as $_f) { if (is_file($_f)) { $fontBold = $_f; break; } }
$fontReg = '';
foreach ([
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
] as $_f) { if (is_file($_f)) { $fontReg = $_f; break; } }

$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);

// --- Background: diagonal blue gradient (#0066CC → #1A73E8 → #0b1220) ----
for ($y = 0; $y < $H; $y++) {
    $r = (int)(0x0b + (0x00 - 0x0b) * ($y / $H));
    $g = (int)(0x12 + (0x66 - 0x12) * ($y / $H));
    $b = (int)(0x20 + (0xCC - 0x20) * ($y / $H));
    for ($x = 0; $x < $W; $x++) {
        // Mix in horizontal diagonal accent
        $hk = $x / $W;
        $rr = (int)($r + ($hk * 0x10));
        $gg = (int)($g + ($hk * 0x18));
        $bb = (int)($b + ($hk * 0x10));
        imagesetpixel($im, $x, $y, imagecolorallocate($im, min($rr,255), min($gg,255), min($bb,255)));
    }
}

// --- Soft glow blob behind the logo letter ------------------------------
$cx = 220; $cy = 315; $rad = 180;
for ($r = $rad; $r > 0; $r -= 2) {
    $alpha = 105 - (int)($r * 0.45);
    if ($alpha < 0) $alpha = 0;
    $col = imagecolorallocatealpha($im, 0x34, 0xa3, 0xff, $alpha);
    imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
}

// --- Big "M" logo on the left ------------------------------------------
$white = imagecolorallocate($im, 255, 255, 255);
// Geometric M built from a polygon — same shape as favicon.svg
$mx = 90; $my = 220; $s = 6.0;
$mPts = [
    16, 200,  16,   0,  40,  0,   100, 130,
    160,   0,  184,   0,  184, 200,  160, 200,
    160,  80,  100, 175,   40,  80,   40, 200,
];
foreach ($mPts as $i => &$v) $v = (int)($v + ($i % 2 === 0 ? $mx : $my));
imagefilledpolygon($im, $mPts, count($mPts) / 2, $white);

// --- Brand name (right of the M) ---------------------------------------

if ($fontBold) {
    imagettftext($im, 60, 0, 330, 220, $white, $fontBold, $brand);
    // Accent rule under brand name
    imagefilledrectangle($im, 330, 240, 480, 246,
        imagecolorallocate($im, 0x60, 0xa5, 0xfa));
} else {
    imagestring($im, 5, 330, 200, $brand, $white);
}

// --- Tagline (large) ---------------------------------------------------
$tagColor = imagecolorallocate($im, 0xcb, 0xe2, 0xff);
if ($fontBold) {
    imagettftext($im, 46, 0, 330, 340, $tagColor, $fontBold, 'Genuine Microsoft');
    imagettftext($im, 46, 0, 330, 400, $tagColor, $fontBold, 'Office & Windows 11');
    imagettftext($im, 46, 0, 330, 460, $white,    $fontBold, 'License Keys');
} else {
    imagestring($im, 5, 330, 300, $tagline, $tagColor);
}

// --- CTA pill (bottom of right column) ---------------------------------
$pillX1 = 330; $pillY1 = 520; $pillX2 = 820; $pillY2 = 580;
$accent = imagecolorallocate($im, 0x22, 0xc5, 0x5e);
// Rounded rect approximation
imagefilledrectangle($im, $pillX1+12, $pillY1, $pillX2-12, $pillY2, $accent);
imagefilledellipse($im, $pillX1+12, ($pillY1+$pillY2)/2, ($pillY2-$pillY1), ($pillY2-$pillY1), $accent);
imagefilledellipse($im, $pillX2-12, ($pillY1+$pillY2)/2, ($pillY2-$pillY1), ($pillY2-$pillY1), $accent);
if ($fontBold) {
    imagettftext($im, 22, 0, $pillX1+24, $pillY1+40, $white, $fontBold, $cta);
} else {
    imagestring($im, 5, $pillX1+20, $pillY1+22, $cta, $white);
}

// --- Bottom-right "M" watermark mark (subtle) --------------------------
$watermark = imagecolorallocatealpha($im, 255, 255, 255, 100);
$wmPts = [
    16, 100, 16,  0, 28,  0, 50, 65,
    72,   0, 84,  0, 84, 100, 72, 100,
    72,  40, 50, 88, 28, 40, 28, 100,
];
foreach ($wmPts as $i => &$v) $v = (int)($v + ($i % 2 === 0 ? 1080 : 510));
imagefilledpolygon($im, $wmPts, count($wmPts) / 2, $watermark);

imagepng($im, null, 6);
imagedestroy($im);
