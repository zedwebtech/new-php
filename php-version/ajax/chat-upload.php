<?php
// Customer-side chat attachment upload (files + voice notes).
//
// Accepts a multipart POST with a single `file` field, validates type/size,
// stores it under /uploads/chat/, inserts a chat_messages row carrying the
// attachment metadata, pings the admin, and returns the new message row so
// the widget can render the customer's own bubble immediately.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$pdo = db();

// Resolve the current lead (session-bound id or chat_token) — mirrors
// chat-customer.php's resolution rules.
$token  = trim((string)($_POST['token'] ?? ''));
$leadId = 0;
if ($token !== '') {
    $st = $pdo->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]);
    $leadId = (int)$st->fetchColumn();
    if ($leadId) $_SESSION['lead_id'] = $leadId;
}
if (!$leadId) $leadId = (int)($_SESSION['lead_id'] ?? 0);
if (!$leadId) { echo json_encode(['ok' => false, 'error' => 'No lead']); exit; }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file received']);
    exit;
}

$f       = $_FILES['file'];
$kind    = strtolower(trim((string)($_POST['kind'] ?? '')));  // 'voice' | 'file' (hint from client)
$maxSize = 15 * 1024 * 1024; // 15 MB
if ($f['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max 15 MB)']);
    exit;
}

// Determine extension + attachment_type from the real MIME where possible.
$origName = (string)$f['name'];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = (string)finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
}
if ($mime === '') $mime = (string)($f['type'] ?? '');

// Allowed map: mime => [extension, attachment_type]
$allowed = [
    'image/jpeg'        => ['jpg',  'image'],
    'image/png'         => ['png',  'image'],
    'image/gif'         => ['gif',  'image'],
    'image/webp'        => ['webp', 'image'],
    'audio/webm'        => ['webm', 'audio'],
    'video/webm'        => ['webm', 'audio'], // MediaRecorder often labels voice as video/webm
    'audio/ogg'         => ['ogg',  'audio'],
    'audio/mpeg'        => ['mp3',  'audio'],
    'audio/mp4'         => ['m4a',  'audio'],
    'audio/wav'         => ['wav',  'audio'],
    'audio/x-wav'       => ['wav',  'audio'],
    'application/pdf'   => ['pdf',  'file'],
    'text/plain'        => ['txt',  'file'],
    'application/msword'=> ['doc',  'file'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'file'],
    'application/vnd.ms-excel' => ['xls', 'file'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'file'],
    'application/zip'   => ['zip',  'file'],
];

if (!isset($allowed[$mime])) {
    // Fall back to the original extension for a small safe set if MIME is unknown.
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $safeExt = ['jpg','jpeg','png','gif','webp','webm','ogg','mp3','m4a','wav','pdf','txt','doc','docx','xls','xlsx','zip'];
    if (!in_array($ext, $safeExt, true)) {
        echo json_encode(['ok' => false, 'error' => 'Unsupported file type']);
        exit;
    }
    if ($ext === 'jpeg') $ext = 'jpg';
    $imgExt = ['jpg','png','gif','webp'];
    $audExt = ['webm','ogg','mp3','m4a','wav'];
    $atype = in_array($ext, $imgExt, true) ? 'image' : (in_array($ext, $audExt, true) ? 'audio' : 'file');
} else {
    [$ext, $atype] = $allowed[$mime];
}
// The client hint can promote a webm to a voice note label.
if ($kind === 'voice') $atype = 'audio';

// Store the file.
$dir = __DIR__ . '/../uploads/chat';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$base   = $leadId . '-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
$dest   = $dir . '/' . $base;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    echo json_encode(['ok' => false, 'error' => 'Could not save file']);
    exit;
}
@chmod($dest, 0644);

$webUrl = '/uploads/chat/' . $base;

// Friendly display name + message label.
$displayName = $atype === 'audio' ? 'Voice message' : ($origName !== '' ? $origName : ('attachment.' . $ext));
$label = $atype === 'audio' ? '🎤 Voice message'
       : ($atype === 'image' ? '🖼️ ' . $displayName : '📎 ' . $displayName);

$ins = $pdo->prepare('INSERT INTO chat_messages (lead_id, sender, message, attachment_url, attachment_type, attachment_name) VALUES (?,?,?,?,?,?)');
$ins->execute([$leadId, 'customer', $label, $webUrl, $atype, mb_substr($displayName, 0, 240, 'UTF-8')]);
$msgId = (int)$pdo->lastInsertId();

// Clear any typing beacon + bump presence.
$pdo->prepare('UPDATE chat_leads SET typing_customer_at = NULL, last_seen = NOW() WHERE id=?')->execute([$leadId]);

// Notify the admin bell.
try {
    $leadRow = $pdo->prepare('SELECT name, email FROM chat_leads WHERE id=?');
    $leadRow->execute([$leadId]);
    $lr = $leadRow->fetch();
    $who = trim((string)($lr['name'] ?? '')) ?: (string)($lr['email'] ?? 'Customer');
    admin_notify(
        'lead',
        'New chat ' . ($atype === 'audio' ? 'voice message' : 'attachment') . ' — ' . $who,
        $label,
        '/admin.php?tab=leads&autochat=' . $leadId
    );
} catch (Throwable $e) { /* best-effort */ }

echo json_encode([
    'ok'      => true,
    'message' => [
        'id'              => $msgId,
        'sender'          => 'customer',
        'message'         => $label,
        'attachment_url'  => $webUrl,
        'attachment_type' => $atype,
        'attachment_name' => $displayName,
    ],
]);
