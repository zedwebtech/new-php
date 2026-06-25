<?php
/**
 * Ensure every product image (.webp / .png) has a .jpg sibling on disk.
 *
 * Many email clients (Outlook, Apple Mail) do NOT render WebP, so the email
 * builder swaps a .webp to its .jpg sibling.  This script guarantees that
 * sibling always exists so product images never appear broken in emails.
 *
 * Idempotent + safe to run on every boot.  CLI usage:  php scripts/ensure-image-fallbacks.php
 */

$root = dirname(__DIR__);
$dir  = $root . '/uploads/products';
if (!is_dir($dir)) { fwrite(STDOUT, "no products dir\n"); exit(0); }

$made = 0; $skipped = 0; $failed = 0;
foreach (glob($dir . '/*.{webp,png,PNG,WEBP}', GLOB_BRACE) as $src) {
    $jpg = preg_replace('/\.(webp|png)$/i', '.jpg', $src);
    if ($jpg === $src || is_file($jpg)) { $skipped++; continue; }

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $im  = false;
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($src);
    } elseif ($ext === 'png') {
        $im = @imagecreatefrompng($src);
    }
    if (!$im) { $failed++; continue; }

    // Flatten onto a white background (JPG has no alpha channel).
    $w = imagesx($im); $h = imagesy($im);
    $canvas = imagecreatetruecolor($w, $h);
    $white  = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);

    if (@imagejpeg($canvas, $jpg, 88)) { $made++; } else { $failed++; }
    imagedestroy($im);
    imagedestroy($canvas);
}

fwrite(STDOUT, "image-fallbacks: created=$made skipped=$skipped failed=$failed\n");
