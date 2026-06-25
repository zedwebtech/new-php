<?php
// Email open-tracking endpoint. Returns a 1x1 transparent GIF and marks
// the email row as opened in `email_outbox`. Public — no auth.

require_once __DIR__ . '/includes/db.php';

$t = $_GET['t'] ?? '';
if ($t !== '' && preg_match('/^[a-f0-9]{32,64}$/', $t)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE email_outbox
            SET opened_at = COALESCE(opened_at, NOW()),
                opened_count = opened_count + 1,
                status = CASE WHEN status IN ("queued","failed") THEN status ELSE "opened" END
            WHERE tracking_token = ?');
        $stmt->execute([$t]);
    } catch (Throwable $e) { /* swallow — never break the pixel */ }
}

// 1x1 transparent GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
