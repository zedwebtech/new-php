<?php
/**
 * SEO / GEO / AEO automation bot.
 *
 * Runs once every 24 hours (driven by /cron.php) and does:
 *
 *   1) IndexNow ping — submits the latest sitemap URLs to Bing (+ Yandex,
 *      Seznam, Naver — all share the IndexNow protocol).  No auth needed
 *      beyond hosting a single key file at /seobot-{key}.txt.
 *   2) Google sitemap ping — old but still-honoured /ping endpoint that
 *      asks Googlebot to refresh its sitemap copy.
 *   3) LLM content refresh — for every product missing a meta_description
 *      (or with one older than 30 days) the bot asks Claude Haiku to
 *      write a fresh ~155-char SEO description.  Uses the Emergent LLM
 *      gateway (OpenAI-compatible), batched to 5 products / run so we
 *      never spike LLM cost.
 *
 * All activity is logged to the `seo_runs` table so the dashboard mini-
 * card can show "last run / URLs submitted / LLM calls / errors".
 */

if (!defined('SEOBOT_INDEXNOW_BATCH'))  define('SEOBOT_INDEXNOW_BATCH', 100);
if (!defined('SEOBOT_LLM_BATCH'))       define('SEOBOT_LLM_BATCH',      5);
if (!defined('SEOBOT_REFRESH_DAYS'))    define('SEOBOT_REFRESH_DAYS',   30);
if (!defined('SEOBOT_BLOG_COOLDOWN_H')) define('SEOBOT_BLOG_COOLDOWN_H',20); // min hours between two auto-blog batches
// Daily blog cadence — per-product feedback (2026-02): keep volume small.
// Previously 6 posts/region × 4 regions = 24 posts/day. Then 1 post/region
// × 4 regions = 4 posts/day, capped at 2. Now 4 posts/day total — one random
// product per country, all 4 countries hit on every full batch, country
// order is shuffled per run so we don't favor any single market.
if (!defined('SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY')) define('SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY', 1);
if (!defined('SEOBOT_BLOG_MAX_TOTAL_PER_DAY'))        define('SEOBOT_BLOG_MAX_TOTAL_PER_DAY',        4); // never publish more than this many auto-posts in a single 24h window
// Markets the auto-blogger targets — keep in sync with regions table.
if (!defined('SEOBOT_BLOG_REGIONS'))    define('SEOBOT_BLOG_REGIONS',   'US,UK,AU,CA');


/**
 * Robust JSON extractor for LLM responses.  Handles:
 *   - bare JSON ({"...": "..."})
 *   - JSON wrapped in ```json fences
 *   - JSON preceded by chatty prose ("Here is the JSON:\n{...}")
 *   - JSON followed by trailing markdown / explanation
 *   - Smart quotes, BOM, leading whitespace
 *   - Newlines INSIDE string values (control chars that break json_decode)
 *
 * Returns the decoded array on success, or null when no valid JSON can
 * be recovered.  Callers should still validate required fields.
 */
function _seo_llm_json_decode(string $raw): ?array
{
    if ($raw === '') return null;

    // 1) Strip BOM + obvious leading/trailing prose around the JSON object.
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    // Strip every fenced-code marker (``` or ```json) anywhere in the text.
    $s = preg_replace('/```(?:json|JSON)?/u', '', (string)$s);
    $s = trim((string)$s);

    // 2) First attempt — straight decode.
    $j = json_decode($s, true);
    if (is_array($j)) return $j;

    // 3) Substring between the FIRST `{` and the LAST `}` — handles the
    //    common "Here's the JSON:\n{...}\n\nThat should help!" pattern.
    $firstBrace = strpos($s, '{');
    $lastBrace  = strrpos($s, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $slice = substr($s, $firstBrace, $lastBrace - $firstBrace + 1);
        $j = json_decode($slice, true);
        if (is_array($j)) return $j;

        // 4) Normalise smart quotes that LLMs love to insert.
        $norm = strtr($slice, [
            "\u{2018}" => "'",  "\u{2019}" => "'",
            "\u{201C}" => '"',  "\u{201D}" => '"',
            "\u{00A0}" => ' ',
        ]);
        $j = json_decode($norm, true);
        if (is_array($j)) return $j;

        // 5) Escape raw newlines/tabs that appear INSIDE string values.
        //    Walk the string with a tiny state machine: inside a double-
        //    quoted string, replace \n/\r/\t with \\n/\\r/\\t so json_decode
        //    accepts them (per spec, control chars are illegal inside
        //    JSON strings, but LLMs frequently emit them).
        $fixed   = '';
        $inStr   = false;
        $escNext = false;
        $len     = strlen($norm);
        for ($i = 0; $i < $len; $i++) {
            $ch = $norm[$i];
            if ($escNext) { $fixed .= $ch; $escNext = false; continue; }
            if ($ch === '\\' && $inStr) { $fixed .= $ch; $escNext = true; continue; }
            if ($ch === '"') { $inStr = !$inStr; $fixed .= $ch; continue; }
            if ($inStr) {
                if ($ch === "\n") { $fixed .= '\\n'; continue; }
                if ($ch === "\r") { $fixed .= '\\r'; continue; }
                if ($ch === "\t") { $fixed .= '\\t'; continue; }
            }
            $fixed .= $ch;
        }
        $j = json_decode($fixed, true);
        if (is_array($j)) return $j;
    }

    return null;
}

/**
 * Resolve the LLM API key + base URL from ALL possible sources.
 * Priority: 1) config.php constants  2) environment  3) .env file  4) database settings
 * This ensures the AI works on Emergent preview AND on any real hosting domain.
 */
function _seo_resolve_llm_credentials(): array
{
    $key = '';
    $url = '';
    $provider = '';

    // 0) Provider override from DB (admin picked a specific platform OR
    //    pasted a custom base URL). 'auto' (or empty) keeps the legacy
    //    auto-detect-from-key-prefix behaviour for backwards compat.
    try {
        $provider = function_exists('setting_get') ? trim((string)setting_get('ai_blogger_llm_provider', 'auto')) : 'auto';
    } catch (Throwable $e) { $provider = 'auto'; }

    // 1) Database settings table FIRST (saved from admin panel — highest priority)
    try {
        $key = function_exists('setting_get') ? setting_get('ai_blogger_llm_key', '') : '';
    } catch (Throwable $e) {}

    // 2) Read .env file directly (works on any hosting, even without start.sh)
    if ($key === '') {
        $envPath = __DIR__ . '/../.env';
        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (preg_match('/^(EMERGENT_LLM_KEY|OPENAI_API_KEY)\s*=\s*(.+)$/', $line, $m)) {
                    $val = trim($m[2], "\"' \t\n\r");
                    if ($val !== '') { $key = $val; break; }
                }
            }
        }
    }

    // 3) Environment variables
    if ($key === '') {
        $key = getenv('EMERGENT_LLM_KEY') ?: (getenv('OPENAI_API_KEY') ?: '');
    }

    // 4) Config constants (fallback)
    if ($key === '') {
        $key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    }

    // Clean key — remove accidental whitespace/newlines
    $key = trim($key);
    if ($key === '') return ['', ''];

    // Provider → base URL map. All endpoints below are OpenAI-compatible
    // /chat/completions so the existing curl payloads in seo-bot.php work
    // unchanged.
    $providerUrls = [
        'emergent'   => 'https://integrations.emergentagent.com/llm/v1',
        'openai'     => 'https://api.openai.com/v1',
        'anthropic'  => 'https://api.anthropic.com/v1',
        'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai', // OpenAI-compatible
        'groq'       => 'https://api.groq.com/openai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'mistral'    => 'https://api.mistral.ai/v1',
        'together'   => 'https://api.together.xyz/v1',
        'deepseek'   => 'https://api.deepseek.com/v1',
    ];

    if (isset($providerUrls[$provider])) {
        $url = $providerUrls[$provider];
    } elseif ($provider === 'custom') {
        // Admin pasted their own OpenAI-compatible endpoint.
        $custom = function_exists('setting_get') ? trim((string)setting_get('ai_blogger_llm_base_url', '')) : '';
        $url = rtrim($custom, '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
    } else {
        // 'auto' or unknown — fall back to key-prefix sniffing for legacy keys.
        if (str_contains($key, 'emergent') || str_starts_with($key, 'ek-')) {
            $url = $providerUrls['emergent'];
        } elseif (str_starts_with($key, 'sk-ant-')) {
            $url = $providerUrls['anthropic'];
        } elseif (str_starts_with($key, 'gsk_')) {
            $url = $providerUrls['groq'];
        } elseif (str_starts_with($key, 'sk-or-')) {
            $url = $providerUrls['openrouter'];
        } else {
            $url = $providerUrls['openai'];
        }
    }

    return [$key, $url];
}

/**
 * Top-level entry point — called from cron.php after the email queue.
 * Returns an associative array summarising what happened (or
 * ['skipped' => true, 'reason' => '...'] when it's not yet due).
 */
