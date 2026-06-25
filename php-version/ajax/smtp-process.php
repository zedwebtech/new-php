<?php
// AJAX: process the email queue on demand. Admin-only.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

header('Content-Type: application/json');

$batch = max(1, min(50, (int)($_POST['batch'] ?? 10)));
$count = smtp_process_queue($batch);
echo json_encode(['ok' => true, 'processed' => $count]);
