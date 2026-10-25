<?php
// The Dashboard's filters: the Search page's Results filters, for Saved Jobs and Applied Jobs (the same markup as the
// desktop app's templates/_dash_filter.html; the web version has no Apply Queue). static/js/dashboard-filters.js reads
// the data-* attributes dash_item_attrs() puts on every job card.

declare(strict_types=1);

/** The data-* attributes the filters read from one job card. */
function dash_item_attrs(array $c): string
{
    $fit = $c['job_fit'] ?? null;
    $fit_score = $fit && $fit['score'] !== null ? (string) $fit['score'] : '';
    $fit_count = $fit ? count($fit['matched'] ?? []) + count($fit['missing'] ?? []) : 0;
    $attrs = [
        'source' => $c['source_type'] ?: 'Brave Search',
        'company' => (string) ($c['name'] ?? ''),
        'job' => (string) ($c['career_job_title'] ?? ''),
        'state' => (string) ($c['state'] ?? ''),
        'city' => (string) ($c['city'] ?? ''),
        'distance' => $c['distance_miles'] !== null && $c['distance_miles'] !== '' ? (string) $c['distance_miles'] : '99999',
        'status' => strtolower((string) ($c['job_open_status'] ?: 'Unknown')),
        'work-arrangement' => (string) ($c['work_arrangement'] ?? ''),
        'career-credibility' => (string) ((int) ($c['career_credibility'] ?? 0)),
        'usa-credibility' => (string) ((int) ($c['usa_credibility'] ?? 0)),
        'skill-fit' => isset($c['skill_fit']['score']) ? (string) $c['skill_fit']['score'] : '',
        'job-fit' => $fit_score,
        'fit-count' => (string) $fit_count,
        'schedule' => (string) ($c['details']['schedule'] ?? ''),
        'found' => (string) ($c['date_found'] ?? ''),
        'applied' => (string) ($c['applied_on'] ?? ''),
        'app-status' => (string) ($c['application_status'] ?? ''),
    ];
    $out = [];
    foreach ($attrs as $name => $value) {
        $out[] = 'data-' . $name . '="' . h($value) . '"';
    }
    return implode(' ', $out);
}

/** The Low / Medium / High score guide above a list. $key keeps the tooltip ids unique. */
function dash_legend(string $key): string
{
    $k = h($key);
    return '<div class="credibility-legend" aria-label="Credibility score guide"><strong>Scoring:</strong><ul>'
        . '<li class="legend-item" tabindex="0" aria-describedby="' . $k . '-low-help"><i class="legend-dot low"></i>Low<small id="' . $k . '-low-help" class="credibility-tip" role="tooltip">0–40%: limited evidence.</small></li>'
        . '<li class="legend-item" tabindex="0" aria-describedby="' . $k . '-medium-help"><i class="legend-dot medium"></i>Medium<small id="' . $k . '-medium-help" class="credibility-tip" role="tooltip">50–70%: some evidence.</small></li>'
        . '<li class="legend-item" tabindex="0" aria-describedby="' . $k . '-high-help"><i class="legend-dot high"></i>High<small id="' . $k . '-high-help" class="credibility-tip" role="tooltip">80–100%: stronger evidence.</small></li>'
        . '</ul><a href="/credibility-scores">How are scores calculated?</a></div>';
}

/**
 * One filter bar. $key: saved or applied. $target: the CSS selector of the list the filters work on.
 * $items: that list's jobs (for the Source choices). $extra: more controls, as HTML.
 */
function dash_toolbar(string $key, string $target, string $item_selector, array $items, string $extra = ''): string
{
    $k = h($key);
    $sources = [];
    foreach ($items as $c) {
        $sources[$c['source_type'] ?: 'Brave Search'] = true;
    }
    ksort($sources);
    $source_options = '';
    foreach (array_keys($sources) as $label) {
        $source_options .= '<option value="' . h($label) . '">' . h($label) . '</option>';
    }
    $app_status = $key === 'applied'
        ? '<div class="filter-box"><label for="applied-app-status">Application Status</label><select id="applied-app-status" data-f="appStatus"><option value="all">All stages</option><option value="Applied">Applied</option><option value="Talking With Recruiter">Talking With Recruiter</option><option value="Interview">Interview</option></select></div>'
        : '';
    $applied_sorts = $key === 'applied'
        ? '<option value="applied-newest">Date Applied (Newest First)</option><option value="applied-oldest">Date Applied (Oldest First)</option>'
        : '';
    return <<<HTML
<section class="results-toolbar dash-toolbar" id="{$k}-filters" data-target="{$target}" data-item="{$item_selector}" aria-label="{$k} filters">
      <div class="toolbar-title"><i class="filter-icon" aria-hidden="true">⌕</i><strong>Filters</strong>
      </div>
      <div class="filter-box"><label for="{$k}-text">Search</label><input id="{$k}-text" data-f="text" type="search" placeholder="Company, job, or city..." />
      </div>
      <div class="filter-box"><label for="{$k}-state">State</label><select id="{$k}-state" data-f="state"><option value="all">All</option></select>
      </div>
      <div class="filter-box"><label for="{$k}-arrangement">Work Location</label><select id="{$k}-arrangement" data-f="arrangement"><option value="all">All work locations</option><option value="Remote">Remote</option><option value="Hybrid">Hybrid</option><option value="Onsite">Onsite</option><option value="unknown">Unspecified</option></select>
      </div>
      <div class="filter-box"><label for="{$k}-status">Listing Status</label><select id="{$k}-status" data-f="status"><option value="all">All statuses</option><option value="open">Open only</option><option value="closed">Closed only</option><option value="unknown">Unknown only</option></select>
      </div>
      {$app_status}
      <div class="filter-box"><label for="{$k}-source">Source</label><select id="{$k}-source" data-f="source"><option value="all">All sources</option>{$source_options}</select>
      </div>
      <div class="filter-box"><label for="{$k}-credibility">Min. Career Credibility</label><select id="{$k}-credibility" data-f="credibility"><option value="-1">Show all scores</option><option value="3">30%+</option><option value="5">50%+</option><option value="7">70%+</option><option value="9">90%+</option></select>
      </div>
      <div class="filter-box"><label for="{$k}-schedule">Work Type</label><select id="{$k}-schedule" data-f="schedule"><option value="all">All work types</option><option value="part">Part-time</option><option value="full">Full-time</option><option value="contract">Contract</option><option value="freelance">Freelance / Gig</option><option value="unknown">Unspecified</option></select>
      </div>
      <div class="filter-box"><label for="{$k}-sort">Sort</label><select id="{$k}-sort" data-f="sort"><option value="default">Newest</option><option value="oldest">Oldest</option>{$applied_sorts}<option value="company">Company A–Z</option><option value="job">Job title A–Z</option><option value="distance">Distance</option><option value="job-fit">Job fit</option><option value="skill-fit">Job Fit Minus Titles</option><option value="career-credibility">Career credibility</option><option value="usa-credibility">USA credibility</option></select>
      </div>{$extra}
      <div class="results-count" id="{$k}-count" data-count>
      </div>
    </section>
HTML;
}
