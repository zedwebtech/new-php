<?php
// Customer-side live chat endpoint.
// Used by the public chat widget to (a) post messages to the admin, (b) poll
// for admin replies, and (c) maintain a "last_seen" presence timestamp so
// the admin sees online/offline status.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? 'poll';
$pdo = db();

// Resolve the current customer's lead row.  The per-browser localStorage
// chat_token is AUTHORITATIVE (so each visitor/IP has a strictly isolated
// chat) — we only fall back to the PHP session when no token is supplied.
// This prevents any session/cache-sharing edge case from leaking one
// customer's conversation to another visitor.
$token  = trim($in['token'] ?? '');
$leadId = 0;
if ($token !== '') {
    $st = $pdo->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]); $leadId = (int)$st->fetchColumn();
    if ($leadId) $_SESSION['lead_id'] = $leadId;
}
if (!$leadId) $leadId = (int)($_SESSION['lead_id'] ?? 0);
if (!$leadId) { echo json_encode(['ok'=>false,'error'=>'No lead']); exit; }

// Heartbeat: update last_seen on every call
$pdo->prepare('UPDATE chat_leads SET last_seen=NOW() WHERE id=?')->execute([$leadId]);

// Typing beacon — customer is composing.  JS pings ~every 2s while
// the textarea has focus + non-empty content; the 5-sec freshness
// check in the response makes the admin's "Customer is typing…"
// indicator disappear quickly once the customer stops.
if ($action === 'typing') {
    $on = !empty($in['typing']);
    $pdo->prepare('UPDATE chat_leads SET typing_customer_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id=?')
        ->execute([$leadId]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'send') {
    $msg = trim($in['message'] ?? '');
    if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }

    // Detect whether this is the customer's FIRST message in this lead's
    // chat thread — kept for the admin notification email throttle below.
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE lead_id=? AND sender='customer'");
    $cnt->execute([$leadId]);
    $isFirstCustomerMsg = ((int)$cnt->fetchColumn() === 0);

    $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
        ->execute([$leadId, 'customer', mb_substr($msg, 0, 2000, 'UTF-8')]);
    // Sending a message implies done typing — clear the beacon immediately.
    $pdo->prepare('UPDATE chat_leads SET typing_customer_at = NULL WHERE id=?')->execute([$leadId]);

    // Surface this in the admin PWA bell so the team knows someone is
    // chatting on the storefront even when the dashboard tab isn't open.
    try {
        $leadRow = $pdo->prepare("SELECT name, email FROM chat_leads WHERE id=?");
        $leadRow->execute([$leadId]);
        $lr = $leadRow->fetch();
        $who = trim((string)($lr['name'] ?? '')) ?: (string)($lr['email'] ?? 'Customer');
        admin_notify(
            'lead',
            'New chat message — ' . $who,
            mb_substr($msg, 0, 140, 'UTF-8'),
            '/admin.php?tab=leads&id=' . (int)$leadId
        );
    } catch (Throwable $e) { /* best-effort */ }

    /*
     * NO AUTO-ADMIN REPLY (iteration 20 product requirement).
     *
     * Previously this endpoint inserted a one-time "Thanks for contacting us!
     * Please tell us how we can assist you further." admin bubble the moment
     * the customer sent their FIRST message.  Per user direction the customer
     * must ONLY ever see messages typed by a real admin agent — no canned
     * auto-replies of any kind.  The customer-side JS now shows a small
     * "Got it — your message was sent to our support team" status note on
     * the customer's screen ONCE per session, which is purely client-side
     * and never lands in chat_messages.
     */

    // Admin notification email — fire a "New chat message from {name}"
    // email at most once per lead per 5 minutes so admins don't get
    // hammered during a fast back-and-forth conversation but never miss
    // a message that arrives while they're away from the dashboard.
    try {
        $lead = $pdo->prepare("SELECT name, email, phone, admin_notified_at FROM chat_leads WHERE id=?");
        $lead->execute([$leadId]);
        $lr = $lead->fetch(PDO::FETCH_ASSOC);
        $throttleOk = !$lr['admin_notified_at']
                   || (time() - strtotime($lr['admin_notified_at'])) > 300;
        if ($lr && $throttleOk) {
            require_once __DIR__ . '/../includes/email.php';
            $co = company_info();
            $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : ($co['email'] ?? '');
            if ($adminEmail) {
                $base   = rtrim(site_url(), '/');
                $logo   = $base . '/assets/images/brand/email-logo.gif';
                $link   = $base . '/admin.php?tab=leads&autochat=' . $leadId;
                $custName  = htmlspecialchars($lr['name']  ?? 'Customer', ENT_QUOTES, 'UTF-8');
                $custEmail = htmlspecialchars($lr['email'] ?? '',          ENT_QUOTES, 'UTF-8');
                $custPhone = htmlspecialchars($lr['phone'] ?? '',          ENT_QUOTES, 'UTF-8');
                $preview   = mb_substr($msg, 0, 280, 'UTF-8');
                $preview   = nl2br(htmlspecialchars($preview, ENT_QUOTES, 'UTF-8'));
                $body = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:32px 0;"><tr><td align="center">
  <table width="580" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.08);">
    <tr><td style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);padding:22px 30px;color:#fff;">
      <table cellpadding="0" cellspacing="0" border="0"><tr>
        <td valign="middle" width="64"><img src="{$logo}" alt="{$co['name']}" width="56" height="56" style="display:block;border-radius:14px;"></td>
        <td valign="middle" style="padding-left:14px;">
          <div style="font-size:11px;letter-spacing:2.5px;text-transform:uppercase;opacity:.9;font-weight:700;">New chat message</div>
          <div style="font-size:20px;font-weight:800;margin-top:3px;">{$custName}</div>
        </td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:24px 30px 16px;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:8px;margin-bottom:18px;">
        <tr><td style="padding:14px 18px;font-size:14px;color:#1e3a8a;line-height:1.55;font-style:italic;">"{$preview}"</td></tr>
      </table>
      <div style="font-size:12px;color:#64748b;margin-bottom:18px;">
        Email: <a href="mailto:{$custEmail}" style="color:#1d4ed8;text-decoration:none;">{$custEmail}</a>
        &middot; Phone: <a href="tel:{$custPhone}" style="color:#1d4ed8;text-decoration:none;">{$custPhone}</a>
      </div>
      <div style="text-align:center;margin:14px 0 4px;">
        <a href="{$link}" style="display:inline-block;padding:12px 30px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13.5px;box-shadow:0 8px 20px rgba(29,78,216,.32);">Reply in admin portal &rarr;</a>
      </div>
    </td></tr>
    <tr><td style="background:#0f172a;padding:14px 30px;color:#94a3b8;font-size:11px;text-align:center;">{$co['name']} &middot; New-message alert (throttled to 1 email per lead per 5 minutes)</td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
                send_email($adminEmail, 'Customer Enquiry — new message from ' . ($lr['name'] ?? 'customer'),
                           $body, null, 'admin_chat_msg_alert', 0);
                $pdo->prepare("UPDATE chat_leads SET admin_notified_at=NOW() WHERE id=?")->execute([$leadId]);
            }
        }
    } catch (Throwable $e) { @error_log('[chat-customer admin alert] ' . $e->getMessage()); }
}

