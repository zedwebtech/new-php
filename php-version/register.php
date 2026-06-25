<?php
/**
 * Self-service customer registration is disabled.
 *
 * The admin panel is single-admin and customer accounts are not used —
 * customers re-access their purchases via the Track Order / Order History
 * page (email + order number).  Anyone landing here is bounced to the
 * sign-in page so the public surface area stays minimal.
 */
require_once __DIR__ . '/includes/functions.php';
// Customers have no accounts — send them to the documented re-access path
// (Track Order / Order History via email + order number) rather than the
// admin sign-in page, which is confusing for a shopper.
header('Location: track-order.php', true, 301);
exit;
