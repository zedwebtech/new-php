<?php
/**
 * Speech-to-text for the support chat mic.
 *
 * Receives a recorded audio blob from the customer's browser, transcribes it
 * with Whisper via the Emergent endpoint (pure PHP cURL — works on any host,
 * including cPanel), and returns the text.  The browser then drops that text
 * into the chat input to be sent as a normal message.
 *
 * This is far more reliable + cross-browser than the Web Speech API (works in
 * Chrome, Edge, Firefox and Safari), since MediaRecorder is universal and the
 * transcription happens server-side.
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function ($e) {
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json'); }
    echo json_encode(['ok' => false, 'error' => 'Could not transcribe — please try again or type your message.']);
});

// Tie usage to a valid chat session (lightweight abuse guard).
$token  = trim((string)($_POST['token'] ?? ''));
$leadId = 0;
if ($token !== '') {
    $st = db()->prepare('SELECT id FROM chat_leads WHERE chat_token=? LIMIT 1');
    $st->execute([$token]);
    $leadId = (int)$st->fetchColumn();
}
if (!$leadId) $leadId = (int)($_SESSION['lead_id'] ?? 0);
if (!$leadId) { echo json_encode(['ok' => false, 'error' => 'Please share your contact details first.']); exit; }

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No audio received — please try again.']);
    exit;
}
$f = $_FILES['audio'];
if ($f['size'] > 25 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'Recording too long (max 25 MB).']); exit; }
if ($f['size'] < 200)            { echo json_encode(['ok' => false, 'error' => "Didn't catch any audio — try again."]); exit; }

// Resolve key + base.
$key = ''; $base = 'https://integrations.emergentagent.com/llm/v1';
if (function_exists('_seo_resolve_llm_credentials')) {
    [$k, $b] = _seo_resolve_llm_credentials();
    if ($k) { $key = $k; $base = $b ?: $base; }
}
if ($key === '') $key = (string)(getenv('EMERGENT_LLM_KEY') ?: (function_exists('setting_get') ? setting_get('ai_blogger_llm_key', '') : ''));
if ($key === '') { echo json_encode(['ok' => false, 'error' => 'Voice typing is not configured.']); exit; }

// Forward the audio to Whisper.
$name = (string)($f['name'] ?: 'voice.webm');
$mime = (string)($f['type'] ?: 'audio/webm');
$cf   = new CURLFile($f['tmp_name'], $mime, $name);
// Use the /audio/translations endpoint so the response is ALWAYS in English,
// regardless of which language the user spoke into the mic. This is a
// Whisper feature — it auto-detects the source language and translates the
// transcription into English in one round-trip.
$ch = curl_init(rtrim($base, '/') . '/audio/translations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
    CURLOPT_POSTFIELDS     => ['model' => 'whisper-1', 'file' => $cf, 'response_format' => 'json'],
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $code < 200 || $code >= 300) {
    $msg = 'Transcription failed — please try again.';
    $j = json_decode((string)$resp, true);
    if (isset($j['error']['message'])) {
        $m = (string)$j['error']['message'];
        if (stripos($m, 'budget') !== false || stripos($m, 'quota') !== false) $msg = 'Voice typing is temporarily unavailable.';
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
$j    = json_decode((string)$resp, true);
$text = trim((string)($j['text'] ?? ''));
echo json_encode(['ok' => true, 'text' => $text]);