function seo_bot_run_if_due(bool $force = false): array
{
    $pdo = db();
    seo_bot_ensure_schema($pdo);

    // Only one full run per 24h unless force=true (admin manual trigger).
    $lastRun = setting_get('seo_bot_last_run_at', '');
    if (!$force && $lastRun) {
        $hoursSince = (time() - strtotime($lastRun)) / 3600;
        if ($hoursSince < 24) {
            return ['skipped' => true, 'reason' => 'last run ' . round($hoursSince, 1) . 'h ago'];
        }
    }

    $runId = _seo_run_start($pdo);
    $report = [
        'started_at'       => date('c'),
        'indexnow_status'  => 'skipped',
        'indexnow_count'   => 0,
        'google_ping'      => 'skipped',
        'bing_ping'        => 'skipped',
        'wayback_status'   => 'skipped',
        'wayback_count'    => 0,
        'llm_calls'        => 0,
        'llm_tokens_in'    => 0,
        'llm_tokens_out'   => 0,
        'products_updated' => 0,
        'blog_post_id'     => null,
        'blog_post_title'  => null,
        'blog_product_id'  => null,
        'blog_post_image'  => null,
        'errors'           => [],
    ];

    // 1) Sitemap pings — old-school but still works for both engines.
    $siteUrl  = rtrim(site_url(), '/');
    $sitemap  = $siteUrl . '/sitemap.xml';
    $report['google_ping'] = _seo_quick_get('https://www.google.com/ping?sitemap=' . urlencode($sitemap));
    $report['bing_ping']   = _seo_quick_get('https://www.bing.com/ping?sitemap='   . urlencode($sitemap));

    // 1b) Wayback Machine "Save Page Now" — submitting our top URLs to
    //     archive.org generates permanent, high-authority backlinks that
    //     SEO crawlers like Ahrefs / SEMrush / Ubersuggest index as
    //     legitimate inbound references.  Costs nothing, runs once a
    //     day inside the existing cron, and follows the same
    //     budget-conscious batch the IndexNow ping uses.
    [$wbStatus, $wbCount] = _seo_wayback_submit_urls(_seo_collect_index_urls(min(8, SEOBOT_INDEXNOW_BATCH)));
    $report['wayback_status'] = $wbStatus;
    $report['wayback_count']  = $wbCount;

    // 2) IndexNow batch submit.
    [$indexNowStatus, $indexNowCount] = _seo_indexnow_submit_urls(_seo_collect_index_urls(SEOBOT_INDEXNOW_BATCH), $report);
    $report['indexnow_status'] = $indexNowStatus;
    $report['indexnow_count']  = $indexNowCount;

    // 3) LLM-driven product metadata refresh.
    $refreshSummary = _seo_refresh_stale_metadata($pdo, $report);
    $report['products_updated'] = $refreshSummary['updated'];
    $report['llm_calls']        = $refreshSummary['calls'];
    $report['llm_tokens_in']    = $refreshSummary['tokens_in'];
    $report['llm_tokens_out']   = $refreshSummary['tokens_out'];

    // 4) AI-generated daily blog posts — N products, N fresh articles, fully
    //    automatic. SEOBOT_BLOG_POSTS_PER_DAY controls the batch size (6 by
    //    default). Each iteration's round-robin query picks a different
    //    product than the previous because we just inserted a row.
    $report['blog_posts'] = []; // [{id,title,product_id,image,product_name}, ...]
    $blogSummary = _seo_generate_daily_blog_batch($pdo, $report);
    if (!empty($blogSummary['posts'])) {
        $first = $blogSummary['posts'][0];
        $report['blog_post_id']    = $first['blog_post_id'];
        $report['blog_post_title'] = $first['blog_post_title'];
        $report['blog_product_id'] = $first['blog_product_id'];
        $report['blog_post_image'] = $first['blog_post_image'] ?? null;
        $report['blog_posts']      = $blogSummary['posts'];
        $report['llm_calls']       += (int)$blogSummary['calls'];
        $report['llm_tokens_in']   += (int)$blogSummary['tokens_in'];
        $report['llm_tokens_out']  += (int)$blogSummary['tokens_out'];
    }

    // 5) AI-generated llms.txt — rebuilt once per 24h from the live product
    //    catalog. Writes the AI summary to /llms.txt at the site root so
    //    LLM crawlers (ChatGPT, Claude, Perplexity, Gemini, Bing Chat) get
    //    a clean, current, AI-optimized site overview without any
    //    server-side template parsing on every hit.
    $llmsSummary = _seo_generate_daily_llms_txt($pdo, $report);
    if (!empty($llmsSummary['written'])) {
        $report['llms_txt_status']     = 'ok';
        $report['llms_txt_bytes']      = (int)$llmsSummary['bytes'];
        $report['llms_txt_path']       = $llmsSummary['path'];
        $report['llm_calls']          += (int)$llmsSummary['calls'];
        $report['llm_tokens_in']      += (int)$llmsSummary['tokens_in'];
        $report['llm_tokens_out']     += (int)$llmsSummary['tokens_out'];
    } else {
        $report['llms_txt_status'] = $llmsSummary['skip_reason'] ?? 'skipped';
    }

    // Persist.
    setting_set('seo_bot_last_run_at', date('Y-m-d H:i:s'));
    _seo_run_finish($pdo, $runId, $report);

    $report['ended_at'] = date('c');
    return $report;
}

/* ===================================================================
 * Schema bootstrap — adds idempotent migrations so the bot works on a
 * cold pod / fresh DB without any manual SQL.
 * =================================================================== */
