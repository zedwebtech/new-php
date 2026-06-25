<?php
/**
 * REST API: email endpoints (internal admin use).
 * Auth: Bearer token = setting `api_token` (auto-generated on first admin login
 * to the SMTP tab) OR an active admin session cookie.
 *
 * Endpoints (router by ?action=…):
 *   POST  ?action=send        Send an email immediately (queued + processed inline)
 *   POST  ?action=queue       Queue an email without processing
 *   GET   ?action=status&id=N Look up a single email's delivery status
 *   GET   ?action=stats       Aggregate counters (sent/queued/failed/bounced/total)
 *   POST  ?action=resend&id=N Re-queue a previously-failed email
 *   POST  ?action=process     Trigger the queue worker (?batch=N)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/email.php';

header('Content-Type: application/json');

// --- Auth -------------------------------------------------------------------
$user = current_user();
$isAdmin = $user && ($user['role'] ?? '') === 'admin';
if (!$isAdmin) {
    $token = setting_get('api_token', '');
    $given = '';
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\\s+(.+)/i', $hdr, $m)) $given = trim($m[1]);
    if ($given === '' || $token === '' || !hash_equals($token, $given)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized — provide a valid Bearer token or sign in as admin.']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$pdo = db();

try {
    switch ($action) {

        case 'send':
        case 'queue': {
            if ($method !== 'POST') throw new RuntimeException('Use POST');
            $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $to       = trim($in['to'] ?? '');
            $subject  = trim($in['subject'] ?? '');
            $html     = (string)($in['html'] ?? '');
            $template = isset($in['template_code']) ? trim($in['template_code']) : null;
            $vars     = is_array($in['vars'] ?? null) ? $in['vars'] : [];

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient');
            if ($subject === '') throw new RuntimeException('subject is required');

            // Template-based send — render via the existing pipeline so company info + variables work.
            if ($template) {
                $rendered = render_template($template, $vars);
                if ($rendered === null) throw new RuntimeException("Template '$template' not found or inactive");
                $html = $rendered;
                $subj = render_template_subject($template, $vars);
                if ($subj) $subject = $subj;
            }
            if ($html === '') throw new RuntimeException('Provide html or template_code');

            $id = smtp_queue_email($to, $subject, $html, [
                'template_code' => $template,
                'priority'      => (int)($in['priority'] ?? 5),
            ]);
            if ($action === 'send') smtp_process_queue(1);
            $row = $pdo->prepare("SELECT status, message_id, tracking_token FROM email_outbox WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok' => true, 'id' => $id] + ($row->fetch() ?: []));
            break;
        }

        case 'status': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id is required');
            $r = $pdo->prepare("SELECT id, recipient, subject, status, retry_count, max_retries, last_error, message_id, tracking_token, template_code, created_at, delivered_at, bounced_at, opened_at FROM email_outbox WHERE id=?");
            $r->execute([$id]);
            $row = $r->fetch();
            if (!$row) throw new RuntimeException('Not found');
            echo json_encode(['ok' => true] + $row);
            break;
        }

        case 'stats': {
            $sql = "SELECT
                COUNT(*) total,
                SUM(status='sent')      sent,
                SUM(status='queued')    queued,
                SUM(status='retrying')  retrying,
                SUM(status='failed')    failed,
                SUM(status='bounced')   bounced,
                SUM(opened_at IS NOT NULL) opened
              FROM email_outbox";
            $row = $pdo->query($sql)->fetch();
            echo json_encode(['ok' => true, 'stats' => $row]);
            break;
        }

        case 'resend': {
            if ($method !== 'POST') throw new RuntimeException('Use POST');
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT * FROM email_outbox WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch();
            if (!$r) throw new RuntimeException('Email not found');
            $pdo->prepare("UPDATE email_outbox SET status='queued', retry_count=0, next_retry_at=NOW(), last_error=NULL, bounced_at=NULL WHERE id=?")
                ->execute([$id]);
            smtp_process_queue(1);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }

        case 'process': {
            if ($method !== 'POST') throw new RuntimeException('Use POST');
            $n = smtp_process_queue((int)($_GET['batch'] ?? 25));
            echo json_encode(['ok' => true, 'processed' => $n]);
            break;
        }

        default:
            throw new RuntimeException('Unknown action. Valid: send, queue, status, stats, resend, process');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
