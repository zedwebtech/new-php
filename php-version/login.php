<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$pageTitle = 'Admin Login | ' . SITE_BRAND;
$pageDescription = 'Sign in to your Maventech Software account to view orders, license keys, downloads and order history. Secure customer & admin login.';
// Default to EMPTY so a normal login uses the role-aware landing
// (admin → dashboard, staff → first allowed panel, customer → account).
// A real ?next= (e.g. from require_admin) still takes precedence.
$next = preg_replace('/[^a-z0-9.\-]/i', '', $_GET['next'] ?? ($_POST['next'] ?? ''));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0);
    if ($_SESSION['login_attempts'] >= 8) {
        $error = 'Too many failed attempts. Please try again later.';
    } else {
        // Match by email (customers + super admin) OR username (staff).
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([strtolower($login), $login]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            // Block deactivated staff accounts.
            if (in_array(($user['role'] ?? ''), ['staff'], true) && (int)($user['active'] ?? 1) === 0) {
                $_SESSION['login_attempts']++;
                $error = 'This account has been deactivated. Please contact your administrator.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                unset($_SESSION['login_attempts']);
                // Super admin → dashboard; staff → their first allowed panel;
                // customers → account page.
                if (($user['role'] ?? '') === 'admin') {
                    $defaultLanding = 'admin.php?tab=dashboard';
                } elseif (($user['role'] ?? '') === 'staff') {
                    $defaultLanding = admin_first_allowed($user);
                } else {
                    $defaultLanding = 'account.php';
                }
                $dest = (!empty($_GET['next']) || !empty($_POST['next'])) ? ($next ?: $defaultLanding) : $defaultLanding;
                header('Location: ' . $dest);
                exit;
            }
        } else {
            $_SESSION['login_attempts']++;
            $error = 'Invalid username/email or password.';
        }
    }
}

// Pull the brand/logo so the heading mirrors the rest of the panel.
$co        = function_exists('company_info') ? company_info() : [];
$brandName = $co['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech');
$brandLogo = $co['logo']  ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<meta name="description" content="<?= esc($pageDescription) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#06b6d4">
<link rel="icon" href="/assets/images/icons/admin-192.png">
<link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="/assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
<script>
  // Apply saved admin theme BEFORE styles render — prevents flash of light/dark.
  // We sync TWO storage locations so the choice carries everywhere:
  //   · localStorage.uc_theme   (used by the public/admin shell layouts)
  //   · cookie  adm_mode        (used server-side by includes/admin-shell.php)
  // Cookie is the authoritative source for admin pages; mirror it back into
  // localStorage so this login page picks it up on subsequent loads.
  (function () {
    try {
      var ck = (document.cookie.match(/(?:^|; )adm_mode=([^;]+)/) || [])[1] || '';
      var ls = localStorage.getItem('uc_theme') || '';
      var t  = ck || ls || 'light';
      if (t !== 'dark' && t !== 'light') t = 'light';
      document.documentElement.setAttribute('data-bs-theme', t);
      try { localStorage.setItem('uc_theme', t); } catch (e) {}
    } catch (e) {}
  })();
