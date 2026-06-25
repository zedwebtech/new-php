<?php
/*
 * Email resend AJAX — admin-only.
 *
 *   action=resend  → re-queue an existing email (optionally to a new
 *                    recipient address) and try to deliver immediately.
 *                    Returns JSON describing the outcome so the admin UI
 *                    can update the row in-place AND refresh the failed-
 *                    email bell counter without a full page reload.
 *
 * Delivery logic:
 *   1. Validate `email_id` + new recipient.
 *   2. Run a DNS MX/A pre-flight on the new recipient — if undeliverable,
 *      return a friendly error so the admin can fix the address BEFORE we
 *      ever create a duplicate row.
 *   3. If SMTP is configured → insert a fresh queued row, run the worker
 *      once (synchronous attempt), and report the outcome.
 *   4. If SMTP is OFF (dev/preview mode) → insert a row with status='sent'
 *      and a clear "captured in dev mode" note, mirroring send_email()'s
 *      dev-mode behaviour so admins can verify the body without leaving
 *      the row stuck at "queued" forever.
 *   5. On a successful send, flip the original failed/bounced row to
 *      'sent' so the failed-email bell counter drops by 1.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: $_GET);
$emailId = (int)($in['email_id'] ?? 0);
$newTo   = trim((string)($in['new_recipient']   ?? ''));
$newKey  = trim((string)($in['new_license_key'] ?? ''));

if (!$emailId) { echo json_encode(['ok'=>false, 'error'=>'email_id required']); exit; }

$pdo = db();
$row = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
$row->execute([$emailId]);
$em = $row->fetch();
if (!$em) { echo json_encode(['ok'=>false, 'error'=>'Email not found']); exit; }

$to = $newTo !== '' ? $newTo : $em['recipient'];
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false, 'error'=>'Invalid email address']); exit;
}

/* ---- 1a. Optional: swap the license key shown in the email body.
   We rewrite the FIRST monospace "Courier New" block inside the email's
   HTML — that's the License Key pill rendered by render_products_block()
   in includes/email.php. Subsequent product keys (if any) are left
   untouched, since the admin is updating one specific customer key. ---- */
$emailHtml = $em['html'];
if ($newKey !== '') {
    $replaced = false;
    $rewritten = preg_replace_callback(
        '#(<div\s+style="[^"]*font-family:[\'"]?Courier New[^"]*">)([^<]+)(</div>)#i',
        function ($m) use ($newKey, &$replaced) {
            if ($replaced) return $m[0];
            $replaced = true;
            return $m[1] . htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8') . $m[3];
        },
        $emailHtml,
        1
    );
    if ($rewritten !== null && $replaced) {
        $emailHtml = $rewritten;
    }
    // If the regex didn't match (unusual email layout), append a clear
    // "Updated License Key" callout near the top so the customer still
    // sees the new key.  Falls back gracefully on weird template variants.
    if (!$replaced) {
        $callout = '<div style="margin:18px 24px;padding:14px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;text-align:center;">'
                 . '<div style="font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;font-weight:600;">Updated License Key</div>'
                 . '<div style="font-family:\'Courier New\',monospace;font-size:17px;font-weight:bold;color:#1d4ed8;letter-spacing:1.8px;">'
                 . htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8')
                 . '</div></div>';
        // Inject right after the opening <body> if present, else prepend.
        if (stripos($emailHtml, '<body') !== false) {
            $emailHtml = preg_replace('#(<body\b[^>]*>)#i', '$1' . $callout, $emailHtml, 1) ?: ($callout . $emailHtml);
        } else {
            $emailHtml = $callout . $emailHtml;
        }
    }
}

/* ---- 1. Pre-flight deliverability check on the new recipient ---- */
$deliv = email_address_deliverable($to);
if (!$deliv['ok'] && in_array($deliv['reason'], ['no_mx','invalid_syntax'], true)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Undeliverable address — ' . ($deliv['detail'] ?: $deliv['reason']),
        'hint'  => 'Ask the customer to confirm their email address before resending.',
    ]);
    exit;
}

