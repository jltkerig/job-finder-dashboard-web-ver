<?php
// The search itself. The desktop app runs one long process; a web host can't, so here a search is a to-do list
// of small tasks (search_tasks) worked through a few seconds at a time, each time the Search page asks for the
// search's status (and by cron.php as a backstop). The checks, their order and their limits follow the desktop
// app's runner.py: remote feeds, employers' own career sites, job sites, then web results, each lead checked in
// depth before it is saved.

declare(strict_types=1);

require_once APP_ROOT . '/includes/tuning.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/judging.php';
require_once __DIR__ . '/employers.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/brave.php';

// Order the stages run in (lower first); within a stage, oldest first.
const P_NOW = 0;
const P_FEED = 10;
const P_EMPLOYER = 20;
const P_SITE = 30;
const P_WEB = 40;
const MAX_BOARDS_PER_RUN = 20;
// Hiring-board sites searched by name, one query per title (each board found is then read in full).
const JOB_BOARD_SITES = ['jobs.ashbyhq.com', 'greenhouse.io', 'jobs.lever.co', 'apply.workable.com', 'jobs.smartrecruiters.com',
    'icims.com', 'myworkdayjobs.com', 'bamboohr.com', 'recruitee.com', 'teamtailor.com'];

// --- Starting --------------------------------------------------------------------------------------------------

/** [job titles, state, cities, error] cleaned as the desktop app does. */
function validate_search_criteria($job_title, $state, $cities): array
{
    $job_title = mb_substr(trim((string) $job_title), 0, 1000);
    $state = mb_substr(trim((string) $state), 0, 100);
    if ($job_title === '') {
        return [null, null, null, 'Enter a job title.'];
    }
    if ($state === '') {
        return [null, null, null, 'Enter a state.'];
    }
    $clean = [];
    $seen = [];
    foreach (array_slice(is_array($cities) ? $cities : [], 0, 25) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $city = mb_substr(trim((string) ($item['city'] ?? '')), 0, 150);
        $radius = (int) ($item['radius'] ?? 50);
        if ($city !== '' && in_array($radius, CITY_RADII, true) && !isset($seen[mb_strtolower($city)])) {
            $seen[mb_strtolower($city)] = true;
            $clean[] = ['city' => $city, 'radius' => $radius];
        }
    }
    return [$job_title, $state, $clean, null];
}

function new_run(string $mode): array
{
    q('DELETE FROM search_tasks');
    clear_old_page_cache();
    return ['id' => bin2hex(random_bytes(6)), 'mode' => $mode, 'started_at' => time(), 'finished' => false, 'stopping' => false,
        'progress' => $mode === 'replacement' ? 'Starting replacement search' : 'Starting search', 'passed' => 0, 'saved' => 0,
        'checked' => 0, 'limit' => 0, 'error' => null, 'stop_reason' => null, 'health' => [], 'seen' => [], 'counts' => []];
}

function add_task(string $kind, array $payload, int $priority): void
{
    q('INSERT INTO search_tasks (run_id, kind, payload, priority, done) VALUES (?, ?, ?, ?, 0)',
        ['run', $kind, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $priority]);
}

/** Starts a search (or a one-job replacement search). Returns the run, or an error message. */
function start_search_run(string $job_title, string $state, array $cities, string $mode = 'search')
{
    $run = new_run($mode);
    $limit = $mode === 'replacement' ? 1 : (int) tuning('max_search_results');
    $run['limit'] = $limit;
    $titles = [];
    foreach (explode(',', $job_title) as $raw) {
        $clean = trim($raw);
        if ($clean !== '' && !in_array(mb_strtolower($clean), array_map('mb_strtolower', $titles), true)) {
            $titles[] = spelling_fix($clean);
        }
    }
    $selected = array_values(array_unique($titles));
    $job_titles = expand_job_titles($selected);
    $query_titles = $job_titles;
    $have = array_map('mb_strtolower', $job_titles);
    foreach (related_family_titles($selected) as $extra) {
        if (!in_array(mb_strtolower($extra), $have, true)) {
            $job_titles[] = $extra;
        }
    }
    $statewide = [];
    foreach ($cities as $item) {
        $code = US_STATES[mb_strtolower(trim($item['city']))] ?? null;
        if ($code) {
            $statewide[] = $code;
        }
    }
    $targets = prepare_city_targets($state, $cities);
    $requested = array_filter($cities, fn($c) => !isset(US_STATES[mb_strtolower(trim($c['city']))]));
    if ($requested && !$targets) {
        return 'None of the selected cities could be found on the map, so the search was stopped (the mileage filter would otherwise be ignored). Check the spelling, or pick a state.';
    }
    $run['ctx'] = ['state' => $state, 'cities' => $cities, 'selected_titles' => $selected, 'job_titles' => $job_titles,
        'query_titles' => $query_titles, 'statewide_states' => array_values(array_unique($statewide)),
        'selected_states' => selected_state_codes($state, $cities, $statewide), 'city_targets' => $targets,
        'rejected' => array_keys(rejected_posting_urls())];
    $run['deadline'] = time() + (int) tuning('search_time_limit_minutes') * 60;

    // 1. Remote feeds, 2. employers' own career sites, 3. job sites, 4. the web search.
    foreach (feed_list() as $name => $_) {
        add_task('feed', ['name' => $name], P_FEED);
    }
    $employers = load_employers();
    if ($employers) {
        // Start at a different board each hour so a full quota does not always come from the first few.
        $turn = intdiv(time(), 3600) % count($employers);
        $employers = array_merge(array_slice($employers, $turn), array_slice($employers, 0, $turn));
    }
    $run['known_boards'] = array_map('board_key', $employers);
    foreach ($employers as $employer) {
        add_task('employer', ['config' => $employer, 'per_cap' => 2, 'cap' => 'employer'], P_EMPLOYER);
    }
    foreach (site_list() as $name => $site) {
        add_task('site', ['site' => $name, 'title' => 0, 'place' => 0], P_SITE);
    }
    foreach (web_queries($run['ctx']) as $query) {
        add_task('query', ['query' => $query, 'page' => 1, 'passed_start' => null, 'dry' => 0, 'empty' => 0], P_WEB);
    }
    save_search_state($run);
    if ($mode !== 'replacement') {
        record_search_history($job_title, $state, $cities);
    }
    return $run;
}

