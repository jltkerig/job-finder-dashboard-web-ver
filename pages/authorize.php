<?php
// Claude's sign-in to the Résumé Builder connector sends you here. Allowing it takes your Job Finder password
// (with the sign-in page's lockout), even when you are already signed in. See includes/connector.php.

declare(strict_types=1);
require_once APP_ROOT . '/includes/connector.php';

start_session();
send_security_headers("'none'");
$keys = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope'];
$params = [];
foreach ($keys as $key) {
    $value = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST[$key] ?? null) : ($_GET[$key] ?? null);
    if (is_string($value) && $value !== '') {
        $params[$key] = mb_substr($value, 0, 2000);
    }
}
[$client, $problem] = check_authorize_request($params);
$error = '';

if ($client && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['decision'] ?? '') !== 'allow') {
        redirect(redirect_with($params['redirect_uri'], ['error' => 'access_denied', 'state' => $params['state'] ?? null]));
    }
    $error = check_password((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? '')) ?? '';
    if ($error === '') {
        redirect(issue_auth_code($params));
    }
}

$title = 'Connect Claude';
require APP_ROOT . '/templates/_login_top.php';
?>
<main class="login-box">
  <h1>Connect Claude</h1>
<?php if (!$client): ?>
  <p class="error"><?= h($problem) ?></p>
  <p><a href="/resume-builder#connect-claude">Résumé Builder</a></p>
<?php else: ?>
  <p class="sub"><strong><?= h($client['client_name']) ?></strong> wants to use Résumé Builder: read your profile, saved jobs,
    résumé, references, documents and writing rules, and save résumés and cover letters here.</p>
  <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="/oauth/authorize">
    <?= csrf_field() ?>
<?php foreach ($params as $key => $value): ?>
    <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>" />
<?php endforeach; ?>
    <label>Username <input type="text" name="username" value="admin" autocomplete="username" required /></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" required autofocus /></label>
    <button type="submit" name="decision" value="allow">Allow</button>
    <p style="text-align:center;margin-top:14px"><button type="submit" name="decision" value="deny" formnovalidate
      style="background:none;box-shadow:none;color:#667085;padding:0;width:auto">Don't allow</button></p>
  </form>
  <p class="sub" style="margin-top:18px">It will be sent back to <?= h(parse_url($params['redirect_uri'], PHP_URL_HOST)) ?>.
    You can disconnect it any time in Résumé Builder.</p>
<?php endif; ?>
</main>
</body>
</html>
