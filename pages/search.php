<?php
// The Search page: search form, results with filters, and each result's details. Same markup as the desktop
// Job Finder's templates/index.html.

declare(strict_types=1);
require_once APP_ROOT . '/includes/search/state.php';

tidy_closed_jobs();
$companies = get_companies();
$profile = get_user_profile();
add_job_fit($companies, $profile['skills']);
$latest_search_at = get_search_history(1)[0]['searched_at'] ?? null;
$skipped = recent_skips();
$run = search_state();

$title = 'Search';
$page = 'search';
$heading = 'Job Finder';
$subtitle = 'Version ' . APP_VERSION;
$subtitle_class = 'app-version';
$body_attrs = ' data-scraper-running="' . (search_running($run) ? 'true' : 'false') . '" data-scraper-mode="'
    . h(search_running($run) ? $run['mode'] : '') . '" data-work-preferences="' . json_attr($profile['work_preferences']) . '"';
$before_nav = "<div id=\"search-progress-bar\" class=\"search-progress-bar\" hidden>\n</div>";
$scripts = ['capture-upload.js', 'columns.js', 'top-picks.js'];

function credibility_class($score): string
{
    $score = (int) ($score ?? 0);
    return $score >= 8 ? 'high' : ($score >= 5 ? 'medium' : 'low');
}

function listing_kind(array $company): string
{
    $source = $company['source_type'] ?? '';
    $evidence = $company['details']['evidence'] ?? [];
    if (in_array($source, FEED_NAMES, true) || in_array($source, CAPTURE_SOURCES, true)) {
        return 'Job board posting';
    }
    if ($source === 'Employer careers' || in_array('JobPosting data', $evidence, true) || in_array('job detail page', $evidence, true)) {
        return 'Direct job posting';
    }
    return 'Possible lead';
}

function fit_title(array $fit): string
{
    return $fit['reason'] . ($fit['matched'] ? ' Matched: ' . implode(', ', $fit['matched']) . '.' : '')
        . ($fit['missing'] ? ' Other page skills: ' . implode(', ', $fit['missing']) . '.' : '');
}

