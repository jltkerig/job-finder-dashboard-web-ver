<?php
// Résumé Builder. On the desktop it is its own app; on the web it will be part of this site, and Claude will
// connect to it by its address. Until then this page says what is coming.

declare(strict_types=1);

$title = 'Résumé Builder';
$page = 'resume';
$subtitle = 'Tailored résumés and cover letters from your Dashboard profile';
$sections = [
    'Your Résumés' => 'Résumés made from your Dashboard profile, saved here as PDFs.',
    'Tailor to a Job' => 'Pick a saved job and get a résumé and cover letter shaped to it.',
    'Connect Claude' => 'This site will act as a connector that Claude can add by its address, so Claude can read your profile and save résumés here. It will have its own secure sign-in.',
    'Design' => 'Fonts, layout and the résumé analyzer.',
];
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <div class="company-section">
<?php foreach ($sections as $heading => $text): ?>
    <details class="settings-panel history-collapse" open>
      <summary><h2><?= h($heading) ?> <span class="soon">Coming soon</span></h2><span class="summary-chevron" aria-hidden="true"></span></summary>
      <p class="field-help"><?= h($text) ?></p>
    </details>
<?php endforeach; ?>
  </div>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
