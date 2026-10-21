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

// ---- Search settings move to the Settings page; Recent Searches move to Tuning (v1.1.300) ----
$settings_page = file_get_contents(APP_ROOT . '/pages/settings.php');
$tuning_page = file_get_contents(APP_ROOT . '/pages/tuning.php');
$settings_partial = file_get_contents(APP_ROOT . '/templates/_search_settings.php');
$tuning_action = file_get_contents(APP_ROOT . '/actions/tuning.php');
check('settings: the Search Settings panel comes first on the Settings page', str_contains($settings_page, "_search_settings.php")
    && strpos($settings_page, '_search_settings.php') < strpos($settings_page, 'id="rejected-listings"') && str_contains($settings_partial, 'id="search-settings"'));
check('tuning: the Tuning page no longer holds the search settings but shows Recent Searches', !str_contains($tuning_page, 'id="search-settings"')
    && str_contains($tuning_page, 'id="recent-searches"') && str_contains($tuning_page, 'jobFinder.recentSearchesOpen'));
check('settings: saving returns to the Settings page', str_contains($tuning_action, "redirect('/rejected-listings?saved=1#search-settings')")
    && str_contains($tuning_action, "redirect('/rejected-listings?error='") && str_contains($tuning_action, "redirect('/tuning#brave-usage')"));
check('dashboard: Recent Searches are no longer on the Dashboard', !str_contains($page, 'id="recent-searches"'));

// ---- Companies: the Search card with a Filters box and Source pills (v1.1.290 - v1.1.294) ----
$companies_page = file_get_contents(APP_ROOT . '/pages/companies.php');
$companies_js = file_get_contents(APP_ROOT . '/static/js/companies.js');
check('companies: the search folds up under one Search heading, with the filters inside it', str_contains($companies_page, 'id="companies-searchbox"')
    && strpos($companies_page, 'id="companies-searchbox"') < strpos($companies_page, 'id="companies-source-tags"')
    && strpos($companies_page, 'id="companies-source-tags"') < strpos($companies_page, 'id="companies-kind-tags"')
    && strpos($companies_page, 'id="companies-kind-tags"') < strpos($companies_page, 'id="companies-search"'));
check('companies: My companies is a Source beside Employers from your searches', str_contains($companies_page, "MINE_KIND => 'My companies'")
    && str_contains($companies_page, 'array_merge([KNOWN_KIND, MINE_KIND]'));
check('companies: My Companies, Add a Company and the results fold, and each remembers its choice', str_contains($companies_page, 'jobFinder.myCompaniesOpen')
    && str_contains($companies_page, 'jobFinder.addCompanyOpen') && str_contains($companies_page, 'id="companies-results-panel"')
    && substr_count($companies_page, '<details') === substr_count($companies_page, '</details>'));
check('companies: Source pills combine with the type pills', str_contains($companies_js, 'activeSources') && str_contains($companies_js, 'companies-source-tags'));