/** The web queries, in the desktop app's order: company boards by name, then each title near each place. */
function web_queries(array $ctx): array
{
    $state = $ctx['state'];
    $queries = [];
    // One query per title covers every hiring-board site (the desktop sends one per site; Brave credits are counted).
    $sites = implode(' OR ', array_map(fn($s) => "site:$s", JOB_BOARD_SITES));
    foreach ($ctx['selected_titles'] as $title) {
        $queries[] = "\"$title\" ($sites)";
    }
    $city_names = [];
    foreach ($ctx['cities'] as $item) {
        $city = trim($item['city']);
        if ($city !== '' && !isset(US_STATES[mb_strtolower($city)]) && !preg_match(ZIP_CODE, $city)) {
            $city_names[] = $city;
        }
    }
    foreach ($ctx['query_titles'] as $title) {
        $list = [];
        foreach ($city_names as $city) {
            $list[] = "\"$title\" \"$city\" \"$state\" jobs";
            $list[] = "\"$title\" \"$city\" careers";
        }
        array_push($list, trim("$title $state"), "\"$title\" \"$state\" careers", "\"$title\" \"$state\" jobs", "$title careers $state",
            "$title hiring $state", "\"$title\" freelance project $state", "\"$title\" contract gig $state",
            "\"$title\" state government jobs $state", "\"$title\" university jobs $state", "\"$title\" hospital health system careers $state");
        foreach ($list as $query) {
            if (!in_array($query, $queries, true)) {
                $queries[] = $query;
            }
        }
    }
    return $queries;
}

/** Re-checks saved results (Refresh: the given rows; Update: every result) without searching. */
function start_refresh_run(?array $ids, string $mode = 'refresh')
{
    $run = new_run($mode);
    $sql = 'SELECT id FROM companies WHERE is_rejected = 0';
    $args = [];
    if ($ids !== null) {
        if (!$ids) {
            return 'There are no displayed listings to refresh.';
        }
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = $ids;
    }
    $rows = array_column(rows("$sql ORDER BY date_found ASC", $args), 'id');
    if (!$rows) {
        return 'There are no existing results to update.';
    }
    $profile = get_user_profile();
    $latest = get_search_history(1)[0] ?? null;
    $cities = $latest ? (json_decode($latest['cities_json'] ?: '[]', true) ?: []) : [];
    $state = $latest['state'] ?? '';
    $titles = $profile['job_titles'];
    if ($latest) {
        $titles = array_merge($titles, array_map('trim', explode(',', $latest['job_title'])));
    }
    $titles = array_values(array_unique(array_filter($titles, 'strlen')));
    $run['ctx'] = ['wanted' => $titles ? expand_job_titles($titles) : [], 'state' => $state,
        'selected_states' => selected_state_codes($state, $cities, []), 'city_targets' => prepare_city_targets($state, $cities)];
    $run['total'] = count($rows);
    $run['progress'] = 'Found ' . count($rows) . ' existing results to check';
    refresh_listing_skills_quietly();
    foreach ($rows as $index => $id) {
        add_task('refresh', ['id' => (int) $id, 'index' => $index + 1], P_WEB);
    }
    save_search_state($run);
    return $run;
}

function refresh_listing_skills_quietly(): void
{
    try {
        refresh_job_fit();
    } catch (Throwable $e) {
    }
}

// --- Working through the tasks ----------------------------------------------------------------------------------

