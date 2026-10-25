<?php
// The Search page: search form, results with filters, and each result's details. Same markup as the desktop
// Job Finder's templates/index.html.

declare(strict_types=1);
require_once APP_ROOT . '/includes/search/state.php';

tidy_closed_jobs();
$companies = get_companies();
$companies = hide_dashboard_jobs($companies, $companies);  // saved, applied and queued jobs live on the Dashboard
$profile = get_user_profile();
add_job_fit($companies, $profile['skills']);
require_once APP_ROOT . '/includes/skill_fitting.php';
add_skill_fit($companies, $profile['skills'], load_skill_weights());  // for the Job Fit Minus Titles sort
$latest_search_at = get_search_history(1)[0]['searched_at'] ?? null;
$run = search_state();
$source_names = array_values(array_unique(array_map(fn($c) => $c['source_type'] ?: 'Brave Search', $companies)));
sort($source_names);

$title = 'Search';
$page = 'search';
$heading = 'Job Finder';
$subtitle = 'Version ' . APP_VERSION;
$subtitle_class = 'app-version';
$body_attrs = ' data-scraper-running="' . (search_running($run) ? 'true' : 'false') . '" data-scraper-mode="'
    . h(search_running($run) ? $run['mode'] : '') . '" data-work-preferences="' . json_attr($profile['work_preferences']) . '"';
