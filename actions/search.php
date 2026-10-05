<?php
// Starting, refreshing, updating and stopping searches, and their status. The Search page asks for the status
// every two seconds while one runs; each time, the search moves on a few seconds (see includes/search/runner.php).

declare(strict_types=1);
require_once APP_ROOT . '/includes/search/runner.php';

const SLICE_SECONDS = 5.0;

$run = search_state();
$busy = fn() => json_out(['status' => 'already_running', 'mode' => $run['mode']]);

switch ($path) {
    case '/search-status':
        session_write_close(); // other pages stay usable while the search works
        if (search_running($run)) {
            run_slice(SLICE_SECONDS);
        }
        json_out(search_status_payload(search_state()));

    case '/stop-search':
        $status = request_stop();
        json_out(['status' => $status] + ($status === 'stopped' ? ['message' => 'No Job Finder process was running.'] : []));

    case '/start-search':
    case '/replace-result':
        if (search_running($run)) {
            $busy();
        }
        if ($path === '/replace-result') {
            $latest = get_search_history(1)[0] ?? null;
            if (!$latest) {
                json_out(['status' => 'no_results', 'message' => 'Start a search to save criteria before requesting a replacement.']);
            }
            $data = ['job_title' => $latest['job_title'], 'state' => $latest['state'], 'cities' => json_decode($latest['cities_json'] ?: '[]', true)];
            $mode = 'replacement';
        } else {
            $data = request_json();
            $mode = 'search';
        }
        [$job_title, $state, $cities, $error] = validate_search_criteria($data['job_title'] ?? '', $data['state'] ?? '', $data['cities'] ?? []);
        if ($error) {
            json_out(['status' => 'error', 'message' => $error], 400);
        }
        if ($mode === 'search') {
            // Search and the Dashboard profile share the same job titles and places.
            $profile = get_user_profile();
            $titles = [];
            foreach (explode(',', $job_title) as $part) {
                $title = mb_substr(trim($part), 0, 255);
                if ($title !== '' && !in_array(mb_strtolower($title), array_map('mb_strtolower', $titles), true)) {
                    $titles[] = $title;
                }
            }
            save_user_profile(['first_name' => $profile['first_name'], 'last_name' => $profile['last_name'], 'state' => $state,
                'job_titles' => $titles, 'cities' => $cities]);
        }
        $started = start_search_run($job_title, $state, $cities, $mode);
        if (is_string($started)) {
            json_out(['status' => 'error', 'message' => $started], 400);
        }
        json_out(['status' => 'started'], 202);

    case '/refresh-search':
    case '/update-existing':
        if (search_running($run)) {
            $busy();
        }
        $ids = null;
        if ($path === '/refresh-search') {
            $ids = request_json()['company_ids'] ?? null;
            if (!is_array($ids) || count($ids) > 500 || array_filter($ids, fn($v) => !is_int($v) || $v <= 0)) {
                json_out(['status' => 'error', 'message' => 'Invalid result selection.'], 400);
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                json_out(['status' => 'no_results', 'message' => 'There are no displayed listings to refresh.']);
            }
        }
        $started = start_refresh_run($ids, $path === '/refresh-search' ? 'refresh' : 'update');
        if (is_string($started)) {
            json_out(['status' => 'no_results', 'message' => $started]);
        }
        json_out(['status' => 'started'], 202);
}
