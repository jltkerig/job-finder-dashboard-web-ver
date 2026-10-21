<?php
// Tuning: what each source did in the last search, Brave Search use, and the recent searches. Same markup as the
// desktop Job Finder's templates/tuning.html, plus the Brave Search Usage panel. The search settings are on the
// Settings page.

declare(strict_types=1);
require_once APP_ROOT . '/includes/tuning.php';
require_once APP_ROOT . '/includes/search/brave.php';

$health = read_board_health();
if ($health) {
    $health['updated_text'] = date('M d, Y h:i A', strtotime($health['updated']));
}
$skipped = recent_skips();
$search_history = get_search_history();
$saved_titles = array_map('mb_strtolower', get_user_profile()['job_titles']);
$brave = brave_counts();
$reset = brave_next_reset();
$days = (int) ceil(($reset - time()) / 86400);

$title = 'Tuning';
$page = 'tuning';
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
    <details class="settings-panel history-collapse" id="skipped-pages" data-remember="jobFinder.skippedPagesOpen">
  <summary>
        <h2>Skipped Pages (<?= count($skipped) ?> Recent Checks)
        </h2><span class="summary-chevron" aria-hidden="true"></span>
      </summary>
      <?php if ($skipped): ?><ul class="skipped-list"><?php foreach ($skipped as $item): ?>
        <li><strong><?= h($item['reason']) ?></strong><?php if ($item['title']): ?> · <?= h($item['title']) ?><?php endif; ?> <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer">Source</a></li><?php endforeach; ?>
      </ul><?php else: ?><p class="field-help">Nothing was skipped in the recent searches.</p><?php endif; ?>
    </details>
  <details class="history-panel history-collapse" id="recent-searches" data-remember="jobFinder.recentSearchesOpen">
    <summary class="history-summary">
      <h2>Recent Searches
      </h2><span class="summary-chevron" aria-hidden="true"></span>
    </summary><?php if ($search_history): ?>
    <div class="history-list"><?php foreach ($search_history as $search): $is_saved = in_array(mb_strtolower($search['main_title']), $saved_titles, true); ?>
      <div class="history-item">
        <div class="history-main"><strong class="history-title" title="<?= h($search['job_title']) ?>"><?= h($search['main_title']) ?></strong><?php if ($search['other_titles']): ?><small class="history-titles"><?= h(implode(' • ', $search['other_titles'])) ?></small><?php endif; ?><span><?= h($search['state']) ?></span><?php if ($search['cities_json']): ?><small class="history-cities" data-cities="<?= h($search['cities_json'] ?: '[]') ?>"></small><?php endif; ?><small><?= h(local_time($search['searched_at'], 'M d, Y h:i A')) ?></small>
        </div>
        <div class="history-actions"><button class="bordered-button save-history-title" type="button" data-job-title="<?= h($search['main_title']) ?>"<?= $is_saved ? ' disabled' : '' ?>><?= $is_saved ? 'Saved' : 'Save Job Title' ?></button><button class="bordered-button search-again-button" type="button" data-job-title="<?= h($search['job_title']) ?>" data-state="<?= h($search['state']) ?>" data-cities="<?= h($search['cities_json'] ?: '[]') ?>">Search Again</button>
        </div>
      </div><?php endforeach; ?>
    </div><?php else: ?>
    <div class="empty-inline">No searches recorded yet.
    </div><?php endif; ?>
  </details>
  </div>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
