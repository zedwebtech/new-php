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
http_response_code(410);
header('Location: login.php', true, 302);
exit;
