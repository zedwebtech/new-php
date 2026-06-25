<?php
/**
 * Forgot Password — Step 1.
 *
 * 1. User enters their registered email.
 * 2. If a matching user exists, we generate a 32-byte URL-safe token,
 *    hash it (sha256) into `password_resets`, set a 60-min TTL.
 * 3. We email a single-use link `/reset-password.php?token=<raw>` —
 *    the reset URL is the linked anchor of the word "reset" inside
 *    the email body (per the user spec).
 * 4. For privacy we ALWAYS render the same success message, whether
 *    the email matched or not (no user enumeration).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
ensure_admin();

$pageTitle = 'Forgot Password | ' . SITE_BRAND;
$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            // SECURITY: the password-reset link is ONLY ever delivered to the
            // registered COMPANY EMAIL (Company Info → Email).  This prevents
            // a stranger from triggering a reset to an arbitrary inbox.  The
            // user must enter that exact registered company email; any other
            // value silently 404s (same success message for non-enumeration).
            $companyEmail = strtolower(trim((string)setting_get('company_email', '')));
            if ($companyEmail !== '' && hash_equals($companyEmail, $email)) {
                // Resolve which user we're resetting.  We prefer an admin row
                // whose email matches the company email; otherwise we fall
                // back to the first admin (single-admin systems).
                $stmt = db()->prepare(
                    "SELECT id, name, email FROM users
                      WHERE role = 'admin' AND email = ?
                      ORDER BY id ASC LIMIT 1"
                );
                $stmt->execute([$companyEmail]);
                $user = $stmt->fetch();
                if (!$user) {
                    $stmt = db()->query(
                        "SELECT id, name, email FROM users
                          WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
                    );
                    $user = $stmt->fetch();
                }
                if ($user) {
                    // Single-use, 60-min token.  We store only the sha256 hash.
                    $raw  = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $raw);
                    $exp  = date('Y-m-d H:i:s', time() + 60 * 60);
                    db()->prepare(
                        "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)"
                    )->execute([(int)$user['id'], $hash, $exp]);

                    // Build the public reset URL.  Prefer the admin-configured
                    // `site_domain_url` because $_SERVER['HTTP_HOST'] inside the
                    // CDN proxy can be the internal cluster name.
                    $publicHost = trim((string)setting_get('site_domain_url', '')) ?: site_url();
                    $resetUrl   = rtrim($publicHost, '/') . '/reset-password.php?token=' . $raw;

                    $brand = htmlspecialchars(SITE_BRAND, ENT_QUOTES, 'UTF-8');
                    $first = trim((string)($user['name'] ?? ''));
                    $name  = htmlspecialchars($first !== '' ? explode(' ', $first)[0] : 'there', ENT_QUOTES, 'UTF-8');
                    $resetUrlEsc = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

                    // Reset link lives INSIDE the word "reset" exactly as the
                    // user requested (clickable anchor text = "reset").
                    $body = ''
                        . '<div style="font-family:-apple-system,Segoe UI,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;color:#0f172a;">'
                        . '<h1 style="font-size:22px;font-weight:800;margin:0 0 14px;">Forgot your password?</h1>'
                        . '<p style="font-size:14px;line-height:1.6;color:#334155;">Hi ' . $name . ',</p>'
                        . '<p style="font-size:14px;line-height:1.6;color:#334155;">'
                        . 'We received a request to <a href="' . $resetUrlEsc . '" '
                        . 'style="color:#2563eb;font-weight:700;text-decoration:underline;">reset</a> '
                        . 'the password on your <strong>' . $brand . '</strong> account.'
                        . '</p>'
                        . '<p style="font-size:14px;line-height:1.6;color:#334155;">Click the word <strong>reset</strong> above to set a new password.  The link is single-use and expires in 60 minutes.</p>'
                        . '<p style="font-size:13px;line-height:1.55;color:#64748b;margin-top:24px;">If you didn\'t request this, you can safely ignore the email — your password will stay the same.</p>'
                        . '<p style="font-size:12px;color:#94a3b8;margin-top:24px;">— The ' . $brand . ' team</p>'
                        . '</div>';
                    // ALWAYS deliver to the registered company email — even
                    // if the admin's `users.email` differs from the company
                    // email setting.  This is the explicit rule the admin
                    // configured: reset links go to the company inbox only.
                    send_email($companyEmail, 'Reset your ' . SITE_BRAND . ' password', $body);
                }
            }
        } catch (Throwable $e) {
            @error_log('[forgot-password] ' . $e->getMessage());
        }
        // Always show success — never reveal whether the email exists.
        $sent = true;
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="min-height:60vh;">
  <div class="mx-auto" style="max-width:440px;">
    <div class="card" style="border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:28px 26px;box-shadow:0 10px 30px rgba(15,23,42,.06);">
      <h1 class="h4 fw-bold mb-2" data-testid="forgot-heading"><i class="bi bi-shield-lock text-primary me-1"></i>Forgot password?</h1>
      <p class="text-secondary small mb-4">For security, the reset link is only ever delivered to your <strong>registered company email</strong>. Enter it below — the link expires in 60 minutes.</p>

      <?php if ($sent): ?>
        <div class="alert alert-success small" data-testid="forgot-success" style="border-radius:10px;line-height:1.5;">
          <i class="bi bi-check2-circle me-1"></i>If that email matches your registered company email, a password-reset link is on its way. Check the company inbox (and spam folder) for a message from <strong><?= esc(SITE_BRAND) ?></strong>.
        </div>
        <a href="login.php" class="btn btn-link p-0 small" data-testid="forgot-back-login"><i class="bi bi-arrow-left"></i> Back to sign in</a>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert alert-danger small" data-testid="forgot-error"><i class="bi bi-exclamation-circle me-1"></i><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" data-testid="forgot-form">
          <label class="form-label small fw-semibold mb-1">Registered company email</label>
          <input type="email" name="email" class="form-control mb-2" required autofocus placeholder="services@yourcompany.com" data-testid="forgot-email" value="<?= esc((string)($_POST['email'] ?? '')) ?>">
          <div class="form-text small mb-3" style="line-height:1.4;">
            <i class="bi bi-info-circle text-primary me-1"></i>The reset link is sent <strong>only</strong> to your registered company email (configured in Admin → Company Info).
          </div>
          <button type="submit" class="btn btn-primary w-100 rounded-pill" data-testid="forgot-submit"><i class="bi bi-envelope-check me-1"></i>Send reset link</button>
        </form>
        <p class="small text-secondary text-center mt-3 mb-0">
          <a href="login.php" class="fw-semibold text-decoration-none" data-testid="forgot-back-login"><i class="bi bi-arrow-left"></i> Back to sign in</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
