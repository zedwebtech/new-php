<?php
/**
 * PHP-native product-image generator.
 *
 * Generates a product card image via the Emergent images endpoint
 * (gpt-image-1) using plain cURL + GD — NO Python, NO emergentintegrations.
 * This means "Regenerate image with AI" works on ANY host (cPanel/Plesk
 * shared hosting included), not only inside the Emergent pod.
 */

if (!function_exists('mv_generate_product_image')) {

/** Resolve [apiKey, baseUrl] for the images endpoint. */
function mv_product_image_creds(): array
{
    if (function_exists('_seo_resolve_llm_credentials')) {
        [$k, $b] = _seo_resolve_llm_credentials();
        if ($k) return [$k, $b ?: 'https://integrations.emergentagent.com/llm/v1'];
    }
    $k = (string)(getenv('EMERGENT_LLM_KEY') ?: (function_exists('setting_get') ? setting_get('ai_blogger_llm_key', '') : ''));
    return [$k, 'https://integrations.emergentagent.com/llm/v1'];
}

/** Build a clean retail-card prompt from the product metadata. */
function mv_build_image_prompt(array $p): string
{
    $name     = trim((string)($p['name'] ?? 'Software'));
    $brand    = trim((string)($p['brand'] ?? ''));
    $platform = trim((string)($p['platform'] ?? ''));
    $prompt   = 'Professional e-commerce product card image for "' . $name . '"'
              . ($brand !== '' ? ' by ' . $brand : '') . '. '
              . 'A premium software retail box / digital license card on a pure white studio background, '
              . 'soft realistic shadow, centered, front-facing, photorealistic, sharp high detail, '
              . 'modern and trustworthy retail-catalog style. No people, no extra text, no watermark, no borders.';
    if ($platform !== '') $prompt .= ' Platform: ' . $platform . '.';
    return $prompt;
}

/**
 * Generate + save the image. Returns ['ok'=>bool, 'image'=>'/uploads/...', 'error'=>'...'].
 */
function mv_generate_product_image(array $prod): array
{
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($prod['slug'] ?? '')));
    if ($slug === '') return ['ok' => false, 'error' => 'Missing product slug.'];

    [$key, $base] = mv_product_image_creds();
    if ($key === '') return ['ok' => false, 'error' => 'No AI key configured — add it under API Keys & Settings.'];

    $payload = json_encode([
        'model'  => 'gpt-image-1',
        'prompt' => mv_build_image_prompt($prod),
        'size'   => '1024x1024',
        'n'      => 1,
    ]);
    $ch = curl_init(rtrim($base, '/') . '/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 95,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        $msg = 'Image service error (' . ($code ?: 'network') . ')';
        $j = json_decode((string)$resp, true);
        if (isset($j['error']['message'])) {
            $m = (string)$j['error']['message'];
            if (stripos($m, 'budget') !== false || stripos($m, 'quota') !== false || stripos($m, 'insufficient') !== false) {
                $msg = 'Universal Key budget exceeded — top up in Profile → Universal Key → Add Balance.';
            } else {
                $msg = $m;
            }
        } elseif ($cerr !== '') {
            $msg = $cerr;
        }
        return ['ok' => false, 'error' => $msg];
    }

    $j   = json_decode((string)$resp, true);
    $b64 = $j['data'][0]['b64_json'] ?? '';
    $url = $j['data'][0]['url'] ?? '';
    $bin = '';
    if ($b64 !== '') $bin = base64_decode($b64);
    elseif ($url !== '') $bin = (string)@file_get_contents($url);
    if ($bin === '') return ['ok' => false, 'error' => 'No image was returned by the AI.'];

    $dir = __DIR__ . '/../uploads/products';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_writable($dir)) return ['ok' => false, 'error' => 'uploads/products is not writable on this server.'];

    $webPath = '/uploads/products/' . $slug . '.webp';
    $fsPath  = $dir . '/' . $slug . '.webp';
    $saved   = false;

    // Prefer WebP (smaller) when GD supports it; resize to max 900px.
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $im = @imagecreatefromstring($bin);
        if ($im) {
            $w = imagesx($im); $h = imagesy($im); $mx = 900; $sc = min(1, $mx / max($w, $h));
            if ($sc < 1) {
                $nw = (int)($w * $sc); $nh = (int)($h * $sc);
                $r = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($r, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im); $im = $r;
            }
            $saved = @imagewebp($im, $fsPath, 82);
            imagedestroy($im);
        }
    }
    // Fallback: save the raw bytes (PNG) when WebP isn't available.
    if (!$saved) {
        $webPath = '/uploads/products/' . $slug . '.png';
        $fsPath  = $dir . '/' . $slug . '.png';
        $saved   = (bool)@file_put_contents($fsPath, $bin);
    }
    if (!$saved) return ['ok' => false, 'error' => 'Could not save the generated image.'];
    @chmod($fsPath, 0644);

    return ['ok' => true, 'image' => $webPath];
}

} // guard
