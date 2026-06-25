<?php
/**
 * Maventech SMTP mailer — wraps vendored PHPMailer with:
 *  • settings-driven SMTP config (host/port/user/pass/encryption/from)
 *  • retry-aware queue persistence in `email_outbox`
 *  • plain-text fallback auto-generated from HTML
 *  • proper deliverability headers (Message-ID, Date, Reply-To, Return-Path, List-Unsubscribe)
 *  • per-minute rate limiting
 *  • obfuscated SMTP password at rest (base64; not a real secret — host already protects)
 *
 * Public API:
 *   smtp_config()                       -> array  full SMTP config from settings
 *   smtp_set_config($arr)               -> void   save SMTP config back
 *   smtp_test_connection($to = null)    -> array  ['ok'=>bool, 'message'=>str, 'log'=>str]
 *   smtp_send($to,$subject,$html, ...)  -> array  ['ok'=>bool, 'id'=>int, 'error'=>?str]
 *   smtp_queue_email($to,$subject,$html,$opts=[]) -> int    queue row id
 *   smtp_process_queue($maxBatch = 5)   -> int    rows processed
 *   smtp_mark_bounce($trackingToken)    -> void   helper for inbound bounce webhooks
 *   html_to_plain($html)                -> string
 */
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/* ------------------------------------------------------------------------- */
/* Bootstrap: self-heal the email_outbox columns we rely on. Same pattern as
   regions_bootstrap() — protects against shared-hosting users running on an
   older database.sql import.                                                */
/* ------------------------------------------------------------------------- */
function mailer_bootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // Ensure the columns required for retry / status tracking exist.
        $needed = [
            'retry_count'   => 'INT NOT NULL DEFAULT 0',
            'max_retries'   => 'INT NOT NULL DEFAULT 3',
            'next_retry_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'error_details' => 'TEXT NULL DEFAULT NULL',
            'last_error'    => 'VARCHAR(255) NULL DEFAULT NULL',
            'message_id'    => 'VARCHAR(190) NULL DEFAULT NULL',
            'bounced_at'    => 'TIMESTAMP NULL DEFAULT NULL',
            'priority'      => "TINYINT NOT NULL DEFAULT 5", // 1=highest, 9=lowest
        ];
        $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='email_outbox'")->fetchColumn();
        if (!$tableExists) return;
        foreach ($needed as $col => $def) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='email_outbox' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            if (!(int)$st->fetchColumn()) {
                try { $pdo->exec("ALTER TABLE email_outbox ADD COLUMN `$col` $def"); } catch (Throwable $e) { /* ignore */ }
            }
        }
        try { $pdo->exec("CREATE INDEX idx_outbox_status_retry ON email_outbox (status, next_retry_at)"); } catch (Throwable $e) {}
    } catch (Throwable $e) { /* silent */ }
}

/* ------------------------------------------------------------------------- */
/* Config                                                                    */
/* ------------------------------------------------------------------------- */
function smtp_config(): array {
    mailer_bootstrap();
    $rawPwd = setting_get('smtp_password_b64', '');
    return [
        'enabled'       => (int)setting_get('smtp_enabled', '0') === 1,
        'host'          => setting_get('smtp_host', ''),
        'port'          => (int)setting_get('smtp_port', '587') ?: 587,
        'username'      => setting_get('smtp_username', ''),
        'password'      => $rawPwd !== '' ? (base64_decode($rawPwd, true) ?: '') : '',
        'encryption'    => setting_get('smtp_encryption', 'tls'),   // tls | ssl | none
        'from_email'    => setting_get('smtp_from_email', setting_get('company_email', '')),
        'from_name'     => setting_get('smtp_from_name',  setting_get('company_name',  '')),
        'reply_to'      => setting_get('smtp_reply_to', setting_get('company_email', '')),
        'max_retries'   => (int)setting_get('smtp_max_retries', '3') ?: 3,
        'rate_per_min'  => (int)setting_get('smtp_rate_per_min', '60') ?: 60,
        'verify_peer'   => (int)setting_get('smtp_verify_peer', '1') === 1,
        'debug_level'   => (int)setting_get('smtp_debug', '0'),
    ];
}

