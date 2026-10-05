<?php
// Sign in. The very first visit asks you to choose the password instead, and "Forgot password?" (/login?forgot)
// chooses a new one. On the web both need the setup code from config.php, which only someone who can edit the site's
// files can put there, so a stranger can't claim the site or reset the password.

declare(strict_types=1);

start_session();
$first_run = password_hash_in_use() === null;
$reset = !$first_run && isset($_GET['forgot']);
$choosing = $first_run || $reset;  // the form asks for a new password
$needs_code = $choosing && !is_local_request();
$error = $needs_code && trim((string) (config()['setup_code'] ?? '')) === ''
    ? ($first_run ? "Before the first sign-in, put a password hash or a 'setup_code' in config.php (see config.sample.php)."
        : "To reset your password, put a 'setup_code' in config.php (hPanel > File Manager), then reload this page.")
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $password = (string) ($_POST['password'] ?? '');

    if ($choosing) {
        $error = $needs_code ? check_setup_code((string) ($_POST['setup_code'] ?? '')) : null;
        if ($error === null) {
            $error = change_password($password, (string) ($_POST['confirm'] ?? ''));
        }
        if ($error === null) {
            redirect($reset ? '/?password=reset' : '/');
        }
    } else {
        $error = check_password((string) ($_POST['username'] ?? ''), $password);
        if ($error === null) {
            sign_in();
            redirect('/');
        }
    }
}

$title = $first_run ? 'Set up Job Finder' : ($reset ? 'Choose a new password' : 'Sign in');
$body_class = 'login-page';
require APP_ROOT . '/templates/_login_top.php';
?>
<main class="narrow login-box">
  <div class="logo" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg></div>
  <h1><?= h($choosing ? $title : 'Welcome back') ?></h1>
  <?php if (!$choosing): ?><p class="sub">Sign in to Job Finder</p><?php endif; ?>
  <?php if ($reset): ?>
    <p>Type the setup code from config.php and choose a new password. Every signed-in browser is signed out and Claude
      is disconnected. Delete the setup code from config.php afterwards.</p>
  <?php elseif ($first_run): ?>
    <p>Choose the password you'll use to sign in. Nobody else can get in without it.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $reset ? '/login?forgot' : '/login' ?>" id="login-form" name="login" autocomplete="on">
    <?= csrf_field() ?>
    <?php if ($needs_code): ?>
      <label for="setup_code">Setup code (from config.php) <input type="text" id="setup_code" name="setup_code" required autofocus
        spellcheck="false" autocapitalize="none" autocomplete="off"></label>
    <?php endif; ?>
    <?php if (!$choosing): ?>
      <label for="username">Username <input type="text" id="username" name="username" spellcheck="false" autocapitalize="none" required autofocus autocomplete="username"></label>
    <?php endif; ?>
    <label for="password">Password <input type="password" id="password" name="password" required <?= $choosing && !$needs_code ? 'autofocus' : '' ?>
      autocomplete="<?= $choosing ? 'new-password' : 'current-password' ?>"></label>
    <?php if ($choosing): ?>
      <label for="confirm">Type it again <input type="password" id="confirm" name="confirm" required autocomplete="new-password"></label>
    <?php endif; ?>
    <button type="submit"><?= $choosing ? 'Save password' : 'Sign in' ?></button>
  </form>
  <?php if ($reset): ?><p class="login-alt"><a href="/login">Back to sign in</a></p>
  <?php elseif (!$first_run): ?><p class="login-alt"><a href="/login?forgot">Forgot password?</a></p><?php endif; ?>
</main>
</body>
</html>
