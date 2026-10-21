<?php
// Job Skill Fitting: open jobs already found, ranked by the skills you have. Same markup as the desktop Job Finder's
// templates/job-skill-fitting.html.

declare(strict_types=1);
require_once APP_ROOT . '/includes/skill_fitting.php';

try {
    [$skills, $jobs, $weights] = skill_fitted_jobs();
    $error = '';
} catch (Throwable $problem) {
    error_log('Skill fitting: ' . $problem->getMessage());
    [$skills, $jobs, $weights, $error] = [[], [], [], 'The jobs could not be read from the database.'];
}

$title = 'Job Skill Fitting';
$page = 'skill-fitting';
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <section class="company-section">
    <p class="section-intro">Open jobs Job Finder has already found, ranked by how many of the skills each listing names are skills you have. Job titles are not used.</p>
    <?php if ($error !== ''): ?><p><?= h($error) ?></p>
    <?php elseif (!$skills): ?><p>Add your skills on the Dashboard to see how well jobs fit them.</p>
    <?php else: ?>
    <details class="skill-weights">
      <summary>Skill weights</summary>
      <p class="field-help">Core skills count triple, Nice to have count half. Used here and in the Search page's Job Fit Minus Titles sort; the regular Job Fit is not changed.</p>
      <form action="/job-skill-fitting/weights" method="post"><?= csrf_field() ?>
        <?php foreach ($skills as $skill): ?><label><?= h($skill) ?> <select name="weight-<?= h($skill) ?>"><?php foreach (SKILL_WEIGHT_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= (($weights[$skill] ?? 'normal') === $key) ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        <?php endforeach; ?>
        <p><button class="bordered-button primary-action" type="submit">Save Weights</button></p>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($error !== '' || !$skills): ?>
    <?php elseif (!$jobs): ?><p>No saved listing names enough skills to judge yet.</p>
    <?php else: ?>
    <div class="saved-job-grid"><?php foreach ($jobs as $job): ?>
      <article class="saved-job-card">
        <p class="summary-line summary-title"><strong><?= (int) $job['score'] ?>% fit</strong> <a href="<?= h($job['career_url'] ?: $job['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($job['career_job_title'] ?: 'Unknown Job') ?></a> at <?= h($job['name'] ?: 'Unknown Company') ?></p>
        <p class="summary-line summary-facts"><span><?= $job['city'] ? h($job['city']) . ', ' : '' ?><?= h($job['state'] ?: 'Location unknown') ?></span><?php if ($job['work_arrangement']): ?><span><?= h($job['work_arrangement']) ?></span><?php endif; ?></p>
        <p class="summary-line"><strong>You have:</strong> <?= h(implode(', ', $job['matched']) ?: 'none') ?></p>
        <p class="summary-line"><strong>Missing:</strong> <?= h(implode(', ', $job['missing']) ?: 'none') ?></p>
      </article><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
