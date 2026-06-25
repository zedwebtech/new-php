<?php
// Lead capture from the chat widget form (name/email/phone + callback choice).
// On every new submission, also email the admin with subject
// "Customer Enquiry — {name}" so they can pick up the conversation in
// Lead Management even if they're not actively watching the dashboard.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';

header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $in['session_id'] ?? ''), 0, 64);
$name = trim($in['name'] ?? '');
$email = trim($in['email'] ?? '');
$phone = trim($in['phone'] ?? '');
$callback = !empty($in['callback_requested']);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 7) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please provide your name, a valid email and phone number.']);
    exit;
}

// Catch typo domains / missing MX records BEFORE saving the lead so we
// don't pile up un-reachable contacts in the CRM.  Matches the same
// hardening applied to checkout + notify-stock.
require_once __DIR__ . '/../includes/mailer.php';
if (function_exists('email_address_deliverable')) {
    $deliv = email_address_deliverable($email);
    if (!$deliv['ok'] && in_array($deliv['reason'], ['no_mx','invalid_syntax'], true)) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => $deliv['detail'] ?: 'That email address looks undeliverable — please double-check the spelling.',
            'reason'=> $deliv['reason'],
        ]);
        exit;
    }
}

$pdo = db();
$stmt = $pdo->prepare('INSERT INTO chat_leads (session_id, name, email, phone, callback_requested, message) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$sessionId, $name, $email, $phone, $callback ? 1 : 0, $callback ? 'Callback requested via chat form' : 'Chat form contact']);
$leadId = (int)$pdo->lastInsertId();
$token  = bin2hex(random_bytes(16));
$pdo->prepare('UPDATE chat_leads SET chat_token=?, last_seen=NOW() WHERE id=?')->execute([$token, $leadId]);
$_SESSION['lead_id'] = $leadId;

// -----------------------------------------------------------------------
// Email the admin with subject "Customer Enquiry — {name}" so the team
// can jump into the conversation from their inbox.  Includes a one-click
// "Open chat" deep-link into Lead Management with the chat drawer pre-
// expanded, AND the customer's contact details for context.  Wrapped in
// try/catch so a mail failure never blocks the lead from being saved.
// -----------------------------------------------------------------------
try {
    $co = company_info();
    $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : ($co['email'] ?? '');
    if ($adminEmail) {
        $base = rtrim(site_url(), '/');
        $logo = $base . '/assets/images/brand/email-logo.gif';
        $deepLink = $base . '/admin.php?tab=leads&autochat=' . $leadId;
        $when     = date('M j, Y · g:i A');
        $callTxt  = $callback ? "<span style=\"background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;\">CALLBACK REQUESTED</span>" : '';
        $body = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:32px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.08);">
      <tr><td style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);padding:24px 30px;color:#fff;">
        <table cellpadding="0" cellspacing="0" border="0"><tr>
          <td valign="middle" width="64"><img src="{$logo}" alt="{$co['name']}" width="56" height="56" style="display:block;border-radius:14px;background:transparent;"></td>
          <td valign="middle" style="padding-left:14px;">
            <div style="font-size:11px;letter-spacing:2.5px;text-transform:uppercase;opacity:.9;font-weight:700;">New Lead · Live Chat</div>
            <div style="font-size:22px;font-weight:800;margin-top:4px;">Customer Enquiry</div>
          </td>
        </tr></table>
      </td></tr>
      <tr><td style="padding:28px 30px 18px;">
        <p style="margin:0 0 18px;font-size:15px;color:#334155;line-height:1.55;"><strong style="color:#0f172a;">{$name}</strong> just opened a chat on your site. They're waiting for a reply right now. {$callTxt}</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:22px;">
          <tr><td style="padding:14px 18px;font-size:13px;color:#475569;line-height:1.7;">
            <div><span style="display:inline-block;width:78px;color:#94a3b8;font-size:11px;letter-spacing:.5px;text-transform:uppercase;font-weight:700;">Name</span> <strong style="color:#0f172a;">{$name}</strong></div>
            <div><span style="display:inline-block;width:78px;color:#94a3b8;font-size:11px;letter-spacing:.5px;text-transform:uppercase;font-weight:700;">Email</span> <a href="mailto:{$email}" style="color:#1d4ed8;text-decoration:none;">{$email}</a></div>
            <div><span style="display:inline-block;width:78px;color:#94a3b8;font-size:11px;letter-spacing:.5px;text-transform:uppercase;font-weight:700;">Phone</span> <a href="tel:{$phone}" style="color:#1d4ed8;text-decoration:none;">{$phone}</a></div>
            <div><span style="display:inline-block;width:78px;color:#94a3b8;font-size:11px;letter-spacing:.5px;text-transform:uppercase;font-weight:700;">When</span> {$when}</div>
          </td></tr>
        </table>
        <div style="text-align:center;margin:24px 0 8px;">
          <a href="{$deepLink}" style="display:inline-block;padding:13px 32px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:.3px;box-shadow:0 8px 22px rgba(29,78,216,.35);">Open chat with this customer &rarr;</a>
        </div>
        <p style="text-align:center;font-size:11.5px;color:#94a3b8;margin:14px 0 0;">Tip: a default greeting was already sent — pick up where they left off.</p>
      </td></tr>
      <tr><td style="background:#0f172a;padding:18px 30px;color:#94a3b8;font-size:11.5px;line-height:1.55;text-align:center;">
        <div style="color:#e2e8f0;font-weight:700;font-size:13px;margin-bottom:6px;">{$co['name']}</div>
        Admin notification &middot; Lead ID #{$leadId}
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
        send_email($adminEmail, 'Customer Enquiry — ' . $name, $body, null, 'admin_lead_alert', 0);
    }
} catch (Throwable $e) {
    @error_log('[lead.php admin alert] ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'lead_id' => $leadId, 'chat_token' => $token]);
