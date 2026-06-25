<?php
/**
 * cron.php — queue worker for shared-hosting cron jobs.
 *
 * Usage on cPanel / Plesk:
 *   * * * * * /usr/bin/curl -s "https://your-domain.com/cron.php?token=YOUR_SECRET" >/dev/null
 *
 * The token is the value of the `cron_token` setting (auto-generated on first
 * access). Hits without a valid token return 403.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/seo-bot.php';
require_once __DIR__ . '/includes/ai-citation-tracker.php';
require_once __DIR__ . '/includes/dmca-watchdog.php';

// Generate a cron token once (so the admin can copy it from the SMTP page).
$token = setting_get('cron_token', '');
if ($token === '') {
    $token = bin2hex(random_bytes(20));
    setting_set('cron_token', $token);
}

$given = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals($token, (string)$given)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden — invalid cron token.\n";
    exit;
}

header('Content-Type: text/plain');
$batch = max(1, min(200, (int)($_GET['batch'] ?? 50)));
$start = microtime(true);
$count = smtp_process_queue($batch);
$ms    = (int)((microtime(true) - $start) * 1000);
echo "[" . date('c') . "] cron.php: processed=$count batch=$batch elapsed_ms=$ms\n";

// SEO / GEO / AEO automation — runs once every 24h.
// IndexNow ping + Google/Bing sitemap ping + Claude Haiku content refresh
// (meta description + AI summary for stale products).
try {
    $seoForce = !empty($_GET['seo_force']);
    $seoReport = seo_bot_run_if_due($seoForce);
    if (!empty($seoReport['skipped'])) {
        echo "[" . date('c') . "] seo-bot: skipped — " . $seoReport['reason'] . "\n";
    } else {
        echo "[" . date('c') . "] seo-bot: indexnow={$seoReport['indexnow_status']} ({$seoReport['indexnow_count']} urls) "
           . "google={$seoReport['google_ping']} bing={$seoReport['bing_ping']} "
           . "llm_calls={$seoReport['llm_calls']} updated={$seoReport['products_updated']}"
           . (!empty($seoReport['blog_post_id']) ? ' blog_post="' . $seoReport['blog_post_title'] . '"' : '')
           . (empty($seoReport['errors']) ? '' : ' errors=' . count($seoReport['errors']))
           . "\n";
    }
} catch (Throwable $e) {
    echo "[" . date('c') . "] seo-bot: ERROR " . $e->getMessage() . "\n";
}

// AI Citation Tracker — once every 7 days, ask Claude / GPT-4o-mini / Gemini
// "what does <brand> sell?" and store the answer. Lets the dashboard surface
// whether the AI engines actually know about us yet.
try {
    $citForce = !empty($_GET['citations_force']);
    $citReport = ai_citations_run_if_due($citForce);
    if (!empty($citReport['skipped'])) {
        echo "[" . date('c') . "] ai-citations: skipped — " . $citReport['reason'] . "\n";
    } else {
        $engineCount = count($citReport['engines'] ?? []);
        $brandHits = 0; $urlHits = 0;
        foreach (($citReport['engines'] ?? []) as $e) {
            if (!empty($e['mentions_brand'])) $brandHits++;
            if (!empty($e['mentions_url']))   $urlHits++;
        }
        echo "[" . date('c') . "] ai-citations: probed=$engineCount brand_mentions=$brandHits url_mentions=$urlHits\n";
    }
} catch (Throwable $e) {
    echo "[" . date('c') . "] ai-citations: ERROR " . $e->getMessage() . "\n";
}

// DMCA Scraper Watchdog — weekly sample of AI posts asked against Claude
// for content-clone detection. Logs findings to dmca_findings.
try {
    $dmcaForce = !empty($_GET['dmca_force']);
    $dmcaReport = dmca_run_if_due($dmcaForce);
    if (!empty($dmcaReport['skipped'])) {
        echo "[" . date('c') . "] dmca-watchdog: skipped — " . $dmcaReport['reason'] . "\n";
    } else {
        echo "[" . date('c') . "] dmca-watchdog: checked={$dmcaReport['checked']} findings={$dmcaReport['findings']}\n";
    }
} catch (Throwable $e) {
    echo "[" . date('c') . "] dmca-watchdog: ERROR " . $e->getMessage() . "\n";
}

// Daily featured-trends article — one editorial-style post per day, picked
// independently from the regional batch.
try {
    require_once __DIR__ . '/includes/seo-bot.php';
    $trReport = ['errors' => []];
    $tr = seo_publish_featured_trends_article($trReport, false);
    if (!empty($tr['skipped'])) {
        echo "[" . date('c') . "] trends-article: skipped — " . $tr['reason'] . "\n";
    } elseif (!empty($tr['blog_post_id'])) {
        echo "[" . date('c') . "] trends-article: published — " . $tr['blog_post_title'] . "\n";
    } else {
        echo "[" . date('c') . "] trends-article: error — " . ($trReport['errors'][0] ?? ($tr['error'] ?? '?')) . "\n";
    }
} catch (Throwable $e) {
    echo "[" . date('c') . "] trends-article: ERROR " . $e->getMessage() . "\n";
}
