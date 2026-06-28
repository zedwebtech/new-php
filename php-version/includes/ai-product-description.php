<?php
/* ============================================================================
 *  AI product-description writer (shared).
 *  Used by:
 *    - admin.php  (action=ai_description_one)  → "Generate with AI" button
 *    - scripts/seed-descriptions.php            → batch-fill every product
 *
 *  Calls the configured OpenAI-compatible endpoint (Emergent LLM proxy when an
 *  Emergent key is used — see config.php). Returns:
 *    ['ok'=>true,  'description'=>string]
 *    ['ok'=>false, 'error'=>string]
 *  ========================================================================== */

if (!function_exists('ai_write_product_description')) {

function ai_write_product_description(array $p): array
{
    $name     = trim((string)($p['name']         ?? ''));
    $brand    = trim((string)($p['brand']        ?? ''));
    $category = trim((string)($p['category']     ?? ''));
    $apps     = trim((string)($p['apps']         ?? ''));
    $platform = trim((string)($p['platform']     ?? ''));
    $year     = trim((string)($p['year']         ?? ''));
    $license  = trim((string)($p['license_type'] ?? ''));

    if ($name === '') {
        return ['ok' => false, 'error' => 'Enter a product name first.'];
    }
    if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
        return ['ok' => false, 'error' => 'AI key missing - configure EMERGENT_LLM_KEY'];
    }

    $facts = [];
    if ($brand    !== '') $facts[] = "Brand: $brand";
    if ($category !== '') $facts[] = "Category: $category";
    if ($apps     !== '') $facts[] = "Includes apps: $apps";
    if ($platform !== '') $facts[] = "Platform: $platform";
    if ($year     !== '') $facts[] = "Year/Edition: $year";
    if ($license  !== '') $facts[] = "Licence type: $license";

    $prompt = "Write an elegant, conversion-focused storefront description for the following software product.\n\n"
            . "Product: \"$name\"\n"
            . ($facts ? implode("\n", $facts) . "\n" : '')
            . "\nFormat (STRICT):\n"
            . "Line 1: ONE short hook sentence (max 18 words) that explains WHO this is for + the headline benefit. No hype words like 'revolutionary'.\n"
            . "Then a BLANK line.\n"
            . "Then 4 bullet points (each starting with the character '\u{2022}' followed by a space) describing the key apps, the licence model (one-time lifetime / annual), the activation experience (instant key, no subscription, etc.), and the support promise.\n"
            . "Then a BLANK line.\n"
            . "Then ONE closing reassurance sentence about delivery time + refund (max 18 words).\n\n"
            . "STYLE RULES:\n"
            . "- Premium, calm, trustworthy tone - like a sophisticated e-commerce listing.\n"
            . "- Plain text only (no markdown, no HTML, no emoji, no asterisks).\n"
            . "- Never invent features that aren't supported by the Product/Apps facts above.\n"
            . "- Never mention prices or specific discounts.\n"
            . "- 70-110 words total.\n\n"
            . "Return STRICT JSON only.  Schema: {\"description\":\"...\"}";

    $resp = '';
    $httpCode = 0;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init(rtrim(OPENAI_BASE_URL, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'You return strict JSON only. Never use markdown. Never wrap output in code fences.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.6,
                'max_tokens' => 500,
                'response_format' => ['type' => 'json_object'],
            ]),
            CURLOPT_TIMEOUT => 45,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $httpCode >= 200 && $httpCode < 300) break;
        usleep(800000);
    }

    if (!$resp || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => 'AI HTTP ' . $httpCode . ' - please retry in a few seconds.'];
    }

    $d    = json_decode($resp, true);
    $text = $d['choices'][0]['message']['content'] ?? '';
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
    $parsed      = json_decode($text, true);
    $description = trim((string)($parsed['description'] ?? ''));

    if ($description === '') {
        return ['ok' => false, 'error' => 'AI returned an empty description - please retry.'];
    }
    return ['ok' => true, 'description' => $description];
}

}
