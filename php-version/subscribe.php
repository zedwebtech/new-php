<?php
/**
 * Public subscription entry point.  A shareable link like
 *   /subscribe.php?plan=pro-shield
 * validates the plan, stows it in the session, and redirects the visitor to
 * the standard secure checkout (which detects the session flag and bills the
 * plan price).  Invalid / inactive / unpriced plans bounce home with a note.
 */
require_once __DIR__ . '/includes/functions.php';

$slug = trim($_GET['plan'] ?? '');
$plan = $slug !== '' ? sub_plan_get($slug) : null;

if (!$plan || (int)$plan['active'] !== 1 || (float)$plan['price'] <= 0) {
    // Plan not available — clear any stale flag and send home with a notice.
    unset($_SESSION['sub_plan']);
    header('Location: index.php?sub_error=1');
    exit;
}

$_SESSION['sub_plan'] = $plan['slug'];
// A subscription checkout ignores the product cart entirely.
header('Location: checkout.php');
exit;