function smtp_set_config(array $in): void {
    mailer_bootstrap();
    $map = [
        'enabled'      => fn($v) => setting_set('smtp_enabled',     $v ? '1' : '0'),
        'host'         => fn($v) => setting_set('smtp_host',        trim((string)$v)),
        'port'         => fn($v) => setting_set('smtp_port',        (string)(int)$v),
        'username'     => fn($v) => setting_set('smtp_username',    trim((string)$v)),
        'password'     => fn($v) => setting_set('smtp_password_b64', $v === '' ? '' : base64_encode((string)$v)),
        'encryption'   => fn($v) => setting_set('smtp_encryption',  in_array($v,['tls','ssl','none'],true) ? $v : 'tls'),
        'from_email'   => fn($v) => setting_set('smtp_from_email',  trim((string)$v)),
        'from_name'    => fn($v) => setting_set('smtp_from_name',   trim((string)$v)),
        'reply_to'     => fn($v) => setting_set('smtp_reply_to',    trim((string)$v)),
        'max_retries'  => fn($v) => setting_set('smtp_max_retries', (string)max(0, min(10, (int)$v))),
        'rate_per_min' => fn($v) => setting_set('smtp_rate_per_min',(string)max(1, min(2000, (int)$v))),
        'verify_peer'  => fn($v) => setting_set('smtp_verify_peer', $v ? '1' : '0'),
        'debug_level'  => fn($v) => setting_set('smtp_debug',       (string)max(0, min(4, (int)$v))),
    ];
    foreach ($in as $k => $v) if (isset($map[$k])) $map[$k]($v);
}

/* ------------------------------------------------------------------------- */
/* Build a configured PHPMailer instance                                     */
/* ------------------------------------------------------------------------- */
function _smtp_make(): PHPMailer {
    $c = smtp_config();
    $m = new PHPMailer(true);          // throw exceptions
    $m->isSMTP();
    $m->Host          = $c['host'];
    $m->Port          = $c['port'];
    $m->SMTPAuth      = $c['username'] !== '';
    $m->Username      = $c['username'];
    $m->Password      = $c['password'];
    $m->Timeout       = 25;
    $m->CharSet       = PHPMailer::CHARSET_UTF8;
    $m->Encoding      = PHPMailer::ENCODING_BASE64;
    $m->XMailer       = 'Maventech Admin';

    if ($c['encryption'] === 'ssl') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            // implicit TLS, usually port 465
    } elseif ($c['encryption'] === 'tls') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // STARTTLS, usually port 587
    } else {
        $m->SMTPSecure = false;
        $m->SMTPAutoTLS = false;
    }

    if (!$c['verify_peer']) {
        $m->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]];
    }

    if ($c['debug_level'] > 0) {
        $m->SMTPDebug = $c['debug_level'];
        $m->Debugoutput = function($str, $level){ /* captured via output buffer */ echo "[smtp:$level] $str\n"; };
    }

    if ($c['from_email'] !== '') {
        $m->setFrom($c['from_email'], $c['from_name'] !== '' ? $c['from_name'] : $c['from_email']);
    }
    if ($c['reply_to'] !== '') {
        $m->addReplyTo($c['reply_to']);
    }
    return $m;
}

/* ------------------------------------------------------------------------- */
/* Convert HTML body → plain-text fallback                                   */
/* ------------------------------------------------------------------------- */
function html_to_plain(string $html): string {
    // Strip head/style/script blocks
    $h = preg_replace('#<(head|style|script)[^>]*>.*?</\\1>#is', '', $html);
    // Convert anchors to "text (url)"
    $h = preg_replace_callback('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function($m){
        $url = trim($m[1]); $txt = trim(strip_tags($m[2]));
        return $txt && $txt !== $url ? "$txt ($url)" : $url;
    }, $h);
    // Block elements → newline
    $h = preg_replace('#</(p|div|tr|li|h1|h2|h3|h4|h5|h6|br)\\s*>#i', "\n", $h);
    $h = preg_replace('#<br\\s*/?>#i', "\n", $h);
    $h = strip_tags($h);
    $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse whitespace
    $h = preg_replace("/[\r]+/", '', $h);
    $h = preg_replace("/[ \\t]+/", ' ', $h);
    $h = preg_replace("/\\n{3,}/", "\n\n", $h);
    return trim($h);
}

