<?php
/*
 * Visitor tracking — light-weight, no external dependencies.
 *
 *   track_visitor($pageUrl)  →  inserts a row into visitor_log on every public
 *   page view from a real human.  Bots, the cron worker, and the logged-in
 *   admin are skipped so the dashboard only shows real-customer traffic.
 *
 *   Parses the User-Agent into OS / Browser / Device (Desktop|Mobile|Tablet)
 *   without a third-party library.  A best-effort country lookup is done via
 *   ip-api.com (no API key, ~45 req/min) and the result is cached on the
 *   PHP session so we only call it once per browsing session.
 */

if (!function_exists('track_visitor')) {

function _ua_parse(string $ua): array
{
    $ua = trim($ua);
    if ($ua === '') return ['Unknown', 'Unknown', 'Unknown'];

    // ---- OS ---------------------------------------------------------------
    $os = 'Unknown';
    if (preg_match('/Windows NT 10/i', $ua))        $os = 'Windows 10/11';
    elseif (preg_match('/Windows NT 6\.3/i', $ua))  $os = 'Windows 8.1';
    elseif (preg_match('/Windows NT 6\.2/i', $ua))  $os = 'Windows 8';
    elseif (preg_match('/Windows NT 6\.1/i', $ua))  $os = 'Windows 7';
    elseif (preg_match('/Windows/i', $ua))          $os = 'Windows';
    elseif (preg_match('/iPad|iPhone|iPod/i', $ua)) $os = 'iOS';
    elseif (preg_match('/Android/i', $ua))          $os = 'Android';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $os = 'macOS';
    elseif (preg_match('/CrOS/i', $ua))             $os = 'Chrome OS';
    elseif (preg_match('/Linux/i', $ua))            $os = 'Linux';

    // ---- Browser ----------------------------------------------------------
    $browser = 'Other';
    if      (preg_match('/Edg\//i', $ua))                                $browser = 'Edge';
    elseif  (preg_match('/OPR\/|Opera/i', $ua))                          $browser = 'Opera';
    elseif  (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium/i', $ua)) $browser = 'Chrome';
    elseif  (preg_match('/Firefox\//i', $ua))                            $browser = 'Firefox';
    elseif  (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome|Chromium|Edg/i', $ua)) $browser = 'Safari';
    elseif  (preg_match('/MSIE|Trident/i', $ua))                         $browser = 'Internet Explorer';

    // ---- Device -----------------------------------------------------------
    $device = 'Desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua))                 $device = 'Tablet';
    elseif (preg_match('/Android(?!.*Mobile)/i', $ua))                   $device = 'Tablet';
    elseif (preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|webOS/i', $ua)) $device = 'Mobile';

    return [$os, $browser, $device];
}

function _is_bot(string $ua): bool
{
    if ($ua === '') return true;
    // Common bot/crawler/spider strings.  We deliberately skip these so the
    // dashboard reflects HUMAN traffic only.
    $patt = '/bot|crawl|spider|slurp|bingpreview|preview|facebookexternalhit|pingdom|monitis|'
          . 'uptimerobot|googlebot|adsbot|mediapartners|duckduckbot|baiduspider|yandex|'
          . 'sogou|exabot|semrushbot|ahrefsbot|mj12bot|dotbot|petalbot|applebot|'
          . 'twitterbot|linkedinbot|whatsapp|telegrambot|discordbot|headlesschrome|'
          . 'phantomjs|puppeteer|playwright|chrome-lighthouse|curl|wget|python-requests/i';
    return (bool)preg_match($patt, $ua);
}

function _client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0] ?? '';
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

function _geo_country(string $ip): string
{
    // Cache per session to avoid hammering the free API.
    if (!empty($_SESSION['vt_country'])) return (string)$_SESSION['vt_country'];
    if ($ip === '' || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.|::1$)/', $ip)) {
        $_SESSION['vt_country'] = 'Local';
        return 'Local';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    // ip-api.com's free tier requires plain HTTP.  This is a server-side
    // PHP fetch (never exposed to the browser) so it cannot trigger Google
    // Safe Browsing's "mixed content" / "deceptive site" warnings on its own.
    $j = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode,status", false, $ctx);
    $code = '';
    if ($j) { $d = json_decode($j, true); if (!empty($d['countryCode']) && ($d['status'] ?? '') === 'success') $code = strtoupper((string)$d['countryCode']); }
    $_SESSION['vt_country'] = $code ?: 'XX';
    return $_SESSION['vt_country'];
}

function track_visitor(?string $pageUrl = null): void
{
    // Never track the admin/cron/CLI.
    if (PHP_SAPI === 'cli') return;
    if (!empty($_SESSION['user_id'])) {
        // Logged-in admins skip tracking so dashboard counts only real customers.
        try {
            $u = current_user();
            if ($u && ($u['role'] ?? '') === 'admin') return;
        } catch (Throwable $e) { /* fall through and track as anonymous */ }
    }

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (_is_bot($ua)) return;

    [$os, $browser, $device] = _ua_parse($ua);
    $ip      = _client_ip();
    $ipHash  = $ip ? hash('sha256', $ip . '|maventech-salt') : '';
    $session = session_id() ?: '';
    $url     = $pageUrl ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $url     = mb_substr($url, 0, 255, 'UTF-8');
    $country = _geo_country($ip);
    $ref     = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255, 'UTF-8');

    try {
        $pdo = db();
        $pdo->prepare("INSERT INTO visitor_log
                        (session_id, ip_hash, user_agent, os, browser, device, country, page_url, referer)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$session, $ipHash, mb_substr($ua, 0, 500, 'UTF-8'),
                       $os, $browser, $device, $country, $url, $ref]);
    } catch (Throwable $e) {
        // Tracking failures must NEVER break the public page.  Log + swallow.
        @error_log('[visitor_track] ' . $e->getMessage());
    }
}

}
