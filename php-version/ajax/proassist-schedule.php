<?php
// ProAssist install-call scheduler — customer-facing endpoint.
//
// Resolves the current visitor's chat_lead via session ($_SESSION['lead_id'])
// or the chat_token query/body field.  Returns the lead's ProAssist status
// (is_proassist, scheduled_at), open slots for a chosen date, or books a slot.
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$in     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? 'status';
$pdo    = db();

// Resolve the current lead from session_id or chat_token (matches
// chat-customer.php's resolution rules).
$token  = trim((string)($in['token'] ?? ''));
$leadId = 0;
if ($token !== '') {
    $st = $pdo->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]);
    $leadId = (int)$st->fetchColumn();
    if ($leadId) $_SESSION['lead_id'] = $leadId;
}
if (!$leadId) $leadId = (int)($_SESSION['lead_id'] ?? 0);
if (!$leadId) {
    echo json_encode(['ok' => false, 'error' => 'No lead']);
    exit;
}

// Pull lead + most recent ProAssist order context so the chat widget can
// render personalised messaging ("Install call for Order #ORD-…").
$st = $pdo->prepare("SELECT l.id, l.name, l.email, l.phone, l.requested_product
                     FROM chat_leads l WHERE l.id=? LIMIT 1");
$st->execute([$leadId]);
$lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    echo json_encode(['ok' => false, 'error' => 'Lead not found']);
    exit;
}
$isPro = trim((string)($lead['requested_product'] ?? '')) === 'ProAssist Premium Installation';