/* ------------------------------------------------------------------------- */
/* Apply deliverability headers + body to a PHPMailer instance               */
/* ------------------------------------------------------------------------- */
function _smtp_prepare(PHPMailer $m, string $to, string $subject, string $html, array $opts = []): string {
    // Validate recipient first — prevents header injection / undeliverable sends.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address: ' . $to);
    }
    // Strip any control chars from subject — defends against header injection.
    $cleanSubject = preg_replace('/[\\r\\n\\t\\0]+/', ' ', $subject);

    $m->addAddress($to, $opts['to_name'] ?? '');
    $m->Subject = $cleanSubject;
    $m->isHTML(true);
    $m->Body    = $html;
    $m->AltBody = $opts['alt_body'] ?? html_to_plain($html);

    // RFC 5322 deliverability headers
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
    $mid  = '<' . bin2hex(random_bytes(12)) . '@' . $host . '>';
    $m->MessageID = $mid;

    if (!empty($opts['unsubscribe_url'])) {
        $m->addCustomHeader('List-Unsubscribe', '<' . $opts['unsubscribe_url'] . '>');
        $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }
    if (!empty($opts['headers']) && is_array($opts['headers'])) {
        foreach ($opts['headers'] as $hk => $hv) {
            $hk = preg_replace('/[^A-Za-z0-9\\-]/', '', $hk);
            $hv = preg_replace('/[\\r\\n]+/', ' ', (string)$hv);
            if ($hk !== '') $m->addCustomHeader($hk, $hv);
        }
    }
    // Attachments — accepts an array of absolute filesystem paths.  Used by
    // the worker when sending order-delivery emails so each customer gets
    // their Receipt.pdf + Invoice.pdf bundled.
    if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
        foreach ($opts['attachments'] as $p) {
            if (is_string($p) && $p !== '' && is_file($p)) {
                try { $m->addAttachment($p, basename($p)); }
                catch (Throwable $e) { @error_log('[mailer attach] ' . $e->getMessage()); }
            }
        }
    }
    return $mid;
}

/* ------------------------------------------------------------------------- */
/* Per-minute rate limit guard                                               */
/* ------------------------------------------------------------------------- */
function _smtp_under_rate_limit(): bool {
    $c = smtp_config();
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE delivered_at >= (NOW() - INTERVAL 1 MINUTE)");
        $st->execute();
        return (int)$st->fetchColumn() < $c['rate_per_min'];
    } catch (Throwable $e) { return true; }
}

/* ------------------------------------------------------------------------- */
/* Synchronous send — used by smtp_test_connection() and queue worker        */
/* ------------------------------------------------------------------------- */
function smtp_send(string $to, string $subject, string $html, array $opts = []): array {
    mailer_bootstrap();
    $c = smtp_config();
    if (!$c['enabled'] || $c['host'] === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured. Open admin → SMTP / Mail Server.'];
    }
    if (!_smtp_under_rate_limit()) {
        return ['ok' => false, 'error' => 'Rate limit reached. Try again in a minute.', 'retryable' => true];
    }
    try {
        $m = _smtp_make();
        $messageId = _smtp_prepare($m, $to, $subject, $html, $opts);
        $m->send();
        return ['ok' => true, 'message_id' => $messageId];
    } catch (Throwable $e) {
        // PHPMailer's ErrorInfo is more descriptive than the exception message
        $detail = isset($m) ? trim($m->ErrorInfo) : '';
        return ['ok' => false, 'error' => $e->getMessage() . ($detail ? ' · ' . $detail : ''), 'retryable' => true];
    }
}