$tok        = bin2hex(random_bytes(16));
$smtp       = smtp_config();
$smtpReady  = ($smtp['enabled'] && $smtp['host'] !== '');
$maxRetries = (int)($smtp['max_retries'] ?? 3);

/* ---- 2. Insert the new outbox row.  Behaviour diverges by mode ---- */
if ($smtpReady) {
    // SMTP enabled — queue, then run the worker once to attempt delivery
    // synchronously so we can report success/failure to the admin.
    // Use a defensive INSERT — older schemas may not have the retry/priority
    // columns, so we ALSO try a minimal form on column-mismatch errors.
    $note = 'Edit & Resend of email #' . $emailId
          . ($newTo  !== '' ? ' (to ' . $newTo . ')' : '')
          . ($newKey !== '' ? ' (license key updated)' : '');
    try {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, attachments_json)
            VALUES (?,?,?,'queued',?,?,?,?,0,?,NOW(),?,?)")
            ->execute([$to, $em['subject'], $emailHtml, $note, $em['order_id'], $tok, $em['template_code'], $maxRetries, 3, $em['attachments_json'] ?? null]);
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, attachments_json)
            VALUES (?,?,?,'queued',?,?,?,?,?)")
            ->execute([$to, $em['subject'], $emailHtml, $note, $em['order_id'], $tok, $em['template_code'], $em['attachments_json'] ?? null]);
    }
    $newId = (int)$pdo->lastInsertId();

    // Attempt immediate delivery via the SMTP worker.
    $delivered = false;
    $lastError = '';
    try {
        smtp_process_queue(5);
        $check = $pdo->prepare("SELECT status FROM email_outbox WHERE id=?");
        $check->execute([$newId]);
        $delivered = (($check->fetchColumn() ?: '') === 'sent');
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }
} else {
    // SMTP NOT configured (dev / preview mode).  Mirror send_email() —
    // record the row as 'sent' with a clear dev-mode note so the admin
    // can verify the body in email-view.php.  Without this branch the
    // row would sit at 'queued' forever because smtp_process_queue()
    // returns 0 immediately when SMTP isn't configured.
    $pdo->prepare("INSERT INTO email_outbox
        (recipient, subject, html, status, note, order_id, tracking_token, template_code, delivered_at, attachments_json)
        VALUES (?,?,?,'sent',?,?,?,?,NOW(),?)")
        ->execute([
            $to,
            $em['subject'],
            $emailHtml,
            '⚠ Captured in dev mode — SMTP disabled, NOT delivered to customer. Edit & Resend of email #' . $emailId . ($newKey !== '' ? ' (license key updated)' : ''),
            $em['order_id'],
            $tok,
            $em['template_code'],
            $em['attachments_json'] ?? null,
        ]);
    $newId = (int)$pdo->lastInsertId();
    $delivered = true; // From the admin UI's perspective, the row is "sent" (captured).
    $lastError = '';
}

/* ---- 3. Flip the ORIGINAL failed/bounced row to 'sent' on success ---- */
if ($delivered && ($em['status'] === 'failed' || $em['status'] === 'bounced')) {
    $pdo->prepare("UPDATE email_outbox
                   SET status='sent', last_error=NULL, delivered_at=NOW(),
                       note=CONCAT(IFNULL(note,''), ' · Resolved by Edit&Resend #', ?)
                   WHERE id=?")
        ->execute([$newId, $emailId]);
}

/* ---- 4. Fresh failed/bounced counter for the topbar bell ---- */
/* Scoped to POST-PURCHASE emails only — matches the Email Activity Center
   so the bell never alerts on a failed review-request or marketing email. */
$failedCount = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox
    WHERE status IN ('failed','bounced')
      AND template_code IN ('order_delivery','order_confirmation','order_pending','refund_confirm')")->fetchColumn();

echo json_encode([
    'ok'           => true,
    'delivered'    => $delivered,
    'new_email_id' => $newId,
    'recipient'    => $to,
    'dev_mode'     => !$smtpReady,
    'error'        => $delivered ? null : ($lastError ?: 'Queued for retry'),
    'failed_count' => $failedCount,
]);
