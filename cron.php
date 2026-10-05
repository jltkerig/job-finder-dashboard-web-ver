<?php
// A backstop for searches: a search moves on while the Search page is open (each status check works on it for a
// few seconds). If the page is closed, this keeps it going. Add it in hPanel > Advanced > Cron Jobs, every minute:
//     php /home/USER/public_html/cron.php
// It only runs from the command line, never from a browser.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/listings.php';
require __DIR__ . '/lib/search/runner.php';

$until = microtime(true) + 50;
while (microtime(true) < $until && search_running(search_state())) {
    run_slice(min(10.0, $until - microtime(true)));
}
// Uploaded Web Job Scraper jobs whose company website hasn't been looked up yet.
require_once __DIR__ . '/lib/captures.php';
if (!search_running(search_state()) && ($left = $until - microtime(true)) > 5) {
    $looked = run_capture_lookups($left);
    if ($looked) {
        echo "Looked up the company website for $looked captured job(s)\n";
    }
}
$run = search_state();
echo search_running($run) ? "Search still running: {$run['progress']}\n" : 'No search running' . (!empty($run['stop_reason']) ? " (last: {$run['stop_reason']})" : '') . "\n";
