<?php
// One-time CLI helper to seed the four polished default templates into the DB.
// Re-runnable — only writes when the row's html is empty or matches the auto-generated default,
// preserving any custom admin edits.
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$now = date('Y-m-d H:i:s');

$map = [
    'lead_followup'   => ['subject' => "Hi {{customer_name}} — saved your cart at {{company_name}} (10% off inside)", 'html' => default_lead_followup_template()],
    'order_pending'   => ['subject' => "Order {{order_number}} — payment pending · {{company_name}}", 'html' => default_order_pending_template()],
    'refund_confirm'  => ['subject' => "Refund initiated for order {{order_number}} — {{company_name}}", 'html' => default_refund_template()],
    'review_request'  => ['subject' => "How was your purchase, {{customer_name}}? — quick 1-tap review", 'html' => default_review_template()],
    'order_delivery'  => ['subject' => "Your {{product_name}} license is ready — order {{order_number}}", 'html' => default_email_template()],
];

foreach ($map as $code => $tpl) {
    $row = $pdo->prepare('SELECT id, html, current_version FROM email_templates WHERE code=?');
    $row->execute([$code]);
    $r = $row->fetch();
    if (!$r) {
        // Insert if missing
        $pdo->prepare('INSERT INTO email_templates (code, name, subject, html, active, current_version) VALUES (?,?,?,?,1,1)')
            ->execute([$code, ucwords(str_replace('_',' ', $code)), $tpl['subject'], $tpl['html']]);
        echo "INSERTED $code\n";
        continue;
    }
    // User explicitly requested polished defaults — force overwrite when --force is passed
    $force = in_array('--force', $argv ?? [], true);
    if (trim($r['html']) === '' || $force) {
        $pdo->prepare('UPDATE email_templates SET subject=?, html=? WHERE id=?')
            ->execute([$tpl['subject'], $tpl['html'], $r['id']]);
        echo ($force ? "OVERWROTE" : "FILLED  ") . " $code\n";
    } else {
        echo "SKIPPED $code (already customised — pass --force to overwrite)\n";
    }
}
echo "\nDone.\n";
