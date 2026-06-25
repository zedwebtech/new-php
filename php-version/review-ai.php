<?php
// AI-powered review comment generator — uses Emergent LLM key (OpenAI-compatible).
// Default: single suggestion. With ?count=3 returns three varied suggestions
// (customers can pick the one that best matches their experience).
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

$rating  = max(1, min(5, (int)($_GET['rating'] ?? 5)));
$product = trim($_GET['product'] ?? 'this product');
$count   = max(1, min(3, (int)($_GET['count'] ?? 1)));

// Per-rating fallback library (3 variations per rating). Used when no API key
// is configured or when the LLM call fails — so the customer ALWAYS sees
// suggestions and the UX never breaks.
$fallback = [
    1 => [
        "Unfortunately {$product} didn't meet my expectations. The activation process gave me trouble and customer support was slow to respond.",
        "I had a frustrating experience with {$product}. The license took a long time to arrive and setup was confusing.",
        "Not what I was hoping for. {$product} had activation issues and I had to contact support multiple times.",
    ],
    2 => [
        "{$product} worked but the experience could be smoother. Setup took longer than I hoped and the documentation was a bit unclear.",
        "Mixed feelings about {$product}. The product itself is fine, but the delivery and activation steps were more complicated than needed.",
        "{$product} got the job done eventually. I had to figure out the install myself; instructions could be clearer.",
    ],
    3 => [
        "{$product} is okay. It does the job, though installation took a few extra steps. Decent value for what you get.",
        "Reasonable experience with {$product}. Activation worked on the second try and everything is running fine now.",
        "Average overall. {$product} works as advertised, but I wouldn't say it stood out — fair price for what it delivers.",
    ],
    4 => [
        "Great experience with {$product}. Easy activation, key worked instantly, and the installation guide was clear. Would recommend.",
        "Really pleased with {$product}. Delivery was fast, the key activated on the first try, and it has been smooth ever since.",
        "Solid purchase. {$product} arrived quickly, setup was painless, and the price was much better than buying retail.",
    ],
    5 => [
        "Absolutely love {$product}! License key arrived in minutes, activation was seamless, and everything worked perfectly. Highly recommend to anyone looking for genuine software at a great price.",
        "Five stars all the way! {$product} was delivered instantly, the key activated without a hitch, and customer service was top notch.",
        "Couldn't be happier with {$product}. Lightning-fast delivery, flawless activation, and a huge discount compared to other sellers. I'll be back.",
    ],
];

function ai_pool(int $rating, string $product, int $count): array {
    if (!OPENAI_API_KEY) return [];
    $prompt = $count > 1
        ? "Write $count distinct, authentic customer product reviews (2-3 sentences each, ~40-60 words) for the software product \"$product\". The customer rated it $rating out of 5 stars. Match the tone to the rating (1=disappointed, 3=mixed, 5=enthusiastic). Mention concrete details about the buying experience (license key delivery, activation, installation). Use first-person voice. Do not use markdown or numbering. Return ONLY a JSON array of strings, like [\"review1\", \"review2\", \"review3\"]."
        : "Write a short, authentic customer product review (2-3 sentences, ~40-60 words) for the software product \"$product\". The customer rated it $rating out of 5 stars. Match the tone to the rating: 1=disappointed, 3=mixed, 5=enthusiastic. Mention concrete details about the buying experience (license key delivery, activation, installation). Do not use markdown. First-person voice. Return ONLY the review text, no quotes.";

    $ch = curl_init(rtrim(OPENAI_BASE_URL, '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST          => true,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS    => json_encode([
            'model'       => OPENAI_MODEL,
            'messages'    => [['role'=>'user','content'=>$prompt]],
            'temperature' => 0.85,
            'max_tokens'  => $count > 1 ? 500 : 200,
        ]),
        CURLOPT_TIMEOUT       => 25,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$resp || $code < 200 || $code >= 300) return [];
    $d    = json_decode($resp, true);
    $text = trim($d['choices'][0]['message']['content'] ?? '');
    if ($text === '') return [];
    if ($count === 1) return [$text];
    // Try parse JSON array; strip code fences if any
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $arr   = json_decode($clean, true);
    if (is_array($arr)) {
        $arr = array_values(array_filter(array_map('trim', $arr)));
        if (count($arr) >= 1) return array_slice($arr, 0, $count);
    }
    return [];
}

$pool = ai_pool($rating, $product, $count);
$source = 'ai';
if (count($pool) < $count) {
    // Top up from fallback library
    $needed = $count - count($pool);
    $bag    = $fallback[$rating] ?? $fallback[5];
    foreach (array_slice($bag, 0, $needed) as $item) $pool[] = $item;
    $source = $pool && count($pool) > 0 && empty($pool[0]) ? 'fallback' : (count($pool) > 0 ? 'mixed' : 'fallback');
    if (!OPENAI_API_KEY) $source = 'fallback';
}

if ($count === 1) {
    echo json_encode(['comment' => $pool[0] ?? $fallback[$rating][0], 'source' => $source]);
} else {
    echo json_encode(['suggestions' => array_values($pool), 'source' => $source]);
}
