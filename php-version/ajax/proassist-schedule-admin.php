<?php
// ProAssist install-call schedule — admin actions endpoint.
// Allowed: update_status (pending/confirmed/done/missed/cancelled),
// update_notes (free-text note from the specialist).
require_once __DIR__ . '/../includes/functions.php';
ensure_admin();

header('Content-Type: application/json');

$in     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? '';
$pdo    = db();

$validStatus = ['pending','confirmed','done','missed','cancelled'];

if ($action === 'update_status') {
    $id     = (int)($in['id'] ?? 0);
    $status = (string)($in['status'] ?? '');
    if (!$id || !in_array($status, $validStatus, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid input']);
        exit;
    }
    $pdo->prepare("UPDATE proassist_schedules SET status=? WHERE id=?")
        ->execute([$status, $id]);
    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

if ($action === 'update_notes') {
    $id    = (int)($in['id'] ?? 0);
    $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000, 'UTF-8');
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }
    $pdo->prepare("UPDATE proassist_schedules SET notes=? WHERE id=?")
        ->execute([$notes, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
