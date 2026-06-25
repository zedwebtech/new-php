<?php
/**
 * /ajax/user-theme.php
 *
 * Persist the current user's preferred site theme ("dark" | "light") so it
 * follows them across browsers and devices instead of only living in
 * per-browser localStorage / cookie.  Called fire-and-forget by both the
 * public theme-toggle (assets/js/main.js — toggleTheme) and the admin
 * theme-toggle (includes/admin-shell-end.php — toggleAdmTheme).
 *
 * Auth: any authenticated user (admin or customer) — the column being
 * written (`users.theme_pref`) is scoped to their own row, so this is safe.
 *
 * Behaviour:
 *   - When the request carries a valid PHPSESSID + logged-in user_id, the
 *     preference is written to users.theme_pref AND the adm_mode cookie
 *     (so the next anonymous tab on the same machine still picks it up).
 *   - When no session is present, the cookie alone is set and a 200 is
 *     returned — clients fall back gracefully to localStorage behaviour.
 *
 * Wire-format: POST JSON {theme: "dark" | "light"}  OR query ?theme=...
 * Returns:     {ok: true, persisted: bool}
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Accept theme from JSON body OR form post OR query string for flexibility.
$theme = '';
$raw   = file_get_contents('php://input');
if ($raw !== false && $raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['theme'])) $theme = (string)$j['theme'];
}
if ($theme === '') $theme = (string)($_POST['theme'] ?? $_GET['theme'] ?? '');
$theme = strtolower(trim($theme));

if (!in_array($theme, ['dark', 'light'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_theme']);
    exit;
}

// Cookie-side persist — works for everyone, signed in or not.  Used by
// admin-shell.php (adm_mode) and the public PHP layer (uc_theme).  1-year
// expiry matches the JS localStorage convention.
setcookie('adm_mode', $theme, [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'samesite' => 'Lax',
]);
setcookie('uc_theme', $theme, [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'samesite' => 'Lax',
]);

// DB-side persist — only when there's an authenticated user.
$persisted = false;
$userId    = (int)($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    try {
        $st = db()->prepare("UPDATE users SET theme_pref = ? WHERE id = ?");
        $st->execute([$theme, $userId]);
        $persisted = $st->rowCount() > 0 || true;
    } catch (Throwable $e) {
        // Column may not exist yet on a brand-new DB the very first time
        // the page is loaded — ensure_db_schema() will add it on the next
        // hit. Cookie persistence still works, so this is a safe no-op.
        $persisted = false;
    }
}

echo json_encode(['ok' => true, 'persisted' => $persisted]);
