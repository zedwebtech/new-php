<?php
// AJAX: test SMTP configuration. Admin-only.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

header('Content-Type: application/json');

$to = trim($_POST['to'] ?? '');
$res = smtp_test_connection($to !== '' ? $to : null);
echo json_encode($res);
