<?php
// Jobs saved by the Web Job Scraper extension, uploaded on the Search page (see includes/captures.php).
// POST /captures/import takes one or more jobs.json files; GET /captures/pending says how many captured jobs still
// wait for their company-website lookup.

declare(strict_types=1);
require_once APP_ROOT . '/includes/captures.php';

if ($path === '/captures/pending') {
    json_out(['lookups' => count(capture_lookups_pending(500))]);
}

$uploads = $_FILES['captures'] ?? null;
$files = [];
if ($uploads && is_array($uploads['name'])) {
    foreach ($uploads['name'] as $i => $name) {
        $error = (int) $uploads['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK || $uploads['size'][$i] > CAPTURE_MAX_FILE) {
            api_error('E6009', "$name is too large or did not upload. Each file can be up to 20 MB.");
        }
        $files[] = [basename((string) $name), (string) file_get_contents($uploads['tmp_name'][$i])];
    }
}
if (!$files) {
    api_error('E6010', 'Choose the jobs.json files the Web Job Scraper saved.');
}
set_time_limit(120);
$started = microtime(true);
$result = import_capture_files($files);
if ($result['error'] !== null && !$result['jobs']) {
    json_out(['status' => 'error', 'message' => $result['error'], 'log' => $result['log']], 400);
}
// company websites: looked up while the request has time, and by cron.php after that
$looked_up = run_capture_lookups(max(0.0, 20 - (microtime(true) - $started)));
json_out(['status' => 'ok', 'files' => $result['files'], 'jobs' => $result['jobs'], 'counts' => $result['counts'],
    'looked_up' => $looked_up, 'lookups_left' => count(capture_lookups_pending(500)), 'log' => $result['log']]);