// ---- Hacker News "Who is hiring?" as a remote feed (v1.1.296) ----
$hn_comments = [
    '1' => 'Acme (https://acme.com) | Senior Frontend Developer | REMOTE (US) | Full-time<p>We build things. Apply at <a href="https://acme.com/jobs/1">acme.com/jobs/1</a>',
    '2' => 'Beta Studio | Graphic Designer / Motion Designer | REMOTE | $90k',
    '3' => 'Gamma | Backend Engineer, Data Engineer | REMOTE (US) | Full-time',
    '4' => 'Delta | Frontend Developer | REMOTE (EU only) | Full-time',
    '5' => 'Epsilon | Frontend Developer | San Francisco, CA | ONSITE',
    '6' => 'Zeta is hiring a frontend developer, everything is great, contact us anywhere.',
    '7' => 'Eta | Hiring: Product Designer, Graphic Designer | Remote (anywhere) | Full-time',
];
$hn_openings = [];
foreach ($hn_comments as $hn_id => $hn_html) {  // what fetch_hacker_news() keeps of each comment
    $parsed = hacker_news_parse($hn_html);
    if (!$parsed) {
        continue;
    }
    [$where, $score] = hacker_news_location($parsed);
    if ($where === null) {
        continue;
    }
    $hn_openings[] = ['id' => (string) $hn_id, 'url' => "https://news.ycombinator.com/item?id=$hn_id", 'company' => $parsed['company'], 'header' => $parsed['header'],
        'parts' => $parsed['parts'], 'lines' => array_slice($parsed['lines'], 0, 6), 'location' => $where, 'usa_score' => $score, 'description' => $hn_html, 'posted' => '2026-10-02'];
}
$hn_found = [];
foreach (hacker_news_matching($hn_openings, ['Frontend Developer', 'Graphic Designer'], true) as $hn_job) {
    $hn_found[$hn_job['name']] = $hn_job;
}
ksort($hn_found);
check('hacker news: remote U.S. openings with a matching title are kept', array_keys($hn_found) === ['Acme', 'Beta Studio', 'Eta']);
check('hacker news: the title, link and U.S. score come from the comment', ($hn_found['Acme']['title'] ?? '') === 'Senior Frontend Developer'
    && ($hn_found['Acme']['url'] ?? '') === 'https://news.ycombinator.com/item?id=1' && ($hn_found['Acme']['usa_score'] ?? 0) === 6
    && ($hn_found['Beta Studio']['title'] ?? '') === 'Graphic Designer' && ($hn_found['Eta']['title'] ?? '') === 'Graphic Designer');
check('hacker news: other regions, onsite jobs and free-form comments are left out', !array_intersect(['Gamma', 'Delta', 'Epsilon', 'Zeta'], array_keys($hn_found)));
check('hacker news: it is a registered feed that links on Hacker News', in_array('Hacker News', FEED_NAMES, true)
    && in_array('news.ycombinator.com', feed_list()['Hacker News']['hosts'], true));

// ---- Search page: Source filter, folds, a Source link (v1.1.294 - v1.1.296) ----
$search_page = file_get_contents(APP_ROOT . '/pages/search.php');
$charts_js = file_get_contents(APP_ROOT . '/static/js/charts.js');
check('search: results can be filtered by Source', str_contains($search_page, 'id="result-source-filter"') && str_contains($search_page, 'data-source="<?= h(')
    && str_contains($charts_js, 'selectedSource') && str_contains($charts_js, 'resultSourceFilter,resultNewFilter'));
check('search: Job Titles, places, Results, Top 10 Picks and Listings fold, each remembered', str_contains($search_page, 'id="fold-titles"') && str_contains($search_page, 'id="fold-places"')
    && str_contains($search_page, 'id="results-panel"') && str_contains($search_page, 'id="top-picks-panel"') && str_contains($search_page, 'id="listings-panel"')
    && str_contains($search_page, "'search-folds.js'") && str_contains($charts_js, 'jobFinder.resultsOpen') && str_contains($charts_js, 'jobFinder.listingsOpen')
    && substr_count($search_page, '<details') === substr_count($search_page, '</details>'));
check('search: a listing\'s Source links to the page the job was found on', str_contains($search_page, "<dt>Source</dt><dd><?php \$source_name")
    && str_contains(file_get_contents(APP_ROOT . '/templates/_job_card.php'), "<dt>Source</dt><dd><?php \$source_name"));
check('iCIMS: a job is checked on its framed page, so the empty shell does not read as closed', str_contains(file_get_contents(APP_ROOT . '/includes/search/employers.php'), "?in_iframe=1"));
check('clean-up: a rejected job is kept when closed jobs are tidied', (bool) preg_match('/is_rejected = 0 AND is_kept = 0 AND application_status NOT IN/', file_get_contents(APP_ROOT . '/includes/listings.php')));

// ---- Job Skill Fitting, notes with links, Clear closed jobs (v1.1.297 - v1.1.300) ----
require_once APP_ROOT . '/includes/skill_fitting.php';

