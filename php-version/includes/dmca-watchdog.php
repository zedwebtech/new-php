<?php
/**
 * DMCA Scraper Watchdog — once a week, sample recent AI blog posts and ask
 * Claude (via Emergent LLM gateway) whether it has encountered the exact
 * text on other domains.  Hits + suspected URLs land in `dmca_findings`
 * with status pending/dismissed/reported/taken_down.
 *
 * Limitations (be honest with the user):
 *   - Without a live web-search API, this is a "what does the LLM already
 *     know" check — it catches scrapers Claude has crawled, not breaking
 *     news.
 *   - The big practical value here is the DMCA NOTICE TEMPLATE the admin
 *     can download once they identify a scraper (manually or via the LLM).
 *
 * Schedule: once every 7 days (settings key: dmca_last_scan_at).
 */

if (!defined('DMCA_SCAN_COOLDOWN_DAYS')) define('DMCA_SCAN_COOLDOWN_DAYS', 7);
if (!defined('DMCA_POSTS_PER_SCAN'))     define('DMCA_POSTS_PER_SCAN',     5);

function dmca_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dmca_findings (
            id INT(11) NOT NULL AUTO_INCREMENT,
            post_id VARCHAR(100) NOT NULL,
            suspected_url VARCHAR(500) NOT NULL,
            suspected_host VARCHAR(200) NULL,
            confidence VARCHAR(20) NULL,
            notes TEXT NULL,
            status ENUM('pending','dismissed','reported','taken_down') NOT NULL DEFAULT 'pending',
            scanned_with VARCHAR(60) NULL,
            ran_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post (post_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { @error_log('[dmca schema] ' . $e->getMessage()); }
}

function dmca_run_if_due(bool $force = false): array
{
    $pdo = db();
    dmca_ensure_schema($pdo);

    $last = setting_get('dmca_last_scan_at', '');
    if (!$force && $last) {
        $daysSince = (time() - strtotime($last)) / 86400;
        if ($daysSince < DMCA_SCAN_COOLDOWN_DAYS) {
            return ['skipped' => true, 'reason' => 'last scan ' . round($daysSince, 1) . 'd ago'];
        }
    }

    if (function_exists('_seo_resolve_llm_credentials')) {
        [$apiKey, $baseUrl] = _seo_resolve_llm_credentials();
    } else {
        $apiKey  = defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : (getenv('EMERGENT_LLM_KEY') ?: '');
        $baseUrl = defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : '';
    }
    if ($apiKey === '' || $baseUrl === '') {
        return ['skipped' => true, 'reason' => 'LLM key not configured'];
    }

    // Sample AI posts that haven't been scanned this cycle.
    $posts = $pdo->query("
        SELECT id, title, content, content_fingerprint, image
          FROM blog_posts
         WHERE ai_generated = 1
           AND content_fingerprint IS NOT NULL
         ORDER BY RAND()
         LIMIT " . (int)DMCA_POSTS_PER_SCAN)->fetchAll();

    $brand    = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software';
    $siteHost = parse_url(site_url(), PHP_URL_HOST);
    $findings = 0;
    $checked  = 0;

    foreach ($posts as $p) {
        $checked++;
        // Pull a distinctive 200-char snippet to ask about.
        $snippet = mb_substr(preg_replace('/\s+/', ' ', trim(strip_tags((string)$p['content']))), 0, 200);

        $sys = 'You are a content-theft investigator. Given a paragraph from a brand-new article, answer in STRICT JSON: {"found_elsewhere": bool, "suspected_urls": [string], "confidence": "low|medium|high", "notes": string}. Only set found_elsewhere=true if you are CONFIDENT the text has been republished on another domain. Never invent URLs. If unsure, return found_elsewhere=false.';
        $usr = "OWNER_BRAND: $brand\nOWNER_HOST: $siteHost\nARTICLE_TITLE: {$p['title']}\nARTICLE_SNIPPET: $snippet\n\nHave you seen this exact text on any domain OTHER than $siteHost?";

        $payload = json_encode([
            'model'       => 'claude-haiku-4-5-20251001',
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr],
            ],
            'max_tokens'  => 350,
            'temperature' => 0.1,
        ]);

        $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err || !$raw) continue;

        $j   = json_decode((string)$raw, true);
        $ans = trim((string)($j['choices'][0]['message']['content'] ?? ''));
        $ans = preg_replace('/^```(?:json)?\s*|```\s*$/i', '', $ans);
        $obj = json_decode($ans, true);
        if (!is_array($obj) || empty($obj['found_elsewhere'])) continue;
        $urls = (array)($obj['suspected_urls'] ?? []);
        foreach ($urls as $u) {
            $u = (string)$u;
            if (!preg_match('#^https?://#i', $u)) continue;
            $host = parse_url($u, PHP_URL_HOST);
            if (!$host || $host === $siteHost) continue;
            try {
                $pdo->prepare("INSERT INTO dmca_findings (post_id, suspected_url, suspected_host, confidence, notes, scanned_with, ran_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$p['id'], $u, $host, (string)($obj['confidence'] ?? ''), mb_substr((string)($obj['notes'] ?? ''), 0, 1000), 'claude-haiku-4-5']);
                $findings++;
            } catch (Throwable $e) {}
        }
    }

    setting_set('dmca_last_scan_at', date('Y-m-d H:i:s'));
    return ['checked' => $checked, 'findings' => $findings, 'ran_at' => date('c')];
}

