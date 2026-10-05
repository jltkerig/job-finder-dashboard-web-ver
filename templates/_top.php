<?php
// The start of every page: <head>, the menu and the page header. Set $title, $page, $heading (optional),
// $subtitle (optional), $body_attrs (optional, already escaped) before including it.
$menu = ['/' => ['Search', 'search'], '/dashboard' => ['Dashboard', 'dashboard'], '/resume-builder' => ['Résumé Builder', 'resume'],
    '/rejected-listings' => ['Settings', 'settings'], '/tuning' => ['Tuning', 'tuning']];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>" />
  <title><?= h($title) ?> | Job Finder</title>
  <link rel="stylesheet" href="/static/css/style.css?v=<?= APP_VERSION ?>" />
  <link rel="stylesheet" href="/assets/style.css?v=<?= APP_VERSION ?>" />
<?php foreach ($extra_css ?? [] as $sheet): ?>
  <link rel="stylesheet" href="<?= h($sheet) ?>?v=<?= APP_VERSION ?>" />
<?php endforeach; ?>
</head>
<body data-page="<?= h($page) ?>"<?= $body_attrs ?? '' ?>>
<?= $before_nav ?? '' ?>
<nav class="top-nav" aria-label="Main navigation">
  <div class="nav-inner"><?php foreach ($menu as $href => [$label, $key]): ?><a class="nav-link<?= $key === $page || ($key === 'settings' && $page === 'rejected-listings') ? ' active' : '' ?>" href="<?= $href ?>"><?= h($label) ?></a><?php endforeach; ?><a class="nav-link nav-signout" href="/logout">Sign out</a>
  </div>
</nav>
<header>
  <div class="header-content">
    <div>
      <h1><?= h($heading ?? $title) ?>
      </h1>
      <?php if (!empty($subtitle)): ?><p<?= !empty($subtitle_class) ? ' class="' . h($subtitle_class) . '"' : '' ?>><?= h($subtitle) ?></p><?php endif; ?>
    </div>
  </div>
</header>
