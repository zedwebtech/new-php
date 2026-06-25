<?php
/**
 * Theme-from-screenshot engine.
 *
 * Admin uploads a screenshot of any theme → we extract a brand palette with
 * GD (most dominant vibrant colour = primary, a different-hue vibrant = accent)
 * and store it as settings.  header.php then injects a small <style> block that
 * overrides the storefront's --theme-* CSS variables, so the whole public site
 * re-skins to match — no code edits.
 */

if (!function_exists('theme_extract_palette')) {

function _theme_clamp($v) { return max(0, min(255, (int)round($v))); }
function _theme_hex($r, $g, $b) { return sprintf('#%02X%02X%02X', _theme_clamp($r), _theme_clamp($g), _theme_clamp($b)); }
function _theme_rgb_from_hex(string $hex): array {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [0, 102, 204];
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}
function _theme_mix(string $hex, array $target, float $pct): string {
    [$r, $g, $b] = _theme_rgb_from_hex($hex);
    return _theme_hex($r + ($target[0] - $r) * $pct, $g + ($target[1] - $g) * $pct, $b + ($target[2] - $b) * $pct);
}
function _theme_darken(string $hex, float $pct): string { return _theme_mix($hex, [0, 0, 0], $pct); }
function _theme_lighten(string $hex, float $pct): string { return _theme_mix($hex, [255, 255, 255], $pct); }
function _theme_rgb2hsl(int $r, int $g, int $b): array {
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $h = 0; $s = 0;
    if ($max != $min) {
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max == $r)      $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        elseif ($max == $g)  $h = ($b - $r) / $d + 2;
        else                 $h = ($r - $g) / $d + 4;
        $h *= 60;
    }
    return [$h, $s, $l];
}

/**
 * Extract a {primary, accent} hex palette from an image file.
 * Returns [] on failure.
 */
function theme_extract_palette(string $path): array {
    if (!function_exists('imagecreatefromstring')) return [];
    $data = @file_get_contents($path);
    if ($data === false) return [];
    $img = @imagecreatefromstring($data);
    if (!$img) return [];
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, 110 / max($w, $h));
    $nw = max(1, (int)($w * $scale)); $nh = max(1, (int)($h * $scale));
    $small = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    $counts = [];
    for ($y = 0; $y < $nh; $y++) {
        for ($x = 0; $x < $nw; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 255; $g = ($rgb >> 8) & 255; $b = $rgb & 255;
            $key = ($r >> 4) . '-' . ($g >> 4) . '-' . ($b >> 4);
            if (!isset($counts[$key])) $counts[$key] = ['c' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
            $counts[$key]['c']++; $counts[$key]['r'] += $r; $counts[$key]['g'] += $g; $counts[$key]['b'] += $b;
        }
    }
    imagedestroy($small);

    $buckets = [];
    foreach ($counts as $v) {
        $r = (int)($v['r'] / $v['c']); $g = (int)($v['g'] / $v['c']); $b = (int)($v['b'] / $v['c']);
        [$hh, $ss, $ll] = _theme_rgb2hsl($r, $g, $b);
        $buckets[] = ['r' => $r, 'g' => $g, 'b' => $b, 'c' => $v['c'], 'h' => $hh, 's' => $ss, 'l' => $ll];
    }
    if (!$buckets) return [];

    // Vibrant candidates: decent saturation, mid lightness, sorted by prominence.
    $vib = array_values(array_filter($buckets, fn($x) => $x['s'] > 0.28 && $x['l'] > 0.18 && $x['l'] < 0.80));
    usort($vib, fn($a, $b) => ($b['c'] * $b['s']) <=> ($a['c'] * $a['s']));
    if (!$vib) { usort($buckets, fn($a, $b) => $b['c'] <=> $a['c']); $vib = $buckets; }

    $primary = $vib[0];
    $accent = null;
    foreach ($vib as $cnd) {
        $dh = abs($cnd['h'] - $primary['h']); if ($dh > 180) $dh = 360 - $dh;
        if ($dh > 25) { $accent = $cnd; break; }
    }
    if (!$accent) $accent = $vib[count($vib) > 1 ? 1 : 0];

    return [
        'primary' => _theme_hex($primary['r'], $primary['g'], $primary['b']),
        'accent'  => _theme_hex($accent['r'], $accent['g'], $accent['b']),
    ];
}

/** Persist a primary+accent palette → all derived theme settings. */
function theme_apply_palette(string $primary, string $accent): void {
    $primary = strtoupper($primary);
    $accent  = strtoupper($accent ?: $primary);
    setting_set('theme_custom',         '1');
    setting_set('theme_primary',        $primary);
    setting_set('theme_primary_hover',  _theme_darken($primary, 0.12));
    setting_set('theme_primary_dark',   _theme_darken($primary, 0.28));
    setting_set('theme_primary_light',  _theme_lighten($primary, 0.82));
    setting_set('theme_primary_soft',   _theme_lighten($primary, 0.92));
    setting_set('theme_accent',         $accent);
}

function theme_clear(): void { setting_set('theme_custom', '0'); }

/** The applied palette (for admin preview). */
function theme_current(): array {
    return [
        'custom'  => setting_get('theme_custom', '') === '1',
        'primary' => setting_get('theme_primary', '#0066CC'),
        'hover'   => setting_get('theme_primary_hover', '#0052A3'),
        'dark'    => setting_get('theme_primary_dark', '#003D7A'),
        'light'   => setting_get('theme_primary_light', '#E7F1FB'),
        'soft'    => setting_get('theme_primary_soft', '#F0F7FF'),
        'accent'  => setting_get('theme_accent', '#1A73E8'),
    ];
}

/** Inline CSS that overrides the storefront --theme-* vars (empty if default). */
function theme_overrides_css(): string {
    if (setting_get('theme_custom', '') !== '1') return '';
    $p = setting_get('theme_primary', '');
    if ($p === '') return '';
    $hover  = setting_get('theme_primary_hover', _theme_darken($p, 0.12));
    $dark   = setting_get('theme_primary_dark',  _theme_darken($p, 0.28));
    $light  = setting_get('theme_primary_light', _theme_lighten($p, 0.82));
    $soft   = setting_get('theme_primary_soft',  _theme_lighten($p, 0.92));
    $accent = setting_get('theme_accent', $p);
    [$r, $g, $b] = _theme_rgb_from_hex($p);
    $accDark = _theme_darken($accent, 0.12);
    return ":root{"
        . "--theme-primary:$p;--theme-primary-hover:$hover;--theme-primary-dark:$dark;"
        . "--theme-primary-light:$light;--theme-primary-soft:$soft;--theme-accent:$accent;"
        . "--bs-primary:$p;--bs-primary-rgb:$r,$g,$b;--bs-link-color:$p;--bs-link-hover-color:$hover;"
        . "--uc-blue:$p;--uc-blue-dark:$hover;--uc-indigo:$accent;"
        . "--uc-grad:linear-gradient(135deg,$p,$accent);--uc-grad-hover:linear-gradient(135deg,$hover,$accDark);"
        . "--uc-soft:$soft;"
        . "}";
}

} // guard