$since = (int)($in['since'] ?? 0);
// Try with attachment columns; fall back to base columns if a fresh deploy
// hasn't migrated them yet (so the customer still receives admin replies).
try {
    $st = $pdo->prepare('SELECT id, sender, message, attachment_url, attachment_type, attachment_name, sent_at FROM chat_messages
                         WHERE lead_id=? AND sender=\'admin\' AND id > ? ORDER BY id ASC LIMIT 50');
    $st->execute([$leadId, $since]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $st = $pdo->prepare('SELECT id, sender, message, sent_at FROM chat_messages
                         WHERE lead_id=? AND sender=\'admin\' AND id > ? ORDER BY id ASC LIMIT 50');
    $st->execute([$leadId, $since]);
    $rows = $st->fetchAll();
}
// Mark fetched admin messages as read by the customer
if ($rows) {
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE chat_messages SET read_at=NOW() WHERE id IN ($ph)");
    $upd->execute($ids);
}

// Surface the admin's typing state + "agent joined" name (best-effort — the
// agent_name column may be missing on a not-yet-migrated DB).
$adminTyping = ''; $agentName = '';
try {
    $tStmt = $pdo->prepare('SELECT typing_admin_at, agent_name FROM chat_leads WHERE id=?');
    $tStmt->execute([$leadId]);
    $leadMeta = $tStmt->fetch() ?: [];
    $adminTyping = (string)($leadMeta['typing_admin_at'] ?? '');
    $agentName   = trim((string)($leadMeta['agent_name'] ?? ''));
} catch (Throwable $e) {
    try {
        $tStmt = $pdo->prepare('SELECT typing_admin_at FROM chat_leads WHERE id=?');
        $tStmt->execute([$leadId]);
        $adminTyping = (string)($tStmt->fetchColumn() ?: '');
    } catch (Throwable $e2) {}
}
$adminIsTyping = $adminTyping && (time() - strtotime($adminTyping)) <= 5;

echo json_encode([
    'ok'           => true,
    'messages'     => $rows,
    'admin_typing' => $adminIsTyping,
    'agent_joined' => $agentName !== '',
    'agent_name'   => $agentName,
]);
