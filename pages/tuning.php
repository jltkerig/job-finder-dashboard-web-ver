<?php
// Tuning: what each source did in the last search, Brave Search use, and the search settings. Same markup as the
// desktop Job Finder's templates/tuning.html, plus the Brave Search Usage panel.

declare(strict_types=1);
require_once APP_ROOT . '/lib/tuning.php';
require_once APP_ROOT . '/lib/search/brave.php';

$values = read_tuning_settings();
$health = read_board_health();
if ($health) {
    $health['updated_text'] = date('M d, Y h:i A', strtotime($health['updated']));
}
$saved = !empty($_GET['saved']);
$error = (string) ($_GET['error'] ?? '');
$brave = brave_counts();
$reset = brave_next_reset();
$days = (int) ceil(($reset - time()) / 86400);

$title = 'Tuning';
$page = 'tuning';
$subtitle = 'Search settings and how each source did in the last search';
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <div class="company-section">
    <details class="settings-panel history-collapse" id="search-health" data-remember="jobFinder.boardHealthOpen">
  <summary>
        <h2>Board Health
        </h2><span class="summary-chevron" aria-hidden="true"></span>
      </summary>
  <?php if ($health): ?>
  <p class="field-help">The most recent <?= h($health['run']) ?> · <?= h($health['updated_text']) ?>. Employer boards and remote feeds are listed with what they produced.
      </p>
  <p class="field-help">"Blocked" means the site refused Job Finder (it does not try to get around that). "Down" means it did not answer or returned an error; it is tried again on the next search.
      </p>
  <div class="board-health-table" style="overflow-x:auto">
        <table class="score-rules">
          <thead>
            <tr><th>Source</th><th>Type</th><th>Status</th><th>Title matches</th><th>Saved</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
  <?php foreach ($health['boards'] as $board): $label = HEALTH_STATUSES[$board['status']] ?? $board['status']; ?>
    <tr><td><?= h($board['name']) ?></td><td><?= h($board['kind']) ?></td>
      <td><?= in_array($board['status'], ['blocked', 'down'], true) ? '<strong>' . h($label) . '</strong>' : h($label) ?></td>
      <td><?= (int) $board['matches'] ?></td><td><?= (int) $board['saved'] ?></td><td><?= h($board['detail']) ?></td>
            </tr>
  <?php endforeach; ?>
  </tbody>
        </table>
      </div>
  <?php else: ?>
  <p class="field-help">No search has recorded board results yet. Start a search and they will appear here.
      </p>
  <?php endif; ?>
    </details>
    <details class="settings-panel history-collapse" id="brave-usage" data-remember="jobFinder.braveUsageOpen">
  <summary>
        <h2>Brave Search Usage
        </h2><span class="summary-chevron" aria-hidden="true"></span>
      </summary>
  <p class="field-help">Every web search uses one of your Brave Search API credits. Credits reset at 00:00 UTC on the 1st of each month, and unused ones do not carry over.
      </p>
  <div class="board-health-table" style="overflow-x:auto">
        <table class="score-rules">
          <tbody>
      <tr><th>This month (<?= h(gmdate('F Y')) ?>)</th><td><strong><?= $brave['month'] ?></strong><?= brave_monthly() ? ' of ' . number_format(brave_monthly()) : '' ?> <?= plural($brave['month'], 'search', 'searches') ?></td></tr>
      <tr><th>Credits reset</th><td><?= h(gmdate('l, F j, Y', $reset)) ?> at 00:00 UTC (<?= h(date('M j, g:i a T', $reset)) ?>) · in <?= $days ?> <?= plural($days, 'day', 'days') ?></td></tr>
      <tr><th>All time</th><td><?= $brave['total'] ?> <?= plural($brave['total'], 'search', 'searches') ?><?= $brave['since'] ? ' since ' . h(date('M j, Y', strtotime($brave['since']))) : '' ?></td></tr>
      <tr><th>Testing limit</th><td><?= brave_used() ?> <?= brave_limit() ? 'of ' . brave_limit() . ' used' : 'used (no limit set)' ?>
        <form method="post" action="/tuning/settings" class="inline"><?= csrf_field() ?><button class="bordered-button secondary-action" type="submit" name="reset_brave" value="1">Reset testing count</button></form></td></tr>
    <?php foreach (array_reverse($brave['months'], true) as $month => $count): if ($month === gmdate('Y-m')) continue; ?>
      <tr><th><?= h(date('F Y', strtotime("$month-01"))) ?></th><td><?= (int) $count ?> <?= plural((int) $count, 'search', 'searches') ?></td></tr>
    <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <details class="settings-panel history-collapse" id="search-settings" data-remember="jobFinder.searchSettingsOpen"<?= $saved || $error !== '' ? ' data-open-now' : '' ?>>
  <summary>
        <h2>Search Settings
        </h2><span class="summary-chevron" aria-hidden="true"></span>
      </summary>
  <?php if ($saved): ?>
      <p class="field-help" role="status"><strong>Saved.</strong> The next search uses these settings.
      </p><?php endif; ?>
  <?php if ($error !== ''): ?>
      <p class="field-help" role="alert"><strong><?= h($error) ?></strong>
      </p><?php endif; ?>
  <form method="post" action="/tuning/settings">
    <?= csrf_field() ?>
    <?php foreach (TUNING_FIELDS as $key => [$label, $help, $kind, $low, $high, $default]): ?>
    <div class="form-field"><label for="tune-<?= h($key) ?>"><?= h($label) ?></label>
      <p class="field-help"><?= h($help) ?> (<?= $low ?>–<?= $high ?>, standard <?= $default ?>)
          </p>
      <input id="tune-<?= h($key) ?>" name="<?= h($key) ?>" type="number" step="<?= $kind === 'int' ? '1' : '0.1' ?>" min="<?= $low ?>" max="<?= $high ?>" value="<?= h($values[$key] ?? $default) ?>" required />
        </div>
    <?php endforeach; ?>
    <?php foreach (TUNING_SWITCHES as $key => [$label, $help, $default]): ?>
    <div class="form-field"><label class="switch-row"><input type="checkbox" name="<?= h($key) ?>"<?= ($values[$key] ?? $default) ? ' checked' : '' ?> /> <?= h($label) ?></label>
      <p class="field-help"><?= h($help) ?>
          </p>
        </div>
    <?php endforeach; ?>
    <button class="bordered-button primary-action" type="submit">Save settings</button>
  </form>
  <p class="field-help">Changes apply to the next search; a search that is running keeps the settings it started with.
      </p>
    </details>
  </div>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
