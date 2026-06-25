<?php
/**
 * Lead-management real-time polling endpoint.
 *
 * Returns the set of chat_leads currently "online" (last_seen < 60s) plus
 * the total lead count.  The admin Lead Management tab polls this every
 * 10 seconds to (a) toggle each Chat button between dark/green and
 * (b) fire a toast + ding when the total grows (= a brand-new lead).
 *
 *  GET /ajax/leads-online.php
 *  →  {
 *        "ok": true,
 *        "now": "2026-02-15T19:21:34+00:00",
 *        "online_ids": [12, 18, 21],   // last_seen < 60s
 *        "total": 87,                  // count of chat_leads
 *        "latest": [                   // 5 most-recent rows, for toast text
 *           { "id": 21, "name": "Priya Sharma", "email": "p@x.in",
 *             "product": "Office 2024", "created_at": "2026-02-15 19:20:55" }
 *        ]
 *     }
 *
 * Admin-only.  Sessions established via /login.php carry is_admin=true.
 */
require_once __DIR__ . '/../includes/functions.php';
ensure_admin();
require_admin_json();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$pdo = db();

try {
    // Online = last_seen within the past 60 seconds.  Falls back to []
    // when the chat_leads table doesn't exist yet (fresh install).
    $online = $pdo->query(
        "SELECT id FROM chat_leads
         WHERE last_seen IS NOT NULL
           AND last_seen >= (NOW() - INTERVAL 60 SECOND)
         ORDER BY last_seen DESC"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $total = (int)$pdo->query("SELECT COUNT(*) FROM chat_leads")->fetchColumn();

    // Install-Schedule pending count — drives the amber badge on the
    // Commerce → Install Schedule sidebar item.  Pending = status='pending'
    // AND scheduled_utc is in the future OR within the last 60 min so a
    // just-missed slot still flags.
    try {
        $installPending = (int)$pdo->query(
            "SELECT COUNT(*) FROM proassist_schedules
             WHERE status='pending'
               AND scheduled_utc >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
        )->fetchColumn();
    } catch (Throwable $e) { $installPending = 0; }

    $latest = $pdo->query(
        "SELECT id, name, email, requested_product AS product,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
         FROM chat_leads
         ORDER BY id DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'              => true,
        'now'             => date('c'),
        'online_ids'      => array_map('intval', $online),
        'total'           => $total,
        'install_pending' => $installPending,
        'latest'          => $latest,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