// Latest ProAssist order for this email (best-effort — we just need the
// order_number for context strings).
$orderNumber = '';
$orderId     = null;
if ($isPro && !empty($lead['email'])) {
    try {
        $st = $pdo->prepare("SELECT o.id, o.order_number FROM orders o
                             WHERE o.email = ? AND o.pro_assist = 1
                             ORDER BY o.id DESC LIMIT 1");
        $st->execute([$lead['email']]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $orderNumber = (string)$row['order_number'];
            $orderId     = (int)$row['id'];
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Helper — fetch the current schedule row for this lead (if any).
function _pa_schedule_for_lead(PDO $pdo, int $leadId): ?array
{
    $st = $pdo->prepare('SELECT id, scheduled_at, scheduled_utc, tz, status, order_number FROM proassist_schedules WHERE lead_id=? LIMIT 1');
    $st->execute([$leadId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Customer-selectable timezones (value => friendly label).  Slots are
// rendered + booked in the timezone the customer picks here; the admin
// panel always re-displays the booking converted to IST.
function _pa_timezones(): array
{
    return [
        'America/New_York'    => 'Eastern Time (US & Canada)',
        'America/Chicago'     => 'Central Time (US & Canada)',
        'America/Denver'      => 'Mountain Time (US & Canada)',
        'America/Phoenix'     => 'Arizona (MST)',
        'America/Los_Angeles' => 'Pacific Time (US & Canada)',
        'America/Anchorage'   => 'Alaska Time',
        'Pacific/Honolulu'    => 'Hawaii Time',
        'America/Toronto'     => 'Toronto',
        'America/Vancouver'   => 'Vancouver',
        'Europe/London'       => 'London (GMT/BST)',
        'Europe/Berlin'       => 'Central Europe (CET)',
        'Asia/Dubai'          => 'Dubai (GST)',
        'Asia/Kolkata'        => 'India (IST)',
        'Asia/Singapore'      => 'Singapore',
        'Australia/Sydney'    => 'Sydney',
        'UTC'                 => 'UTC',
    ];
}
// Normalise a requested timezone to one of the allowed values (defaults
// to US Eastern when the value is missing or unrecognised).
function _pa_valid_tz(string $tz): string
{
    $all = _pa_timezones();
    return isset($all[$tz]) ? $tz : 'America/New_York';
}

// ============================================================
// STATUS — chat widget polls this on open to decide whether to
// render the calendar card or the "you're scheduled" confirmation.
// ============================================================
if ($action === 'status') {
    $sched = _pa_schedule_for_lead($pdo, $leadId);
    $schedOut = null;
    if ($sched) {
        $stz = _pa_valid_tz((string)$sched['tz']);
        try {
            $utc = new DateTime((string)$sched['scheduled_utc'], new DateTimeZone('UTC'));
            $loc = (clone $utc)->setTimezone(new DateTimeZone($stz));
            $pretty = $loc->format('l, M j · g:i A') . ' ' . $loc->format('T');
        } catch (Throwable $e) {
            $pretty = date('l, M j · g:i A', strtotime((string)$sched['scheduled_at'])) . ' EST';
        }
        $schedOut = [
            'id'           => (int)$sched['id'],
            'scheduled_at' => $sched['scheduled_at'],
            'tz'           => $stz,
            'status'       => $sched['status'],
            'pretty'       => $pretty,
        ];
    }
    echo json_encode([
        'ok'           => true,
        'is_proassist' => $isPro,
        'order_number' => $orderNumber,
        'customer'     => [
            'name'  => $lead['name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'],
        ],
        'timezones'    => _pa_timezones(),
        'schedule'     => $schedOut,
    ]);
    exit;
}

// ============================================================
// SLOTS — returns the 9:00 AM–6:00 PM slot grid (30-min steps) for a
// chosen date, in the customer's selected timezone.  Each slot is flagged
// taken (already booked by someone else, compared in UTC) or past.
// Any future date is bookable (weekends included).
// ============================================================
if ($action === 'slots') {
    $date = trim((string)($in['date'] ?? ''));
    $tzName = _pa_valid_tz(trim((string)($in['tz'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    $tz = new DateTimeZone($tzName);
    $utcTz = new DateTimeZone('UTC');
    try {
        $dayStart = new DateTime($date . ' 09:00:00', $tz);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    // Booked slots across ALL leads (status != cancelled), compared in UTC
    // so bookings made in different timezones still collide correctly.
    $bookedUtc = [];
    try {
        $b = $pdo->prepare("SELECT scheduled_utc FROM proassist_schedules
                            WHERE status <> 'cancelled' AND lead_id <> ?");
        $b->execute([$leadId]);
        foreach ($b->fetchAll(PDO::FETCH_COLUMN) as $ts) {
            $bookedUtc[date('Y-m-d H:i', strtotime($ts . ' UTC'))] = true;
        }
    } catch (Throwable $e) { /* non-fatal */ }
    // Build slots 09:00 → 17:30 every 30 min in the customer's timezone.
    $nowLocal = new DateTime('now', $tz);
    $slots = [];
    $cursor = clone $dayStart;
    $end    = (clone $dayStart)->setTime(17, 30);
    while ($cursor <= $end) {
        $hm    = $cursor->format('H:i');
        $lab   = $cursor->format('g:i A');
        $utcK  = (clone $cursor)->setTimezone($utcTz)->format('Y-m-d H:i');
        $past  = ($cursor <= $nowLocal);
        $slots[] = [
            'time'  => $hm,
            'label' => $lab,
            'taken' => !empty($bookedUtc[$utcK]),
            'past'  => $past,
        ];
        $cursor->modify('+30 minutes');
    }
    echo json_encode(['ok' => true, 'slots' => $slots, 'closed' => false, 'tz' => $tzName]);
    exit;
}

// ============================================================
// BOOK — creates or updates the schedule row for this lead.
// Inserts an admin-side chat_messages confirmation so the customer
// sees a "✓ Confirmed for Tue Jun 17 · 2:30 PM EST" bubble immediately.
// ============================================================
if ($action === 'book') {
    if (!$isPro) {
        echo json_encode(['ok' => false, 'error' => 'Not a ProAssist lead']);
        exit;
    }
    $date = trim((string)($in['date'] ?? ''));
    $time = trim((string)($in['time'] ?? ''));
    $tzName = _pa_valid_tz(trim((string)($in['tz'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date or time']);
        exit;
    }
    $tzCust = new DateTimeZone($tzName);
    $tzUTC = new DateTimeZone('UTC');
    try {
        $local = new DateTime($date . ' ' . $time . ':00', $tzCust);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date/time']);
        exit;
    }
    // Reject past slots + enforce the 9:00 AM–6:00 PM window in the
    // customer's own timezone (any future date is allowed, weekends too).
    $now = new DateTime('now', $tzCust);
    if ($local <= $now) {
        echo json_encode(['ok' => false, 'error' => 'That slot has already passed — please pick another time']);
        exit;
    }
    $hour = (int)$local->format('H');
    $min  = (int)$local->format('i');
    if ($hour < 9 || ($hour > 17 || ($hour === 17 && $min > 30))) {
        echo json_encode(['ok' => false, 'error' => 'Office hours are 9:00 AM – 6:00 PM']);
        exit;
    }
    if (!in_array($min, [0, 30], true)) {
        echo json_encode(['ok' => false, 'error' => 'Slots are in 30-minute increments']);
        exit;
    }
    $localStr = $local->format('Y-m-d H:i:s');
    $utc = (clone $local)->setTimezone($tzUTC);
    $utcStr = $utc->format('Y-m-d H:i:s');

    // Slot collision check in UTC (someone else already booked this instant).
    $coll = $pdo->prepare("SELECT id FROM proassist_schedules
                           WHERE scheduled_utc = ? AND status <> 'cancelled' AND lead_id <> ?");
    $coll->execute([$utcStr, $leadId]);
    if ($coll->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'That slot was just taken — please choose another time']);
        exit;
    }

    // Upsert: one schedule per lead.  If a row already exists, update it
    // (reschedule), otherwise insert.
    $existing = _pa_schedule_for_lead($pdo, $leadId);
    if ($existing) {
        $up = $pdo->prepare("UPDATE proassist_schedules SET
                              scheduled_at=?, scheduled_utc=?, tz=?, status='pending'
                            WHERE id=?");
        $up->execute([$localStr, $utcStr, $tzName, (int)$existing['id']]);
        $scheduleId = (int)$existing['id'];
        $wasReschedule = true;
    } else {
        $ins = $pdo->prepare("INSERT INTO proassist_schedules
            (lead_id, order_id, order_number, customer_name, customer_email, customer_phone,
             scheduled_at, scheduled_utc, tz, status)
            VALUES (?,?,?,?,?,?,?,?,?,'pending')");
        $ins->execute([
            $leadId,
            $orderId,
            $orderNumber,
            (string)$lead['name'],
            (string)$lead['email'],
            (string)$lead['phone'],
            $localStr, $utcStr, $tzName,
        ]);
        $scheduleId = (int)$pdo->lastInsertId();
        $wasReschedule = false;
    }

    // Customer-facing "pretty" string is in THEIR timezone; the admin-facing
    // one is converted to IST (Asia/Kolkata) per the install team's request.
    $tzAbbr  = $local->format('T');
    $pretty  = $local->format('l, F j') . ' at ' . $local->format('g:i A') . ' ' . $tzAbbr;
    $istDt    = (clone $utc)->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $prettyIST = $istDt->format('l, F j') . ' at ' . $istDt->format('g:i A') . ' IST';

    // Append a confirmation message to the chat thread so the customer
    // sees an instant acknowledgement (and the admin gets a record).
    $confirm = ($wasReschedule
                    ? "✅ Rescheduled — your install call is now booked for "
                    : "✅ Confirmed — your install call is booked for ")
             . $pretty
             . ". A specialist will call you on the number we have on file. "
             . "If you need to reschedule, just let us know here.";
    try {
        $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message) VALUES (?,?,?)')
            ->execute([$leadId, 'admin', $confirm]);
    } catch (Throwable $e) { /* non-fatal */ }

    /* ----------------------------------------------------------------
     *  Bell-badge alert — push a row into `admin_notifications` so the
     *  Install Schedule sidebar item lights up + the topbar bell shows
     *  a +1 counter the moment a customer books (or reschedules) a
     *  ProAssist call.  Idempotent within a 30-minute window so admins
     *  don't get spammed if the customer mis-clicks.
     * --------------------------------------------------------------- */
    try {
        require_once __DIR__ . '/../includes/admin-notify.php';
        $cName = (string)$lead['name']  ?: '(no name)';
        $cEml  = (string)$lead['email'] ?: '(no email)';
        $verb  = $wasReschedule ? 'rescheduled' : 'booked';
        admin_notify(
            'install',
            'Install pending — ' . $cName,
            $cName . ' ' . $verb . ' a ProAssist install call for ' . $prettyIST
                . ' (customer local: ' . $pretty . ')'
                . ($cEml ? ' · ' . $cEml : '')
                . ($orderNumber !== '' ? ' · Order #' . $orderNumber : ''),
            '/admin.php?tab=install-schedule&open=' . $scheduleId
        );
    } catch (Throwable $e) { @error_log('[proassist-schedule admin_notify] ' . $e->getMessage()); }

    /* ----------------------------------------------------------------
     *  Email the company support address so the install team gets a
     *  real-time alert whenever a customer books (or reschedules) a
     *  ProAssist install call.  Pulls the recipient from Company Info
     *  → settings (`company_support_email`) with a hard fallback to
     *  SITE_BRAND's main address so the notification never gets lost.
     * --------------------------------------------------------------- */
    try {
        // company_info() lives in includes/settings.php; rather than pull
        // in another include we read the same keys directly via setting_get
        // which is already available through functions.php.
        $toEmail = trim((string)setting_get('company_email', ''));
        if ($toEmail === '') $toEmail = trim((string)setting_get('company_support_email', ''));
        if ($toEmail === '' && defined('SUPPORT_EMAIL')) $toEmail = (string)SUPPORT_EMAIL;
        if ($toEmail !== '') {
            $brand     = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software';
            $siteUrl   = function_exists('site_url') ? rtrim(site_url(), '/') : '';
            $adminLink = $siteUrl . '/admin.php?tab=install-schedule&open=' . $scheduleId;
            $cName     = htmlspecialchars((string)$lead['name'],  ENT_QUOTES, 'UTF-8') ?: '(no name)';
            $cEmail    = htmlspecialchars((string)$lead['email'], ENT_QUOTES, 'UTF-8') ?: '(no email)';
            $cPhone    = htmlspecialchars((string)$lead['phone'], ENT_QUOTES, 'UTF-8') ?: '(no phone)';
            $orderTxt  = $orderNumber !== '' ? htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') : '(no linked order)';
            $verb      = $wasReschedule ? 'rescheduled' : 'booked';
            $subject   = ($wasReschedule ? '[Rescheduled] ' : '[New] ')
                       . 'ProAssist install call — ' . $prettyIST . ' — ' . ($lead['name'] ?: $lead['email']);
            $prettyISTEsc  = htmlspecialchars($prettyIST, ENT_QUOTES, 'UTF-8');
            $prettyLocalEsc = htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="background:#0f172a;color:#fbbf24;padding:18px 22px;border-radius:10px 10px 0 0;">
    <div style="font-size:11px;letter-spacing:.12em;font-weight:800;text-transform:uppercase;color:#fcd34d;">{$brand} — ProAssist</div>
    <div style="font-size:20px;font-weight:800;color:#fff;margin-top:4px;">Install call {$verb} — action needed</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;padding:24px;line-height:1.55;">
    <p style="margin:0 0 14px 0;font-size:14px;">A customer just <strong>{$verb}</strong> a ProAssist Premium Installation slot.  Please add the call to the specialist's calendar and confirm with the customer ahead of the scheduled time.</p>
    <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;margin:6px 0 18px 0;">
      <tr><td style="padding:7px 0;color:#64748b;width:140px;">Scheduled (IST)</td><td style="padding:7px 0;font-weight:700;color:#0f172a;">{$prettyISTEsc}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Customer's local time</td><td style="padding:7px 0;">{$prettyLocalEsc}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Customer name</td><td style="padding:7px 0;">{$cName}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Email</td><td style="padding:7px 0;"><a href="mailto:{$cEmail}" style="color:#2563eb;text-decoration:none;">{$cEmail}</a></td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Phone</td><td style="padding:7px 0;"><a href="tel:{$cPhone}" style="color:#2563eb;text-decoration:none;">{$cPhone}</a></td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Linked order</td><td style="padding:7px 0;">{$orderTxt}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Schedule ID</td><td style="padding:7px 0;font-family:ui-monospace,Menlo,monospace;">#{$scheduleId}</td></tr>
    </table>
    <a href="{$adminLink}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:700;font-size:14px;">Open in admin &rsaquo;</a>
    <p style="margin:22px 0 0 0;font-size:12px;color:#64748b;">This is an automated notification from {$brand}. To stop receiving these alerts, update the support email in Admin → Company Info.</p>
  </div>
</div>
HTML;
            send_email($toEmail, $subject, $html, null, 'proassist_booked', 0);
        }
    } catch (Throwable $e) { /* never block the booking on email failure */ }

    echo json_encode([
        'ok'       => true,
        'schedule' => [
            'id'           => $scheduleId,
            'scheduled_at' => $localStr,
            'tz'           => $tzName,
            'status'       => 'pending',
            'pretty'       => $pretty,
        ],
        'rescheduled' => $wasReschedule,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