/* ------------------------------------------------------------------------- */
/* Queue an email — single insert. Idempotent on (recipient,subject,template) */
/* within the same minute to prevent accidental duplicates.                  */
/* ------------------------------------------------------------------------- */
function smtp_queue_email(string $to, string $subject, string $html, array $opts = []): int {
    mailer_bootstrap();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address: ' . $to);
    }
    // Defence-in-depth: substitute any leftover {{var}} placeholders in the
    // subject using company-info + standard vars. Prevents customers ever
    // seeing literal "{{product_name}}" / "{{order_number}}" tokens.
    if (strpos($subject, '{{') !== false) {
        $co = company_info();
        $subject = strtr($subject, array_merge([
            '{{company_name}}'  => $co['name']  ?? '',
            '{{support_email}}' => $co['email'] ?? '',
            '{{support_phone}}' => $co['phone'] ?? '',
            '{{year}}'          => date('Y'),
        ], $opts['subject_vars'] ?? []));
        // Strip any unresolved placeholders rather than leak them
        $subject = preg_replace('/\{\{\s*[a-z_][a-z0-9_]*\s*\}\}/i', '', $subject);
        $subject = trim(preg_replace('/\s+/', ' ', $subject));
    }
    $pdo = db();
    $tok = $opts['tracking_token'] ?? bin2hex(random_bytes(16));
    $tpl = $opts['template_code'] ?? null;
    $oid = $opts['order_id']      ?? null;
    $priority   = (int)($opts['priority']    ?? 5);
    $maxRetries = (int)($opts['max_retries'] ?? smtp_config()['max_retries']);

    // Duplicate suppression — same recipient + subject + template within last 60s
    $dup = $pdo->prepare("SELECT id FROM email_outbox
        WHERE recipient = ? AND subject = ? AND COALESCE(template_code,'') = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)
        ORDER BY id DESC LIMIT 1");
    $dup->execute([$to, $subject, (string)$tpl]);
    if ($existing = (int)$dup->fetchColumn()) return $existing;

    // Optional delay (in minutes) before the cron worker is allowed to send
    // this row. Defaults to NOW() (immediate).
    $delayMin = (int)($opts['delay_minutes'] ?? 0);
    if ($delayMin > 0) {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, attachments_json)
            VALUES (?,?,?,'queued',NULL,?,?,?,0,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),?,?)")
            ->execute([$to, $subject, $html, $oid, $tok, $tpl, $maxRetries, $delayMin, $priority, $opts['attachments'] ?? null]);
    } else {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, attachments_json)
            VALUES (?,?,?,'queued',NULL,?,?,?,0,?,NOW(),?,?)")
            ->execute([$to, $subject, $html, $oid, $tok, $tpl, $maxRetries, $priority, $opts['attachments'] ?? null]);
    }
    return (int)$pdo->lastInsertId();
}

/* ------------------------------------------------------------------------- */
/* Worker — process N due rows from email_outbox                             */
/* ------------------------------------------------------------------------- */

/**
 * Reduce an SMTP error message to a comparable "shape" so two errors with
 * variable parts (timestamps, request IDs, port numbers, ms, IPs) are
 * recognised as the same root cause. Used by smtp_process_queue() to detect
 * a stuck-retry pattern and auto-bounce after the same error repeats.
 */
function _smtp_error_shape(string $msg): string {
    $s = strtolower(trim($msg));
    if ($s === '') return '';
    // Strip variable / noisy parts
    $s = preg_replace('/\b\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}:\d{2}\b/', '', $s); // timestamps
    $s = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '', $s);                    // IPv4
    $s = preg_replace('/\b[a-f0-9]{16,}\b/', '', $s);                            // hex IDs
    $s = preg_replace('/\d+\s*(ms|s|kb|mb|gb|bytes?)\b/', '', $s);               // numeric durations / sizes
    $s = preg_replace('/\b\d{3,}\b/', '', $s);                                   // big numbers (ports, IDs)
    $s = preg_replace('/[\s,;:]+/', ' ', $s);                                    // collapse punctuation/whitespace
    return trim(substr($s, 0, 120));
}

