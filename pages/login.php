<?php
// Sign in. The very first visit asks you to choose the password instead.

declare(strict_types=1);

start_session();
$login = config()['login'] ?? null;  // username + password hash from config.php, if set there
$first_run = $login === null && setting('password_hash') === null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $locked_until = (int) setting('locked_until', '0');

    if ($first_run) {
        if (strlen($password) < 10) {
            $error = 'Use at least 10 characters.';
        } elseif ($password !== ($_POST['confirm'] ?? '')) {
            $error = "The two passwords don't match.";
        } else {
            set_setting('password_hash', password_hash($password, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['user'] = 'me';
            redirect('/');
        }
    } else {
        $error = check_password((string) ($_POST['username'] ?? ''), $password);
        if ($error === null) {
            session_regenerate_id(true);
            $_SESSION['user'] = 'me';
            redirect('/');
        }
    }
}

$title = $first_run ? 'Set up Job Finder' : 'Sign in';
$body_class = 'login-page';
require APP_ROOT . '/templates/_login_top.php';
?>
<main class="narrow login-box">
  <div class="logo" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg></div>
  <h1><?= h($first_run ? $title : 'Welcome back') ?></h1>
  <?php if (!$first_run): ?><p class="sub">Sign in to Job Finder</p><?php endif; ?>
  <?php if ($first_run): ?>
    <p>Choose the password you'll use to sign in. Nobody else can get in without it.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="/login" id="login-form" name="login" autocomplete="on">
    <?= csrf_field() ?>
    <?php if (!$first_run): ?>
      <label for="username">Username <input type="text" id="username" name="username" spellcheck="false" autocapitalize="none" required autofocus autocomplete="username"></label>
    <?php endif; ?>
    <label for="password">Password <input type="password" id="password" name="password" required <?= $first_run ? 'autofocus' : '' ?>
      autocomplete="<?= $first_run ? 'new-password' : 'current-password' ?>"></label>
    <?php if ($first_run): ?>
      <label for="confirm">Type it again <input type="password" id="confirm" name="confirm" required autocomplete="new-password"></label>
    <?php endif; ?>
    <button type="submit"><?= $first_run ? 'Save password' : 'Sign in' ?></button>
  </form>
</main>
</body>
</html>