$fit = skill_fit_score(['HTML', 'CSS'], ['HTML', 'CSS', 'Python', 'SQL']);
check('skill fit: the score is the share of the job\'s skills you have', $fit['score'] === 50 && $fit['matched'] === ['HTML', 'CSS'] && $fit['missing'] === ['Python', 'SQL']);
check('skill fit: aliases count as the same skill', skill_fit_score(['JS'], ['JavaScript', 'HTML'])['matched'] === ['JavaScript']);
check('skill fit: a listing with too few skills is not scored', skill_fit_score(['HTML'], ['HTML'])['score'] === null);
check('skill fit: malformed saved skills are empty', skill_fit_parse('not json') === [] && skill_fit_parse(null) === [] && skill_fit_parse('["HTML"]') === ['HTML']);
$ranked = skill_fit_rank(['HTML', 'CSS'], [
    ['career_job_title' => 'Half', 'listing_skills' => json_encode(['HTML', 'Python'])],
    ['career_job_title' => 'All', 'listing_skills' => json_encode(['HTML', 'CSS'])],
    ['career_job_title' => 'Thin', 'listing_skills' => json_encode(['HTML'])]]);
check('skill fit: best fit first, unscored jobs left out', array_column($ranked, 'career_job_title') === ['All', 'Half']);
check('skill fit: a Core skill counts triple and a Nice one half', skill_fit_score(['HTML'], ['HTML', 'Python'])['score'] === 50
    && skill_fit_score(['HTML'], ['HTML', 'Python'], ['HTML' => 'core'])['score'] === 75 && skill_fit_score(['HTML'], ['HTML', 'Python'], ['HTML' => 'nice'])['score'] === 33);
$rows = [['listing_skills' => json_encode(['HTML', 'Python']), 'job_fit' => ['score' => 7]], ['listing_skills' => null]];
add_skill_fit($rows, ['HTML'], ['HTML' => 'core']);
check('skill fit: weights change the skill fit but not the regular Job Fit', $rows[0]['skill_fit']['score'] === 75 && $rows[0]['job_fit'] === ['score' => 7] && $rows[1]['skill_fit']['score'] === null);
check('skill fit: weights follow aliases and ignore bad values', skill_fit_score(['JS'], ['JavaScript', 'HTML'], ['JS' => 'core', 'HTML' => 'huge'])['score'] === 75);
save_skill_weights(['HTML' => 'core', 'CSS' => 'normal', 'PHP' => 'nice', 'Go' => 'huge']);
check('skill fit: only the skills you changed from Normal are kept', load_skill_weights() === ['HTML' => 'core', 'PHP' => 'nice']);
set_setting_json('skill_weights', []);

$note = linkify('Call <b>Sam</b>, see https://example.com/jobs/1.');
check('notes: web addresses become links and the rest is escaped', str_contains($note, '<a href="https://example.com/jobs/1" target="_blank" rel="noopener noreferrer">')
    && str_contains($note, '&lt;b&gt;Sam&lt;/b&gt;') && str_ends_with($note, '</a>.'));
$note_id = (int) value("SELECT id FROM companies ORDER BY id LIMIT 1");
check('notes: saved with the listing, trimmed and capped', $note_id > 0 && save_listing_notes($note_id, "  see https://example.com  ") === 'see https://example.com'
    && value('SELECT notes FROM companies WHERE id = ?', [$note_id]) === 'see https://example.com'
    && save_listing_notes($note_id, '') === '' && value('SELECT notes FROM companies WHERE id = ?', [$note_id]) === null
    && mb_strlen(save_listing_notes($note_id, str_repeat('x', 6000))) === NOTES_LIMIT);
