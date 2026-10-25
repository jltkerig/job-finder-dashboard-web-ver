<?php
// The start of every page: <head>, the menu and the page header. Set $title, $page, $heading (optional),
// $subtitle (optional), $body_attrs (optional, already escaped) before including it.
$menu = ['/' => ['Search', 'search'], '/companies' => ['Companies', 'companies'], '/dashboard' => ['Dashboard', 'dashboard'], '/resume-builder' => ['Resume Builder', 'resume'],
    '/rejected-listings' => ['Settings', 'settings'], '/tuning' => ['Tuning', 'tuning'], '/static/design.html' => ['Design', 'design']];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow, noarchive" />
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>" />
  <title><?= h($title) ?> | Job Finder</title>
  <link rel="stylesheet" href="/static/css/tokens.css?v=<?= APP_VERSION ?>" />
  <link rel="stylesheet" href="/static/css/structure.css?v=<?= APP_VERSION ?>" />
  <link rel="stylesheet" href="/static/css/style.css?v=<?= APP_VERSION ?>" />
  <link rel="stylesheet" href="/static/css/animations.css?v=<?= APP_VERSION ?>" />
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>" />
<?php foreach ($extra_css ?? [] as $sheet): ?>
  <link rel="stylesheet" href="<?= h($sheet) ?>?v=<?= APP_VERSION ?>" />
<?php endforeach; ?>
</head>
<body data-page="<?= h($page) ?>"<?= $body_attrs ?? '' ?>>
<?= $before_nav ?? '' ?>
<nav class="top-nav" aria-label="Main navigation">
  <div class="nav-inner"><a class="nav-link" href="https://jkerig.com">Home</a><?php foreach ($menu as $href => [$label, $key]): ?><a class="nav-link<?= $key === $page || ($key === 'settings' && $page === 'rejected-listings') ? ' active' : '' ?>" href="<?= $href ?>"><?= h($label) ?></a><?php endforeach; ?><form method="post" action="/logout" class="nav-signout-form"><?= csrf_field() ?><button type="submit" class="nav-link quit-button nav-signout">Logout</button></form>
  </div>
</nav>
<header>
  <div class="header-content">
    <h1><?= h($heading ?? $title) ?></h1>
    <?php if (!empty($subtitle)): ?><p<?= !empty($subtitle_class) ? ' class="' . h($subtitle_class) . '"' : '' ?>><?= h($subtitle) ?></p><?php endif; ?>
  </div>
</header>
