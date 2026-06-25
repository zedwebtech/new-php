<?php
/**
 * Reset Password — Step 2.
 *
 * Validates the single-use token from the email, lets the user set a new
 * password, marks the token as used, then redirects to /login.php with a
 * success notice.  Tokens are looked up by sha256 hash so a DB leak alone
 * doesn't reveal the raw URL.
 */
require_once __DIR__ . '/includes/functions.php';
ensure_admin();

$pageTitle = 'Reset Password | ' . SITE_BRAND;
$rawToken  = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$rawToken  = preg_match('/^[a-f0-9]{64}$/', $rawToken) ? $rawToken : '';
$tokenHash = $rawToken !== '' ? hash('sha256', $rawToken) : '';
$validRow  = null;
$error     = '';
$success   = false;

if ($tokenHash !== '') {
    $st = db()->prepare(
        "SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email
           FROM password_resets pr JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = ? LIMIT 1"
    );
    $st->execute([$tokenHash]);
    $row = $st->fetch();
    if ($row) {
        if ($row['used_at']) {
            $error = 'This reset link has already been used.  Please request a new one.';
        } elseif (strtotime((string)$row['expires_at']) < time()) {
            $error = 'This reset link has expired (links are valid for 60 minutes).  Please request a new one.';
        } else {
            $validRow = $row;
        }
    } else {
        $error = 'This reset link is invalid.  Please request a new one.';
    }
} else {
    $error = 'Missing or invalid reset link.';
}

if ($validRow && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new   = (string)($_POST['new_password'] ?? '');
    $check = (string)($_POST['confirm_password'] ?? '');
    if (mb_strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $check) {
        $error = 'Passwords don\'t match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$hash, (int)$validRow['user_id']]);
        db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
            ->execute([(int)$validRow['id']]);
        // Burn any other open tokens for this user.
        db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([(int)$validRow['user_id']]);
        $success = true;
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="min-height:60vh;">
  <div class="mx-auto" style="max-width:460px;">
    <div class="card" style="border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:28px 26px;box-shadow:0 10px 30px rgba(15,23,42,.06);">
      <h1 class="h4 fw-bold mb-2" data-testid="reset-heading"><i class="bi bi-key text-primary me-1"></i>Set a new password</h1>

      <?php if ($success): ?>
        <div class="alert alert-success small" data-testid="reset-success" style="border-radius:10px;line-height:1.55;">
          <i class="bi bi-check2-circle me-1"></i>Your password has been updated.  You can now sign in with your new password.
        </div>
        <a href="login.php" class="btn btn-primary w-100 rounded-pill mt-2" data-testid="reset-login-now">Sign in now</a>
      <?php elseif (!$validRow): ?>
        <div class="alert alert-danger small" data-testid="reset-invalid" style="border-radius:10px;line-height:1.55;">
          <i class="bi bi-exclamation-octagon me-1"></i><?= esc($error) ?>
        </div>
        <a href="forgot-password.php" class="btn btn-outline-primary w-100 rounded-pill" data-testid="reset-request-new"><i class="bi bi-arrow-clockwise me-1"></i>Request a new reset link</a>
      <?php else: ?>
        <p class="text-secondary small mb-3">You're resetting the password for <strong><?= esc($validRow['email']) ?></strong>.  Choose a strong new password (at least 8 characters).</p>
        <?php if ($error): ?>
          <div class="alert alert-danger small" data-testid="reset-error"><i class="bi bi-exclamation-circle me-1"></i><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" data-testid="reset-form">
          <input type="hidden" name="token" value="<?= esc($rawToken) ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">New password</label>
            <input type="password" name="new_password" minlength="8" required class="form-control" placeholder="••••••••" data-testid="reset-new-password">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">Confirm new password</label>
            <input type="password" name="confirm_password" minlength="8" required class="form-control" placeholder="••••••••" data-testid="reset-confirm-password">
          </div>
          <button type="submit" class="btn btn-primary w-100 rounded-pill" data-testid="reset-submit"><i class="bi bi-check2-circle me-1"></i>Update password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