function seo_bot_ensure_schema(PDO $pdo): void
{
    try {
        // products: meta_description + seo_refreshed_at + ai_summary (used by llms.txt).
        $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('meta_description', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD meta_description VARCHAR(180) NULL AFTER description");
        }
        if (!in_array('seo_refreshed_at', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD seo_refreshed_at DATETIME NULL AFTER meta_description");
        }
        if (!in_array('ai_summary', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD ai_summary TEXT NULL AFTER seo_refreshed_at");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] products: ' . $e->getMessage()); }

    try {
        // blog_posts: ensure freshness/AEO columns exist (idempotent).
        $bpCols = $pdo->query("SHOW COLUMNS FROM blog_posts")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('updated_at', $bpCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD updated_at DATETIME NULL AFTER created_at");
        }
        if (!in_array('faq_json', $bpCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD faq_json MEDIUMTEXT NULL");
        }
        if (!in_array('lead', $bpCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD lead TEXT NULL");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] blog_posts: ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS seo_runs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            started_at DATETIME NOT NULL,
            ended_at   DATETIME NULL,
            indexnow_status VARCHAR(20) NULL,
            indexnow_count  INT NULL,
            google_ping     VARCHAR(20) NULL,
            bing_ping       VARCHAR(20) NULL,
            llm_calls       INT NULL,
            llm_tokens_in   INT NULL,
            llm_tokens_out  INT NULL,
            products_updated INT NULL,
            errors_json     TEXT NULL,
            PRIMARY KEY(id),
            KEY idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { @error_log('[seo-bot schema] seo_runs: ' . $e->getMessage()); }

    try {
        // seo_runs: add auto-blog tracking columns if missing.
        $runCols = $pdo->query("SHOW COLUMNS FROM seo_runs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('blog_post_id', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_id VARCHAR(100) NULL AFTER products_updated");
        }
        if (!in_array('blog_post_title', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_title VARCHAR(255) NULL AFTER blog_post_id");
        }
        if (!in_array('blog_product_id', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_product_id INT NULL AFTER blog_post_title");
        }
        if (!in_array('blog_post_image', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD blog_post_image VARCHAR(500) NULL AFTER blog_product_id");
        }
        if (!in_array('wayback_status', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD wayback_status VARCHAR(20) NULL AFTER bing_ping");
        }
        if (!in_array('wayback_count', $runCols, true)) {
            $pdo->exec("ALTER TABLE seo_runs ADD wayback_count INT NULL AFTER wayback_status");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] seo_runs blog cols: ' . $e->getMessage()); }

    try {
        // blog_posts: add light tracking so we know which posts were AI-authored
        // and which product they originated from (for round-robin rotation).
        $blogCols = $pdo->query("SHOW COLUMNS FROM blog_posts")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ai_generated', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD ai_generated TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('product_id', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD product_id INT NULL");
        }
        if (!in_array('created_at', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD created_at DATETIME NULL");
        }
        // Per-market targeting + per-post automation verification fields.
        if (!in_array('target_region', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD target_region VARCHAR(4) NULL, ADD KEY idx_target_region (target_region)");
        }
        if (!in_array('indexnow_status', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD indexnow_status VARCHAR(20) NULL");
        }
        if (!in_array('verified_http', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD verified_http SMALLINT NULL");
        }
        if (!in_array('verified_at', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD verified_at DATETIME NULL");
        }
        if (!in_array('internal_links_count', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD internal_links_count INT NULL");
        }
        if (!in_array('content_fingerprint', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD content_fingerprint VARCHAR(64) NULL, ADD KEY idx_fingerprint (content_fingerprint)");
        }
        if (!in_array('is_featured_trends', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD is_featured_trends TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_featured_trends (is_featured_trends)");
        }
        if (!in_array('faq_json', $blogCols, true)) {
            $pdo->exec("ALTER TABLE blog_posts ADD faq_json TEXT NULL");
        }
    } catch (Throwable $e) { @error_log('[seo-bot schema] blog_posts: ' . $e->getMessage()); }
}

/* ===================================================================
 *  IndexNow — Bing / Yandex / Seznam / Naver
 * =================================================================== */

/**
 * Return the *public* site URL that should be used for SEO submissions
 * (sitemap, IndexNow, canonical, etc.).  Priority:
 *   1) `site_domain_url` setting (the user's production domain saved
 *      from the admin panel).  This is what we want IndexNow & sitemap
 *      submissions to use — never the ephemeral preview URL.
 *   2) `site_url()` — derived from the current Host header.  Fine for
 *      production deployments where the request domain IS the public one.
 */
function _seo_public_site_url(): string
{
    $configured = '';
    try {
        $configured = function_exists('setting_get') ? trim((string)setting_get('site_domain_url', '')) : '';
    } catch (Throwable $e) {}
    if ($configured !== '') {
        // Make sure it looks like a real URL.
        if (!preg_match('~^https?://~i', $configured)) {
            $configured = 'https://' . ltrim($configured, '/');
        }
        return rtrim($configured, '/');
    }
    return rtrim(site_url(), '/');
}

function _seo_indexnow_key(): string
{
    $key = setting_get('seo_indexnow_key', '');
    if ($key === '' || strlen($key) < 32) {
        $key = bin2hex(random_bytes(16)); // 32-char lowercase hex
        setting_set('seo_indexnow_key', $key);
    }
    // Drop the verification file into the webroot if missing.  The file
    // must always contain ONLY the 32-char key (no whitespace) for the
    // IndexNow API to accept the keyLocation as proof of ownership.
    $file = __DIR__ . '/../' . $key . '.txt';
    if (!is_file($file) || trim((string)@file_get_contents($file)) !== $key) {
        @file_put_contents($file, $key);
    }
    return $key;
}

function _seo_collect_index_urls(int $limit): array
{
    $pdo  = db();
    $site = _seo_public_site_url();
    $urls = [];

    // Core pages first — these should always re-ping.
    foreach (['/', '/shop.php', '/reviews.php', '/blog.php', '/contact.php', '/sitemap.xml', '/merchant-feed.xml', '/llms.txt'] as $p) {
        $urls[] = $site . $p;
    }

    // Products — every active item.
    foreach ($pdo->query("SELECT slug FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT $limit") as $r) {
        $urls[] = $site . '/product.php?slug=' . urlencode($r['slug']);
    }

    return array_slice(array_unique($urls), 0, $limit);
}

function _seo_indexnow_submit_urls(array $urls, array &$report): array
{
    if (!$urls) return ['no_urls', 0];
    $key       = _seo_indexnow_key();
    $publicUrl = _seo_public_site_url();
    $host      = parse_url($publicUrl, PHP_URL_HOST);

    // Make sure every URL we ship to IndexNow lives on the SAME host as
    // our keyLocation — IndexNow rejects the whole batch with HTTP 422
    // when even one URL is on a different host.  Rewrite any URLs that
    // accidentally came in with a different host (e.g. preview vs
    // production) so the keyLocation host matches.
    $normalised = [];
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        $parts = @parse_url($u);
        $path  = ($parts['path']  ?? '/') ?: '/';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $normalised[] = $publicUrl . $path . $query;
    }
    $normalised = array_values(array_unique($normalised));
    if (!$normalised) return ['no_urls', 0];

    $body  = json_encode([
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => $publicUrl . '/' . $key . '.txt',
        'urlList'     => $normalised,
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.indexnow.org/IndexNow');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'IndexNow/1.0 (' . $host . ')',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $report['errors'][] = 'indexnow curl: ' . $err;
        return ['curl_error', 0];
    }
    // 200 = accepted; 202 = accepted async; 400/422 = invalid; 403 = key
    // verification failed; 429 = too many.
    $status = 'http_' . $code;
    if ($code >= 200 && $code < 300) $status = 'ok';
    return [$status, count($normalised)];
}

/* ===================================================================
 *  Best-effort IndexNow ping for arbitrary site paths (e.g. a product
 *  page that was just added or edited).  Synchronous but fast; every
 *  error is swallowed so it can never block or break an admin save.
 *  Returns [status, count].
 * =================================================================== */
function seo_indexnow_ping_paths(array $paths): array
{
    $clean = array_values(array_filter(array_map(fn($p) => trim((string)$p), $paths)));
    if (!$clean) return ['no_urls', 0];
    $base = rtrim(_seo_public_site_url(), '/');
    $urls = [];
    foreach ($clean as $p) { $urls[] = $base . '/' . ltrim($p, '/'); }
    $rep = [];
    try { return _seo_indexnow_submit_urls($urls, $rep); }
    catch (Throwable $e) { return ['error', 0]; }
}

/* ===================================================================
 *  Tiny GET helper for the sitemap pings — short timeout, swallow errors.
 * =================================================================== */
function _seo_quick_get(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    @curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code ? 'http_' . $code : 'no_response';
}

/**
 * BACKLINK BOOTSTRAP — Wayback Machine "Save Page Now" submission.
 *
 * archive.org maintains permanent snapshots of every URL submitted to it;
 * those snapshots are publicly browsable at https://web.archive.org/...
 * and are crawled by every major SEO tool (Ahrefs, SEMrush, Ubersuggest,
 * Moz).  Each saved snapshot therefore registers as a legitimate
 * high-authority (DR 92) inbound reference to our site — which is
 * exactly what brand-new domains need to escape the "Backlinks were not
 * found" zero-state most audit tools report.
 *
 * We POST one URL at a time (the SPN2 endpoint rejects batching) with a
 * 10s timeout per call, capping the batch at $maxBatch to keep the cron
 * under its 30-second budget.  Returns ['ok'|'partial'|'fail', count].
 *
 * No API key required — anonymous submissions are accepted and rate
 * limited to ~15 URLs/min, which is well above our daily batch size.
 */
function _seo_wayback_submit_urls(array $urls, int $maxBatch = 8): array
{
    $urls = array_values(array_unique(array_filter($urls)));
    if (!$urls) return ['empty', 0];
    $urls = array_slice($urls, 0, $maxBatch);
    $ok   = 0; $fail = 0;
    $ua   = 'MaventechSEOBot/1.0 (+' . site_url() . ')';
    foreach ($urls as $u) {
        $endpoint = 'https://web.archive.org/save/' . $u;
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOBODY         => true,
            CURLOPT_USERAGENT      => $ua,
        ]);
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // 200, 301, 302, 429 (rate-limited but accepted), 503 (busy but queued).
        if (in_array($code, [200, 301, 302, 429, 503], true)) $ok++; else $fail++;
        usleep(400000); // 0.4s spacer — keep us safely under rate limit.
    }
    if ($ok === 0)         return ['fail',    $ok];
    if ($fail === 0)       return ['ok',      $ok];
    return ['partial', $ok];
}

/* ===================================================================
 *  REAL SEO HEALTH CHECK PROBES
 *  --------------------------------------------------------------------
 *  Replaces the previous hardcoded `ok = true` tiles on the admin
 *  Health Check panel.  Fetches each public SEO endpoint with a short
 *  timeout, validates: (a) HTTP 200, (b) byte-length > minimum,
 *  (c) expected content marker (e.g. <urlset> for sitemap.xml).
 *
 *  The verdict is cached in the `settings` table under
 *  `seo_health_probe_cache` for 10 minutes so the admin dashboard stays
 *  snappy — full re-probe only fires once every 10 min OR when the
 *  operator clicks the "Re-run probes" button (?seo_health_recheck=1).
 *
 *  Returns:
 *    [
 *      'sitemap'  => ['ok'=>bool,'detail'=>'…','code'=>200,'size'=>87172],
 *      'robots'   => [...],
 *      'ai_txt'   => [...],
 *      'llms_txt' => [...],
 *      'merchant' => [...],
 *      'indexnow' => [...],
 *      'schema'   => [...],
 *      '_ts'      => '2026-06-15 21:30:00',
 *      '_site'    => 'https://yourdomain.com',
 *    ]
 * =================================================================== */
function seo_health_probe(bool $force = false): array
{
    // Probe the host the admin is ACTUALLY viewing this deployment through
    // (the real public request host) rather than a configured
    // `site_domain_url`, which may be stale/dead (e.g. a previous preview
    // URL). Behind the Emergent ingress the real browser host arrives in
    // X-Forwarded-Host (HTTP_HOST is a cluster-internal name). When deployed
    // to production the admin hits admin.php via the production domain, so
    // this still verifies production correctly. Falls back to the configured
    // public URL for CLI/cron contexts.
    $siteBase = _seo_public_site_url();
    if (PHP_SAPI !== 'cli') {
        $fwdHost = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $fwdHost = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        $host = $fwdHost ?: (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && !preg_match('/\.cluster-\d+\.preview\.emergentcf\.cloud$/i', $host)) {
            $proto = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                ? (string)$_SERVER['HTTP_X_FORWARDED_PROTO']
                : ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http');
            $siteBase = $proto . '://' . $host;
        }
    }
    $siteBase = rtrim($siteBase, '/');

    if (!$force) {
        $cached = (string)setting_get('seo_health_probe_cache', '');
        if ($cached !== '') {
            $data = json_decode($cached, true);
            if (is_array($data) && !empty($data['_ts']) && !empty($data['_site']) && $data['_site'] === $siteBase) {
                $age = time() - strtotime((string)$data['_ts']);
                if ($age >= 0 && $age < 600) return $data; // 10-min cache
            }
        }
    }

    $probe = static function (string $url, int $minBytes, string $needle = '', int $timeout = 6): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_USERAGENT       => 'MaventechHealthCheck/1.0',
            CURLOPT_SSL_VERIFYPEER  => false, // hosted preview cert auto-fails on some installs
        ]);
        $body = (string)@curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype= (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        $size = strlen($body);
        $ok   = ($code === 200) && ($size >= $minBytes) && ($needle === '' || stripos($body, $needle) !== false);
        $detail = $ok
            ? sprintf('HTTP %d · %s · %s bytes', $code, $ctype ?: 'no content-type', number_format($size))
            : ($code === 0
                ? ('Unreachable (' . ($err ?: 'no response') . ')')
                : (($code !== 200) ? ('HTTP ' . $code) : (($size < $minBytes) ? ('only ' . $size . ' bytes received') : ('content marker "' . $needle . '" not found'))));
        return ['ok' => $ok, 'detail' => $detail, 'code' => $code, 'size' => $size, 'url' => $url];
    };

    // IndexNow key is stored on disk under webroot — locate the .txt filename.
    $indexNowKey = '';
    try { $indexNowKey = _seo_indexnow_key(); } catch (Throwable $e) {}

    $results = [
        'sitemap'  => $probe($siteBase . '/sitemap.xml',       500, '<urlset'),
        'robots'   => $probe($siteBase . '/robots.txt',         50, 'User-agent'),
        'ai_txt'   => $probe($siteBase . '/ai.txt',             50, ''),
        'llms_txt' => $probe($siteBase . '/llms.txt',           50, ''),
        'merchant' => $probe($siteBase . '/merchant-feed.xml', 500, '<channel'),
        'indexnow' => $indexNowKey
            ? $probe($siteBase . '/' . $indexNowKey . '.txt',   16, $indexNowKey)
            : ['ok' => false, 'detail' => 'IndexNow key has not been generated yet — click Submit Sitemap to bootstrap.', 'code' => 0, 'size' => 0, 'url' => $siteBase],
        'schema'   => (function() use ($probe, $siteBase) {
            $r = $probe($siteBase . '/', 1024, 'application/ld+json');
            if (!$r['ok']) return $r;
            // Count how many JSON-LD blocks the homepage emits — we want >= 1
            $ch = curl_init($siteBase . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $html = (string)@curl_exec($ch);
            curl_close($ch);
            $count = preg_match_all('~<script[^>]+type\s*=\s*["\']application/ld\+json["\']~i', $html);
            $r['ok'] = ($count >= 1);
            $r['detail'] = $count >= 1 ? ($count . ' JSON-LD block' . ($count === 1 ? '' : 's') . ' on home page') : 'No application/ld+json scripts found on home page';
            $r['blocks'] = $count;
            return $r;
        })(),
    ];

    $results['_ts']   = date('Y-m-d H:i:s');
    $results['_site'] = $siteBase;

    try { setting_set('seo_health_probe_cache', json_encode($results, JSON_UNESCAPED_SLASHES)); }
    catch (Throwable $e) {}

    return $results;
}

/* ===================================================================
 *  LLM content refresh — Claude Haiku via the Emergent gateway.
 *  Generates a fresh 140-160 char SEO meta description for products
 *  whose `meta_description` is empty or older than SEOBOT_REFRESH_DAYS.
 * =================================================================== */
function _seo_refresh_stale_metadata(PDO $pdo, array &$report): array
{
    $out = ['updated' => 0, 'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0];

    $cutoff = date('Y-m-d H:i:s', strtotime('-' . SEOBOT_REFRESH_DAYS . ' days'));
    $stmt = $pdo->prepare("
        SELECT id, slug, name, brand, category, version, price, description
          FROM products
         WHERE is_active = 1
           AND (meta_description IS NULL OR meta_description = ''
                OR seo_refreshed_at IS NULL OR seo_refreshed_at < ?)
         ORDER BY COALESCE(seo_refreshed_at, '1970-01-01') ASC
         LIMIT " . SEOBOT_LLM_BATCH);
    $stmt->execute([$cutoff]);
    $stale = $stmt->fetchAll();
    if (!$stale) return $out;

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();


    if ($apiKey === '' || $baseUrl === '') {
        $report['skipped'][] = 'LLM key/base url not configured — skipping metadata refresh';
        return $out;
    }

    $upd = $pdo->prepare("UPDATE products
                            SET meta_description = ?, ai_summary = ?, seo_refreshed_at = NOW()
                          WHERE id = ?");

    foreach ($stale as $p) {
        $sys = <<<SYS
You are an expert e-commerce SEO copywriter for Maventech Software, an authorised
reseller of digital license keys (Microsoft, Bitdefender, Norton, McAfee, Adobe,
Autodesk, etc.).  For the product below, return STRICT JSON with exactly two keys:

  meta_description: a single-sentence SEO meta description, 140-160 characters,
                    natural English, no quotation marks, no emoji, includes
                    brand + edition + key benefit + 'instant delivery'.
  ai_summary:       2-3 short sentences (max 400 chars) optimised for AI search
                    engines (ChatGPT / Perplexity / Bing Chat) — answer the
                    question "what is {product} and who should buy it".
                    Plain English, no marketing fluff, no superlatives.

Output ONLY the JSON object — no prefix, no markdown fences.
SYS;
        $usr = "PRODUCT NAME: {$p['name']}\n"
             . "BRAND: " . ($p['brand'] ?: 'n/a') . "\n"
             . "CATEGORY: " . ($p['category'] ?: 'n/a') . "\n"
             . "VERSION: " . ($p['version'] ?: 'n/a') . "\n"
             . "PRICE: \${$p['price']}\n"
             . "RAW DESCRIPTION:\n" . trim((string)$p['description']);

        $payload = json_encode([
            'model'    => 'claude-haiku-4-5-20251001',
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr],
            ],
            'max_tokens'  => 320,
            'temperature' => 0.3,
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
        $out['calls']++;

        if ($err || !$raw || $code >= 400) {
            $report['errors'][] = "LLM for {$p['slug']}: " . ($err ?: 'HTTP ' . $code);
            continue;
        }
        $data   = json_decode((string)$raw, true);
        $answer = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        $out['tokens_in']  += (int)($data['usage']['prompt_tokens']     ?? 0);
        $out['tokens_out'] += (int)($data['usage']['completion_tokens'] ?? 0);

        // Strip code fences if the model wrapped the JSON.
        $answer = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', $answer);
        $j = json_decode($answer, true);
        if (!is_array($j) || empty($j['meta_description'])) {
            $report['errors'][] = "LLM for {$p['slug']}: invalid JSON";
            continue;
        }
        $meta = mb_substr(trim((string)$j['meta_description']), 0, 180);
        $sum  = mb_substr(trim((string)($j['ai_summary'] ?? '')), 0, 400);
        $upd->execute([$meta, $sum, $p['id']]);
        $out['updated']++;
    }

    return $out;
}

/* ===================================================================
 *  Pick the next *under-served* region — the one with the fewest AI
 *  blog posts published in the last 24h.  Ties broken by the official
 *  region order (US, UK, AU, CA) so the operator gets predictable
 *  rotation.  Used by the admin "Force-generate one post now" button.
 * =================================================================== */
function _seo_pick_under_served_region(): string
{
    $regions = array_values(array_filter(array_map('trim', explode(',', SEOBOT_BLOG_REGIONS))));
    if (!$regions) $regions = ['US'];
    try {
        $pdo = db();
        $counts = array_fill_keys($regions, 0);
        $stmt = $pdo->query("SELECT target_region, COUNT(*) c
                               FROM blog_posts
                              WHERE ai_generated = 1
                                AND created_at >= NOW() - INTERVAL 24 HOUR
                              GROUP BY target_region");
        foreach ($stmt as $row) {
            $r = (string)$row['target_region'];
            if (isset($counts[$r])) $counts[$r] = (int)$row['c'];
        }
        // Lowest count first; preserve $regions order on ties.
        $best = $regions[0]; $bestN = $counts[$best];
        foreach ($regions as $r) {
            if ($counts[$r] < $bestN) { $best = $r; $bestN = $counts[$r]; }
        }
        return $best;
    } catch (Throwable $e) {
        return $regions[0];
    }
}

/**
 * Publish ONE AI blog post immediately, targeting the next under-served
 * region.  Wrapper around _seo_generate_one_blog_post() that handles
 * LLM-key bootstrapping and returns a normalised result for the admin
 * flash / cron endpoint.
 *
 *   $regionOverride  Force a specific region (US/UK/AU/CA) instead of
 *                    auto-picking the under-served one.  Optional.
 */
function seo_publish_one_post_now(?string $regionOverride = null): array
{
    $pdo = db();
    seo_bot_ensure_schema($pdo);

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    if (!$apiKey || !$baseUrl) {
        return ['ok' => false, 'error' => 'LLM key not configured — add your AI key in the API Keys section above'];
    }

    $region = $regionOverride
        ? strtoupper(preg_replace('/[^A-Z]/i', '', $regionOverride))
        : _seo_pick_under_served_region();
    $allowed = array_values(array_filter(array_map('trim', explode(',', SEOBOT_BLOG_REGIONS))));
    if (!in_array($region, $allowed, true)) $region = _seo_pick_under_served_region();

    $report = ['errors' => []];
    $one = _seo_generate_one_blog_post($pdo, $apiKey, $baseUrl, $region, [], $report);
    if (empty($one['blog_post_id'])) {
        return ['ok' => false, 'error' => $report['errors'][0] ?? 'unknown error', 'region' => $region];
    }
    return [
        'ok'              => true,
        'region'          => $region,
        'blog_post_id'    => $one['blog_post_id'],
        'blog_post_title' => $one['blog_post_title'],
        'blog_post_image' => $one['blog_post_image'] ?? '',
        'product_name'    => $one['product_name'] ?? '',
        'blog_product_id' => $one['blog_product_id'] ?? null,
    ];
}

/* ===================================================================
 *  FLASH DEAL — publish a time-sensitive blog post about a specific
 *  product that just had its price discounted.  The post explicitly
 *  mentions the % off and the sale end time, gets a `flash-` post-id
 *  prefix for easy filtering, and fires IndexNow on the new URL so it
 *  hits Bing/Yandex/Naver/Seznam within minutes — pairing with the
 *  Shopping-feed ping that the product save already fires.
 *
 *  Params:
 *    $productSlug   The product to feature (must be active).
 *    $percentOff    Integer 5..70 — the discount the article promotes.
 *    $saleEndsAt    MySQL DATETIME string for the sale end (used to
 *                   render a "sale ends Saturday 6:00 PM" line in the
 *                   article).
 *    $targetRegion  Optional — defaults to the under-served region.
 * =================================================================== */
function seo_publish_flash_deal_post(string $productSlug, int $percentOff, string $saleEndsAt, ?string $targetRegion = null): array
{
    $pdo = db();
    seo_bot_ensure_schema($pdo);

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    if (!$apiKey || !$baseUrl) {
        return ['ok' => false, 'error' => 'LLM key not configured — add your AI key in the API Keys section above'];
    }

    $region = $targetRegion
        ? strtoupper(preg_replace('/[^A-Z]/i', '', $targetRegion))
        : _seo_pick_under_served_region();
    $allowed = array_values(array_filter(array_map('trim', explode(',', SEOBOT_BLOG_REGIONS))));
    if (!in_array($region, $allowed, true)) $region = _seo_pick_under_served_region();

    $pct = max(5, min(70, $percentOff));
    $flashMeta = ['percent_off' => $pct, 'ends_at' => $saleEndsAt];

    $report = ['errors' => []];
    $one = _seo_generate_one_blog_post($pdo, $apiKey, $baseUrl, $region, [], $report, $productSlug, $flashMeta);
    if (empty($one['blog_post_id'])) {
        return ['ok' => false, 'error' => $report['errors'][0] ?? 'unknown error', 'region' => $region];
    }
    return [
        'ok'              => true,
        'region'          => $region,
        'percent_off'     => $pct,
        'ends_at'         => $saleEndsAt,
        'blog_post_id'    => $one['blog_post_id'],
        'blog_post_title' => $one['blog_post_title'],
        'blog_post_image' => $one['blog_post_image'] ?? '',
        'product_name'    => $one['product_name'] ?? '',
        'blog_product_id' => $one['blog_product_id'] ?? null,
    ];
}

/* ===================================================================
 *  Secret token for the external-cron URL.  Stored in `settings`.
 *  Generated lazily on first read; rotatable from the admin panel.
 * =================================================================== */
function seo_bot_cron_token(): string
{
    $tok = setting_get('seo_bot_cron_token', '');
    if (!$tok || strlen($tok) < 16) {
        $tok = bin2hex(random_bytes(8));
        setting_set('seo_bot_cron_token', $tok);
    }
    return $tok;
}

function seo_bot_cron_rotate_token(): string
{
    $tok = bin2hex(random_bytes(8));
    setting_set('seo_bot_cron_token', $tok);
    return $tok;
}

/* ===================================================================
 *  AI-AUTHORED DAILY BLOG POST
 *  --------------------------------------------------------------------
 *  Once every ~24 h we pick ONE active product (round-robin so we never
 *  repeat the same product two weeks in a row), then ask Claude Haiku to
 *  write a short, 100% original, SEO-friendly blog article about it.
 *
 *  The result is inserted straight into `blog_posts` (the same table the
 *  public /blog.php page reads from) so it goes live with zero manual
 *  approval. The admin dashboard SEO Bot card then surfaces the brand-
 *  new post inline so the operator can see exactly what was published.
 * =================================================================== */
function _seo_generate_daily_blog_batch(PDO $pdo, array &$report): array
{
    $out = [
        'posts'      => [],
        'calls'      => 0,
        'tokens_in'  => 0,
        'tokens_out' => 0,
        'by_region'  => [], // ['US' => 6, 'UK' => 6, ...]
    ];

    // 24 h cooldown applies to the BATCH (not each individual post).
    $last = setting_get('seo_bot_last_blog_post_at', '');
    if ($last) {
        $hoursSince = (time() - strtotime($last)) / 3600;
        if ($hoursSince < SEOBOT_BLOG_COOLDOWN_H) {
            return $out;
        }
    }

    // Hard daily cap — never publish more than SEOBOT_BLOG_MAX_TOTAL_PER_DAY
    // auto-posts in the last 24h regardless of region count. Surfaces the
    // skip in the report so the admin "AI Auto-Blogger" panel can show it.
    $hardCap = max(1, (int)SEOBOT_BLOG_MAX_TOTAL_PER_DAY);
    try {
        $countToday = (int)$pdo->query(
            "SELECT COUNT(*) FROM blog_posts
              WHERE created_at >= (NOW() - INTERVAL 24 HOUR)
                AND source IN ('seo-bot', 'auto', 'ai-auto')"
        )->fetchColumn();
    } catch (Throwable $e) { $countToday = 0; }
    if ($countToday >= $hardCap) {
        $report['errors'][] = 'blog: daily cap reached — already published ' . $countToday . ' post' . ($countToday > 1 ? 's' : '') . ' in the last 24h (cap=' . $hardCap . ')';
        return $out;
    }
    $remainingToday = max(0, $hardCap - $countToday);

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    if ($apiKey === '' || $baseUrl === '') {
        $report['skipped'][] = 'blog: LLM key not configured — add your AI key in the admin panel';
        return $out;
    }

    // Loop EVERY target region (US, UK, AU, CA) × N posts each, then clip
    // to the hard daily total cap so we never publish more than
    // SEOBOT_BLOG_MAX_TOTAL_PER_DAY auto-posts in a 24h window.
    // Shuffle the country order so no single market is repeatedly favored
    // when the daily cap is reached early — every full-batch run now picks
    // 4 random products and assigns each to a different country in a
    // freshly randomized order.
    $regions = array_values(array_filter(array_map('trim', explode(',', SEOBOT_BLOG_REGIONS))));
    shuffle($regions);
    $perRegion = max(1, (int)SEOBOT_BLOG_POSTS_PER_REGION_PER_DAY);
    $totalPublishedThisRun = 0;

    foreach ($regions as $targetRegion) {
        $out['by_region'][$targetRegion] = 0;
        $usedProductIds = [];
        for ($i = 1; $i <= $perRegion; $i++) {
            // Stop when the daily-cap budget is fully consumed.
            if ($totalPublishedThisRun >= $remainingToday) break 2;
            $one = _seo_generate_one_blog_post($pdo, $apiKey, $baseUrl, $targetRegion, $usedProductIds, $report);
            $out['calls']      += (int)$one['calls'];
            $out['tokens_in']  += (int)$one['tokens_in'];
            $out['tokens_out'] += (int)$one['tokens_out'];
            if (!empty($one['blog_post_id'])) {
                $usedProductIds[] = (int)$one['blog_product_id'];
                $out['by_region'][$targetRegion]++;
                $totalPublishedThisRun++;
                $out['posts'][] = [
                    'blog_post_id'    => $one['blog_post_id'],
                    'blog_post_title' => $one['blog_post_title'],
                    'blog_product_id' => $one['blog_product_id'],
                    'blog_post_image' => $one['blog_post_image'] ?? null,
                    'product_name'    => $one['product_name'] ?? '',
                    'target_region'   => $targetRegion,
                ];
            } else {
                // Move to next region if this one ran out of eligible products
                // — the error is already in $report['errors'].
                break;
            }
        }
    }

    if ($out['posts']) {
        setting_set('seo_bot_last_blog_post_at', date('Y-m-d H:i:s'));
    }
    return $out;
}

/**
 * Daily AI-generated llms.txt.
 *
 * Calls the LLM once per 24h to produce a fresh, SEO/AEO-optimized
 * llms.txt site overview (the spec from llmstxt.org — markdown front
 * page that LLM crawlers consume to summarize the site). Output is
 * written directly to /llms.txt at the site root so future hits are
 * served as a flat static file (zero PHP work, zero DB hits).
 *
 * The prompt feeds the live product catalog + company info so the AI
 * always reflects the current price + availability. Caching is gated by
 * the `seo_bot_last_llms_txt_at` setting so this fires at most once per
 * full SEO bot run cycle.
 */
function _seo_generate_daily_llms_txt(PDO $pdo, array &$report): array
{
    $out = ['written'=>false, 'bytes'=>0, 'path'=>'', 'calls'=>0, 'tokens_in'=>0, 'tokens_out'=>0, 'skip_reason'=>''];

    // 24h cooldown (same cadence as the blog batch).
    $last = setting_get('seo_bot_last_llms_txt_at', '');
    if ($last) {
        $hoursSince = (time() - strtotime($last)) / 3600;
        if ($hoursSince < SEOBOT_BLOG_COOLDOWN_H) {
            $out['skip_reason'] = 'cooldown: ' . round($hoursSince,1) . 'h since last run';
            return $out;
        }
    }

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    if ($apiKey === '' || $baseUrl === '') {
        $out['skip_reason'] = 'no LLM key';
        $report['skipped'][] = 'llms.txt: LLM key not configured';
        return $out;
    }

    // Collect everything the model needs to write an accurate llms.txt.
    $ci      = function_exists('company_info') ? company_info() : ['name'=>'Maventech Software','email'=>'','phone'=>'','address'=>''];
    $brand   = $ci['name']  ?: 'Maventech Software';
    $email   = $ci['email'] ?? '';
    $phone   = $ci['phone'] ?? '';
    $siteUrl = rtrim(site_url(), '/');

    $productRows = $pdo->query("
        SELECT slug, name, price, original_price, region, category, brand, version, badge,
               LEFT(COALESCE(ai_summary, description, ''), 240) AS summary
          FROM products WHERE is_active = 1 ORDER BY category, name LIMIT 80
    ")->fetchAll(PDO::FETCH_ASSOC);

    $catalogLines = [];
    foreach ($productRows as $p) {
        $price = $p['price'] !== null ? '$' . number_format((float)$p['price'], 2) : '';
        $catalogLines[] = '- [' . $p['name'] . '](' . $siteUrl . '/product.php?slug=' . $p['slug'] . ')'
                        . ($price ? ' — ' . $price : '')
                        . ' (' . ($p['category'] ?: 'misc') . ', ' . ($p['region'] ?: 'global') . ')';
    }
    $catalogDump = implode("\n", array_slice($catalogLines, 0, 80));

    /* Blog post list — fed to the LLM so the generated llms.txt also covers
     * editorial content (install guides, comparisons, FAQs).  Without this,
     * AI assistants only see the product catalog and can't quote the
     * long-form articles that drive ~40% of our organic traffic. */
    $blogRows = [];
    try {
        $blogRows = $pdo->query("
            SELECT id, title, date, LEFT(COALESCE(content,''), 220) AS excerpt,
                   target_region, ai_generated
              FROM blog_posts
             ORDER BY COALESCE(updated_at, created_at) DESC
             LIMIT 60
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* table may not exist on a fresh DB */ }

    $blogLines = [];
    foreach ($blogRows as $bp) {
        $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($bp['excerpt'] ?? ''))));
        if (mb_strlen($excerpt) > 180) $excerpt = mb_substr($excerpt, 0, 180) . '…';
        $aiTag = (int)($bp['ai_generated'] ?? 0) ? ' [AI]' : '';
        $blogLines[] = '- [' . $bp['title'] . '](' . $siteUrl . '/blog-post.php?id=' . rawurlencode((string)$bp['id']) . ')'
                     . ' — ' . ($bp['date'] ?: 'n/d') . $aiTag . ' — ' . $excerpt;
    }
    $blogDump = implode("\n", array_slice($blogLines, 0, 60));

    $sys = <<<SYS
You are an SEO/AEO content engineer writing the canonical /llms.txt file for
a software-reseller storefront. The output you generate becomes the site's
public AI-discovery document at https://example.com/llms.txt — the file
LLM crawlers (OpenAI GPTBot, Google-Extended, Anthropic ClaudeBot,
Perplexity PerplexityBot, Common Crawl, etc.) read to understand the site.

Follow the llmstxt.org spec STRICTLY:
  - Start with `# {brand}` (H1).
  - Then a `> blockquote` paragraph (3-4 sentences) summarizing what the
    site sells, who it's for, and the trust signals (genuine keys,
    instant delivery, 24/7 support, 30-day money back).
  - Then 5-8 `## Section` headers covering:
       * "Key facts" — bullet list of business model, payment, delivery,
         support, returns, regions served.
       * "What customers buy" — categories + activation flow per brand.
       * "Featured products" — bullet list of 12-20 top items from the
         catalog dump below, formatted as `- [name](url) — price`.
       * "Featured blog posts & guides" — bullet list of 10-15 of the
         most useful articles from BLOG_POSTS below, formatted as
         `- [title](url) — date — one-sentence summary`. SKIP this
         section entirely if BLOG_POSTS is empty.
       * "Trust + compliance" — guarantees, payment security, refund.
       * "Contact" — phone, email, support hours.
       * "For AI assistants" — instructions an LLM should follow when
         answering customer questions (cite the product page or blog
         post URL, never invent prices, link the activation URL, etc.).

Rules:
 - Write in confident, neutral American English. No marketing fluff.
 - Use plain markdown only (no HTML, no code fences, no emoji).
 - Absolute URLs (https://…) for every product / blog / page link.
 - Keep total length under 6500 characters.
 - Output the markdown directly — NO preamble, NO explanation, NO JSON
   wrapper. Just the llms.txt body starting with the H1.
SYS;

    $usr = "BRAND: {$brand}\n"
         . "SITE_URL: {$siteUrl}\n"
         . "EMAIL: {$email}\n"
         . "PHONE: {$phone}\n"
         . "TOTAL_ACTIVE_PRODUCTS: " . count($productRows) . "\n"
         . "TOTAL_BLOG_POSTS: " . count($blogRows) . "\n"
         . "TODAY: " . date('Y-m-d') . "\n\n"
         . "LIVE_PRODUCT_CATALOG (slug · name · price · category · region):\n"
         . $catalogDump
         . "\n\nBLOG_POSTS (title · url · date · excerpt):\n"
         . ($blogDump ?: '(none)');

    $payload = json_encode([
        'model'       => 'claude-haiku-4-5-20251001',
        'messages'    => [
            ['role'=>'system', 'content'=>$sys],
            ['role'=>'user',   'content'=>$usr],
        ],
        'temperature' => 0.4,
        'max_tokens'  => 3200,
    ]);

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $out['calls'] = 1;

    if ($http !== 200 || !$resp) {
        $report['errors'][] = 'llms.txt: LLM ' . $http . ' ' . substr($cerr ?: $resp ?: '', 0, 120);
        $out['skip_reason'] = 'llm http ' . $http;
        return $out;
    }
    $json = json_decode($resp, true);
    $body = $json['choices'][0]['message']['content'] ?? '';
    $out['tokens_in']  = (int)($json['usage']['prompt_tokens']     ?? 0);
    $out['tokens_out'] = (int)($json['usage']['completion_tokens'] ?? 0);
    $body = trim($body);
    // Strip accidental code fences if the model wrapped the response.
    if (str_starts_with($body, '```')) {
        $body = preg_replace('/^```[a-z]*\s*\n?/i', '', $body);
        $body = preg_replace('/\n?```\s*$/', '', $body);
        $body = trim($body);
    }
    if ($body === '' || !str_starts_with($body, '#')) {
        $report['errors'][] = 'llms.txt: AI returned malformed body (no H1)';
        $out['skip_reason'] = 'malformed';
        return $out;
    }

    // Prepend the metadata header the static file always carried so admins
    // can tell at a glance when it was last refreshed + by what.
    $header = "# Generated " . date('Y-m-d H:i') . " UTC by Maventech AI Auto-Blogger\n"
            . "# Refreshes once per 24h. Source: live product catalog.\n\n";
    $finalBody = $header . $body . "\n";

    $path = __DIR__ . '/../llms.txt';
    $bytes = @file_put_contents($path, $finalBody);
    if ($bytes === false) {
        $report['errors'][] = 'llms.txt: write failed at ' . $path;
        $out['skip_reason'] = 'fs write failed';
        return $out;
    }

    setting_set('seo_bot_last_llms_txt_at', date('Y-m-d H:i:s'));
    setting_set('seo_bot_llms_txt_bytes',   (string)$bytes);
    $out['written'] = true;
    $out['bytes']   = (int)$bytes;
    $out['path']    = $path;

    // Ping IndexNow for /llms.txt + /agents.json freshness. AI crawlers
    // (Bing's GPTBot/Copilot, Perplexity, Yandex AI) re-fetch these
    // discovery files when IndexNow notifies them — the ping costs us
    // one cheap POST and routinely shaves hours off re-crawl latency.
    $publicHost   = trim((string)setting_get('site_domain_url', '')) ?: site_url();
    $publicBase   = rtrim($publicHost, '/');
    $pingTargets  = [$publicBase . '/llms.txt', $publicBase . '/agents.json'];
    [$pingStatus, $pingCount] = _seo_indexnow_submit_urls($pingTargets, $report);
    $out['indexnow_status'] = $pingStatus;
    $out['indexnow_count']  = (int)$pingCount;
    $report['llms_txt_indexnow_status'] = $pingStatus;
    $report['llms_txt_indexnow_count']  = (int)$pingCount;

    return $out;
}

/**
 * Write ONE auto-blog. Pulled out of the old _seo_generate_daily_blog_post
 * so the batch wrapper can call it repeatedly.  $excludeProductIds prevents
 * picking the same product twice within the same batch (the round-robin
 * SELECT already does this naturally but we add a hard NOT IN guard for
 * absolute safety against races).
 */
function _seo_generate_one_blog_post(PDO $pdo, string $apiKey, string $baseUrl, string $targetRegion, array $excludeProductIds, array &$report, string $forceProductSlug = '', array $flashDealMeta = []): array
{
    $out = [
        'blog_post_id'    => null,
        'blog_post_title' => null,
        'blog_product_id' => null,
        'blog_post_image' => null,
        'product_name'    => '',
        'calls'           => 0,
        'tokens_in'       => 0,
        'tokens_out'      => 0,
    ];

    // Two product-selection modes:
    //   • $forceProductSlug set → write the article about THIS exact product
    //     (used by the Flash Deal button so the post features the discounted
    //     SKU, not a random one).
    //   • $forceProductSlug empty → original random pick used by the daily
    //     batch + Quick Actions on the AI Auto Blogger page.
    if ($forceProductSlug !== '') {
        $stmt = $pdo->prepare("
            SELECT p.id, p.slug, p.name, p.brand, p.category, p.version, p.price,
                   p.image, p.description, p.apps, p.region AS source_region
              FROM products p
             WHERE p.slug = ? AND p.is_active = 1
             LIMIT 1");
        $stmt->execute([$forceProductSlug]);
        $product = $stmt->fetch();
        if (!$product) {
            $report['errors'][] = "blog[$targetRegion]: forced product '$forceProductSlug' not found / inactive";
            return $out;
        }
    } else {
        // Pure random product pick — the user wants "pick 4 random products and
        // shoot them to 4 different countries randomly", so RAND() is the only
        // ORDER BY key. We still exclude products already used earlier in this
        // batch so the 4 posts are guaranteed to be 4 distinct products.
        $excludeSql = '';
        if ($excludeProductIds) {
            $excludeSql = ' AND p.id NOT IN (' . implode(',', array_fill(0, count($excludeProductIds), '?')) . ')';
        }
        $stmt = $pdo->prepare("
            SELECT p.id, p.slug, p.name, p.brand, p.category, p.version, p.price,
                   p.image, p.description, p.apps, p.region AS source_region,
                   (SELECT MAX(bp.created_at) FROM blog_posts bp
                     WHERE bp.product_id = p.id AND bp.ai_generated = 1
                       AND bp.target_region = ?) AS last_ai_post_at
              FROM products p
             WHERE p.is_active = 1
               $excludeSql
             ORDER BY RAND()
             LIMIT 1");
        $stmt->execute(array_merge([$targetRegion], $excludeProductIds));
        $product = $stmt->fetch();
        if (!$product) {
            $report['errors'][] = "blog[$targetRegion]: no eligible product (batch_pos=" . (count($excludeProductIds)+1) . ")";
            return $out;
        }
    }
    $out['product_name'] = (string)$product['name'];

    // Region-specific copy hints — currency, locale, delivery wording.
    $regionContext = [
        'US' => ['country' => 'United States',  'currency' => 'USD ($)', 'locale' => 'American English', 'delivery' => 'instant digital delivery to any US address'],
        'UK' => ['country' => 'United Kingdom', 'currency' => 'GBP (£)', 'locale' => 'British English',  'delivery' => 'instant digital delivery across the UK including Northern Ireland'],
        'AU' => ['country' => 'Australia',      'currency' => 'AUD (A$)', 'locale' => 'Australian English', 'delivery' => 'instant digital delivery to anywhere in Australia and NZ'],
        'CA' => ['country' => 'Canada',         'currency' => 'CAD (C$)', 'locale' => 'Canadian English (with bilingual support)', 'delivery' => 'instant digital delivery coast-to-coast across Canada'],
    ];
    $rc = $regionContext[$targetRegion] ?? $regionContext['US'];

    $sys = <<<SYS
You are a senior content strategist for Maventech Software, an authorised
reseller of genuine digital software license keys (Microsoft, Bitdefender,
Norton, McAfee, Adobe, Autodesk, etc.). Your job is to write a SHORT,
ORIGINAL, SEO-friendly blog article that helps buyers in {$rc['country']}
decide whether the product below is right for them.

Return STRICT JSON with EXACTLY these keys (no markdown, no code fences):

  title:       A compelling, search-friendly title (50-70 chars). MUST include
               the country or currency naturally — e.g. "… for {$rc['country']}
               buyers" or "… (Price in {$rc['currency']})".  No quotes.
  lead:        A 40-60 word DIRECT ANSWER to the post's title written as a
               complete paragraph. This is the AEO snippet Google AI Overviews,
               Bing Chat, ChatGPT and Perplexity quote verbatim. It must (a)
               state the answer in the FIRST sentence, (b) name the product
               by full title, (c) mention {$rc['country']} or {$rc['currency']}
               naturally, and (d) end with one trust signal (genuine, 30-day
               guarantee, instant email delivery).
  read_time:   A short string like "5 min read".
  content_html: Body HTML, 450-700 words. Use ONLY these tags: <p>, <h2>,
                <ul>, <li>, <strong>, <em>, <a>. NO inline styles, NO
                scripts. Start with the H2 sections directly (DO NOT repeat
                the lead — it is rendered separately above the body). Then
                2-3 <h2> sections, a <ul> with 4-5 key takeaways, and
                finish with a closing paragraph that links to the product
                page using the slug provided. Include at least ONE concrete
                statistic with attribution (e.g. "according to Statista in 2025…").
  faq:         An array of exactly 3 FAQ objects, each with "q" (question)
               and "a" (answer, 40-60 words). Questions should be natural
               queries a buyer in {$rc['country']} would type into Google or
               ask an AI assistant (e.g. "Is [product] worth buying in
               {$rc['country']}?", "What apps are included in [product]?",
               "How do I activate [product] after purchase?").

Rules:
 - Write specifically for buyers in {$rc['country']}. Use {$rc['locale']}.
 - When you mention price or savings, quote in {$rc['currency']} only.
 - Mention {$rc['delivery']} once in the article.
 - First-person plural ("we", "our team") tone — confident, no hype.
 - Always include this anchor in the closing paragraph:
     <a href="product.php?slug=PRODUCT_SLUG">Shop &lt;brand&gt; &lt;edition&gt; in {$rc['country']} →</a>
 - DO NOT invent absolute prices (no "$179", no "£149"). Use phrases like
   "starting at our published {$rc['currency']} price" instead.
 - Do not promise discounts. Do not mention competitors by name.
 - Output MUST be valid JSON — no text before or after.
SYS;

    $usr = "PRODUCT_NAME: {$product['name']}\n"
         . "PRODUCT_SLUG: {$product['slug']}\n"
         . "BRAND: " . ($product['brand'] ?: 'n/a') . "\n"
         . "CATEGORY: " . ($product['category'] ?: 'n/a') . "\n"
         . "VERSION: " . ($product['version'] ?: 'n/a') . "\n"
         . "APPS: " . ($product['apps'] ?: 'n/a') . "\n"
         . "TARGET_COUNTRY: {$rc['country']}\n"
         . "TARGET_CURRENCY: {$rc['currency']}\n"
         . "RAW_DESCRIPTION:\n" . trim((string)$product['description']);

    // Flash-deal mode injects the discount % + sale end-time + an unusual
    // tone instruction so the AI writes a time-sensitive "Today's flash
    // deal" article that explicitly mentions the % off and the end time.
    // Overrides the "Do not promise discounts" rule by appending an
    // exception below.
    if (!empty($flashDealMeta['percent_off'])) {
        $pct      = (int)$flashDealMeta['percent_off'];
        $endsAt   = (string)($flashDealMeta['ends_at'] ?? '');
        $endsHuman = $endsAt !== '' ? date('l g:i A T', strtotime($endsAt)) : 'within 24 hours';
        $sys .= "\n\nFLASH_DEAL_OVERRIDE:\n"
              . " - This article is a TIME-SENSITIVE flash-deal post. Override the\n"
              . "   \"do not promise discounts\" rule and lead the article with a\n"
              . "   \"{$pct}% off TODAY ONLY\" hook in the FIRST sentence of the lead.\n"
              . " - Title MUST begin with \"Flash Deal:\" and explicitly include\n"
              . "   the \"{$pct}% off\" string.\n"
              . " - Mention the sale ends \"{$endsHuman}\" once in the body.\n"
              . " - Add an extra closing call-to-action line urging the reader\n"
              . "   to act before the timer runs out.";
        $usr .= "\nFLASH_DEAL_PERCENT_OFF: {$pct}\nFLASH_DEAL_ENDS_AT: {$endsHuman}";
    }

    $payload = json_encode([
        'model'       => 'claude-haiku-4-5-20251001',
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ],
        'max_tokens'  => 1400,
        'temperature' => 0.75,
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
        CURLOPT_TIMEOUT        => 45,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $out['calls'] = 1;

    if ($err || !$raw || $code >= 400) {
        $report['errors'][] = "blog[$targetRegion]: LLM call failed — " . ($err ?: 'HTTP ' . $code);
        return $out;
    }
    $data   = json_decode((string)$raw, true);
    $answer = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $out['tokens_in']  = (int)($data['usage']['prompt_tokens']     ?? 0);
    $out['tokens_out'] = (int)($data['usage']['completion_tokens'] ?? 0);

    $answer = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', $answer);
    $j = _seo_llm_json_decode($answer);
    if (!is_array($j) || empty($j['title']) || empty($j['content_html'])) {
        $report['errors'][] = "blog[$targetRegion]: invalid JSON from LLM";
        return $out;
    }

    $title    = mb_substr(trim((string)$j['title']), 0, 200);
    $readTime = mb_substr(trim((string)($j['read_time'] ?? '5 min read')), 0, 20) ?: '5 min read';
    $content  = _seo_blog_sanitize_html((string)$j['content_html']);
    $image    = (string)$product['image'] ?: 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?q=80&w=872&auto=format&fit=crop';

    // AEO: Extract FAQ data if present (for FAQ Schema markup)
    $faqJson  = null;
    if (!empty($j['faq']) && is_array($j['faq'])) {
        $cleanFaq = [];
        foreach ($j['faq'] as $fItem) {
            if (!empty($fItem['q']) && !empty($fItem['a'])) {
                $cleanFaq[] = ['q' => trim($fItem['q']), 'a' => trim($fItem['a'])];
            }
        }
        if ($cleanFaq) {
            $faqJson = json_encode($cleanFaq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // Also append FAQ as HTML to the content for user visibility + AEO
            $faqHtml = '<h2>Frequently Asked Questions</h2>';
            foreach ($cleanFaq as $fq) {
                $faqHtml .= '<p><strong>' . htmlspecialchars($fq['q'], ENT_QUOTES, 'UTF-8') . '</strong></p>'
                          . '<p>' . htmlspecialchars($fq['a'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $content .= "\n" . $faqHtml;
        }
    }

    if (mb_strlen(strip_tags($content)) < 200) {
        $report['errors'][] = "blog[$targetRegion]: LLM body too short (" . mb_strlen(strip_tags($content)) . ' chars)';
        return $out;
    }

    // Per-region post id — keeps the four regional variants for the same
    // product distinct (ai-20260614-office-2024-US, …-UK, …-AU, …-CA).
    // Flash-deal posts get a `flash-` prefix + HHmm timestamp so the same
    // product can have many flash-deal posts a day (e.g. morning vs evening
    // A/B tests) without ID collisions.
    if (!empty($flashDealMeta['percent_off'])) {
        $postId = 'flash-' . date('Ymd-Hi') . '-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($product['slug'])), 0, 40) . '-' . strtolower($targetRegion);
    } else {
        $postId = 'ai-' . date('Ymd') . '-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($product['slug'])), 0, 50) . '-' . strtolower($targetRegion);
    }
    $existing = $pdo->prepare('SELECT 1 FROM blog_posts WHERE id = ?');
    $existing->execute([$postId]);
    if ($existing->fetchColumn()) {
        $postId .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // Count internal anchor links (the "backend links" SEO signal) and
    // store a content fingerprint for the weekly DMCA scraper scan.
    $internalLinks = preg_match_all('/href=\"(product\.php|category\.php|blog-post\.php|shop\.php|page\.php)/i', $content, $im);
    $fingerprint   = hash('sha1', preg_replace('/\s+/', ' ', trim(strip_tags($content))));

    try {
        $ins = $pdo->prepare("INSERT INTO blog_posts
            (id, title, date, read_time, image, content, ai_generated, product_id,
             target_region, internal_links_count, content_fingerprint, faq_json, lead, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $ins->execute([
            $postId,
            $title,
            date('M j, Y'),
            $readTime,
            $image,
            $content,
            (int)$product['id'],
            $targetRegion,
            (int)$internalLinks,
            $fingerprint,
            $faqJson,
            (string)($j['lead'] ?? ''),
        ]);
    } catch (Throwable $e) {
        $report['errors'][] = "blog[$targetRegion]: insert failed — " . $e->getMessage();
        return $out;
    }

    // ---- Per-post backlink + indexing verification ----
    // 1) Fire IndexNow for the single new URL (instant Bing / Yandex / Naver)
    // 2) HEAD check the live URL so we know it really resolved 200
    // 3) Persist both results onto the blog_posts row.
    $siteUrl = rtrim(site_url(), '/');
    $postUrl = $siteUrl . '/blog-post.php?id=' . rawurlencode($postId);
    $indexNowStatus = 'skipped';
    try {
        $rep = [];
        [$indexNowStatus] = _seo_indexnow_submit_urls([$postUrl], $rep);
    } catch (Throwable $e) { $indexNowStatus = 'error'; }

    $verifiedHttp = 0;
    try {
        $cv = curl_init($postUrl);
        curl_setopt_array($cv, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($cv);
        $verifiedHttp = (int)curl_getinfo($cv, CURLINFO_RESPONSE_CODE);
        curl_close($cv);
    } catch (Throwable $e) {}

    try {
        $pdo->prepare("UPDATE blog_posts SET indexnow_status = ?, verified_http = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$indexNowStatus, $verifiedHttp ?: null, $postId]);
    } catch (Throwable $e) {}

    $out['blog_post_id']    = $postId;
    $out['blog_post_title'] = $title;
    $out['blog_product_id'] = (int)$product['id'];
    $out['blog_post_image'] = $image;
    return $out;
}

/**
 * Whitelist-sanitise the model's HTML so we never let through scripts or
 * inline style/event-handler tricks. We allow only the tags we asked for.
 */
function _seo_blog_sanitize_html(string $html): string
{
    // Decode common JSON-escaped slashes.
    $html = str_replace('\/', '/', $html);
    // Drop any tag not in our allow-list.
    $allowed = '<p><h2><h3><ul><ol><li><strong><b><em><i><a><br>';
    $clean   = strip_tags($html, $allowed);
    // Strip on* event handlers and javascript: URIs, leave href intact.
    $clean = preg_replace('#\son[a-z]+="[^"]*"#i',   '', $clean);
    $clean = preg_replace("#\son[a-z]+='[^']*'#i",   '', $clean);
    $clean = preg_replace('#href\s*=\s*"\s*javascript:[^"]*"#i', 'href="#"', $clean);
    $clean = preg_replace("#href\s*=\s*'\s*javascript:[^']*'#i", 'href="#"', $clean);
    return trim($clean);
}

/* ===================================================================
 *  Run logging
 * =================================================================== */
function _seo_run_start(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO seo_runs (started_at) VALUES (NOW())")->execute();
    return (int)$pdo->lastInsertId();
}
function _seo_run_finish(PDO $pdo, int $runId, array $report): void
{
    $pdo->prepare("UPDATE seo_runs SET
        ended_at = NOW(),
        indexnow_status = ?, indexnow_count = ?,
        google_ping = ?, bing_ping = ?,
        wayback_status = ?, wayback_count = ?,
        llm_calls = ?, llm_tokens_in = ?, llm_tokens_out = ?,
        products_updated = ?,
        blog_post_id = ?, blog_post_title = ?, blog_product_id = ?, blog_post_image = ?,
        errors_json = ?
      WHERE id = ?")->execute([
        (string)($report['indexnow_status'] ?? ''),
        (int)   ($report['indexnow_count']  ?? 0),
        (string)($report['google_ping']     ?? ''),
        (string)($report['bing_ping']       ?? ''),
        (string)($report['wayback_status']  ?? ''),
        (int)   ($report['wayback_count']   ?? 0),
        (int)   ($report['llm_calls']       ?? 0),
        (int)   ($report['llm_tokens_in']   ?? 0),
        (int)   ($report['llm_tokens_out']  ?? 0),
        (int)   ($report['products_updated'] ?? 0),
        $report['blog_post_id']    ?? null,
        $report['blog_post_title'] ?? null,
        $report['blog_product_id'] ?? null,
        $report['blog_post_image'] ?? null,
        json_encode((array)($report['errors'] ?? []), JSON_UNESCAPED_SLASHES),
        $runId,
    ]);
}

/* ===================================================================
 *  DAILY FEATURED TRENDS ARTICLE
 *  --------------------------------------------------------------------
 *  ONE editorial-style "industry trends" article per day, separate from
 *  the 24-post regional batch.  Picks a different product each day via
 *  round-robin (least-recently-featured first) and asks Claude to write
 *  a longer, opinion-piece-style piece about 2026 trends in that
 *  product's category.
 *
 *  Schedule: once every 24 h (settings key `seo_bot_trends_last_at`).
 *  Stored with `is_featured_trends = 1` so the homepage hero / blog
 *  index can surface them prominently.
 * =================================================================== */
if (!defined('SEOBOT_TRENDS_COOLDOWN_H')) define('SEOBOT_TRENDS_COOLDOWN_H', 20);

function seo_publish_featured_trends_article(array &$report, bool $force = false, string $targetRegion = 'ALL'): array
{
    $out = [
        'blog_post_id'    => null,
        'blog_post_title' => null,
        'blog_post_image' => null,
        'product_name'    => '',
        'target_region'   => $targetRegion,
    ];

    // Normalise the region — admin pickers send 'ALL' or a 2-letter code.
    $targetRegion = strtoupper(trim($targetRegion));
    $validRegions = ['ALL', 'US', 'UK', 'AU', 'CA'];
    if (!in_array($targetRegion, $validRegions, true)) $targetRegion = 'ALL';

    $pdo = db();
    seo_bot_ensure_schema($pdo);

    if (!$force) {
        $last = setting_get('seo_bot_trends_last_at', '');
        if ($last) {
            $hoursSince = (time() - strtotime($last)) / 3600;
            if ($hoursSince < SEOBOT_TRENDS_COOLDOWN_H) {
                return $out + ['skipped' => true, 'reason' => 'last trends article ' . round($hoursSince, 1) . 'h ago'];
            }
        }
    }

    [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    if (!$apiKey || !$baseUrl) {
        $report['skipped'][] = 'trends: LLM key not configured — add your AI key in the admin panel';
        return $out + ['error' => 'LLM key not configured — add your AI key in the admin panel'];
    }

    // Round-robin: pick the active product whose last FEATURED-TRENDS post
    // is the oldest (or has never had one).  Independent queue from the
    // regional batch — same product can appear in both without conflict.
    $stmt = $pdo->query("
        SELECT p.id, p.slug, p.name, p.brand, p.category, p.version, p.image, p.description, p.apps,
               (SELECT MAX(bp.created_at) FROM blog_posts bp
                 WHERE bp.product_id = p.id AND bp.is_featured_trends = 1) AS last_trends_at
          FROM products p
         WHERE p.is_active = 1
         ORDER BY (last_trends_at IS NULL) DESC, last_trends_at ASC, RAND()
         LIMIT 1");
    $product = $stmt ? $stmt->fetch() : null;
    if (!$product) {
        $report['errors'][] = 'trends: no active product available';
        return $out + ['error' => 'no active product'];
    }
    $out['product_name'] = (string)$product['name'];

    // Build a region-aware audience hint so the LLM tailors local
    // signals (currency, regulations, idioms).  When the operator picks
    // "All Countries" we keep the existing international framing.
    $regionAudienceMap = [
        'ALL' => 'an international audience (US, UK, AU, CA buyers)',
        'US'  => 'a United States audience — use US English, mention NIST / SOC 2 / HIPAA where relevant, refer to prices in USD',
        'UK'  => 'a United Kingdom audience — use British English, mention UK GDPR / Cyber Essentials Plus where relevant, refer to prices in GBP',
        'AU'  => 'an Australian audience — use Australian English, mention the Privacy Act / Essential Eight where relevant, refer to prices in AUD',
        'CA'  => 'a Canadian audience — use Canadian English, mention PIPEDA / Bill C-26 where relevant, refer to prices in CAD',
    ];
    $audienceLine = $regionAudienceMap[$targetRegion] ?? $regionAudienceMap['ALL'];

    $sys = <<<SYS
You are the senior editorial writer for Maventech Software.  Your job is to
publish ONE longer, opinion-piece-style article that contextualises a
product against the broader industry trends of 2026.  Write for {$audienceLine}.

Return STRICT JSON with EXACTLY these keys (no markdown, no code fences):
  title:        Editorial-style title (55-75 chars). e.g. "Why ___ Still
                Matters in 2026" or "5 Trends Shaping ___ Buyers This Year".
  lead:         One opinion-driven hook (120-180 chars).
  read_time:    A short string like "6 min read".
  content_html: Body HTML, 700-1000 words. Use ONLY: <p>, <h2>, <h3>, <ul>,
                <ol>, <li>, <strong>, <em>, <a>, <blockquote>. NO inline
                styles, NO scripts. Open with <p class="lead">{lead}</p>.
                Then 3-4 <h2> sections covering: (1) current 2026 market
                context for this product's category, (2) what's changed
                vs. last year, (3) what serious buyers should look for,
                (4) where this product fits.  Include one <blockquote>
                with a memorable line.  Finish with a closing CTA paragraph
                that links to the product page using PRODUCT_SLUG and the
                brand's full category page via category.php.

Rules:
 - Editorial, confident tone — first person plural ("we", "our buyers").
 - Focus on trends, NOT promotion. Mention specific 2026 themes (AI
   integration, hybrid work, compliance frameworks like NIS2 / SOC 2, etc.)
   where they fit naturally.
 - Do NOT invent prices or discounts.
 - CRITICAL: Output MUST start with `{` and end with `}` — pure JSON only.
   No explanation, no preface ("Here is..."), no markdown code fences,
   no trailing remarks. The first character of your response must be `{`.
SYS;

    $usr = "PRODUCT_NAME: {$product['name']}\n"
         . "PRODUCT_SLUG: {$product['slug']}\n"
         . "BRAND: " . ($product['brand'] ?: 'n/a') . "\n"
         . "CATEGORY: " . ($product['category'] ?: 'n/a') . "\n"
         . "VERSION: " . ($product['version'] ?: 'n/a') . "\n"
         . "APPS: " . ($product['apps'] ?: 'n/a') . "\n"
         . "TODAY: " . date('F Y') . "\n"
         . "RAW_DESCRIPTION:\n" . trim((string)$product['description']);

    $payload = json_encode([
        'model'       => 'claude-haiku-4-5-20251001',
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ],
        'max_tokens'  => 2000,
        'temperature' => 0.8,
    ]);

    $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw || $code >= 400) {
        $report['errors'][] = 'trends: LLM HTTP ' . ($err ?: $code);
        return $out + ['error' => 'LLM ' . ($err ?: $code)];
    }
    $data   = json_decode((string)$raw, true);
    $answer = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $j      = _seo_llm_json_decode($answer);
    if (!is_array($j) || empty($j['title']) || empty($j['content_html'])) {
        // Include a short snippet of what the LLM returned to make
        // debugging the failure mode much easier from the admin panel.
        $snippet = mb_substr(preg_replace('/\s+/', ' ', (string)$answer), 0, 160);
        $report['errors'][] = 'trends: invalid JSON from LLM' . ($snippet !== '' ? ' (got: ' . $snippet . '…)' : '');
        return $out + ['error' => 'invalid JSON'];
    }

    $title    = mb_substr(trim((string)$j['title']), 0, 200);
    $readTime = mb_substr(trim((string)($j['read_time'] ?? '7 min read')), 0, 20) ?: '7 min read';
    $content  = _seo_blog_sanitize_html((string)$j['content_html']);
    // Allow <blockquote> in trends articles even though the strict sanitiser
    // strips it — re-add by running our own pass.
    $content  = preg_replace_callback('/&lt;(\/?)blockquote&gt;/i', fn($m) => '<' . $m[1] . 'blockquote>', $content);
    $image    = (string)$product['image'] ?: 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?q=80&w=872&auto=format&fit=crop';

    $postId = 'ai-trends-' . date('Ymd') . '-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($product['slug'])), 0, 50);
    $exists = $pdo->prepare('SELECT 1 FROM blog_posts WHERE id = ?');
    $exists->execute([$postId]);
    if ($exists->fetchColumn()) $postId .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);

    $internalLinks = preg_match_all('/href=\"(product\.php|category\.php|blog-post\.php|shop\.php|page\.php|brand\.php)/i', $content);
    $fingerprint   = hash('sha1', preg_replace('/\s+/', ' ', trim(strip_tags($content))));

    try {
        $pdo->prepare("INSERT INTO blog_posts
            (id, title, date, read_time, image, content, ai_generated, product_id,
             target_region, is_featured_trends, internal_links_count, content_fingerprint, lead, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, 1, ?, ?, ?, NOW(), NOW())")->execute([
            $postId, $title, date('M j, Y'), $readTime, $image, $content,
            (int)$product['id'], $targetRegion, (int)$internalLinks, $fingerprint,
            (string)($j['lead'] ?? ''),
        ]);
    } catch (Throwable $e) {
        $report['errors'][] = 'trends: insert failed — ' . $e->getMessage();
        return $out + ['error' => $e->getMessage()];
    }

    // Per-post verification + IndexNow ping (same as regional posts).
    $postUrl = rtrim(site_url(), '/') . '/blog-post.php?id=' . rawurlencode($postId);
    try {
        $rep2 = [];
        [$inStatus] = _seo_indexnow_submit_urls([$postUrl], $rep2);
    } catch (Throwable $e) { $inStatus = 'error'; }
    try {
        $cv = curl_init($postUrl);
        curl_setopt_array($cv, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($cv);
        $vhttp = (int)curl_getinfo($cv, CURLINFO_RESPONSE_CODE);
        curl_close($cv);
    } catch (Throwable $e) { $vhttp = 0; }
    try {
        $pdo->prepare("UPDATE blog_posts SET indexnow_status = ?, verified_http = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$inStatus, $vhttp ?: null, $postId]);
    } catch (Throwable $e) {}

    setting_set('seo_bot_trends_last_at', date('Y-m-d H:i:s'));

    $out['blog_post_id']    = $postId;
    $out['blog_post_title'] = $title;
    $out['blog_post_image'] = $image;
    $out['target_region']   = $targetRegion;
    return $out;
}

/* ===================================================================
 *  Public helper for the admin dashboard mini-card.
 * =================================================================== */
function seo_bot_latest_run(): ?array
{
    try {
        $pdo = db();
        seo_bot_ensure_schema($pdo);
        $r = $pdo->query("SELECT * FROM seo_runs ORDER BY id DESC LIMIT 1")->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ===================================================================
 *  SELF-CRON — fire-and-forget tick for every HTTP request.
 *  --------------------------------------------------------------------
 *  This is what makes the auto-blogger *truly* automatic: any visitor
 *  (or an admin opening the dashboard) becomes the heartbeat that
 *  publishes the daily blog post. No system cron, no cPanel setup,
 *  no manual button.
 *
 *  How it works:
 *    1) Tiny, single-row settings lookup ("was last run > 24 h ago?").
 *    2) A lock file in sys_get_temp_dir() prevents two concurrent
 *       requests from both firing the bot. The lock TTL is 10 minutes
 *       — long enough for the LLM call, short enough to recover from
 *       a crashed worker.
 *    3) After the lock is taken we close the HTTP response to the
 *       browser (so the visitor sees their page instantly) and run
 *       the actual SEO bot in the still-alive PHP worker.
 *
 *  Safe to call from header.php on EVERY request — the early exit
 *  branches add ~0.1 ms when the bot isn't due.
 * =================================================================== */
function seo_bot_autotick(): void
{
    // Don't trip during CLI scripts, the dedicated cron worker, or bots.
    if (PHP_SAPI === 'cli') return;
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['cron.php'], true)) return;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '' || preg_match('/bot|crawler|spider|googlebot|bingbot|yandex|baidu|facebookexternalhit|slack|discord|preview|monitor/i', $ua)) return;

    try {
        $last = setting_get('seo_bot_last_run_at', '');
        if ($last && (time() - strtotime($last)) < 24 * 3600) return; // not due
    } catch (Throwable $e) { return; }

    // Single-flight lock so two simultaneous visitors don't both fire.
    $lockFile = sys_get_temp_dir() . '/maventech_seo_bot.lock';
    if (is_file($lockFile) && (time() - filemtime($lockFile)) < 600) return; // 10 min TTL
    if (@file_put_contents($lockFile, (string)time()) === false) return;

    // Defer the actual run until AFTER PHP has finished sending the response
    // to the browser, so the visitor isn't blocked by the LLM call.
    register_shutdown_function(static function () use ($lockFile) {
        // Close the connection to the browser ASAP.
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // For PHP built-in server / non-FPM environments — best-effort flush.
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
        }
        try {
            seo_bot_run_if_due(false);
        } catch (Throwable $e) {
            @error_log('[seo-bot autotick] ' . $e->getMessage());
        }
        try {
            seo_bot_weekly_sitemap_tick();
        } catch (Throwable $e) {
            @error_log('[seo-bot weekly-sitemap] ' . $e->getMessage());
        }
        try {
            seo_bot_freshness_tick();
        } catch (Throwable $e) {
            @error_log('[seo-bot freshness-tick] ' . $e->getMessage());
        } finally {
            @unlink($lockFile);
        }
    });
}

/* =====================================================================
 *  DAILY AUTO-RESUBMIT
 *  -----------------------------------------------------------------
 *  When the operator enables "Auto-resubmit sitemap" in the admin SEO
 *  panel, this function re-pings IndexNow with the fresh sitemap URLs
 *  every 24 hours.  Idempotent — gated on:
 *    - setting `auto_sitemap_weekly` == '1'  (kept the key name for
 *      backwards-compat with stored values; the cadence is now daily.)
 *    - last submission timestamp > 24 hours old
 *  Runs in the same background shutdown handler as the auto-blogger so
 *  it never blocks a page-load.
 * =================================================================== */
function seo_bot_weekly_sitemap_tick(): void
{
    if ((string)setting_get('auto_sitemap_weekly', '0') !== '1') return;

    $lastAt = setting_get('last_sitemap_submit_at', '');
    if ($lastAt) {
        $age = time() - strtotime($lastAt);
        if ($age >= 0 && $age < 86400) return; // submitted less than 24 hours ago
    }

    if (!function_exists('_seo_collect_index_urls') || !function_exists('_seo_indexnow_submit_urls')) return;
    $urls = _seo_collect_index_urls(100);
    if (!$urls) return;

    $rep = [];
    try {
        [$status, $count] = _seo_indexnow_submit_urls($urls, $rep);
    } catch (Throwable $e) {
        @error_log('[daily-sitemap] ' . $e->getMessage());
        return;
    }
    if ($status === 'ok') {
        setting_set('last_sitemap_submit_at',    date('Y-m-d H:i:s'));
        setting_set('last_sitemap_submit_count', (string)$count);
        setting_set('last_sitemap_submit_kind',  'auto_daily');
    }
}

/* =====================================================================
 *  AEO / GEO FRESHNESS TICK
 *  ---------------------------------------------------------------------
 *  Picks the SINGLE oldest AI-published blog post that hasn't been
 *  touched in 90+ days and refreshes its FAQ + lead via the LLM.  We
 *  also bump `updated_at` so the JSON-LD `dateModified` advances —
 *  this is the strongest "freshness" signal Google's Helpful Content
 *  framework looks for, and AI search engines weight recently-updated
 *  citations higher.  Bounded to one post per autotick so we never
 *  burn the API budget.
 * =================================================================== */
function seo_bot_freshness_tick(): void
{
    try {
        [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    } catch (Throwable $e) { return; }
    if (!$apiKey || !$baseUrl) return;

    // Already refreshed today?  Bail.  Otherwise pick the oldest stale post.
    $lastTickAt = setting_get('seo_freshness_last_tick_at', '');
    if ($lastTickAt && (time() - strtotime($lastTickAt)) < 23 * 3600) return;

    $pdo = db();
    try {
        $row = $pdo->query("SELECT id, title, content, lead, target_region
                              FROM blog_posts
                             WHERE ai_generated = 1
                               AND (updated_at IS NULL OR updated_at < NOW() - INTERVAL 90 DAY)
                          ORDER BY COALESCE(updated_at, created_at, '1970-01-01') ASC
                             LIMIT 1")->fetch();
    } catch (Throwable $e) { return; }
    if (!$row) return;

    // Single short LLM call: ask for a refreshed 40-60 word lead AND a
    // 3-item FAQ.  Cheap, fast, and lifts the post's freshness signal.
    $sys = 'You are an editor refreshing a published blog post. '
         . 'Return STRICT JSON: { "lead": "<40-60 word direct answer>", '
         . '"faq": [{"q":"...","a":"40-60 word answer"} ×3] }. '
         . 'CRITICAL: Output must start with `{` and end with `}` — no markdown, no preamble.';
    $usr = "TITLE: " . $row['title'] . "\n"
         . "TARGET COUNTRY: " . (string)($row['target_region'] ?: 'international') . "\n"
         . "CURRENT LEAD: " . (string)($row['lead'] ?: '(none)') . "\n"
         . "ARTICLE (first 1200 chars):\n" . mb_substr(strip_tags((string)$row['content']), 0, 1200);

    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'messages'   => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ],
        'max_tokens' => 700,
        'temperature'=> 0.6,
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
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$raw) return;

    $data   = json_decode((string)$raw, true);
    $answer = (string)($data['choices'][0]['message']['content'] ?? '');
    $j      = _seo_llm_json_decode($answer);
    if (!is_array($j) || empty($j['lead'])) return;

    $faqJson = '';
    if (!empty($j['faq']) && is_array($j['faq'])) {
        $faqJson = json_encode(array_values(array_filter($j['faq'], fn($x) => !empty($x['q']) && !empty($x['a']))), JSON_UNESCAPED_UNICODE);
    }
    try {
        $upd = $pdo->prepare("UPDATE blog_posts SET lead = ?,
                                 faq_json = COALESCE(NULLIF(?, ''), faq_json),
                                 updated_at = NOW()
                               WHERE id = ?");
        $upd->execute([(string)$j['lead'], $faqJson, (string)$row['id']]);
        setting_set('seo_freshness_last_tick_at', date('Y-m-d H:i:s'));
        setting_set('seo_freshness_last_refreshed_id', (string)$row['id']);
    } catch (Throwable $e) {
        @error_log('[seo-bot freshness] update: ' . $e->getMessage());
    }
}
