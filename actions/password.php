<?php
// Settings > Password: a new password needs the current one. Other signed-in browsers are signed out and Claude is
// disconnected (change_password).

declare(strict_types=1);

$error = check_password((string) (config()['login']['username'] ?? 'admin'), (string) ($_POST['current'] ?? ''));
if ($error !== null) {
    $error = str_starts_with($error, 'Too many') ? $error : 'Your current password is wrong.';
} else {
    $error = change_password((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
}
$_SESSION['password_notice'] = $error ?? 'saved';
redirect('/rejected-listings#password');