</script>
<style>
  /* Clean, focused admin sign-in canvas — PayPal-style centered card.
     No public-site chrome, no newsletter, no footer.  Theme-aware so the
     same screen looks at home in both dark and light dashboards. */
  :root {
    --ml-bg: #f7f8fa;
    --ml-card: #ffffff;
    --ml-text: #0f172a;
    --ml-muted: #6b7280;
    --ml-border: #d1d5db;
    --ml-input-bg: #f0f3fa;
    --ml-input-focus-bg: #ffffff;
    /* Zoom-blue brand (matches the new public storefront theme). */
    --ml-brand: #0B5CFF;
    --ml-brand-dk: #0848CC;
    --ml-card-shadow: 0 1px 3px rgba(15, 23, 42, .06), 0 10px 24px rgba(15, 23, 42, .04);
  }
  [data-bs-theme="dark"] {
    --ml-bg: #050B1B;             /* Zoom navy */
    --ml-card: #111A38;
    --ml-text: #f1f5f9;
    --ml-muted: #9AA5BC;
    --ml-border: rgba(255, 255, 255, .10);
    --ml-input-bg: #0B1430;
    --ml-input-focus-bg: #0B1430;
    /* Same Zoom blue in dark mode so the "Log In" CTA is one consistent
       brand colour across the whole site.  Previously this was sky-cyan
       (#38bdf8) which clashed with the deep-navy palette and read as a
       different brand. */
    --ml-brand: #4480FF;
    --ml-brand-dk: #0B5CFF;
    --ml-card-shadow: 0 1px 3px rgba(0, 0, 0, .50), 0 18px 40px rgba(0, 0, 0, .35);
  }

  html, body { height: 100%; }
  body {
    margin: 0;
    background: var(--ml-bg);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: var(--ml-text);
    display: flex; align-items: center; justify-content: center;
    padding: 32px 16px;
    transition: background .25s, color .25s;
    overflow-x: hidden;
  }

  /* =============================================================
     FLOATING TECH ICONS — same animated layer as the admin shell
     so the login screen feels like a continuous experience.
     Slightly more visible than admin-shell defaults.
     ============================================================= */
  .lg-floats { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
  .lg-floats i {
    position: absolute;
    font-size: 64px;
    opacity: 0.32;
    filter: drop-shadow(0 4px 10px rgba(15,23,42,.15));
    animation: lg-float-drift 16s ease-in-out infinite;
    will-change: transform;
  }
  [data-bs-theme="dark"] .lg-floats i {
    opacity: 0.40;
    filter: drop-shadow(0 4px 14px rgba(0,0,0,.55));
  }
  .lg-floats i:nth-child(even) { animation-name: lg-float-drift-rev; animation-duration: 18s; }
  .lg-floats i:nth-child(3n)   { animation-duration: 14s; }
  .lg-floats i:nth-child(4n)   { animation-duration: 20s; }
  .lg-floats i:nth-child(5n)   { animation-duration: 12s; }
  /* Real Microsoft-product colours — same palette as admin-shell.php. */
  .lg-floats .ic-win    { color: #0078D4; }
  .lg-floats .ic-office { color: #D24726; }
  .lg-floats .ic-apple  { color: #6b7280; }
  .lg-floats .ic-droid  { color: #3DDC84; }
  .lg-floats .ic-shield { color: #DC2626; }
  .lg-floats .ic-cloud  { color: #0EA5E9; }
  .lg-floats .ic-key    { color: #F59E0B; }
  .lg-floats .ic-cpu    { color: #8B5CF6; }
  .lg-floats .ic-mail   { color: #2563EB; }
  .lg-floats .ic-card   { color: #10B981; }
  .lg-floats .ic-globe  { color: #6366F1; }
  .lg-floats .ic-bell   { color: #EAB308; }
  @keyframes lg-float-drift {
    0%   { transform: translate(0, 0)         rotate(0deg)   scale(1); }
    25%  { transform: translate(20vw, -12vh)  rotate(45deg)  scale(1.15); }
    50%  { transform: translate(35vw, 18vh)   rotate(-25deg) scale(0.9); }
    75%  { transform: translate(15vw, 30vh)   rotate(60deg)  scale(1.1); }
    100% { transform: translate(0, 0)         rotate(0deg)   scale(1); }
  }
  @keyframes lg-float-drift-rev {
    0%   { transform: translate(0, 0)         rotate(0deg)    scale(1); }
    25%  { transform: translate(-18vw, 15vh)  rotate(-60deg)  scale(0.85); }
    50%  { transform: translate(-32vw, -10vh) rotate(40deg)   scale(1.2); }
    75%  { transform: translate(-15vw, -25vh) rotate(-30deg)  scale(1); }
    100% { transform: translate(0, 0)         rotate(0deg)    scale(1); }
  }
  @media (prefers-reduced-motion: reduce) { .lg-floats i { animation: none; } }
  @media (max-width: 575px) { .lg-floats i { font-size: 42px; opacity: .22; } }

  /* Theme toggle pill — top-right of the viewport. */
  .ml-theme-toggle {
    position: fixed; top: 18px; right: 18px;
    background: var(--ml-card); color: var(--ml-text);
    border: 1px solid var(--ml-border);
    border-radius: 999px; padding: 6px 14px;
    font-size: 12.5px; font-weight: 700; letter-spacing: .2px;
    cursor: pointer; z-index: 10;
    display: inline-flex; align-items: center; gap: 6px;
    box-shadow: 0 2px 6px rgba(15,23,42,.06);
    transition: background .15s, color .15s, transform .1s, box-shadow .15s;
  }
  .ml-theme-toggle:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(15,23,42,.10);
  }
  .ml-theme-toggle .bi { font-size: 14px; line-height: 1; }
  [data-bs-theme="dark"] .ml-theme-toggle { box-shadow: 0 2px 8px rgba(0,0,0,.45); }
  /* Hide the icon that doesn't match the current theme. */
  [data-bs-theme="light"] .ml-theme-toggle .bi-moon-stars-fill { display: none; }
  [data-bs-theme="dark"]  .ml-theme-toggle .bi-sun-fill { display: none; }
  [data-bs-theme="light"] .ml-theme-toggle .lbl-dark { display: none; }
  [data-bs-theme="dark"]  .ml-theme-toggle .lbl-light { display: none; }

  .ml-shell { width: 100%; max-width: 420px; position: relative; z-index: 1; }
  .ml-card {
    background: var(--ml-card);
    border: 1px solid transparent;
    border-radius: 14px;
    box-shadow: var(--ml-card-shadow);
    padding: 40px 36px 32px;
    transition: background .25s, box-shadow .25s;
  }
  [data-bs-theme="dark"] .ml-card { border-color: var(--ml-border); }

  .ml-brand { text-align: center; margin-bottom: 28px; }
  .ml-brand img { height: 56px; width: auto; max-width: 200px; object-fit: contain; }
  .ml-brand-svg { display: flex; justify-content: center; margin-bottom: 10px; }
  .ml-brand-svg .brand-mark {
    width: 56px; height: 56px; border-radius: 14px;
    box-shadow: 0 4px 16px rgba(15,23,42,.10);
  }
  [data-bs-theme="dark"] .ml-brand-svg .brand-mark { box-shadow: 0 4px 18px rgba(0,0,0,.5); }
  .ml-brand-wordmark {
    font-size: 19px; font-weight: 800; letter-spacing: -.3px; color: var(--ml-text);
    line-height: 1.1;
  }
  .ml-brand-wordmark .brand-grad {
    background: linear-gradient(135deg, #0B5CFF, #4480FF);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .ml-brand-fallback {
    font-size: 28px; font-weight: 800; letter-spacing: -.5px; color: var(--ml-text);
  }
  .ml-brand-fallback .brand-grad {
    background: linear-gradient(135deg, #0B5CFF, #4480FF);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .ml-title {
    text-align: center;
    font-size: 24px; font-weight: 700; color: var(--ml-text);
    margin: 0 0 28px 0;
    letter-spacing: -.2px;
  }
  .ml-error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
    border-radius: 10px; padding: 10px 14px; font-size: 13px;
    margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }
  [data-bs-theme="dark"] .ml-error { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.30); }
  .ml-error .bi { font-size: 16px; }
  .ml-field { margin-bottom: 16px; position: relative; }
  .ml-input {
    width: 100%;
    height: 52px;
    background: var(--ml-input-bg);
    border: 1.5px solid transparent;
    border-radius: 10px;
    padding: 0 16px;
    font-size: 15px; color: var(--ml-text);
    outline: none;
    transition: border-color .15s, background .15s, color .15s;
    box-sizing: border-box;
  }
  .ml-input::placeholder { color: var(--ml-muted); font-size: 14px; }
  .ml-input:hover { border-color: rgba(37,99,235,.35); }
  .ml-input:focus { background: var(--ml-input-focus-bg); border-color: var(--ml-brand); }
  [data-bs-theme="dark"] .ml-input:focus { box-shadow: 0 0 0 3px rgba(56,189,248,.18); }
  .ml-pass-wrap { position: relative; }
  .ml-pass-wrap .ml-input { padding-right: 54px; }
  .ml-pass-toggle {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    background: transparent; border: 0; color: var(--ml-brand); font-weight: 600;
    font-size: 13px; cursor: pointer; padding: 4px 8px; border-radius: 6px;
  }
  .ml-pass-toggle:hover { background: rgba(37, 99, 235, .12); }
  .ml-forgot { text-align: center; margin: 18px 0 22px; }
  .ml-forgot a {
    color: var(--ml-brand); font-size: 13px; font-weight: 600;
    text-decoration: none;
  }
  .ml-forgot a:hover { text-decoration: underline; }
  .ml-submit {
    width: 100%; height: 52px;
    background: var(--ml-brand); color: #ffffff;
    border: 0; border-radius: 999px;
    font-size: 15px; font-weight: 700; letter-spacing: .2px;
    cursor: pointer;
    transition: background .15s, transform .1s, box-shadow .15s;
    box-shadow: 0 1px 3px rgba(37, 99, 235, .25);
  }
  .ml-submit:hover { background: var(--ml-brand-dk); box-shadow: 0 4px 14px rgba(37, 99, 235, .35); }
  .ml-submit:active { transform: translateY(1px); }
  [data-bs-theme="dark"] .ml-submit { color: #021124; }
  .ml-footer {
    margin-top: 22px; text-align: center;
    font-size: 11px; color: var(--ml-muted);
  }
  .ml-footer a { color: var(--ml-muted); text-decoration: none; margin: 0 6px; }
  .ml-footer a:hover { color: var(--ml-text); text-decoration: underline; }

  /* Phones — tighten padding so the card breathes on small screens. */
  @media (max-width: 480px) {
    .ml-card { padding: 28px 22px 24px; border-radius: 12px; }
    .ml-title { font-size: 21px; margin-bottom: 22px; }
    .ml-brand { margin-bottom: 22px; }
    .ml-theme-toggle { top: 12px; right: 12px; padding: 5px 11px; font-size: 11.5px; }
  }
</style>
</head>
<body>

<!-- Theme toggle pill — mirrors the admin shell `uc_theme` localStorage key. -->
<button type="button" class="ml-theme-toggle" id="loginThemeToggle" data-testid="login-theme-toggle" aria-label="Switch theme">
  <i class="bi bi-moon-stars-fill"></i>
  <i class="bi bi-sun-fill"></i>
  <span class="lbl-dark">Dark</span>
  <span class="lbl-light">Light</span>
</button>

<!-- Animated floating Microsoft-product icons — same set as the admin shell. -->
<div class="lg-floats" aria-hidden="true" data-testid="login-floats">
  <i class="bi bi-windows      ic-win"    style="left:5%;  top:8%;  animation-delay: 0s;"></i>
  <i class="bi bi-microsoft    ic-office" style="left:18%; top:62%; animation-delay: -2s;"></i>
  <i class="bi bi-shield-lock  ic-shield" style="left:32%; top:18%; animation-delay: -4s;"></i>
  <i class="bi bi-key-fill     ic-key"    style="left:46%; top:75%; animation-delay: -6s;"></i>
  <i class="bi bi-cloud-fill   ic-cloud"  style="left:60%; top:30%; animation-delay: -1s;"></i>
  <i class="bi bi-laptop       ic-win"    style="left:74%; top:55%; animation-delay: -3s;"></i>
  <i class="bi bi-fingerprint  ic-shield" style="left:88%; top:12%; animation-delay: -5s;"></i>
  <i class="bi bi-cpu-fill     ic-cpu"    style="left:10%; top:42%; animation-delay: -7s;"></i>
  <i class="bi bi-envelope-paper ic-mail" style="left:28%; top:88%; animation-delay: -8s;"></i>
  <i class="bi bi-bag-check    ic-card"   style="left:52%; top:8%;  animation-delay: -9s;"></i>
  <i class="bi bi-graph-up     ic-cpu"    style="left:68%; top:85%; animation-delay: -10s;"></i>
  <i class="bi bi-globe2       ic-globe"  style="left:82%; top:38%; animation-delay: -11s;"></i>
  <i class="bi bi-credit-card-2-front ic-card" style="left:38%; top:48%; animation-delay: -12s;"></i>
  <i class="bi bi-bell-fill    ic-bell"   style="left:90%; top:72%; animation-delay: -13s;"></i>
  <i class="bi bi-apple        ic-apple"  style="left:2%;  top:78%; animation-delay: -14s;"></i>
  <i class="bi bi-android2     ic-droid"  style="left:42%; top:32%; animation-delay: -15s;"></i>
  <i class="bi bi-shield-check ic-shield" style="left:65%; top:65%; animation-delay: -2.5s;"></i>
  <i class="bi bi-window-stack ic-win"    style="left:22%; top:25%; animation-delay: -4.5s;"></i>
</div>

<div class="ml-shell">
  <div class="ml-card" data-testid="admin-login-card">
    <div class="ml-brand" data-testid="admin-login-brand">
      <?php
      // Prefer the uploaded logo image when it actually exists and looks like
      // a real image (≥ 200 B — guards against 1×1 placeholder uploads).
      // Otherwise fall back to the SVG brand monogram + wordmark so the page
      // always renders a recognisable identity instead of a blank gap.
      $brandLogoLocal = '';
      if (!empty($brandLogo)) {
          $p = parse_url((string)$brandLogo, PHP_URL_PATH);
          if ($p) {
              $diskPath = __DIR__ . $p;
              if (is_file($diskPath) && filesize($diskPath) >= 200) {
                  $brandLogoLocal = $brandLogo;
              }
          }
      }
      ?>
      <?php if ($brandLogoLocal !== ''): ?>
        <img src="<?= esc($brandLogoLocal) ?>" alt="<?= esc($brandName) ?>">
      <?php else: ?>
        <div class="ml-brand-svg" aria-label="<?= esc($brandName) ?>">
          <?= render_logo(56) ?>
        </div>
        <div class="ml-brand-wordmark">
          <?php
            $bnParts = preg_split('/\s+/', trim($brandName));
            $bnLast  = array_pop($bnParts) ?: '';
            $bnHead  = implode(' ', $bnParts);
          ?>
          <?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <h1 class="ml-title" data-testid="admin-login-title">Admin login</h1>

    <?php if ($error): ?>
      <div class="ml-error" data-testid="login-error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= esc($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="next" value="<?= esc($next) ?>">

      <div class="ml-field">
        <input
          name="email"
          type="text"
          required
          class="ml-input"
          placeholder="Email or username"
          autocomplete="username"
          autofocus
          data-testid="login-email">
      </div>

      <div class="ml-field">
        <div class="ml-pass-wrap">
          <input
            name="password"
            id="login-pass"
            type="password"
            required
            class="ml-input"
            placeholder="Password"
            autocomplete="current-password"
            data-testid="login-password">
          <button
            type="button"
            class="ml-pass-toggle"
            id="passToggleBtn"
            data-testid="login-pass-toggle"
            onclick="(function(){var i=document.getElementById('login-pass');var on=i.type==='password';i.type=on?'text':'password';document.getElementById('passToggleBtn').textContent=on?'Hide':'Show';})()">
            Show
          </button>
        </div>
      </div>

      <div class="ml-forgot">
        <a href="forgot-password.php" data-testid="login-forgot-link">Forgotten password?</a>
      </div>

      <button class="ml-submit" type="submit" data-testid="login-submit">Log In</button>
    </form>

    <div class="ml-footer">
      <a href="/" data-testid="login-back-link"><i class="bi bi-arrow-left"></i> Back to store</a>
      <span>·</span>
      <span>&copy; <?= date('Y') ?> <?= esc($brandName) ?></span>
    </div>
  </div>
</div>

<script>
  // Theme toggle — flips data-bs-theme on <html> AND persists in both:
  //   · localStorage.uc_theme   (front-end remembers across page loads)
  //   · cookie  adm_mode        (server-side admin shell reads this)
  // Result: the choice made here is honoured throughout the admin panel.
  (function () {
    const btn = document.getElementById('loginThemeToggle');
    if (!btn) return;
    function setCookie(name, value, days) {
      var d = new Date(); d.setTime(d.getTime() + (days * 86400000));
      document.cookie = name + '=' + value + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
    }
    btn.addEventListener('click', function () {
      const html = document.documentElement;
      const next = (html.getAttribute('data-bs-theme') === 'dark') ? 'light' : 'dark';
      html.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('uc_theme', next); } catch (e) {}
      setCookie('adm_mode', next, 365);
    });
  })();
</script>

</body>
</html>
