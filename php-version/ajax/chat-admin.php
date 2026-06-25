<?php
// Admin-side live chat endpoint.
// - thread:  return the full conversation for a lead + online status
// - send:    post a message from admin to the customer
// - unread:  return total unread customer messages (for sidebar badge + toast)
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin_json();
header('Content-Type: application/json; charset=utf-8');

// A DB hiccup (e.g. a column that hasn't migrated yet on a fresh deploy) must
// NEVER return a 500 HTML page — the chat UI can only parse JSON, otherwise it
// hangs on "Loading conversation…".  These handlers turn any uncaught error
// into a clean JSON response.
set_exception_handler(function ($e) {
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => 'Server error — please refresh and try again.']);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        echo json_encode(['ok' => false, 'error' => 'Server error — please refresh and try again.']);
    }
});

$in = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: $_GET);
$action = $in['action'] ?? 'thread';
$pdo = db();
// Make queries throw so our per-query fallbacks (below) can catch a missing
// column and degrade gracefully instead of fataling.
try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) {}
// Belt-and-suspenders: make sure the chat columns exist on this DB right now.
if (function_exists('chat_schema_migrate')) { try { chat_schema_migrate(); } catch (Throwable $e) {} }

function _is_online(?string $lastSeen): bool {
    if (!$lastSeen) return false;
    return (time() - strtotime($lastSeen)) <= 120; // 2-minute window
}

if ($action === 'send') {
    $leadId = (int)($in['lead_id'] ?? 0);
    $msg    = trim($in['message'] ?? '');
    if (!$leadId || $msg === '') { echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }

    // The agent's display identity (name + department) for the "joined" notice.
    $agentName = trim((string)($admin['name'] ?? '')) ?: (string)($admin['username'] ?? 'Support');
    $agentDept = trim((string)($admin['department'] ?? ''));

    // "Agent joined" notice — best-effort. The agent_name column may not exist
    // yet on a freshly-deployed DB; never let that block the real message.
    try {
        $cur = $pdo->prepare('SELECT agent_name FROM chat_leads WHERE id=?');
        $cur->execute([$leadId]);
        $assigned = trim((string)($cur->fetchColumn() ?: ''));
        if ($assigned === '') {
            $joinMsg = '👋 ' . $agentName . ($agentDept !== '' ? ' (' . $agentDept . ')' : '')
                     . ' has joined the chat. You\'re now connected with our team — how can we help?';
            $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
                ->execute([$leadId, 'admin', $joinMsg]);
            $pdo->prepare('UPDATE chat_leads SET agent_name=?, assigned_to=? WHERE id=?')
                ->execute([$agentName, (int)($admin['id'] ?? 0) ?: null, $leadId]);
        }
    } catch (Throwable $e) { /* agent_name column missing — skip the join notice */ }

    // The actual message — ALWAYS runs (this is what was failing on deploy).
    $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
        ->execute([$leadId, 'admin', mb_substr($msg, 0, 2000, 'UTF-8')]);
    $msgId = (int)$pdo->lastInsertId();
    // Sending implies done typing — clear the beacon immediately.
    try { $pdo->prepare('UPDATE chat_leads SET typing_admin_at = NULL WHERE id=?')->execute([$leadId]); } catch (Throwable $e) {}
    echo json_encode(['ok'=>true,'id'=>$msgId]);
    exit;
}

// Typing beacon — admin is composing.  JS pings ~every 2s while
// the textarea has focus + non-empty content.  Customer-side poller
// surfaces this within 1 tick as "● Admin is typing…".
if ($action === 'typing') {
    $leadId = (int)($in['lead_id'] ?? 0);
    if (!$leadId) { echo json_encode(['ok'=>false]); exit; }
    $on = !empty($in['typing']);
    $pdo->prepare('UPDATE chat_leads SET typing_admin_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id=?')
        ->execute([$leadId]);
    echo json_encode(['ok'=>true]); exit;
}

// Presence — bulk online/offline map for the Leads tab so chat-pill
// colours flip green → metallic-gray within a single polling tick
// once the customer leaves / idles for 2 min.  Accepts a list of lead
// IDs; returns only the rows that have changed visible since last
// page-load.  120-sec threshold matches the table's server-side check.
if ($action === 'presence') {
    $ids = array_slice(array_map('intval', $in['lead_ids'] ?? []), 0, 200);
    if (!$ids) { echo json_encode(['ok'=>true,'presence'=>[]]); exit; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, last_seen FROM chat_leads WHERE id IN ($ph)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt as $r) {
        $online = $r['last_seen'] && (time() - strtotime($r['last_seen'])) <= 120;
        $out[] = ['id'=>(int)$r['id'], 'online'=>$online, 'last_seen'=>$r['last_seen']];
    }
    echo json_encode(['ok'=>true, 'presence'=>$out]); exit;
}

