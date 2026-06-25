<?php
/**
 * AI Citation Tracker — periodically asks Claude (and any other LLM the
 * Emergent gateway supports) "What does <BRAND> sell?  Cite their URLs."
 *
 * The goal is to monitor — over time — whether the AI engines we've allow-
 * listed (and indexed) actually KNOW about our catalogue and cite it
 * correctly.  Results are stored in `ai_citations` and shown on the
 * AI Auto-Blogger admin page so the operator can see if the SEO/AEO
 * work is paying off.
 *
 * Schedule: once every 7 days (settings key: ai_citations_last_run_at).
 * Cost   : ~1500 tokens per engine × 3 engines per week ≈ $0.003 / week.
 */

if (!defined('AI_CITATIONS_COOLDOWN_DAYS')) define('AI_CITATIONS_COOLDOWN_DAYS', 7);

function ai_citations_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_citations (
            id INT(11) NOT NULL AUTO_INCREMENT,
            engine VARCHAR(40) NOT NULL,
            model  VARCHAR(60) NULL,
            query  TEXT NOT NULL,
            response MEDIUMTEXT NOT NULL,
            mentions_brand TINYINT(1) NOT NULL DEFAULT 0,
            mentions_url   TINYINT(1) NOT NULL DEFAULT 0,
            product_count  INT NOT NULL DEFAULT 0,
            cited_urls_json TEXT NULL,
            tokens_in  INT NULL,
            tokens_out INT NULL,
            error VARCHAR(255) NULL,
            ran_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_engine_time (engine, ran_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { @error_log('[citations schema] ' . $e->getMessage()); }
}

/**
 * Top-level entry — call from cron / autotick / dashboard.  Returns array
 * summarising each engine probe, or ['skipped' => true, 'reason' => '...']
 * when the weekly cooldown is still active and $force=false.
 */
function ai_citations_run_if_due(bool $force = false): array
{
    $pdo = db();
    ai_citations_ensure_schema($pdo);

    $last = setting_get('ai_citations_last_run_at', '');
    if (!$force && $last) {
        $daysSince = (time() - strtotime($last)) / 86400;
        if ($daysSince < AI_CITATIONS_COOLDOWN_DAYS) {
            return ['skipped' => true, 'reason' => 'last run ' . round($daysSince, 1) . 'd ago'];
        }
    }

    $brand = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software';
    $url   = rtrim(site_url(), '/');
    $query = "What does $brand ($url) sell? List 3 of their actual products with the exact product URLs they use. If you don't know, say 'I don't have specific information about this site' — do not guess.";

    // Probe Claude Haiku, Gemini Flash and GPT-4o-mini via the Emergent
    // gateway. Each is independent so one engine failing doesn't break the
    // others.  We pick three families on purpose: ChatGPT/OpenAI for SearchGPT
    // visibility, Gemini for Google's AI Overviews, Claude as the highest
    // quality fallback.
    $engines = [
        ['name' => 'Claude Haiku 4.5', 'model' => 'claude-haiku-4-5-20251001'],
        ['name' => 'GPT-4o mini',      'model' => 'gpt-4o-mini'],
        ['name' => 'Gemini 2.5 Flash', 'model' => 'gemini-2.5-flash'],
    ];

    $results = [];
    foreach ($engines as $eng) {
        $row = _ai_citations_probe($eng['name'], $eng['model'], $query, $brand, $url);
        try {
            $pdo->prepare("INSERT INTO ai_citations
              (engine, model, query, response, mentions_brand, mentions_url, product_count, cited_urls_json, tokens_in, tokens_out, error, ran_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute([
                $eng['name'],
                $eng['model'],
                $query,
                $row['response'],
                (int)$row['mentions_brand'],
                (int)$row['mentions_url'],
                (int)$row['product_count'],
                json_encode($row['cited_urls'], JSON_UNESCAPED_SLASHES),
                (int)$row['tokens_in'],
                (int)$row['tokens_out'],
                $row['error'] ?: null,
            ]);
        } catch (Throwable $e) {
            @error_log('[citations insert] ' . $e->getMessage());
        }
        $results[] = ['engine' => $eng['name']] + $row;
    }

    setting_set('ai_citations_last_run_at', date('Y-m-d H:i:s'));
    return ['engines' => $results, 'ran_at' => date('c')];
}

/**
 * Single-engine probe — sends the question through the Emergent LLM gateway
 * (OpenAI-compatible) and parses the response for brand / URL mentions.
 */
function _ai_citations_probe(string $engineName, string $model, string $query, string $brand, string $url): array
{
    $out = [
        'response'       => '',
        'mentions_brand' => false,
        'mentions_url'   => false,
        'product_count'  => 0,
        'cited_urls'     => [],
        'tokens_in'      => 0,
        'tokens_out'     => 0,
        'error'          => '',
    ];

    // Resolve credentials from all sources (env, .env file, database)
    if (function_exists('_seo_resolve_llm_credentials')) {
        [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    } else {
        $apiKey  = defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : (getenv('EMERGENT_LLM_KEY') ?: '');
        $baseUrl = defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : '';
    }
    if ($apiKey === '' || $baseUrl === '') {
        $out['error'] = 'LLM key/base URL not configured';
        return $out;
    }

    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => 'You are an unbiased product researcher. Answer factually based ONLY on what you actually know — do not invent products or URLs. If the site is unfamiliar, say so explicitly.'],
            ['role' => 'user',   'content' => $query],
        ],
        'max_tokens'  => 600,
        'temperature' => 0.2,
    ]);

    $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 25,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw || $code >= 400) {
        $out['error'] = $err ?: ('HTTP ' . $code);
        return $out;
    }
    $data    = json_decode((string)$raw, true);
    $answer  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $out['response']   = mb_substr($answer, 0, 4000);
    $out['tokens_in']  = (int)($data['usage']['prompt_tokens']     ?? 0);
    $out['tokens_out'] = (int)($data['usage']['completion_tokens'] ?? 0);

    // Brand match (case-insensitive)
    $out['mentions_brand'] = (bool)preg_match('/' . preg_quote($brand, '/') . '/i', $answer);

    // URL match — check both the configured site URL and the bare host
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && preg_match('/' . preg_quote($host, '/') . '/i', $answer)) {
        $out['mentions_url'] = true;
    }

    // Pull any anchor-style or naked URLs out of the response
    if (preg_match_all('/https?:\/\/[^\s<>\)"]+/i', $answer, $m)) {
        $out['cited_urls'] = array_values(array_unique($m[0]));
    }

    // Count product-list bullets/numbers — rough heuristic
    $bulletCount = preg_match_all('/^\s*(?:\d+[.\)]|\-|\*)\s+\S/m', $answer, $bm);
    $out['product_count'] = (int)min($bulletCount ?: 0, 10);

    return $out;
}

/**
 * Fetch the latest run for each engine — used by the admin panel.
 * Returns ['engine_name' => ['response' => ..., 'mentions_brand' => 1, ...], ...]
 */
function ai_citations_latest_by_engine(int $limitPerEngine = 1): array
{
    try {
        $pdo = db();
        ai_citations_ensure_schema($pdo);
        $rows = $pdo->query("
            SELECT * FROM ai_citations c1
             WHERE c1.id IN (
                 SELECT MAX(c2.id) FROM ai_citations c2 GROUP BY c2.engine
             )
             ORDER BY c1.engine ASC")->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['engine']] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Recent log of all probes — for the activity table. */
function ai_citations_recent(int $limit = 12): array
{
    try {
        $pdo = db();
        ai_citations_ensure_schema($pdo);
        return $pdo->query("SELECT id, engine, model, mentions_brand, mentions_url, product_count, tokens_in, tokens_out, error, ran_at FROM ai_citations ORDER BY id DESC LIMIT $limit")->fetchAll();
    } catch (Throwable $e) { return []; }
}