q("UPDATE companies SET notes = NULL WHERE id = ?", [$note_id]);
$before = (int) value('SELECT COUNT(*) FROM companies');
q("UPDATE companies SET is_kept = 1, job_open_status = 'Closed' WHERE id = ?", [$note_id]);
$closed_saved = (int) value("SELECT COUNT(*) FROM companies WHERE is_kept = 1 AND job_open_status = 'Closed'");
$others = $before - $closed_saved;
check('clear closed jobs: deletes saved jobs whose posting is closed, and only those', $closed_saved >= 1 && delete_closed_kept() === $closed_saved
    && (int) value("SELECT COUNT(*) FROM companies WHERE is_kept = 1 AND job_open_status = 'Closed'") === 0 && (int) value('SELECT COUNT(*) FROM companies') === $others);

$skill_page = file_get_contents(APP_ROOT . '/pages/skill_fitting.php');
$top_menu = file_get_contents(APP_ROOT . '/templates/_top.php');
check('skill fitting: the page, its menu entry and its routes exist', str_contains($skill_page, 'Job Skill Fitting') && str_contains($top_menu, "'/job-skill-fitting' => ['Skill Fitting'")
    && str_contains(file_get_contents(APP_ROOT . '/app.php'), "'POST /job-skill-fitting/weights'") && str_contains(file_get_contents(APP_ROOT . '/app.php'), "'GET /job-skill-fitting.json'"));
check('menu: Companies sits right of Search', strpos($top_menu, "'/companies'") < strpos($top_menu, "'/dashboard' =>"));

// ---- Dashboard folds; rule presets and greeting suggestions (v1.1.290 - v1.1.300) ----
$dash_src = file_get_contents(APP_ROOT . '/pages/dashboard.php');
check('dashboard: Profile sections, Saved Jobs and Applied Jobs fold, each remembered', substr_count($dash_src, 'profile-collapse" open data-section=') === 6
    && str_contains($dash_src, 'id="saved-jobs-panel"') && str_contains($dash_src, 'id="applied-jobs-panel"') && str_contains($charts_js, 'savedJobsOpen')
    && substr_count($dash_src, '<details') === substr_count($dash_src, '</details>'));
check('dashboard: a collapsed saved job has Delete beside Listing, an applied one does not', str_contains(file_get_contents(APP_ROOT . '/templates/_job_card.php'), '<?php if ($pick): ?><form action="/delete-kept/'));
require_once APP_ROOT . '/includes/resume/presets.php';
check('rule presets: both rules boxes offer ready-made rules, grouped under headings', count(RULE_PRESETS['resume']) >= 4 && count(RULE_PRESETS['cover_letter']) >= 5
    && str_contains(rule_menu('cover_letter', 'cover-letter-rules-box'), 'data-target="cover-letter-rules-box"')
    && str_contains(rule_menu('resume', 'resume-rules-box'), '<legend>Bullets</legend>') && str_contains(rule_menu('cover_letter', 'x'), 'Do not use em dashes.'));
check('greetings: a person\'s name is used, a company is not mistaken for one', greeting_options(['Jane Smith', 'Acme Inc'], 'Acme') === ['Hello Jane Smith,', 'Dear Jane Smith,', 'Hi Jane,', 'Hello,', 'Good morning,', 'To the team at Acme,']
    && greeting_options(['Acme Inc'], '') === ['Hello,', 'Good morning,'] && rule_person_name(['Human Resources']) === '' && rule_person_name(['Sam Lee']) === 'Sam Lee');
check('rule presets: the Résumé Builder pages load their scripts', str_contains(file_get_contents(APP_ROOT . '/pages/resume.php'), "'rule-presets.js'")
    && str_contains(file_get_contents(APP_ROOT . '/pages/resume_build.php'), "'greeting.js'"));

// ---- Dashboard profile: other titles never repeat the main one; the work description grows with its text (v1.1.288) ----
check('profile: the other-titles box skips the main title when it suggests', str_contains(file_get_contents(APP_ROOT . '/pages/dashboard.php'), 'name="job_titles" type="text" data-title-suggest="list" data-also-from="primary-job-title"')
    && str_contains($charts_js, 'input.dataset.alsoFrom') && str_contains($charts_js, 'box.scrollHeight'));