$before_nav = '<div id="search-progress-bar" class="search-progress-bar" hidden></div>';
$scripts = ['capture-upload.js', 'columns.js', 'job-title-bubbles.js', 'reject-reason.js', 'top-picks-facts.js', 'top-picks.js', 'details-all.js', 'header-theme.js', 'search-folds.js', 'listing-notes.js'];

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
    <div class="panel search-panel">
      <div class="search-panel-heading">
        <h2>Search for Jobs</h2>
      </div>
      <form id="job-search-form" class="job-search-form">
        <details class="form-field job-title-field search-fold" id="fold-titles" data-list="#job-title-list" data-empty="Add the jobs you want" open>
          <summary>
            <h3>Job Titles</h3>
            <output class="fold-count"></output><small class="fold-glance"></small><i class="summary-chevron" aria-hidden="true"></i>
          </summary>
          <div class="fold-inside">
            <label for="job-title-add">Job Titles</label>
            <p class="field-help">Type a title and press Enter or Add Title; use commas to add several at once. Click × on a chip to remove it, or + on a suggestion to add it. Leave empty to find any jobs nearby.</p>
            <input class="job-title-store js-job-title" name="job_title" type="hidden" value="<?= h(implode(', ', $profile['job_titles'])) ?>" />
            <div class="city-add-row">
              <input id="job-title-add" type="text" data-title-suggest="single" maxlength="120" placeholder="e.g. Web Designer" autocomplete="off" aria-label="Add a job title" /><button class="bordered-button" id="add-job-title" type="button">Add Title</button>
            </div>
            <div id="job-title-list" class="city-list city-list-below"></div>
            <div id="job-title-suggestions" class="job-title-suggestions" hidden aria-live="polite"></div>
          </div>
        </details>
        <details class="form-field city-search-field search-fold" id="fold-places" data-list="#search-city-list" data-empty="Add a city, zip, country or state">
          <summary>
            <h3>City, Zip, Country, State</h3>
            <output class="fold-count"></output><small class="fold-glance"></small><i class="summary-chevron" aria-hidden="true"></i>
          </summary>
          <div class="fold-inside">
            <label for="search-city-input">City, Zip, Country, State</label>
            <p class="field-help">A city searches around it; a full state name searches the whole state.</p>
            <div class="city-add-row">
              <input id="search-city-input" type="text" list="city-state-options" placeholder="e.g. Wilmington, DE" autocomplete="off" /><select id="search-city-radius" aria-label="Radius"><?php foreach (CITY_RADII as $radius): ?><option value="<?= $radius ?>"<?= $radius === 50 ? ' selected' : '' ?>><?= $radius ?> miles</option><?php endforeach; ?></select><button id="add-search-city" class="bordered-button" type="button">Add Location</button>
            </div>
            <div id="search-city-list" class="city-list city-list-below"></div>
          </div>
        </details>
        <input id="search-state" name="state" type="hidden" value="<?= h($profile['state']) ?>" />
        <datalist id="city-state-options"> </datalist>
        <div id="search-form-error" class="form-error" hidden role="alert"></div>
      </form>
      <div class="search-controls">
        <button class="primary-button start-search js-start-search" type="button">Start Job Finder</button><button id="refresh-search" class="bordered-button" type="button">Refresh Results</button>
        <div id="searching-status" class="searching-status" hidden aria-live="polite"><i class="searching-dot"></i><output id="activity-label">Searching</output><i class="searching-ellipsis" aria-hidden="true"></i></div>
      </div>
      <details id="capture-upload" class="capture-prompt capture-upload">
        <summary>Import jobs from the Web Job Scraper</summary>
        <p>Choose the <code>jobs.json</code> files the extension saved (Desktop\web-job-scraper\searches, one folder per day and site). Jobs are filtered by your profile like a search, and ones you applied to are saved as Applied.</p>
        <form id="capture-form" class="capture-prompt-actions"><input type="file" name="captures[]" accept=".json,application/json" multiple required /><button class="primary-button" type="submit">Import</button></form>
        <div id="capture-result" aria-live="polite"></div>
      </details>
    </div>

    <details class="results-collapse history-collapse" id="results-panel" open>
      <summary class="section-heading-row results-summary">
        <h2>Results</h2>
        <i class="summary-chevron" aria-hidden="true"></i>
      </summary>
    <?php if ($companies): ?>
    <section class="results-toolbar" aria-label="Results filters">
      <div class="toolbar-title">
        <i class="filter-icon" aria-hidden="true">⌕</i>
        <h3>Filters</h3>
      </div>
      <div class="filter-box"><label for="result-filter">Search Results</label><input id="result-filter" type="search" placeholder="Company, job, or city..." /></div>
      <div class="filter-box"><label for="result-state-filter">State</label><select id="result-state-filter"><option value="all">All states</option></select></div>
      <div class="filter-box"><label for="result-arrangement-filter">Work Location</label><select id="result-arrangement-filter"><option value="all">All work locations</option><option value="Remote">Remote</option><option value="Hybrid">Hybrid</option><option value="Onsite">Onsite</option><option value="unknown">Unspecified</option></select></div>
      <div class="filter-box"><label for="result-status-filter">Listing Status</label><select id="result-status-filter"><option value="all">All statuses</option><option value="open">Open only</option><option value="closed">Closed only</option><option value="unknown">Unknown only</option></select></div>
      <div class="filter-box"><label for="result-source-filter">Source</label><select id="result-source-filter"><option value="all">All sources</option><?php foreach ($source_names as $source_name): ?><option value="<?= h($source_name) ?>"><?= h($source_name) ?></option><?php endforeach; ?></select></div>
      <div class="filter-box"><label for="result-min-credibility" title="Only individual openings at 30%+ count toward the goal. Lower scores are hidden after a search or refresh; choose Show all scores to review them.">Min. Career Credibility</label><select id="result-min-credibility" title="Only individual openings at 30%+ count toward the goal. Lower scores are hidden after a search or refresh; choose Show all scores to review them."><option value="0">30%+ (default)</option><option value="-1">Show all scores</option><option value="3">30%+</option><option value="5">50%+</option><option value="7">70%+</option><option value="9">90%+</option></select></div>
      <div class="filter-box"><label for="result-schedule-filter">Work Type</label><select id="result-schedule-filter"><option value="all">All work types</option><option value="part">Part-time</option><option value="full">Full-time</option><option value="contract">Contract</option><option value="freelance">Freelance / Gig</option><option value="unknown">Unspecified</option></select></div>
      <div class="filter-box"><label for="result-new-filter">Found</label><select id="result-new-filter"><option value="all">All results</option><option value="new">New since last search</option></select></div>
      <div class="filter-box"><label for="result-sort">Sort</label><select id="result-sort"><option value="default">Newest</option><option value="company">Company A–Z</option><option value="job">Job title A–Z</option><option value="distance">Distance</option><option value="job-fit">Job fit</option><option value="skill-fit">Job Fit Minus Titles</option><option value="career-credibility">Career credibility</option><option value="usa-credibility">USA credibility</option></select></div>
      <div class="filter-actions"><button id="details-all-button" class="bordered-button" type="button" title="Open or close the Details of every result shown and of the Top 10 picks">Open All Details</button></div>
      <details class="display-options">
        <summary>Rows and Columns<i class="summary-chevron" aria-hidden="true"></i></summary>
        <div class="filter-options">
          <span class="field-label">Rows</span><label class="compact-rows-toggle"><input type="checkbox" id="compact-rows-toggle" checked /> Compact rows</label>
        </div>
        <div class="filter-options">
          <span class="field-label" id="column-chooser-label">Columns</span>
          <div id="column-chooser" class="column-chooser-inline">
            <div class="column-options" role="group" aria-labelledby="column-chooser-label"></div>
            <button class="bordered-button column-show-all js-column-show-all" type="button">Show all</button>
          </div>
        </div>
      </details>
      <div class="results-count" id="results-count"><?= count($companies) ?> results</div>
    </section>
    <section id="top-picks" class="top-picks" aria-labelledby="top-picks-heading">
      <details class="top-picks-collapse" id="top-picks-panel" open>
        <summary class="top-picks-head">
          <h3 id="top-picks-heading">Top 10 Picks</h3>
          <button class="bordered-button top-picks-button js-top-picks-button" type="button" title="Rank the results shown and pick the 10 best to apply to">Top 10 Picks</button><i class="summary-chevron" aria-hidden="true"></i>
        </summary>
        <p class="field-help">Ranked by job fit, credibility and how close the job is, from the results shown (filters apply). Untick any you don't want, then build: they're kept on your Dashboard and one request for Claude is copied to make a resume and cover letter for each.</p>
        <ol id="top-picks-list" class="top-picks-list"></ol>
        <div class="top-picks-actions"><output id="top-picks-status" class="field-help" role="status"></output><button id="top-picks-build" class="primary-button" type="button">Build resumes and cover letters</button></div>
      </details>
    </section>
    <details class="listings-collapse history-collapse" id="listings-panel" open>
      <summary class="listings-summary">
        <h3>Listings</h3>
        <i class="summary-chevron" aria-hidden="true"></i>
      </summary>
      <div class="credibility-legend" aria-label="Credibility score guide">
        <strong>Scoring:</strong>
        <ul>
          <li class="legend-item" tabindex="0" aria-describedby="credibility-low-help"><i class="legend-dot low"></i>Low<small id="credibility-low-help" class="credibility-tip" role="tooltip">0–40%: limited evidence.</small></li>
          <li class="legend-item" tabindex="0" aria-describedby="credibility-medium-help"><i class="legend-dot medium"></i>Medium<small id="credibility-medium-help" class="credibility-tip" role="tooltip">50–70%: some evidence.</small></li>
          <li class="legend-item" tabindex="0" aria-describedby="credibility-high-help"><i class="legend-dot high"></i>High<small id="credibility-high-help" class="credibility-tip" role="tooltip">80–100%: stronger evidence.</small></li>
        </ul>
        <a href="/credibility-scores">How are scores calculated?</a>
      </div>

      <div class="table-wrapper">
        <table class="compact-rows results-table js-results-table">
          <thead>
            <tr><th>Save</th><th>Company</th><th>Job</th><th>Work Location</th><th>Domain</th><th>Career Credibility</th><th>Location</th><th>Distance</th><th>USA Credibility</th><th>Job Fit</th><th>Listing</th><th>Found</th><th>Actions</th></tr>
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
      <tr class="result-row" data-company-id="<?= $id ?>" data-source="<?= h($company['source_type'] ?: 'Brave Search') ?>" data-company="<?= h($company['name']) ?>" data-job="<?= h($company['career_job_title']) ?>" data-state="<?= h($company['state']) ?>" data-city="<?= h($company['city']) ?>" data-distance="<?= $company['distance_miles'] !== null ? h($company['distance_miles']) : 99999 ?>" data-status="<?= h($listing_status) ?>" data-work-arrangement="<?= h($company['work_arrangement']) ?>" data-career-credibility="<?= (int) $career ?>" data-usa-credibility="<?= (int) $usa ?>" data-job-fit="<?= $company['job_fit']['score'] ?? '' ?>" data-fit-count="<?= count($company['job_fit']['matched']) + count($company['job_fit']['missing']) ?>" data-skill-fit="<?= $company['skill_fit']['score'] ?? '' ?>" data-saved="<?= $company['is_kept'] ? 'true' : 'false' ?>" data-schedule="<?= h($schedule) ?>" data-search-run="<?= $is_new ?>" data-new="<?= $is_new ?>">
        <td data-label="Save"><button class="js-keep-action <?= $company['is_kept'] ? 'success-button' : 'bordered-button' ?>" type="button" data-company-id="<?= $id ?>"><?= $company['is_kept'] ? 'Saved' : 'Keep' ?></button><button class="bordered-button js-apply-action" data-icon="📨" type="button" data-job-id="<?= $id ?>" data-job-title="<?= h($company['career_job_title']) ?>" data-company-name="<?= h($company['name']) ?>" title="Copy a request for a tailored resume and cover letter, then open Claude to paste it.">Apply</button></td>
        <td data-label="Company"><?= h($company['name'] ?: 'Unknown') ?></td>
        <td data-label="Job"><strong><?= h($company['career_job_title'] ?: 'Unknown') ?></strong><?= !empty($d['easy_apply']) ? easy_apply_badge() : '' ?><small class="job-meta job-open-state"><i class="status-dot <?= $listing_status === 'closed' ? 'closed' : ($listing_status === 'open' ? 'open' : 'unknown') ?>" role="img" aria-label="<?= $listing_status === 'open' ? 'Open' : ($listing_status === 'closed' ? 'Closed' : 'Status unknown') ?>" tabindex="0" data-tooltip="<?= $listing_status === 'open' ? 'This listing appears to still be active.' : ($listing_status === 'closed' ? 'This listing appears to no longer be active.' : 'The current listing status could not be confirmed.') ?>"></i></small><?php if ($schedule !== '' || $salary !== ''): ?><small class="job-meta job-pay"><?= h(ucwords(str_replace('_', ' ', strtolower($schedule)))) ?><?= $schedule !== '' && $salary !== '' ? ' · ' : '' ?><?= h($salary) ?></small><?php endif; ?><?php if (!empty($d['also_on'])): ?><small class="job-meta">Also on: <?= implode(', ', array_map(fn($l) => '<a href="' . h($l['url']) . '" target="_blank" rel="noopener noreferrer">' . h($l['site']) . '</a>', $d['also_on'])) ?></small><?php endif; ?></td>
        <td data-label="Work Location"<?= $company['work_arrangement'] ? '' : ' class="is-empty"' ?>><?= h($company['work_arrangement'] ?: '—') ?><?php if (!empty($d['remote_limited_to'])): ?><small class="remote-states"><?= h(implode(', ', $d['remote_limited_to'])) ?> only</small><?php endif; ?></td>
        <td data-label="Domain"><?php if ($domain_url): ?><a href="<?= h($domain_url) ?>" target="_blank" rel="noopener noreferrer"<?= !empty($d['verification']) ? ' title="' . h($d['verification']) . '"' : '' ?>><?= h($domain) ?></a><?php else: ?>Unknown<?php endif; ?><?php if (!empty($d['verification'])): ?><br><small class="verification" title="Whether this posting was found on the company's own website or hiring board"><?= h($d['verification']) ?></small><?php endif; ?></td>
        <td data-label="Career Credibility"><a class="credibility-link" href="/credibility-scores" aria-label="How career credibility is calculated"><span class="badge credibility-badge credibility-<?= credibility_class($career) ?>"><?= (int) $career * 10 ?>%</span></a></td>
        <td data-label="Location"><?php if (!empty($d['location_unknown']) && !$company['state']): ?>Location unknown<?php else: ?><?= $company['city'] ? h($company['city']) . ', ' : '' ?><?= h($company['state'] ?: 'Unknown') ?><?php endif; ?></td>
        <td data-label="Distance"<?= $company['distance_miles'] === null ? ' class="is-empty"' : '' ?>><?= $company['distance_miles'] !== null ? number_format((float) $company['distance_miles'], 1) . ' mi' : '—' ?></td>
        <td data-label="USA Credibility"><a class="credibility-link" href="/credibility-scores" aria-label="How USA credibility is calculated"><span class="badge credibility-badge credibility-<?= credibility_class($usa) ?>"><?= (int) $usa * 10 ?>%</span></a></td>
        <td data-label="Job Fit"><span class="badge job-fit" tabindex="0" title="<?= h(fit_title($company['job_fit'])) ?>"><?= $company['job_fit']['score'] !== null ? $company['job_fit']['score'] . '%' : '?' ?></span></td><td data-label="Listing"><?php if ($company['source_url'] || $company['career_url']): ?><?php if ($employer_site && $company['source_url']): ?><a class="bordered-button" href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer" title="The listing this job was found on<?= $company['source_host'] ? ' (' . h($company['source_host']) . ')' : '' ?>">Source <svg class="listing-arrow" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 17 17 7M8 7h9v9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></a> <?php endif; ?><a class="bordered-button js-listing-open" href="<?= h($listing_url) ?>" target="_blank" rel="noopener noreferrer"<?= $company['source_type'] ? ' title="' . h($company['source_type']) . '"' : '' ?>>Open <svg class="listing-arrow" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 17 17 7M8 7h9v9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></a><?php if ($is_feed): ?> <small class="listing-feed"><?= h($company['source_type']) ?></small><?php endif; ?><?php else: ?>—<?php endif; ?></td>
        <td data-label="Found" class="found-cell" data-found="<?= h(gmdate('c', strtotime($company['date_found'] . ' UTC'))) ?>" title="Found <?= h(local_time($company['date_found'])) ?><?= $company['last_checked'] ? '. Last checked ' . h(local_time($company['last_checked'])) : '' ?>"><span class="found-label">Found </span><?= h(local_time($company['date_found'], 'M d, Y')) ?><small class="found-time"><?= h(local_time($company['date_found'], 'h:i A')) ?></small><small class="found-age"><span class="age-long">on results </span><?= h(how_long($company['date_found'])) ?></small></td>
        <td data-label="Actions" class="row-actions">
            <div class="row-action-grid"><button class="bordered-button js-details-action" data-icon="🔍" type="button" data-details-target="details-<?= $id ?>"><span class="btn-long">View </span>Details</button><button class="destructive-button js-reject-action" data-icon="🚫" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name'] ?: 'this listing') ?>" title="Hide this job, but allow other jobs from this company.">Reject<span class="btn-long"> Listing</span></button><?php if (!$is_feed): ?><button class="destructive-button js-block-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="destructive-button js-block-company-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button>
            </div></td>
      </tr>
      <tr id="details-<?= $id ?>" class="details-row" hidden><td colspan="13">
            <div class="details-panel">
              <section class="details-section">
                <h4>About This Listing</h4>
                <dl class="details-grid">
                  <div><dt>Source</dt><dd><?php $source_name = $company['source_type'] ?: 'Brave Search'; ?><?php if ($company['source_url']): ?><a href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($source_name) ?></a><?php else: ?><?= h($source_name) ?><?php endif; ?></dd></div>
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
              <section class="details-section details-notes" data-company-id="<?= $id ?>"><h4>Notes</h4><p class="notes-view"><?php if ($company['notes']): ?><?= linkify($company['notes']) ?><?php else: ?><em>No notes yet.</em><?php endif; ?></p><textarea class="notes-input" rows="4" maxlength="5000" aria-label="Notes" placeholder="Notes and links (web addresses become clickable)" hidden><?= h($company['notes']) ?></textarea>
                <div class="notes-actions"><button class="bordered-button js-notes-edit" type="button">Edit</button><button class="primary-button js-notes-save" type="button" hidden>Save</button><output class="notes-status field-help" role="status"></output></div>
              </section>
              <div class="details-footer">
                <div class="details-links"><?php if ($company['source_url']): ?><a href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer">Original source</a><?php endif; ?><?php if (!empty($d['source']) && $d['source'] !== $company['source_url']): ?><a href="<?= h($d['source']) ?>" target="_blank" rel="noopener noreferrer">Discovery source</a><?php endif; ?></div>
                <div class="details-blocks"><?php if (!$is_feed): ?><button class="destructive-button js-block-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="destructive-button js-block-company-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button>
                </div>
              </div>
            </div></td>
          </tr>
    <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php else: ?>
    <div class="empty-dashboard">
      <h3>No Search Results Yet</h3>
      <p>Enter a job title and City, State above, then start Job Finder.</p>
    </div><?php endif; ?>
    </details>
  </section>
</main>

  <script id="saved-profile-cities" type="application/json"><?= json_script($profile['cities']) ?></script>
  <script id="saved-profile-titles" type="application/json"><?= json_script($profile['job_titles']) ?></script>
<?php
$after_main = '<div id="loading-panel" class="loading-panel" hidden aria-live="polite">
  <div class="loading-card">
    <div class="color-spinner" aria-hidden="true">
    </div><strong id="loading-title">Working…</strong><output id="loading-detail"></output>
    <div class="loading-actions"><button id="loading-stop" class="bordered-button" type="button" hidden>Stop</button></div>
  </div>
</div>';
require APP_ROOT . '/templates/_bottom.php';
