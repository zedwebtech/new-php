<?php
/**
 * Track Order — public lookup page.
 *
 * Aliased to /order-history.php so customers can land on the same lookup
 * UI from either a marketing link ("Track your order") or the footer
 * ("Order History").  We keep ONE backend so the QR-code links from old
 * receipts continue to resolve.
 */
require_once __DIR__ . '/includes/functions.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: order-history.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;
