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

if (!function_exists('mv_generate_blog_image')) {

/**
 * Build a VARIED, contextual lifestyle prompt for a blog cover image.
 * Never a plain product box — instead the software shown in real use
 * (on a laptop, a person working, a desk scene, etc). The scene is
 * picked deterministically from $seed so the same post is stable, but
 * different posts get visibly different images.
 */
function mv_build_blog_image_prompt(array $p, string $seed): string
{
    $name     = trim((string)($p['name'] ?? 'productivity software'));
    $brand    = trim((string)($p['brand'] ?? ''));
    $category = trim((string)($p['category'] ?? 'software'));

    $scenes = [
        'running on a laptop screen on a tidy modern wooden desk, a person typing, warm natural window light',
        'displayed on a sleek desktop monitor in a bright minimalist home office, a coffee mug and a small plant beside it',
        'in use on a laptop held by a smiling young professional in a contemporary open-plan office, soft daylight',
        'open on a laptop at a cozy cafe table, hands resting on the keyboard, blurred warm background bokeh',
        'on a laptop screen in a creative studio, a designer reviewing the interface, cinematic side lighting',
        'running on a laptop on a clean glass desk in a corporate meeting room, a businessperson working',
        'shown on a tablet and a laptop side by side on a desk at dusk, ambient desk-lamp glow, focused work atmosphere',
        'on a laptop in a student\'s room with bookshelves behind, casual and productive, soft afternoon light',
        'on a large monitor at a standing desk in a tech startup, a developer pointing at the screen, energetic vibe',
        'on a laptop on a kitchen island, a freelancer working from home with morning coffee, airy and bright',
    ];
    $idx   = (int)(hexdec(substr(md5($seed), 0, 6)) % count($scenes));
    $scene = $scenes[$idx];

    $subject = trim(($brand !== '' ? $brand . ' ' : '') . $name);
    return 'Photorealistic editorial lifestyle photograph for a blog article about ' . $subject
         . ' (' . $category . '). The application is ' . $scene . '. '
         . 'The laptop/monitor screen shows a generic, modern software dashboard interface — '
         . 'no readable brand text and no logos. Natural depth of field, professional photography, '
         . 'wide 16:9 composition, realistic colours. No text overlay, no captions, no watermark, no borders.';
}

/** Varied curated workspace/laptop stock fallback, picked from $seed. */
function mv_blog_stock_fallback(string $seed): string
{
    $pool = [
        'photo-1498050108023-c5249f4df085', // laptop code desk
        'photo-1531297484001-80022131f5a1', // laptop on surface
        'photo-1517336714731-489689fd1ca8', // macbook desk
        'photo-1486312338219-ce68d2c6f44d', // person at laptop
        'photo-1454165804606-c3d57bc86b40', // team laptops desk
        'photo-1488998427799-e3362cec87c3', // working on laptop cafe
        'photo-1542744173-8e7e53415bb0',    // workspace planning
        'photo-1521737711867-e3b97375f902', // team working
        'photo-1499951360447-b19be8fe80f5', // laptop closeup typing
        'photo-1497032628192-86f99bcd76bc', // office desk monitor
        'photo-1434030216411-0b793f4b4173', // student studying laptop
        'photo-1531403009284-440f080d1e12', // home office laptop
    ];
    $idx = (int)(hexdec(substr(md5($seed), 0, 6)) % count($pool));
    return 'https://images.unsplash.com/' . $pool[$idx] . '?q=80&w=1200&auto=format&fit=crop';
}

/**
 * Generate + save a NEW contextual blog cover image for a post.
 * Returns ['ok'=>bool, 'image'=>'/uploads/blog/...', 'error'=>'...'].
 * On any failure the caller should fall back to mv_blog_stock_fallback().
 */
function mv_generate_blog_image(array $prod, string $postId, string $seed = ''): array
{
    $fname = preg_replace('/[^a-z0-9\-]/', '', strtolower($postId));
    if ($fname === '') return ['ok' => false, 'error' => 'Missing post id.'];
    if ($seed === '') $seed = $postId . '|' . ($prod['slug'] ?? '');

    [$key, $base] = mv_product_image_creds();
    if ($key === '') return ['ok' => false, 'error' => 'No AI key configured.'];

    $payload = json_encode([
        'model'  => 'gpt-image-1',
        'prompt' => mv_build_blog_image_prompt($prod, $seed),
        'size'   => '1536x1024',
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
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'Image service error (' . ($code ?: 'network') . ')'];
    }

    $j   = json_decode((string)$resp, true);
    $b64 = $j['data'][0]['b64_json'] ?? '';
    $url = $j['data'][0]['url'] ?? '';
    $bin = $b64 !== '' ? base64_decode($b64) : ($url !== '' ? (string)@file_get_contents($url) : '');
    if ($bin === '') return ['ok' => false, 'error' => 'No image returned.'];

    $dir = __DIR__ . '/../uploads/blog';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_writable($dir)) return ['ok' => false, 'error' => 'uploads/blog not writable.'];

    $webPath = '/uploads/blog/' . $fname . '.webp';
    $fsPath  = $dir . '/' . $fname . '.webp';
    $saved   = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $im = @imagecreatefromstring($bin);
        if ($im) {
            $w = imagesx($im); $h = imagesy($im); $mx = 1200; $sc = min(1, $mx / max($w, $h));
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
    if (!$saved) {
        $webPath = '/uploads/blog/' . $fname . '.png';
        $fsPath  = $dir . '/' . $fname . '.png';
        $saved   = (bool)@file_put_contents($fsPath, $bin);
    }
    if (!$saved) return ['ok' => false, 'error' => 'Could not save image.'];
    @chmod($fsPath, 0644);

    return ['ok' => true, 'image' => $webPath];
}

} // guard