/** All findings, optionally filtered by status. */
function dmca_list_findings(?string $status = null, int $limit = 50): array
{
    try {
        $pdo = db();
        dmca_ensure_schema($pdo);
        if ($status) {
            $stmt = $pdo->prepare("SELECT f.*, bp.title AS post_title
                                     FROM dmca_findings f
                                     LEFT JOIN blog_posts bp ON bp.id = f.post_id
                                    WHERE f.status = ?
                                    ORDER BY f.id DESC LIMIT $limit");
            $stmt->execute([$status]);
            return $stmt->fetchAll();
        }
        return $pdo->query("SELECT f.*, bp.title AS post_title
                              FROM dmca_findings f
                              LEFT JOIN blog_posts bp ON bp.id = f.post_id
                             ORDER BY f.id DESC LIMIT $limit")->fetchAll();
    } catch (Throwable $e) { return []; }
}

function dmca_set_status(int $id, string $status): bool
{
    if (!in_array($status, ['pending','dismissed','reported','taken_down'], true)) return false;
    try {
        db()->prepare("UPDATE dmca_findings SET status = ? WHERE id = ?")->execute([$status, $id]);
        return true;
    } catch (Throwable $e) { return false; }
}

/**
 * Build a copy-paste DMCA notice for a finding.  Output is plain text
 * ready for the user to paste into the host's abuse form or send by email.
 */
function dmca_build_notice(array $finding): string
{
    $ci    = function_exists('company_info') ? company_info() : [];
    $brand = $ci['name']    ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
    $email = $ci['email']   ?? 'services@maventechsoftware.com';
    $addr  = $ci['address'] ?? '';
    $phone = $ci['phone']   ?? '';
    $origin = rtrim(site_url(), '/') . '/blog-post.php?id=' . rawurlencode((string)$finding['post_id']);

    return "DMCA Takedown Notice\n"
         . "====================\n\n"
         . "To: Designated Copyright Agent of " . (string)$finding['suspected_host'] . "\n"
         . "Date: " . date('F j, Y') . "\n\n"
         . "I am the duly authorised representative of $brand, the owner of the\n"
         . "original copyrighted work described below.  Under penalty of\n"
         . "perjury, I assert that the information in this notice is accurate\n"
         . "and that I have a good-faith belief that the use described below\n"
         . "is not authorised by the copyright owner, its agent, or the law.\n\n"
         . "--- Original work ---\n"
         . "Title  : " . (string)$finding['post_title'] . "\n"
         . "URL    : $origin\n"
         . "Owner  : $brand\n\n"
         . "--- Infringing material ---\n"
         . "URL    : " . (string)$finding['suspected_url'] . "\n"
         . "Notes  : " . (string)$finding['notes'] . "\n\n"
         . "Please remove or disable access to the infringing material at\n"
         . "your earliest opportunity.\n\n"
         . "Signed,\n"
         . "$brand\n"
         . ($addr ? "$addr\n" : '')
         . ($phone ? "Tel: $phone\n" : '')
         . "Email: $email\n";
}
