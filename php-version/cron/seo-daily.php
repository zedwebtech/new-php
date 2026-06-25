<?php
/**
 * External-cron endpoint for shared-hosting deployments.
 *
 *   GET /cron/seo-daily.php?token={SECRET}
 *
 * Token is stored in the `settings` table under `seo_bot_cron_token` and
 * rotatable from the admin panel (AI Auto-Blogger tab → "Rotate token").
 *
 * Behaviour:
 *   - If today's batch hasn't run in the last 20 h (SEOBOT_BLOG_COOLDOWN_H)
 *     it triggers seo_bot_run_if_due() to publish the full 24-post batch.
 *   - Otherwise (cron pinging more often than once a day), it publishes
 *     ONE post for the next under-served region so the cap is still
 *     progressed toward 24/day even on hosts that ping us every hour.
 *   - Always returns a JSON body so the scheduler logs are readable.
 *
 * Wire it up:
 *   shared hosting cron tab:  curl -fsS "https://YOURDOMAIN/cron/seo-daily.php?token=…"
 *   server crontab line:      0 * * * * curl -fsS "…" >/dev/null 2>&1
 *
 * NOTE: the same self-cron auto-tick continues to fire on regular page
 * views, so this endpoint is purely a *belt-and-braces* mechanism for
 * deployments that prefer a real scheduler.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo-bot.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Auth — constant-time compare so an attacker can't time-attack the token.
$expected = seo_bot_cron_token();
$provided = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_SLASHES);
    return;
}

$started   = microtime(true);
$response  = [
    'ok'           => true,
    'started_at'   => date('c'),
    'mode'         => null,
    'published'    => [],
    'errors'       => [],
    'duration_ms'  => null,
];

try {
    // If a full daily batch hasn't run inside the cooldown window, run it.
    // SEOBOT_BLOG_COOLDOWN_H is defined inside seo-bot.php (20 h default).
    $lastBatchAt = setting_get('seo_bot_last_blog_post_at', '');
    $hoursSince  = $lastBatchAt ? ((time() - strtotime($lastBatchAt)) / 3600) : 9999;

    if ($hoursSince >= SEOBOT_BLOG_COOLDOWN_H) {
        $response['mode'] = 'daily_batch';
        // Force=true bypasses the inner 24h check so the cron decides timing.
        $r = seo_bot_run_if_due(true);
        if (!empty($r['skipped'])) {
            $response['mode']      = 'skipped';
            $response['reason']    = $r['reason'] ?? '';
        } else {
            foreach (($r['blog_posts'] ?? []) as $p) {
                $response['published'][] = [
                    'id'      => $p['blog_post_id']    ?? null,
                    'title'   => $p['blog_post_title'] ?? null,
                    'region'  => $p['target_region']   ?? null,
                    'product' => $p['product_name']    ?? null,
                ];
            }
            $response['indexnow_status'] = $r['indexnow_status'] ?? null;
            $response['indexnow_count']  = (int)($r['indexnow_count'] ?? 0);
            $response['llm_calls']       = (int)($r['llm_calls']     ?? 0);
            $response['errors']          = array_values((array)($r['errors'] ?? []));
        }
    } else {
        // Batch already ran inside the cooldown — push ONE under-served-region
        // post so the daily-cap progress bar keeps moving forward.
        $response['mode'] = 'one_under_served';
        $one = seo_publish_one_post_now();
        if (!empty($one['ok'])) {
            $response['published'][] = [
                'id'      => $one['blog_post_id'],
                'title'   => $one['blog_post_title'],
                'region'  => $one['region'],
                'product' => $one['product_name'],
            ];
        } else {
            $response['ok']     = false;
            $response['errors'] = [$one['error'] ?? 'unknown error'];
        }
    }
} catch (Throwable $e) {
    $response['ok']     = false;
    $response['errors'] = [$e->getMessage()];
    http_response_code(500);
}

$response['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