if ($action === 'unread') {
    // Leads needing attention — mirrors the sidebar badge: unread customer
    // messages OR brand-new callback/ProAssist leads not yet opened.  Falls
    // back to a plain unread-message count if admin_seen_at is missing.
    try {
        $r = $pdo->query("
            SELECT COUNT(*) FROM chat_leads l
            WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.lead_id=l.id AND m.sender='customer' AND m.read_at IS NULL)
               OR (l.callback_requested=1 AND l.admin_seen_at IS NULL)
        ")->fetchColumn();
    } catch (Throwable $e) {
        $r = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM chat_messages WHERE sender='customer' AND read_at IS NULL")->fetchColumn();
    }
    $latest = $pdo->query("SELECT cm.lead_id, cm.message, cl.name
                            FROM chat_messages cm LEFT JOIN chat_leads cl ON cl.id=cm.lead_id
                            WHERE cm.sender='customer' AND cm.read_at IS NULL
                            ORDER BY cm.id DESC LIMIT 1")->fetch();
    echo json_encode(['ok'=>true, 'unread'=>(int)$r, 'latest'=>$latest ?: null]);
    exit;
}

if ($action === 'widget') {
    // Compact feed for the floating staff chat widget: recent leads (with
    // last message + unread count) and upcoming/pending install schedules.
    $leads = $pdo->query("
        SELECT l.id, l.name, l.email, l.phone, l.last_seen, l.requested_product,
          (SELECT m.message FROM chat_messages m WHERE m.lead_id=l.id ORDER BY m.id DESC LIMIT 1) AS last_message,
          (SELECT m.sent_at FROM chat_messages m WHERE m.lead_id=l.id ORDER BY m.id DESC LIMIT 1) AS last_at,
          (SELECT COUNT(*) FROM chat_messages m WHERE m.lead_id=l.id AND m.sender='customer' AND m.read_at IS NULL) AS unread
        FROM chat_leads l
        WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.lead_id=l.id)
           OR l.callback_requested=1
           OR (l.requested_product IS NOT NULL AND l.requested_product <> '')
           OR (l.email IS NOT NULL AND l.email <> '')
        ORDER BY COALESCE((SELECT MAX(m.id) FROM chat_messages m WHERE m.lead_id=l.id),0) DESC, l.id DESC
        LIMIT 40
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($leads as &$ld) {
        $ld['id'] = (int)$ld['id'];
        $ld['unread'] = (int)$ld['unread'];
        $ld['online'] = _is_online($ld['last_seen']);
    }
    unset($ld);

    $installs = [];
    try {
        // Show ALL pending/confirmed installs (those needing attention),
        // soonest first — no date cut-off, so a pending call never silently
        // disappears from the console.
        $rows = $pdo->query("SELECT id, customer_name, customer_phone, order_number, scheduled_utc, status
                             FROM proassist_schedules
                             WHERE status IN ('pending','confirmed')
                             ORDER BY scheduled_utc ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ist = '';
            try { $u = new DateTime((string)$r['scheduled_utc'], new DateTimeZone('UTC')); $u->setTimezone(new DateTimeZone('Asia/Kolkata')); $ist = $u->format('D, M j · g:i A') . ' IST'; }
            catch (Throwable $e) {}
            $installs[] = [
                'id' => (int)$r['id'], 'name' => $r['customer_name'], 'phone' => $r['customer_phone'],
                'order' => $r['order_number'], 'when' => $ist, 'status' => $r['status'],
            ];
        }
    } catch (Throwable $e) { $installs = []; }

    $totalUnread = (int)$pdo->query("
        SELECT COUNT(*) FROM chat_leads l
        WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.lead_id=l.id AND m.sender='customer' AND m.read_at IS NULL)
           OR (l.callback_requested=1 AND l.admin_seen_at IS NULL)
    ")->fetchColumn();
    echo json_encode(['ok'=>true, 'leads'=>$leads, 'installs'=>$installs, 'unread'=>$totalUnread]);
    exit;
}

// thread (default)
$leadId = (int)($in['lead_id'] ?? 0);
if (!$leadId) { echo json_encode(['ok'=>false,'error'=>'lead_id required']); exit; }
$lead = $pdo->prepare('SELECT id, name, email, phone, last_seen, typing_customer_at FROM chat_leads WHERE id=?');
$lead->execute([$leadId]); $leadRow = $lead->fetch();
if (!$leadRow) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
// Messages — try with attachment columns; if they don't exist yet on this DB
// (fresh deploy that hasn't migrated), fall back to the base columns so the
// conversation still loads instead of erroring.
try {
    $msgs = $pdo->prepare('SELECT id, sender, message, attachment_url, attachment_type, attachment_name, sent_at FROM chat_messages WHERE lead_id=? ORDER BY id ASC LIMIT 200');
    $msgs->execute([$leadId]);
    $messageRows = $msgs->fetchAll();
} catch (Throwable $e) {
    $msgs = $pdo->prepare('SELECT id, sender, message, sent_at FROM chat_messages WHERE lead_id=? ORDER BY id ASC LIMIT 200');
    $msgs->execute([$leadId]);
    $messageRows = $msgs->fetchAll();
}
// Mark customer messages as read
try { $pdo->prepare("UPDATE chat_messages SET read_at=NOW() WHERE lead_id=? AND sender='customer' AND read_at IS NULL")->execute([$leadId]); } catch (Throwable $e) {}
// Mark the lead as seen by an admin (best-effort — column may be missing).
try { $pdo->prepare("UPDATE chat_leads SET admin_seen_at=NOW() WHERE id=? AND admin_seen_at IS NULL")->execute([$leadId]); } catch (Throwable $e) {}
// Surface the customer's typing state so the admin chat panel can show
// "● Customer is typing…" within one polling tick.
$customerIsTyping = $leadRow['typing_customer_at']
    && (time() - strtotime($leadRow['typing_customer_at'])) <= 5;
echo json_encode([
    'ok' => true,
    'lead' => [
        'id'             => (int)$leadRow['id'],
        'name'           => $leadRow['name'],
        'email'          => $leadRow['email'],
        'phone'          => $leadRow['phone'],
        'last_seen'      => $leadRow['last_seen'],
        'online'         => _is_online($leadRow['last_seen']),
        'customer_typing'=> $customerIsTyping,
    ],
    'messages' => $messageRows,
]);
