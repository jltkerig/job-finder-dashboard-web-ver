<?php
// Tests for what the web version took over from the desktop Job Finder v1.1.286 - v1.1.300 (the Apply Queue and the
// desktop-only parts stay out). Included by run.php (it uses its check() and its throwaway database). Every job here
// is made up.

declare(strict_types=1);

// ---- Dashboard: Applied Jobs and the Search-style filters (v1.1.297, v1.1.300) ----
require_once APP_ROOT . '/templates/_dash_filter.php';

$job = ['name' => 'Sample & Co', 'career_job_title' => 'Web "Designer"', 'source_type' => '', 'state' => 'PA', 'city' => 'Harrisburg',
    'distance_miles' => 12.5, 'job_open_status' => 'Open', 'work_arrangement' => 'Remote', 'career_credibility' => 8,
    'usa_credibility' => 9, 'date_found' => '2026-10-01', 'details' => ['schedule' => 'Full-time'],
    'job_fit' => ['score' => 70, 'matched' => ['a', 'b'], 'missing' => ['c']]];
$attrs = dash_item_attrs($job);
check('dashboard filters: a job card carries what the filters read', str_contains($attrs, 'data-job="Web &quot;Designer&quot;"')
    && str_contains($attrs, 'data-company="Sample &amp; Co"') && str_contains($attrs, 'data-source="Brave Search"')
    && str_contains($attrs, 'data-distance="12.5"') && str_contains($attrs, 'data-schedule="Full-time"')
    && str_contains($attrs, 'data-job-fit="70"') && str_contains($attrs, 'data-fit-count="3"'));
$job['distance_miles'] = null;
check('dashboard filters: no distance sorts last', str_contains(dash_item_attrs($job), 'data-distance="99999"'));
$bar = dash_toolbar('applied', '#applied-jobs-grid', '.saved-job-card', [$job, ['source_type' => 'Lever'] + $job]);
check('dashboard filters: the bar names its list and offers the sources found', str_contains($bar, 'id="applied-filters"')
    && str_contains($bar, 'data-target="#applied-jobs-grid"') && str_contains($bar, '<option value="Lever">Lever</option>')
    && str_contains($bar, '<option value="Brave Search">Brave Search</option>'));
check('dashboard filters: the legend ids are per list', str_contains(dash_legend('saved'), 'id="saved-low-help"')
    && str_contains(dash_legend('applied'), 'id="applied-low-help"'));
$page = file_get_contents(APP_ROOT . '/pages/dashboard.php');
check('dashboard: Applied Jobs gets its own panel and the old filter form is gone', str_contains($page, 'id="applied-jobs-panel"')
    && !str_contains($page, 'dashboard-filter-panel') && str_contains($page, "'dashboard-filters.js'"));