/** Works on the running search for up to $seconds. Safe to call from several requests at once (one wins the lock). */
function run_slice(float $seconds): void
{
    @set_time_limit((int) ceil($seconds) + 90);
    ignore_user_abort(true);
    with_search_lock(function () use ($seconds) {
        $run = search_state();
        if (!search_running($run)) {
            return;
        }
        $deadline = microtime(true) + $seconds;
        $GLOBALS['slice_deadline'] = $deadline;
        while (microtime(true) < $deadline) {
            if (!empty($run['stopping'])) {
                finish_run($run, 'Stopped by user');
                return;
            }
            if (in_array($run['mode'], ['search', 'replacement'], true)) {
                if (full($run)) {
                    finish_run($run, "{$run['saved']} of {$run['limit']} distinct jobs found");
                    return;
                }
                if (time() >= ($run['deadline'] ?? PHP_INT_MAX)) {
                    $minutes = (int) tuning('search_time_limit_minutes');
                    finish_run($run, "Search time limit reached ($minutes minutes). Raise the time limit on the Tuning page to search longer.");
                    return;
                }
            }
            $task = row('SELECT id, kind, payload FROM search_tasks WHERE done = 0 ORDER BY priority, id LIMIT 1');
            if (!$task) {
                finish_run($run, run_end_reason($run));
                return;
            }
            $payload = json_decode($task['payload'], true) ?: [];
            try {
                $done = run_task($run, $task['kind'], $payload);
            } catch (Throwable $error) {
                error_log("Search task {$task['kind']} failed: " . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
                $done = true;
            }
            if ($done) {
                q('UPDATE search_tasks SET done = 1 WHERE id = ?', [$task['id']]);
            } else {
                q('UPDATE search_tasks SET payload = ? WHERE id = ?', [json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $task['id']]);
            }
            save_search_state($run);
            // Another request may have asked to stop while this step ran.
            $latest = search_state();
            if (!empty($latest['stopping'])) {
                $run['stopping'] = true;
            }
        }
    });
}

function run_end_reason(array $run): string
{
    if (in_array($run['mode'], ['refresh', 'update'], true)) {
        return ($run['mode'] === 'refresh' ? 'Refresh' : 'Update') . ' complete';
    }
    if (!empty($run['blocked_message'])) {
        return $run['blocked_message'];
    }
    return 'Search sources exhausted: every query was checked. Try more job titles, more cities or a larger radius to find more.';
}

function finish_run(array &$run, string $reason, ?string $error = null): void
{
    q('DELETE FROM search_tasks');
    $run['finished'] = true;
    $run['stopping'] = false;
    $run['stop_reason'] = $reason;
    $run['error'] = $error;
    $run['progress'] = null;
    $run['finished_at'] = time();
    unset($run['seen']);
    if (in_array($run['mode'], ['search', 'replacement'], true)) {
        write_board_health($run['health'] ?? [], $run['mode']);
    }
    try {
        tidy_closed_jobs();
    } catch (Throwable $e) {
    }
    save_search_state($run);
}

/** Asks the running search to stop (it stops at its next step and keeps what it found). */
function request_stop(): string
{
    $run = search_state();
    if (!search_running($run)) {
        return 'stopped';
    }
    $run['stopping'] = true;
    save_search_state($run);
    return 'stopping';
}

/** Runs one step of a task. True when the task is finished; otherwise its payload was updated to continue later. */
function run_task(array &$run, string $kind, array &$payload): bool
{
    switch ($kind) {
        case 'feed':
            return task_feed($run, $payload);
        case 'employer':
            return task_employer($run, $payload);
        case 'site':
            return task_site($run, $payload);
        case 'query':
            return task_query($run, $payload);
        case 'result':
            return task_result($run, $payload);
        case 'opening':
            return task_opening($run, $payload);
        case 'refresh':
            return task_refresh($run, $payload);
    }
    return true;
}

// --- Bookkeeping ----------------------------------------------------------------------------------------------

function seen(array &$run, string $set, string $url): bool
{
    return isset($run['seen'][$set][substr(sha1($url), 0, 12)]);
}

function mark_seen(array &$run, string $set, string $url): void
{
    $run['seen'][$set][substr(sha1($url), 0, 12)] = 1;
}

function skip(array &$run, string $reason, string $url, string $title = ''): void
{
    record_skip($url, $title, $reason);
    $run['progress'] = mb_substr("Skipped ($reason): $title", 0, 220);
}

function count_up(array &$run, string $counter, int $by = 1): int
{
    return $run['counts'][$counter] = ($run['counts'][$counter] ?? 0) + $by;
}

function is_rejected_url(array $run, string $url): bool
{
    return in_array(canonical_url($url), $run['ctx']['rejected'], true);
}

function company_blocked(?string $name): bool
{
    static $names = null;
    $names ??= array_flip(array_map('mb_strtolower', blocked_company_list()));
    return isset($names[mb_strtolower(trim((string) $name))]);
}

function full(array $run): bool
{
    return $run['limit'] > 0 && $run['saved'] >= $run['limit'];  // 0 = no limit
}

function matched_title_of(string $title, array $wanted): string
{
    foreach ($wanted as $one) {
        if (matching_title($title, [$one])) {
            return $one;
        }
    }
    return $wanted[0] ?? $title;
}

/** Saves a passing job and updates the counts the status shows. */
function save_passing(array &$run, array $job, string $label): bool
{
    $inserted = save_company($job);
    clear_skip($job['source_url']);
    $run['passed']++;
    $run['progress'] = mb_substr("Passed validation: {$run['passed']} · {$job['title']} — $label", 0, 220);
    if ($inserted) {
        $run['saved']++;
    }
    return $inserted;
}

// --- 1. Remote feeds ------------------------------------------------------------------------------------------

function task_feed(array &$run, array $payload): bool
{
    $name = $payload['name'];
    $feed = feed_list()[$name];
    $cap = $run['limit'] > 0 ? max(1, intdiv($run['limit'], 3)) : PHP_INT_MAX;
    if (($run['counts']['feed_total'] ?? 0) >= $cap || full($run)) {
        return true;
    }
    $run['progress'] = "Checking $name for matching remote listings";
    try {
        $jobs = feed_matching($name, $feed, ($feed['fetch'])(), $run['ctx']['job_titles'], (bool) tuning('usa_only'));
    } catch (Throwable $error) {
        note_board($run['health'], $name, 'Remote feed', classify_error($error->getMessage()), 0, 0, $error->getMessage());
        return true;
    }
    $saved = 0;
    foreach ($jobs as $job) {
        if (full($run) || ($run['counts']['feed_total'] ?? 0) >= $cap || $saved >= $cap) {
            break;
        }
        $run['checked']++;
        $run['progress'] = mb_substr("Checking result {$run['checked']}: {$job['title']} ($name)", 0, 220);
        if ($job['name'] === '' || company_blocked($job['name'])) {
            continue;
        }
        if (is_rejected_url($run, $job['url'])) {
            skip($run, 'Previously rejected', $job['url'], $job['title']);
            continue;
        }
        if (is_internship($job['title'])) {
            skip($run, 'Internship', $job['url'], $job['title']);
            continue;
        }
        $limited = remote_state_restrictions($job['text']);
        if ($limited && $run['ctx']['selected_states'] && !array_intersect($limited, $run['ctx']['selected_states'])) {
            sort($limited);
            skip($run, 'Remote job limited to residents of ' . implode(', ', $limited), $job['url'], $job['title']);
            continue;
        }
        $inserted = save_passing($run, ['name' => $job['name'], 'title' => $job['title'], 'career_credibility' => 6, 'domain' => $feed['domain'],
            'career_url' => $job['url'], 'source_url' => $job['url'], 'country' => $job['usa_score'] === 6 ? 'United States' : null,
            'state' => mb_substr($job['location'], 0, 100) ?: null, 'usa_credibility' => $job['usa_score'], 'work_arrangement' => 'Remote',
            'skills' => listing_skills($job['html']), 'source_type' => $name,
            'details' => ['location' => $job['location'], 'posted' => $job['posted'], 'evidence' => ["$name feed", 'matching title'],
                'matched_title' => matched_title_of($job['title'], $run['ctx']['job_titles']),
                'verification' => 'From a remote-job feed; the company site was not checked']], $name);
        if ($inserted) {
            $saved++;
            count_up($run, 'feed_total');
        }
    }
    note_board($run['health'], $name, 'Remote feed', $jobs ? 'ok' : 'no matches', count($jobs), $saved);
    return true;
}

// --- 2. Employers' own career sites ---------------------------------------------------------------------------

function task_employer(array &$run, array &$payload): bool
{
    $employer = $payload['config'];
    $kind = !empty($employer['discovered']) ? 'Discovered board' : 'Employer board';
    $total_cap = $run['limit'] <= 0 ? PHP_INT_MAX
        : ($payload['cap'] === 'employer' ? max(1, intdiv($run['limit'], 2)) : $run['limit']);
    if (full($run) || ($payload['cap'] === 'employer' && ($run['counts']['employer_total'] ?? 0) >= $total_cap)) {
        return true;
    }
    $run['progress'] = "Checking {$employer['name']} careers";
    $wanted = array_merge($run['ctx']['job_titles'], $employer['extra_titles'] ?? []);
    try {
        if (!isset($payload['listings'])) {
            // Search, one keyword per step.
            $payload['keywords'] ??= array_slice(array_values(array_unique(array_filter(array_map(fn($t) => mb_strtolower(trim($t)), $wanted)))), 0, MAX_KEYWORDS);
            $payload['found'] ??= [];
            $payload['k'] ??= 0;
            $memo = [];
            if (!empty($payload['whole'])) {
                $memo['all'] = $payload['whole'];
            }
            $keyword = $payload['keywords'][$payload['k']] ?? null;
            if ($keyword !== null) {
                foreach (board_search($employer, $keyword, $memo) as $listing) {
                    $payload['found'][$listing['id']] ??= $listing;
                }
                if (isset($memo['all'])) {
                    // A whole board was read: every keyword would give the same list.
                    $payload['k'] = count($payload['keywords']);
                } else {
                    $payload['k']++;
                }
                if ($payload['k'] < count($payload['keywords'])) {
                    return false;
                }
            }
            $chosen = array_values(array_filter($payload['found'], fn($l) => matching_title($l['title'], $wanted)
                && employer_allows($employer, $l['title'], (string) $l['location'])));
            $payload['listings'] = array_slice($chosen, 0, MAX_DETAILS);
            $payload['matches'] = 0;
            $payload['saved'] = 0;
            $payload['d'] = 0;
            unset($payload['found']);
            if (!empty($employer['discovered'])) {
                record_board_result($employer, count($payload['listings']));
            }
            return false;
        }
        // Details, one listing per step; each opening is judged straight away.
        $listing = $payload['listings'][$payload['d']] ?? null;
        if ($listing === null || $payload['saved'] >= $payload['per_cap']) {
            note_board($run['health'], $employer['name'], $kind, $payload['matches'] ? 'ok' : 'no matches', $payload['matches'], $payload['saved']);
            return true;
        }
        $payload['d']++;
        $opening = board_detail($employer, $listing);
        if ($opening) {
            $opening['locations'] = array_values(array_filter($opening['locations'], fn($p) => employer_allows(['exclude_locations' => $employer['exclude_locations'] ?? []], '', $p)));
            if ($opening['locations']) {
                $opening['location'] = $opening['locations'][0];
                $payload['matches']++;
                if (check_employer_opening($run, $employer, $opening)) {
                    $payload['saved']++;
                    if ($payload['cap'] === 'employer') {
                        count_up($run, 'employer_total');
                    }
                }
            }
        }
        return false;
    } catch (Throwable $error) {
        note_board($run['health'], $employer['name'], $kind, classify_error($error->getMessage()), 0, 0, $error->getMessage());
        return true;
    }
}

function check_employer_opening(array &$run, array $employer, array $opening): bool
{
    $job_url = canonical_url($opening['url']);
    $run['checked']++;
    $run['progress'] = mb_substr("Checking result {$run['checked']}: {$opening['title']} ({$employer['name']})", 0, 220);
    if ($job_url === '' || seen($run, 'openings', $job_url)) {
        return false;
    }
    if (is_rejected_url($run, $job_url)) {
        skip($run, 'Previously rejected', $job_url, $opening['title']);
        return false;
    }
    mark_seen($run, 'openings', $job_url);
    if (company_blocked($employer['name'])) {
        return false;
    }
    if (tuning('usa_only') && $opening['country'] && !str_contains(mb_strtolower($opening['country']), 'united states')) {
        skip($run, 'Posting restricts applicants outside the US', $job_url, $opening['title']);
        return false;
    }
    $page = new Page($job_url, $opening['html']);
    $outcome = null;
    foreach ($opening['locations'] as $place) {
        $outcome = assess_opening(['location' => $place] + $opening, $page, $job_url, $run['ctx']);
        if (!$outcome['skip']) {
            $opening['location'] = $place;
            break;
        }
    }
    if (!$outcome || $outcome['skip']) {
        skip($run, $outcome['skip'] ?? 'Outside selected location', $job_url, $opening['title']);
        return false;
    }
    $wanted = array_merge($run['ctx']['job_titles'], $employer['extra_titles'] ?? []);
    $limited = $outcome['remote_limited_to'];
    sort($limited);
    return save_passing($run, ['name' => $employer['name'], 'title' => $opening['title'], 'career_credibility' => $outcome['detail_score'],
        'domain' => $employer['domain'], 'career_url' => $job_url, 'source_url' => $job_url, 'country' => $outcome['country'],
        'state' => mb_substr((string) $outcome['state_name'], 0, 100) ?: null, 'usa_credibility' => $outcome['location']['score'],
        'city' => $outcome['city'], 'latitude' => $outcome['lat'], 'longitude' => $outcome['lon'], 'distance_miles' => $outcome['miles'],
        'work_arrangement' => $outcome['arrangement'], 'skills' => listing_skills($opening['html']), 'source_type' => EMPLOYER_SOURCE,
        'details' => ['schedule' => $opening['schedule'], 'salary' => $opening['salary'], 'posted' => $opening['posted'],
            'evidence' => $opening['evidence'], 'location' => $opening['location'], 'matched_title' => matched_title_of($opening['title'], $wanted),
            'remote_limited_to' => $limited, 'verification' => "Company's own careers site",
            'source' => $employer['domain'], 'description' => mb_substr($opening['description'], 0, 20000)]], "{$employer['name']} careers");
}

// --- 3. Job sites ---------------------------------------------------------------------------------------------

function task_site(array &$run, array &$payload): bool
{
    $name = $payload['site'];
    $site = site_list()[$name];
    if (!tuning_source_on($name)) {
        note_board($run['health'], $name, 'Job site', 'off');
        return true;
    }
    if (!$site['configured']) {
        note_board($run['health'], $name, 'Job site', 'off', 0, 0, $site['setup']);
        return true;
    }
    $cap = $run['limit'] > 0 ? max(1, intdiv($run['limit'], 3)) : PHP_INT_MAX;
    $places = search_places($run['ctx']['state'], $run['ctx']['cities']);
    $titles = $run['ctx']['selected_titles'];
    $payload['found'] ??= 0;
    $payload['saved'] ??= 0;
    $title = $titles[$payload['title']] ?? null;
    $place = $places[$payload['place']] ?? null;
    if ($title === null || $place === null || full($run) || $payload['saved'] >= $cap) {
        note_board($run['health'], $name, 'Job site', $payload['found'] ? 'ok' : 'no matches', $payload['found'], $payload['saved']);
        return true;
    }
    $run['progress'] = mb_substr("Searching $name for $title near {$place[0]}", 0, 220);
    try {
        $listings = site_search($name, $title, $place[0], $place[1]);
    } catch (SiteBlocked $error) {
        note_board($run['health'], $name, 'Job site', 'blocked', $payload['found'], $payload['saved'], $error->getMessage());
        return true;
    } catch (Throwable $error) {
        note_board($run['health'], $name, 'Job site', classify_error($error->getMessage()), $payload['found'], $payload['saved'], $error->getMessage());
        return true;
    }
    foreach ($listings as $listing) {
        $payload['found']++;
        if (check_site_listing($run, $name, $site, $listing)) {
            $payload['saved']++;
        }
        if ($payload['saved'] >= $cap || full($run)) {
            break;
        }
    }
    // Next place, then next title.
    $payload['place']++;
    if ($payload['place'] >= count($places)) {
        $payload['place'] = 0;
        $payload['title']++;
    }
    return false;
}

function tuning_source_on(string $name): bool
{
    return (bool) ((read_tuning_settings()['sources'][$name] ?? true));
}

function check_site_listing(array &$run, string $name, array $site, array $listing): bool
{
    $job_url = $listing['url'];
    $title = $listing['title'];
    $run['checked']++;
    $run['progress'] = mb_substr("Checking result {$run['checked']}: $title ($name)", 0, 220);
    if ($title === '' || seen($run, 'openings', $job_url)) {
        return false;
    }
    if (is_rejected_url($run, $job_url)) {
        skip($run, 'Previously rejected', $job_url, $title);
        return false;
    }
    mark_seen($run, 'openings', $job_url);
    $company = $listing['company'] ?: 'Unknown employer';
    if (company_blocked($company)) {
        return false;
    }
    if (!matching_title($title, $run['ctx']['job_titles'])) {
        skip($run, 'Title matches none of your job titles', $job_url, $title);
        return false;
    }
    if (tuning('usa_only') && $listing['country'] && !str_contains(mb_strtolower($listing['country']), 'united states')
        && !in_array(strtoupper($listing['country']), ['US', 'USA'], true)) {
        skip($run, 'Posting restricts applicants outside the US', $job_url, $title);
        return false;
    }
    $opening = ['title' => $title, 'company' => $company, 'description' => $listing['description'], 'type' => null,
        'location' => $listing['location'], 'locations' => [$listing['location']], 'remote_states' => [], 'schedule' => '',
        'evidence' => ["$name listing"]];
    $html = '<html><body><h1>' . h($title) . '</h1><p>' . h($company) . '</p>'
        . ($listing['location'] ? '<p class="job-location">' . h($listing['location']) . '</p>' : '')
        . ($listing['description'] ? '<div>' . h($listing['description']) . '</div>' : '') . '</body></html>';
    $outcome = assess_opening($opening, new Page($job_url, $html), $job_url, $run['ctx']);
    // The site's own location field ("Aberdeen, MD") is trusted as U.S. proof.
    if ($outcome['skip'] === 'US eligibility unverified' && site_location_in_us($listing['location'])) {
        $outcome['skip'] = null;
        $outcome['country'] = 'United States';
        $outcome['location']['score'] = max($outcome['location']['score'], USA_CREDIBILITY_THRESHOLD);
    }
    if ($outcome['skip']) {
        skip($run, $outcome['skip'], $job_url, $title);
        return false;
    }
    $same = same_saved_job($company, $title, $listing['location'], $outcome['arrangement'], $job_url);
    if ($same) {
        if ($same['is_rejected']) {
            skip($run, 'Previously rejected', $job_url, $title);
            return false;
        }
        refresh_same_job($same, $name, $listing);
        $run['passed']++;
        $run['progress'] = mb_substr("Already saved as job #{$same['id']}: linked this $name listing — $title", 0, 220);
        return false;
    }
    $limited = $outcome['remote_limited_to'];
    sort($limited);
    return save_passing($run, ['name' => $company, 'title' => $title, 'career_credibility' => $outcome['detail_score'], 'domain' => $site['domain'],
        'career_url' => $job_url, 'source_url' => $job_url, 'country' => $outcome['country'],
        'state' => mb_substr((string) $outcome['state_name'], 0, 100) ?: null, 'usa_credibility' => $outcome['location']['score'],
        'city' => $outcome['city'], 'latitude' => $outcome['lat'], 'longitude' => $outcome['lon'], 'distance_miles' => $outcome['miles'],
        'work_arrangement' => $outcome['arrangement'], 'skills' => listing_skills($listing['description']), 'source_type' => $name,
        'details' => ['posted' => $listing['posted'], 'location' => $listing['location'], 'matched_title' => matched_title_of($title, $run['ctx']['job_titles']),
            'evidence' => ["$name listing", 'title matches search'], 'external_id' => $listing['guid'], 'remote_limited_to' => $limited,
            'verification' => "Listed on the $name; the company site was not checked", 'description' => mb_substr($listing['description'], 0, 20000)]], $name);
}

/** The job already saved from another place, when this listing is the same real job; null when it is new. */
function same_saved_job(string $company, string $title, string $location, ?string $arrangement, string $url): ?array
{
    $key = job_match_key($company, $title, $location, $arrangement);
    if ($key === null) {
        return null;
    }
    foreach (rows('SELECT id, name, career_job_title, work_arrangement, city, state, source_url, source_type, listing_details, is_rejected FROM companies') as $row) {
        $details = json_decode($row['listing_details'] ?: '{}', true) ?: [];
        $place = $details['location'] ?? implode(', ', array_filter([$row['city'], $row['state']]));
        if (job_match_key($row['name'], $row['career_job_title'], (string) $place, $row['work_arrangement']) === $key
            && canonical_url($row['source_url']) !== canonical_url($url)) {
            return $row;
        }
    }
    return null;
}

function refresh_same_job(array $row, string $site, array $listing): void
{
    $details = json_decode($row['listing_details'] ?: '{}', true) ?: [];
    $links = $details['also_on'] ?? [];
    if (!array_filter($links, fn($l) => ($l['url'] ?? '') === $listing['url'])) {
        $links[] = ['site' => $site, 'url' => $listing['url']];
    }
    $details['also_on'] = $links;
    foreach (['posted' => $listing['posted'], 'location' => $listing['location'], 'description' => mb_substr($listing['description'], 0, 20000)] as $field => $value) {
        if ($value && empty($details[$field])) {
            $details[$field] = $value;
        }
    }
    q("UPDATE companies SET listing_details = ?, job_open_status = 'Open', last_checked = ?, result_updated_at = ? WHERE id = ?",
        [json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now_utc(), now_utc(), $row['id']]);
}

// --- 4. The web search ----------------------------------------------------------------------------------------

function task_query(array &$run, array &$payload): bool
{
    if (full($run) || !empty($run['blocked_message'])) {
        return true;
    }
    $blocked = brave_blocked_reason();
    if ($blocked !== null) {
        $run['blocked_message'] = $blocked;
        q("UPDATE search_tasks SET done = 1 WHERE kind = 'query'");
        return true;
    }
    $query = $payload['query'];
    $page = $payload['page'];
    // The page before this one: did its results pass anything? Three dry pages in a row end the query.
    if ($payload['passed_start'] !== null) {
        $payload['dry'] = $run['passed'] > $payload['passed_start'] ? 0 : $payload['dry'] + 1;
        if (!empty($payload['mostly_seen']) || $payload['dry'] >= 3) {
            return true;
        }
    }
    $pages = str_contains($query, 'site:') ? min(2, (int) tuning('max_search_pages')) : (int) tuning('max_search_pages');
    if ($page > $pages) {
        return true;
    }
    $run['progress'] = mb_substr("Searching the web: $query" . ($page > 1 ? " (page $page)" : ''), 0, 220);
    $results = brave_web_search($query, $page);
    if ($page === 1) {
        // Many queries in a row with no results at all means the search engine is refusing us.
        $run['counts']['empty_streak'] = $results ? 0 : ($run['counts']['empty_streak'] ?? 0) + 1;
        if ($run['counts']['empty_streak'] >= (int) tuning('stop_after_empty_queries')) {
            $run['blocked_message'] = "Brave Search returned nothing for {$run['counts']['empty_streak']} queries in a row"
                . (setting('brave_last_error') ? ' (' . setting('brave_last_error') . ')' : '') . '. Check the API key and your Brave plan, then try again.';
            return true;
        }
    }
    $fresh = array_values(array_filter($results, fn($r) => !seen($run, 'results', $r['url'])));
    if (!$results || !$fresh) {
        $payload['empty'] = ($payload['empty'] ?? 0) + 1;
        if ($payload['empty'] >= 2) {
            return true;
        }
        $payload['page']++;
        $payload['passed_start'] = null;
        return false;
    }
    foreach ($fresh as $result) {
        mark_seen($run, 'results', $result['url']);
    }
    $web = array_values(array_filter($fresh, fn($r) => !is_pdf_url($r['url'])));
    // Download this page's results together; the checks after read them from the cache.
    $urls = [];
    foreach ($web as $result) {
        $url = canonical_url($result['url']);
        if ($url && !domain_is_blocked(get_domain($url)) && !has_blocked_country_domain(get_domain($url)) && !recently_skipped($url)) {
            $urls[] = $url;
        }
    }
    prefetch_pages(array_slice($urls, 0, 24), $GLOBALS['slice_deadline'] ?? null);
    foreach ($web as $result) {
        add_task('result', ['url' => $result['url'], 'title' => $result['title'], 'query' => $query], P_WEB);
    }
    // The next page of this query goes after this page's results.
    add_task('query', ['query' => $query, 'page' => $page + 1, 'passed_start' => $run['passed'], 'dry' => $payload['dry'], 'empty' => 0,
        'mostly_seen' => count($results) >= 5 && count($fresh) / count($results) < 0.2], P_WEB);
    return true;
}

/** One web result: the landing page, then its careers pages and job links, one page per step. */
function task_result(array &$run, array &$payload): bool
{
    if (full($run)) {
        return true;
    }
    $url = canonical_url($payload['url']);
    $title = $payload['title'];
    if (!isset($payload['pending'])) {
        $run['checked']++;
        if ($url === '' || !is_valid_url($url)) {
            return true;
        }
        $domain = get_domain($url);
        if (domain_is_blocked($domain) || has_blocked_country_domain($domain)) {
            skip($run, 'Blocked domain', $url, $title);
            return true;
        }
        $run['progress'] = mb_substr("Checking result {$run['checked']}: $title", 0, 220);
        if (recently_skipped($url)) {
            return true;
        }
        discover_board($run, $url, $title);
        $landing = fetch_page($url);
        if ($landing === null) {
            skip($run, 'Page unavailable', $url, $title);
            return true;
        }
        if (is_directory_or_marketplace_result($domain, $title, $landing)) {
            skip($run, 'Directory or marketplace page', $url, $title);
            return true;
        }
        if (is_article_page($landing) || is_student_employment_overview($landing)) {
            skip($run, 'Article or student employment guide', $url, $title);
            return true;
        }
        $company = extract_company_name($landing, $title, $domain);
        if (company_blocked($company)) {
            return true;
        }
        // Follow the site's career navigation, then individual job links.
        $pending = [$url];
        foreach ($landing->links() as [$href, $text, $raw]) {
            $marker = mb_strtolower("$text $raw");
            foreach (['career', 'job openings', 'open positions', 'join our team'] as $term) {
                if (str_contains($marker, $term)) {
                    $candidate = canonical_url($href);
                    if ($candidate && (get_domain($candidate) === $domain || domain_in(get_domain($candidate), ATS_DOMAINS))) {
                        $pending[] = $candidate;
                    }
                    break;
                }
            }
        }
        $pending = array_merge($pending, job_links($landing));
        foreach (array_slice($pending, 0, 10) as $board_url) {
            $pending = array_merge($pending, public_board_links($board_url, $run['ctx']['job_titles']));
        }
        $payload['pending'] = array_values(array_unique($pending));
        $payload['checked'] = [];
        $payload['company'] = $company;
        return false;
    }
    // One page per step.
    if (!$payload['pending'] || count($payload['checked']) >= 18) {
        return true;
    }
    $page_url = array_shift($payload['pending']);
    if ($page_url !== $url) {
        discover_board($run, $page_url, $payload['company']);
    }
    if (in_array($page_url, $payload['checked'], true) || seen($run, 'pages', $page_url) || is_pdf_url($page_url)) {
        return false;
    }
    $payload['checked'][] = $page_url;
    mark_seen($run, 'pages', $page_url);
    $page = fetch_page($page_url);
    if ($page === null) {
        skip($run, 'Job page unavailable', $page_url, $title);
        return false;
    }
    $openings = extract_jobs($page, $run['ctx']['job_titles']);
    if (!$openings && count($payload['checked']) < 8) {
        $more = array_merge(job_links($page, 24), pagination_links($page), public_board_links($page->url, $run['ctx']['job_titles']));
        $payload['pending'] = array_values(array_unique(array_merge($payload['pending'], $more)));
        $ahead = array_values(array_filter($payload['pending'], fn($u) => !in_array($u, $payload['checked'], true) && !seen($run, 'pages', $u)));
        prefetch_pages(array_slice($ahead, 0, 4), $GLOBALS['slice_deadline'] ?? null);
    } elseif (!$openings) {
        skip($run, 'No matching individual opening', $page->url, $title);
    }
    foreach ($openings as $opening) {
        add_task('opening', ['opening' => $opening, 'page_url' => $page->url, 'source' => $url, 'title' => $title,
            'company' => $payload['company'], 'query' => $payload['query']], P_NOW);
    }
    return false;
}

/** A web result on a hiring platform (UltiPro, Greenhouse, ...): search that employer's whole board too. */
function discover_board(array &$run, string $page_url, string $hint = ''): void
{
    if (($run['counts']['boards_found'] ?? 0) >= MAX_BOARDS_PER_RUN) {
        return;
    }
    $config = identify_board($page_url);
    if (!$config) {
        return;
    }
    $key = board_key($config);
    if (in_array($key, $run['known_boards'] ?? [], true)) {
        return;
    }
    $run['known_boards'][] = $key;
    count_up($run, 'boards_found');
    $hint = trim(preg_replace('/\s+/', ' ', $hint));
    $generic = $hint === '' || mb_strlen($hint) > 60 || in_array(mb_strtolower($hint), ['job opportunities', 'careers', 'jobs', 'job board'], true);
    $config['name'] = $generic || $config['system'] !== 'ultipro' ? board_pretty_name($config) : $hint;
    $config['domain'] = get_domain($page_url);
    save_discovered($config);
    add_task('employer', ['config' => $config + ['discovered' => true], 'per_cap' => 3, 'cap' => 'all'], P_NOW);
}

/** One opening found on a web page: judged, linked to the employer's own site and board, and saved. */
function task_opening(array &$run, array $payload): bool
{
    if (full($run)) {
        return true;
    }
    $opening = $payload['opening'];
    $job_url = canonical_url($opening['url']);
    if ($job_url === '' || seen($run, 'openings', $job_url)) {
        return true;
    }
    if (is_rejected_url($run, $job_url)) {
        return true;
    }
    mark_seen($run, 'openings', $job_url);
    if (domain_is_blocked(get_domain($job_url))) {
        return true;
    }
    $name = tidy_company_name($opening['company']) ?: $payload['company'];
    if (company_blocked($name)) {
        return true;
    }
    $page = fetch_page($payload['page_url']) ?? new Page($payload['page_url'], '');
    $wanted = $run['ctx']['job_titles'];
    $details = ['schedule' => $opening['schedule'], 'salary' => $opening['salary'], 'posted' => $opening['posted'],
        'evidence' => $opening['evidence'], 'location' => $opening['location'], 'source' => $payload['source'],
        'matched_title' => matched_title_of($opening['title'], $wanted), 'description' => mb_substr($opening['description'], 0, 20000)];
    $outcome = assess_opening($opening, $page, $job_url, $run['ctx']);
    if ($outcome['skip']) {
        skip($run, $outcome['skip'], $job_url, $opening['title']);
        return true;
    }
    $limited = $outcome['remote_limited_to'];
    sort($limited);
    $details['remote_limited_to'] = $limited;
    $third_party = is_third_party($job_url, $name);
    // A repost on a job board can outlive the real opening; check where Apply leads.
    if ($third_party && ($closed = apply_link_closed($page))) {
        skip($run, 'Job is closed (expired)', $job_url, $opening['title']);
        return true;
    }
    $detail_score = $outcome['detail_score'];
    $row_domain = get_domain($job_url);
    $row_career_url = $job_url;
    if ($third_party) {
        // A listing found on a job board should link to the employer's own careers page.
        $notes = [];
        $employer = resolve_employer_site($name, $opening['title'], $job_url, $page, $wanted, $notes);
        if ($employer) {
            $row_domain = $employer['domain'];
            $row_career_url = $employer['posting_url'] ?: $job_url;
            $details['employer_site'] = ['domain' => $employer['domain'], 'careers_url' => $employer['careers_url'],
                'method' => $employer['method'], 'posting_found' => (bool) $employer['posting_url']];
            $details['original_source'] = $job_url;
            $details['evidence'] = array_merge($details['evidence'], $employer['evidence']);
        }
        // The job board is only a copy: use the company's own hiring-board posting when it has one.
        $board = company_board_posting($name, $opening['title'], microtime(true) + 20);
        if ($board) {
            $row_career_url = $board['url'];
            $detail_score = max($detail_score, OFFICIAL_BOARD_CREDIBILITY);
            $details['ats_posting'] = ['system' => $board['system'], 'url' => $board['url']];
            $details['original_source'] ??= $job_url;
            $details['evidence'][] = "posting found on the company's own " . ucwords($board['system']) . ' board';
        }
    }
    $details['verification'] = verification_label($details, null, $name, $job_url);
    save_passing($run, ['name' => $name, 'title' => $opening['title'], 'career_credibility' => $detail_score, 'domain' => $row_domain,
        'career_url' => $row_career_url, 'source_url' => $job_url, 'country' => $outcome['country'],
        'state' => mb_substr((string) $outcome['state_name'], 0, 100) ?: null, 'usa_credibility' => $outcome['location']['score'],
        'city' => $outcome['city'], 'latitude' => $outcome['lat'], 'longitude' => $outcome['lon'], 'distance_miles' => $outcome['miles'],
        'work_arrangement' => $outcome['arrangement'], 'skills' => listing_skills($page->html ?: $opening['description']),
        'source_type' => 'Brave Search', 'details' => $details], implode(', ', $details['evidence']) ?: 'individual opening');
    return true;
}

// --- Refresh and Update ---------------------------------------------------------------------------------------

/** Re-checks one saved result: still open, still fits, location and employer links filled in. */
function task_refresh(array &$run, array $payload): bool
{
    $company = row('SELECT * FROM companies WHERE id = ? AND is_rejected = 0', [$payload['id']]);
    if (!$company) {
        return true;
    }
    $label = $company['name'] ?: $company['domain'];
    $run['progress'] = mb_substr("Checking {$payload['index']} of {$run['total']}: $label", 0, 220);
    if (in_array($company['source_type'], CAPTURE_SOURCES, true)) {
        return true; // kept current by the Web Job Scraper extension
    }
    $details = json_decode($company['listing_details'] ?: '{}', true) ?: [];
    $title = $company['career_job_title'] ?: ($company['name'] ?: '');
    $wanted = $run['ctx']['wanted'];
    $kept = (bool) $company['is_kept'];
    $is_feed = in_array($company['source_type'], FEED_NAMES, true);
    // Unsaved leads that no longer fit go to Rejected Listings for review (Restore puts them back).
    $internship = !$kept && is_internship($title, $details['schedule'] ?? '');
    $irrelevant = $wanted && $title !== '' && !$kept && empty($details['matched_title']) && !$is_feed && !matching_title($title, $wanted);
    $abroad = tuning('usa_only') && !$kept && !$is_feed && !empty($details['location']) && excludes_us($details['location']);
    $reason = $internship || $irrelevant ? 'wrong_role' : ($abroad ? 'wrong_location' : null);
    if ($reason) {
        q("UPDATE companies SET pre_reject_kept = is_kept, pre_reject_status = application_status, is_rejected = 1, is_kept = 0,
            application_status = 'Rejected', rejected_at = ?, rejection_reason = ?, rejected_by = 'system'
            WHERE id = ? AND is_kept = 0 AND is_rejected = 0", [now_utc(), $reason, $company['id']]);
        return true;
    }
    $tidy = tidy_company_name($company['name']);
    if ($tidy !== '' && $tidy !== $company['name']) {
        q('UPDATE companies SET name = ? WHERE id = ?', [$tidy, $company['id']]);
        $company['name'] = $tidy;
    }
    $now = now_utc();
    $set_status = function (string $status) use ($company, $now) {
        q('UPDATE companies SET job_open_status = ?, last_checked = ?, result_updated_at = CASE WHEN job_open_status <> ? THEN ? ELSE result_updated_at END WHERE id = ?',
            [$status, $now, $status, $now, $company['id']]);
    };
    if (isset(site_list()[$company['source_type']])) {
        $set_status(site_status($company['source_type'], (string) $company['source_url']));
        return true;
    }
    if ($company['source_type'] === EMPLOYER_SOURCE) {
        $employer = employer_for_url((string) $company['source_url'], load_employers());
        $set_status($employer ? board_status($employer, (string) $company['source_url']) : 'Unknown');
        return true;
    }
    if ($is_feed) {
        $feed = feed_list()[$company['source_type']] ?? null;
        try {
            $listings = $feed ? ($feed['fetch'])() : null;
        } catch (Throwable $e) {
            $listings = null;
        }
        if ($listings === null) {
            return true; // feed unavailable: status left as it was
        }
        $item = null;
        foreach ($listings as $listing) {
            if (($listing['url'] ?? '') === $company['source_url']) {
                $item = $listing;
                break;
            }
        }
        if ($item) {
            $location = mb_substr((string) ($item['location'] ?? ''), 0, 100) ?: null;
            $changed = $company['state'] !== $location || $company['job_open_status'] !== 'Open';
            q("UPDATE companies SET listing_skills = ?, state = ?, job_open_status = 'Open', last_checked = ?,
                result_updated_at = CASE WHEN ? THEN ? ELSE result_updated_at END WHERE id = ?",
                [json_encode(listing_skills($item['description'] ?? '')), $location, $now, $changed ? 1 : 0, $now, $company['id']]);
        } else {
            $set_status('Unknown'); // not in the recent feed: unknown until verified at the source
        }
        return true;
    }
    refresh_web_row($run, $company, $details, $title);
    return true;
}

/** Refresh for a job found on the web: its page, its employer's site, whether it is still open. */
function refresh_web_row(array $run, array $company, array $details, string $title): void
{
    $source_url = (string) $company['source_url'];
    $career_url = (string) $company['career_url'];
    $wanted = $run['ctx']['wanted'] ?: [$title];
    $arrangement = detect_work_arrangement($title) ?? $company['work_arrangement'];
    $location = ['country' => null, 'state' => null, 'score' => 0, 'evidence' => []];
    $employer = null;
    $apply_closed = null;
    $ats_found = false;
    $repaired_domain = null;
    $landing = null;
    $skills = null;
    $credibility = 0;
    $status = $company['job_open_status'] ?: 'Open';
    if ($source_url !== '' && is_valid_url($source_url)) {
        $landing = fetch_page($source_url, false);
        if ($landing) {
            $location = merge_location_data($location, analyze_usa_location($landing, $title, 'search/landing page'));
            $arrangement = $arrangement ?? detect_work_arrangement('', $landing);
            if (is_third_party($source_url, $company['name'])) {
                $apply_closed = apply_link_closed($landing);
                if (empty($details['employer_site'])) {
                    $notes = [];
                    $employer = resolve_employer_site((string) $company['name'], $title, $source_url, $landing, $wanted, $notes);
                    if ($employer) {
                        $career_url = $employer['posting_url'] ?: $source_url;
                        $details['employer_site'] = ['domain' => $employer['domain'], 'careers_url' => $employer['careers_url'],
                            'method' => $employer['method'], 'posting_found' => (bool) $employer['posting_url']];
                        $details['original_source'] = $source_url;
                        $details['evidence'] = array_merge($details['evidence'] ?? [], $employer['evidence']);
                    }
                }
            }
        }
    }
    if (empty($details['ats_posting']) && $source_url !== '' && is_third_party($source_url, $company['name']) && !$company['is_kept']) {
        $board = company_board_posting((string) $company['name'], $title, microtime(true) + 15);
        if ($board) {
            $career_url = $board['url'];
            $details['ats_posting'] = ['system' => $board['system'], 'url' => $board['url']];
            $details['original_source'] ??= $source_url;
            $details['evidence'][] = "posting found on the company's own " . ucwords($board['system']) . ' board';
            $ats_found = true;
        }
    }
    $saved_site = $details['employer_site'] ?? [];
    $listing_host = get_domain($source_url);
    if (!empty($saved_site['domain']) && $listing_host !== $saved_site['domain'] && str_ends_with($listing_host, '.' . $saved_site['domain'])) {
        $details['employer_site']['domain'] = $repaired_domain = $listing_host;
        $details['employer_site']['posting_found'] = true;
    }
    $employer_row = !empty($details['employer_site']);
    $saved_careers = $details['employer_site']['careers_url'] ?? null;
    $repaired_view = $ats_found;
    if ($saved_careers && $source_url !== '' && $career_url === $saved_careers && $source_url !== $saved_careers) {
        $career_url = $source_url; // View goes to the job itself
        $repaired_view = true;
    }
    if ($career_url !== '' && is_valid_url($career_url)) {
        $GLOBALS['last_failure_status'] = null;
        $page = canonical_url($career_url) === canonical_url($source_url) && $landing ? $landing : fetch_page($career_url, false);
        if ($page) {
            if (!$employer_row || canonical_url($career_url) === canonical_url($source_url)) {
                $skills = listing_skills($page->html);
            }
            $postings = extract_jobs($page, [$title]);
            $matching = null;
            foreach ($postings as $posting) {
                if (canonical_url($posting['url']) === canonical_url($career_url)) {
                    $matching = $posting;
                }
            }
            if ($matching === null && count($postings) === 1 && canonical_url($page->url) === canonical_url($career_url)) {
                $matching = $postings[0];
            }
            $arrangement = ($matching['type'] ?? null) ?: ($arrangement ?? detect_work_arrangement('', $page));
            $credibility = score_career_page($page)['score'];
            $active = $details && $matching;
            if ($active) {
                $credibility = max(CAREER_CREDIBILITY_THRESHOLD, $credibility);
                if (identify_board($career_url)) {
                    $credibility = max(OFFICIAL_BOARD_CREDIBILITY, $credibility);
                }
            }
            if (!empty($details['ats_posting'])) {
                $credibility = max(OFFICIAL_BOARD_CREDIBILITY, $credibility);
            }
            if ($details && !$active && !$employer_row) {
                $status = 'Unknown';
            } elseif ($active || $credibility >= CAREER_CREDIBILITY_THRESHOLD) {
                $status = 'Open';
            } elseif ($credibility <= 2 && !$employer_row) {
                $status = 'Closed';
            }
            $location = merge_location_data($location, analyze_usa_location($page, $title, "career page $career_url"));
        } elseif (in_array($GLOBALS['last_failure_status'], [404, 410], true)) {
            $status = 'Closed';
        } else {
            $status = 'Unknown';
        }
    } else {
        $status = 'Closed';
    }
    if ($apply_closed) {
        $status = 'Closed';
    }
    if ($location['score'] === 0 && !$location['evidence']) {
        $location = ['country' => $company['country'], 'state' => $company['state'], 'score' => (int) $company['usa_credibility'], 'evidence' => []];
    }
    // A row saved before city radii gets its distance filled in.
    $found_place = null;
    if ($run['ctx']['city_targets'] && $landing && $company['distance_miles'] === null && !$company['city'] && $arrangement !== 'Remote') {
        [, $city, $lat, $lon, $miles] = distance_to_city_targets($landing, (string) ($details['location'] ?? $title), $run['ctx']['state'],
            $run['ctx']['city_targets'], on_company_site($source_url, $company['name'], $details));
        if ($lat !== null) {
            $found_place = [$city, $lat, $lon, $miles];
        }
    }
    $label = verification_label($details, $company['source_type'], $company['name'], $source_url);
    $details_dirty = $employer || $ats_found || $repaired_domain || ($details['verification'] ?? null) !== $label;
    $details['verification'] = $label;
    $changed = $company['work_arrangement'] !== $arrangement || (int) $company['career_credibility'] !== $credibility
        || ($company['job_open_status'] ?: 'Open') !== $status || $company['country'] !== $location['country']
        || $company['state'] !== $location['state'] || (int) $company['usa_credibility'] !== (int) $location['score']
        || $employer || $repaired_view || $found_place || $apply_closed;
    $now = now_utc();
    q("UPDATE companies SET career_credibility = ?, job_open_status = ?, country = ?, state = ?, usa_credibility = ?, work_arrangement = ?,
        listing_skills = COALESCE(?, listing_skills), domain = COALESCE(?, domain), career_url = COALESCE(?, career_url),
        listing_details = COALESCE(?, listing_details), city = COALESCE(?, city), latitude = COALESCE(?, latitude),
        longitude = COALESCE(?, longitude), distance_miles = COALESCE(?, distance_miles), last_checked = ?,
        result_updated_at = CASE WHEN ? THEN ? ELSE result_updated_at END WHERE id = ?", [
        $credibility, $status, $location['country'], $location['state'], (int) $location['score'], $arrangement,
        $skills !== null ? json_encode($skills) : null, $employer ? $employer['domain'] : $repaired_domain,
        ($employer || $repaired_view) ? $career_url : null,
        $details_dirty ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        $found_place[0] ?? null, $found_place[1] ?? null, $found_place[2] ?? null, $found_place[3] ?? null,
        $now, $changed ? 1 : 0, $now, $company['id']]);
}