require APP_ROOT . '/templates/_top.php';
?>

  <main>
  <section class="company-section">
    <div class="search-panel">
      <div class="search-panel-heading">
        <div>
          <h2>Search for Jobs
          </h2>
        </div>
      </div>
      <form id="job-search-form" class="job-search-form">
        <div class="form-field"><label for="job-title">Job Titles</label>
          <p class="field-help">Separate multiple titles with commas. Related titles appear automatically after you pause typing.
          </p><input id="job-title" name="job_title" type="text" data-title-suggest="list" placeholder="e.g. Web Designer, UI Designer" value="<?= h(implode(', ', $profile['job_titles'])) ?>" required />
          <div id="job-title-suggestions" class="job-title-suggestions" hidden aria-live="polite">
          </div>
        </div>
        <div class="form-field city-search-field"><label for="search-city-input">City, Zip, Country, State</label>
          <p class="field-help">Add a city for a radius search, or enter a full state name for a statewide search. Statewide searches ignore the radius.
          </p>
          <div class="city-add-row"><input id="search-city-input" type="text" list="city-state-options" placeholder="e.g. Wilmington, DE" autocomplete="off" /><select id="search-city-radius" aria-label="Radius"><?php foreach (CITY_RADII as $radius): ?><option value="<?= $radius ?>"<?= $radius === 50 ? ' selected' : '' ?>><?= $radius ?> miles</option><?php endforeach; ?></select><button id="add-search-city" class="bordered-button secondary-action city-add-button" type="button">Add Location</button>
          </div>
          <div id="search-city-list" class="city-list city-list-below">
          </div>
        </div>
        <input id="search-state" name="state" type="hidden" value="<?= h($profile['state']) ?>" />
        <datalist id="city-state-options">
        </datalist>
        <div id="search-form-error" class="form-error" hidden role="alert">
        </div>
      </form>
      <div class="search-controls"><button id="start-search" class="bordered-button primary-action" type="button">Start Job Finder</button><button id="refresh-search" class="bordered-button secondary-action" type="button">Refresh Results</button>
        <div id="searching-status" class="searching-status" hidden aria-live="polite"><span class="searching-dot"></span><span id="activity-label">Searching</span><span class="searching-ellipsis" aria-hidden="true"></span>
        </div>
      </div>
      <details id="capture-upload" class="capture-prompt capture-upload">
        <summary>Import jobs from the Web Job Scraper</summary>
        <p>Choose the <code>jobs.json</code> files the extension saved (Desktop\web-job-scraper\searches, one folder per day and site). Jobs are filtered by your profile like a search, and ones you applied to are saved as Applied.</p>
        <form id="capture-form" class="capture-prompt-actions"><input type="file" name="captures[]" accept=".json,application/json" multiple required /><button class="bordered-button primary-action" type="submit">Import</button></form>
        <div id="capture-result" aria-live="polite"></div>
      </details>
    </div>

    <div class="section-heading-row">
      <h2>Results
      </h2>
    </div><?php if ($skipped): ?>
    <details class="skipped-results">
      <summary>Why were some pages skipped? (<?= count($skipped) ?> recent checks)
      </summary>
      <ul><?php foreach ($skipped as $item): ?>
        <li><strong><?= h($item['reason']) ?></strong><?php if ($item['title']): ?> · <?= h($item['title']) ?><?php endif; ?> <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer">Source</a>
        </li><?php endforeach; ?>
      </ul>
    </details><?php endif; ?>
    <?php if ($companies): ?>
    <section class="results-toolbar" aria-label="Results filters">
      <div class="toolbar-title"><span class="filter-icon" aria-hidden="true">⌕</span><strong>Filters</strong>
      </div>
      <div class="form-field compact-field"><label for="result-filter">Search Results</label><input id="result-filter" type="search" placeholder="Company, job, or city..." />
      </div>
      <div class="form-field compact-field"><label for="result-state-filter">State</label><select id="result-state-filter"><option value="all">All states</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-arrangement-filter">Work Location</label><select id="result-arrangement-filter"><option value="all">All work locations</option><option value="Remote">Remote</option><option value="Hybrid">Hybrid</option><option value="Onsite">Onsite</option><option value="unknown">Unspecified</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-status-filter">Listing Status</label><select id="result-status-filter"><option value="all">All statuses</option><option value="open">Open only</option><option value="closed">Closed only</option><option value="unknown">Unknown only</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-min-credibility" title="Only individual openings at 30%+ count toward the goal. Lower scores are hidden after a search or refresh; choose Show all scores to review them.">Min. Career Credibility</label><select id="result-min-credibility" title="Only individual openings at 30%+ count toward the goal. Lower scores are hidden after a search or refresh; choose Show all scores to review them."><option value="0">30%+ (default)</option><option value="-1">Show all scores</option><option value="3">30%+</option><option value="5">50%+</option><option value="7">70%+</option><option value="9">90%+</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-schedule-filter">Work Type</label><select id="result-schedule-filter"><option value="all">All work types</option><option value="part">Part-time</option><option value="full">Full-time</option><option value="contract">Contract</option><option value="freelance">Freelance / Gig</option><option value="unknown">Unspecified</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-new-filter">Found</label><select id="result-new-filter"><option value="all">All results</option><option value="new">New since last search</option></select>
      </div>
      <div class="form-field compact-field"><label for="result-sort">Sort</label><select id="result-sort"><option value="default">Newest</option><option value="company">Company A–Z</option><option value="job">Job title A–Z</option><option value="distance">Distance</option><option value="career-credibility">Career credibility</option><option value="usa-credibility">USA credibility</option></select>
      </div>
      <details class="display-options"><summary>Rows and Columns</summary>
      <div class="form-field compact-field compact-rows-field"><span class="field-label">Rows</span><label class="compact-rows-toggle"><input type="checkbox" id="compact-rows-toggle" checked /> Compact rows</label></div>
      <div class="form-field compact-field column-chooser-field"><span class="field-label" id="column-chooser-label">Columns</span>
        <div id="column-chooser" class="column-chooser-inline">
          <div class="column-options" role="group" aria-labelledby="column-chooser-label">
          </div><button id="column-show-all" class="bordered-button secondary-action" type="button">Show all</button>
        </div>
      </div>
      </details>
      <button id="top-picks-button" class="bordered-button" type="button" title="Rank the results shown and pick the 10 best to apply to">Top 10 Picks</button>
      <div class="results-count" id="results-count"><?= count($companies) ?> results
      </div>
    </section>
    <section id="top-picks" class="top-picks" hidden aria-labelledby="top-picks-heading">
      <div class="top-picks-head"><h2 id="top-picks-heading">Top 10 Picks</h2><button id="top-picks-close" class="bordered-button secondary-action" type="button">Close</button></div>
      <p class="field-help">Ranked by job fit, credibility and how close the job is, from the results shown (filters apply). Untick any you don't want, then build: they're kept on your Dashboard and one request for Claude is copied to make a résumé and cover letter for each.</p>
      <ol id="top-picks-list" class="top-picks-list"></ol>
      <div class="top-picks-actions"><span id="top-picks-status" class="field-help" role="status"></span><button id="top-picks-build" class="bordered-button primary-action" type="button">Build résumés and cover letters</button></div>
    </section>
    <div class="credibility-legend" aria-label="Credibility score guide"><strong>Scoring:</strong><span class="legend-item" tabindex="0" aria-describedby="credibility-low-help"><i class="legend-dot low"></i>Low<span id="credibility-low-help" class="credibility-tip" role="tooltip">0–40%: limited evidence.</span></span><span class="legend-item" tabindex="0" aria-describedby="credibility-medium-help"><i class="legend-dot medium"></i>Medium<span id="credibility-medium-help" class="credibility-tip" role="tooltip">50–70%: some evidence.</span></span><span class="legend-item" tabindex="0" aria-describedby="credibility-high-help"><i class="legend-dot high"></i>High<span id="credibility-high-help" class="credibility-tip" role="tooltip">80–100%: stronger evidence.</span></span><a href="/credibility-scores">How are scores calculated?</a>
    </div>

    <div class="table-wrapper">
      <table id="results-table" class="compact-rows">
        <thead>
          <tr><th>Save</th><th>Company</th><th>Job</th><th>Work Location</th><th>Domain</th><th>Career Credibility</th><th>Location</th><th>Distance</th><th>USA Credibility</th><th>Job Fit</th><th>Listing</th><th>Found</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
    <?php foreach ($companies as $company):
        $d = $company['details'];
        $id = (int) $company['id'];
        $listing_status = strtolower($company['job_open_status'] ?: 'Unknown');
        $employer_site = $d['employer_site'] ?? null;
        $domain = (string) $company['domain'];
        $domain_url = $domain === '' ? '' : (str_contains($domain, '://') ? $domain : "https://$domain/");
        $is_new = $latest_search_at && $company['date_found'] >= $latest_search_at ? 'true' : 'false';
        $is_feed = in_array($company['source_type'], FEED_NAMES, true);
        $career = $company['career_credibility'];
        $usa = $company['usa_credibility'];
        $schedule = (string) ($d['schedule'] ?? '');
        $salary = (string) ($d['salary'] ?? '');
        $listing_url = $employer_site && $company['career_url'] ? $company['career_url'] : ($company['source_url'] ?: $company['career_url']);
    ?>
      <tr class="result-row" data-company-id="<?= $id ?>" data-company="<?= h($company['name']) ?>" data-job="<?= h($company['career_job_title']) ?>" data-state="<?= h($company['state']) ?>" data-city="<?= h($company['city']) ?>" data-distance="<?= $company['distance_miles'] !== null ? h($company['distance_miles']) : 99999 ?>" data-status="<?= h($listing_status) ?>" data-work-arrangement="<?= h($company['work_arrangement']) ?>" data-career-credibility="<?= (int) $career ?>" data-usa-credibility="<?= (int) $usa ?>" data-job-fit="<?= $company['job_fit']['score'] ?? '' ?>" data-saved="<?= $company['is_kept'] ? 'true' : 'false' ?>" data-schedule="<?= h($schedule) ?>" data-search-run="<?= $is_new ?>" data-new="<?= $is_new ?>">
        <td data-label="Save"><button class="bordered-button keep-action<?= $company['is_kept'] ? ' is-saved' : '' ?>" type="button" data-company-id="<?= $id ?>"><?= $company['is_kept'] ? 'Saved' : 'Keep' ?></button></td>
        <td data-label="Company"><?= h($company['name'] ?: 'Unknown') ?></td>
        <td data-label="Job"><strong><?= h($company['career_job_title'] ?: 'Unknown') ?></strong><?php if ($schedule !== '' || $salary !== ''): ?><small class="job-meta"><?= h(ucwords(str_replace('_', ' ', strtolower($schedule)))) ?><?= $schedule !== '' && $salary !== '' ? ' · ' : '' ?><?= h($salary) ?></small><?php endif; ?><?php if (!empty($d['also_on'])): ?><small class="job-meta">Also on: <?= implode(', ', array_map(fn($l) => '<a href="' . h($l['url']) . '" target="_blank" rel="noopener noreferrer">' . h($l['site']) . '</a>', $d['also_on'])) ?></small><?php endif; ?></td>
        <td data-label="Work Location"<?= $company['work_arrangement'] ? '' : ' class="is-empty"' ?>><?= h($company['work_arrangement'] ?: '—') ?><?php if (!empty($d['remote_limited_to'])): ?><br><small><?= h(implode(', ', $d['remote_limited_to'])) ?> only</small><?php endif; ?></td>
        <td data-label="Domain"><?php if ($domain_url): ?><a href="<?= h($domain_url) ?>" target="_blank" rel="noopener noreferrer"<?= !empty($d['verification']) ? ' title="' . h($d['verification']) . '"' : '' ?>><?= h($domain) ?></a><?php else: ?>Unknown<?php endif; ?><?php if (!empty($d['verification'])): ?><br><small class="verification" title="Whether this posting was found on the company's own website or hiring board"><?= h($d['verification']) ?></small><?php endif; ?></td>
        <td data-label="Career Credibility"><a class="credibility-link" href="/credibility-scores" aria-label="How career credibility is calculated"><span class="credibility-badge credibility-<?= credibility_class($career) ?>"><?= (int) $career * 10 ?>%</span></a></td>
        <td data-label="Location"><?php if (!empty($d['location_unknown']) && !$company['state']): ?>Location unknown<?php else: ?><?= $company['city'] ? h($company['city']) . ', ' : '' ?><?= h($company['state'] ?: 'Unknown') ?><?php endif; ?></td>
        <td data-label="Distance"<?= $company['distance_miles'] === null ? ' class="is-empty"' : '' ?>><?= $company['distance_miles'] !== null ? number_format((float) $company['distance_miles'], 1) . ' mi' : '—' ?></td>
        <td data-label="USA Credibility"><a class="credibility-link" href="/credibility-scores" aria-label="How USA credibility is calculated"><span class="credibility-badge credibility-<?= credibility_class($usa) ?>"><?= (int) $usa * 10 ?>%</span></a></td>
        <td data-label="Job Fit"><span class="job-fit" tabindex="0" title="<?= h(fit_title($company['job_fit'])) ?>"><?= $company['job_fit']['score'] !== null ? $company['job_fit']['score'] . '%' : '?' ?></span></td><td data-label="Listing"><?php if ($company['source_url'] || $company['career_url']): ?><a class="inline-action-link" href="<?= h($listing_url) ?>" target="_blank" rel="noopener noreferrer"<?= $company['source_type'] ? ' title="' . h($company['source_type']) . '"' : '' ?>>View</a><?php if ($employer_site && $company['source_url']): ?> <small><a href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer" title="The listing this job was found on<?= $company['source_host'] ? ' (' . h($company['source_host']) . ')' : '' ?>">Source</a></small><?php endif; ?><?php if ($is_feed): ?> <small class="listing-feed"><?= h($company['source_type']) ?></small><?php endif; ?><?php else: ?>—<?php endif; ?></td>
        <td data-label="Found" class="found-cell" data-found="<?= h(gmdate('c', strtotime($company['date_found'] . ' UTC'))) ?>" title="Found <?= h(local_time($company['date_found'])) ?><?= $company['last_checked'] ? '. Last checked ' . h(local_time($company['last_checked'])) : '' ?>"><?= h(local_time($company['date_found'], 'M d')) ?><small class="found-time"><?= h(local_time($company['date_found'], 'h:i A')) ?></small><small class="found-age"><span class="age-long">on results </span><?= h(how_long($company['date_found'])) ?></small></td>
        <td data-label="Actions" class="row-actions">
            <div class="row-action-grid"><button class="bordered-button details-action" type="button" data-details-target="details-<?= $id ?>"><span class="btn-long">View </span>Details</button><button class="bordered-button apply-action" type="button" data-job-id="<?= $id ?>" data-job-title="<?= h($company['career_job_title']) ?>" data-company-name="<?= h($company['name']) ?>" title="Copy a request for a tailored résumé and cover letter, then open Claude to paste it.">Apply</button><button class="bordered-button reject-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name'] ?: 'this listing') ?>" title="Hide this job, but allow other jobs from this company.">Reject<span class="btn-long"> Listing</span></button><?php if (!$is_feed): ?><button class="bordered-button block-action destructive-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="bordered-button block-company-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button>
            </div></td>
      </tr>
      <tr id="details-<?= $id ?>" class="details-row" hidden><td colspan="13">
            <div class="details-panel">
              <section class="details-section">
                <h4>About This Listing</h4>
                <dl class="details-grid">
                  <div><dt>Source</dt><dd><?= h($company['source_type'] ?: 'Brave Search') ?></dd></div>
                  <div><dt>Type</dt><dd><?= h(listing_kind($company)) ?></dd></div>
                  <?php if (!empty($d['posted'])): ?><div><dt>Posted</dt><dd><?= h($d['posted']) ?></dd></div><?php endif; ?>
                  <?php if (!empty($d['location'])): ?><div><dt>Listing location</dt><dd><?= h($d['location']) ?></dd></div><?php endif; ?>
                  <?php if ($employer_site): ?><div><dt>Employer site</dt><dd><a href="<?= h($employer_site['careers_url'] ?? '' ?: 'https://' . $employer_site['domain'] . '/') ?>" target="_blank" rel="noopener noreferrer"><?= h($employer_site['domain']) ?></a> <small>(<?= h($employer_site['method'] ?? '') ?><?= empty($employer_site['careers_url']) ? '; no careers page found' : '' ?>)</small></dd></div><?php endif; ?>
                  <?php if (!empty($d['verification'])): ?><div><dt>Verification</dt><dd><?= h($d['verification']) ?></dd></div><?php endif; ?>
                  <div><dt>Application status</dt><dd><?= h($company['is_kept'] ? $company['application_status'] : 'None') ?></dd></div>
                </dl>
              </section>
              <?php if (!empty($d['evidence'])): ?>
              <section class="details-section">
                <h4>Why This Result</h4>
                <ul class="details-reasons"><?php foreach ($d['evidence'] as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?><?php if (!empty($d['matched_title'])): ?><li>Matched <?= h($d['matched_title']) ?></li><?php endif; ?></ul>
              </section>
              <?php endif; ?>
              <section class="details-section">
                <h4>Dates</h4>
                <dl class="details-grid">
                  <div><dt>Found</dt><dd><?= h(local_time($company['date_found']) ?: 'Unknown') ?></dd></div>
                  <div><dt>Last verified</dt><dd><?= h(local_time($company['last_checked']) ?: 'Never') ?></dd></div>
                  <?php if ($company['result_updated_at']): ?><div><dt>Updated</dt><dd><?= h(local_time($company['result_updated_at'])) ?></dd></div><?php endif; ?>
                </dl>
              </section>
              <?php if ($company['notes']): ?><section class="details-section details-notes"><h4>Notes</h4><p><?= h($company['notes']) ?></p></section><?php endif; ?>
              <div class="details-footer">
                <div class="details-links"><?php if ($company['source_url']): ?><a href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer">Original source</a><?php endif; ?><?php if (!empty($d['source']) && $d['source'] !== $company['source_url']): ?><a href="<?= h($d['source']) ?>" target="_blank" rel="noopener noreferrer">Discovery source</a><?php endif; ?></div>
                <div class="details-blocks"><label class="reject-reason-label">If rejecting, why? <select class="reject-reason"><option value="other">Other reason</option><option value="wrong_role">Wrong role</option><option value="wrong_location">Wrong location</option><option value="not_a_job">Not a job</option><option value="duplicate">Duplicate</option></select></label><?php if (!$is_feed): ?><button class="bordered-button block-action destructive-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="bordered-button block-company-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button></div>
              </div>
            </div></td>
          </tr>
    <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-dashboard">
      <h3>No Search Results Yet
      </h3>
      <p>Enter a job title and City, State above, then start Job Finder.
      </p>
    </div><?php endif; ?>
  </section>
</main>

  <script id="saved-profile-cities" type="application/json"><?= json_script($profile['cities']) ?></script>
  <script id="saved-profile-titles" type="application/json"><?= json_script($profile['job_titles']) ?></script>
<?php
$after_main = '<div id="loading-panel" class="loading-panel" hidden aria-live="polite">
  <div class="loading-card">
    <div class="color-spinner" aria-hidden="true">
    </div><strong id="loading-title">Working…</strong><span id="loading-detail"></span><button id="loading-stop" class="bordered-button secondary-action" type="button" hidden>Stop</button>
  </div>
</div>';
require APP_ROOT . '/templates/_bottom.php';
