<?php
// The state of the current (or last) search, refresh or update: what the Search page's status checks report.
// Kept in the settings table as one JSON value, so it survives between requests.

declare(strict_types=1);

const RUN_KEY = 'search_run';

function search_state(): array
{
    return setting_json(RUN_KEY, []) ?: [];
}

function save_search_state(array $run): void
{
    set_setting_json(RUN_KEY, $run);
}

function search_running(array $run): bool
{
    return !empty($run) && empty($run['finished']);
}

/** What /search-status sends (the same fields the desktop app sends, so its script works unchanged). */
function search_status_payload(array $run): array
{
    $running = search_running($run);
    $searching = $running && in_array($run['mode'] ?? '', ['search', 'replacement'], true);
    return [
        'running' => $running,
        'mode' => $running ? $run['mode'] : null,
        'stopping' => $running && !empty($run['stopping']),
        'error' => empty($run['finished']) ? null : ($run['error'] ?? null),
        'progress' => $running ? ($run['progress'] ?? 'Starting search') : null,
        'passed' => $searching ? (int) ($run['passed'] ?? 0) : null,
        'saved' => $searching ? (int) ($run['saved'] ?? 0) : null,
        'limit' => $searching ? (int) ($run['limit'] ?? 0) : null,
        'checked' => $searching ? (int) ($run['checked'] ?? 0) : null,
        'elapsed_seconds' => $running ? max(0, time() - (int) ($run['started_at'] ?? time())) : 0,
        'stop_reason' => $running ? null : ($run['stop_reason'] ?? null),
    ];
}

/** Runs $work while holding the search lock; returns false straight away when another request holds it. */
function with_search_lock(callable $work): bool
{
    $dir = APP_ROOT . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $handle = fopen("$dir/search.lock", 'c');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        return false;
    }
    try {
        $work();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return true;
}
