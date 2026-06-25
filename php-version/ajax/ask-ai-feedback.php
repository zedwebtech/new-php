<?php
// /ajax/ask-ai-feedback.php — customer marks an answer helpful / not helpful.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id = (int)($in['chat_id'] ?? 0);
$helpful = (int)!empty($in['helpful']);
if (!$id) { echo json_encode(['ok'=>false]); exit; }
try {
    db()->prepare("UPDATE product_ai_chats SET helpful=? WHERE id=?")->execute([$helpful, $id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