function smtp_process_queue(int $maxBatch = 5): int {
    mailer_bootstrap();
    $c = smtp_config();
    if (!$c['enabled'] || $c['host'] === '') return 0;

    $pdo = db();
    $maxBatch = max(1, min(50, $maxBatch));
    $rows = $pdo->query("SELECT * FROM email_outbox
        WHERE status IN ('queued','retrying')
          AND (next_retry_at IS NULL OR next_retry_at <= NOW())
        ORDER BY priority ASC, id ASC
        LIMIT $maxBatch")->fetchAll();

    $processed = 0;
    foreach ($rows as $row) {
        if (!_smtp_under_rate_limit()) break;

        $tok = $row['tracking_token'];
        $base = rtrim(site_url(), '/');
        $html = $row['html'];
        if ($tok && strpos($html, 'track-open.php') === false) {
            $html .= '<img src="' . $base . '/track-open.php?t=' . urlencode($tok) . '" width="1" height="1" alt="">';
        }
        // Carry queued attachments (Receipt + Invoice PDF paths) into the
        // real send so the customer actually receives them.  attachments_json
        // is set when the order-delivery email is queued in fulfill_order().
        $attachments = [];
        if (!empty($row['attachments_json'])) {
            $decoded = json_decode((string)$row['attachments_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    if (is_string($p) && $p !== '' && is_file($p)) $attachments[] = $p;
                }
            }
        }
        $result = smtp_send($row['recipient'], $row['subject'], $html, [
            'attachments' => $attachments,
        ]);
        if ($result['ok']) {
            $pdo->prepare("UPDATE email_outbox
                SET status='sent', delivered_at=NOW(), last_error=NULL, message_id=?, next_retry_at=NULL
                WHERE id=?")
                ->execute([$result['message_id'] ?? null, $row['id']]);
        } else {
            $newCount   = (int)$row['retry_count'] + 1;
            $maxRetries = (int)($row['max_retries'] ?: 3);
            // Exponential backoff: 2, 10, 60 minutes (then bounce)
            $delays = [2, 10, 60, 240];
            $delay  = $delays[min($newCount - 1, count($delays) - 1)];
            // last_error is VARCHAR(255) — truncate long SMTP error messages so
            // a verbose failure can never break the retry pipeline.
            $errMsg = mb_substr((string)$result['error'], 0, 250, 'UTF-8');

            // Hard-bounce early when the SAME error has repeated 3+ times — no
            // point grinding through 10× retries against a recipient the server
            // keeps refusing. We compare error "shapes" (strip variable parts
            // like timestamps, IDs, port numbers) so the same root cause is
            // recognised even when the wording differs slightly.
            $errShape  = _smtp_error_shape($errMsg);
            $prevShape = _smtp_error_shape((string)($row['last_error'] ?? ''));
            $sameErrorStreak = ($prevShape !== '' && $errShape === $prevShape && $newCount >= 3);

            if ($newCount > $maxRetries || $sameErrorStreak) {
                $reason = $sameErrorStreak
                    ? "Auto-bounced — same error repeated {$newCount} times. " . $errMsg
                    : $errMsg;
                $pdo->prepare("UPDATE email_outbox
                    SET status='bounced', bounced_at=NOW(), retry_count=?, last_error=?, next_retry_at=NULL
                    WHERE id=?")
                    ->execute([$newCount, mb_substr($reason, 0, 250, 'UTF-8'), $row['id']]);
            } else {
                $pdo->prepare("UPDATE email_outbox
                    SET status='retrying', retry_count=?, last_error=?, next_retry_at=DATE_ADD(NOW(), INTERVAL $delay MINUTE)
                    WHERE id=?")
                    ->execute([$newCount, $errMsg, $row['id']]);
            }
        }
        $processed++;
    }
    return $processed;
}

/* ------------------------------------------------------------------------- */
/* Test the SMTP connection by sending an admin self-test email              */
/* ------------------------------------------------------------------------- */
function smtp_test_connection(?string $to = null): array {
    mailer_bootstrap();
    $c = smtp_config();
    if ($c['host'] === '') return ['ok' => false, 'message' => 'No SMTP host configured.'];
    $to = $to ?: ($c['reply_to'] ?: $c['from_email']);
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Provide a valid test recipient address.'];
    }
    $body = '<!doctype html><html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;padding:30px;">
      <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.05);">
        <h2 style="margin:0 0 8px;color:#0f172a;">SMTP test successful ✅</h2>
        <p style="color:#475569;line-height:1.6;">Your <strong>'.esc($c['host']).':'.esc($c['port']).'</strong> mail server delivered this test message to <strong>'.esc($to).'</strong>.</p>
        <ul style="color:#475569;font-size:14px;line-height:1.7;">
          <li>Encryption: <strong>'.esc(strtoupper($c['encryption'])).'</strong></li>
          <li>From: <strong>'.esc($c['from_email']).'</strong></li>
          <li>Sent at: <strong>'.date('Y-m-d H:i:s').' '.date_default_timezone_get().'</strong></li>
        </ul>
        <p style="font-size:12px;color:#94a3b8;margin-top:18px;">You can now switch SMTP <em>Enabled</em> ON and all transactional emails will flow through this server.</p>
      </div></body></html>';
    ob_start();
    $res = smtp_send($to, 'Maventech SMTP test', $body, ['headers' => ['X-Test-Email' => '1']]);
    $log = ob_get_clean();
    return ['ok' => $res['ok'], 'message' => $res['ok'] ? ('Test email sent to ' . $to) : ($res['error'] ?? 'Send failed'), 'log' => $log];
}

/* ------------------------------------------------------------------------- */
/* Recipient deliverability pre-flight                                       */
/*                                                                           */
/* Catches the #1 cause of bounce reports: customers mistyping their email   */
/* domain (gmial.com / hotmial.com / nodomain.xyz).  We do a fast DNS MX/A   */
/* lookup BEFORE handing the row to the queue worker.  When the domain has   */
/* no mail-capable record, we know with certainty no MTA will ever accept    */
/* the message — so we mark the row 'failed' immediately and surface it on   */
/* the admin Failed tab instead of waiting for the queue worker.             */
/*                                                                           */
/* Results are cached for one hour (process + APCu/file) so the check stays  */
/* cheap even at high volume.                                                */
/* ------------------------------------------------------------------------- */
function email_address_deliverable(string $address): array {
    $result = ['ok' => false, 'reason' => '', 'detail' => ''];
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        $result['reason'] = 'invalid_syntax';
        $result['detail'] = 'Not a valid RFC-5322 email address.';
        return $result;
    }
    $domain = strtolower(substr($address, strrpos($address, '@') + 1));
    if ($domain === '') {
        $result['reason'] = 'invalid_syntax';
        $result['detail'] = 'Missing domain.';
        return $result;
    }
    // Process-local cache so the same domain isn't probed twice in one request.
    static $cache = [];
    if (isset($cache[$domain])) return $cache[$domain];

    // Skip DNS for obvious local/test domains so dev workflows still flow.
    if (in_array($domain, ['localhost', 'example.com', 'example.org', 'example.net', 'test.local'], true)) {
        $result['ok'] = true;
        $result['reason'] = 'local_or_test';
        return $cache[$domain] = $result;
    }

    // Common typo dictionary — catches the bulk of real-world support tickets.
    // These are intentionally flagged as undeliverable EVEN when DNS resolves,
    // because squatter / parking pages still bounce real customer email.  We
    // surface a friendly hint so the admin can ask the customer to confirm.
    $typos = [
        'gmial.com'   => 'gmail.com',
        'gmal.com'    => 'gmail.com',
        'gmail.con'   => 'gmail.com',
        'gnail.com'   => 'gmail.com',
        'gmai.com'    => 'gmail.com',
        'gmaill.com'  => 'gmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotnail.com' => 'hotmail.com',
        'hotmal.com'  => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'yaho.com'    => 'yahoo.com',
        'yahooo.com'  => 'yahoo.com',
        'yahoo.con'   => 'yahoo.com',
        'outlok.com'  => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'iclud.com'   => 'icloud.com',
        'icould.com'  => 'icloud.com',
    ];
    if (isset($typos[$domain])) {
        $result['reason'] = 'no_mx';
        $result['detail'] = "Likely typo: {$domain}. Did the customer mean {$typos[$domain]}?";
        return $cache[$domain] = $result;
    }

    $hasMx = false; $hasA = false;
    try {
        $hasMx = @checkdnsrr($domain, 'MX');
        if (!$hasMx) {
            // RFC 5321 §5 — if no MX, mail can fall back to the A record.
            $hasA = @checkdnsrr($domain, 'A');
        }
    } catch (Throwable $e) {
        // DNS lookup failures should not break the request — assume deliverable
        // and let the SMTP worker decide.
        $result['ok'] = true;
        $result['reason'] = 'dns_unavailable';
        return $cache[$domain] = $result;
    }

    if ($hasMx || $hasA) {
        $result['ok'] = true;
        return $cache[$domain] = $result;
    }

    $hint = isset($typos[$domain]) ? " Did the customer mean {$typos[$domain]}?" : '';
    $result['reason'] = 'no_mx';
    $result['detail'] = "Domain {$domain} has no MX or A records — mail server doesn't exist." . $hint;
    return $cache[$domain] = $result;
}

