<?php
// Search settings you can change on the Tuning page (saved in the settings table), and the Board Health report
// of what each source did in the last search. Ported from the desktop app's tuning.py and board_health.py; the
// Docker and SearXNG options are gone because the web version searches with Brave.

declare(strict_types=1);

// key => [label, help, kind, minimum, maximum, default]
const TUNING_FIELDS = [
    'search_time_limit_minutes' => ['Search time limit (minutes)', 'A search stops after this long.', 'int', 1, 240, 60],
    'max_search_results' => ['Jobs to find per search', 'A search stops once it has saved this many new jobs.', 'int', 1, 100, 10],
    'max_search_pages' => ['Result pages per query', 'How many pages of Brave results to read for each search query. Each page is one search from your Brave allowance.', 'int', 1, 10, 1],
    'request_delay_seconds' => ['Pause between requests to one site (seconds)', 'Lower is faster; keep at 1 or more to stay polite.', 'float', 0, 10, 1],
    'search_query_delay_seconds' => ['Pause between search-engine queries (seconds)', "Brave's free plan allows one search a second.", 'float', 0, 10, 1.1],
    'website_timeout_seconds' => ['Wait for a slow page (seconds)', 'A page that has not answered by then is skipped.', 'int', 5, 60, 15],
    'parallel_page_fetches' => ['Pages downloaded at once', 'More is faster but uses more of the server\'s connection.', 'int', 1, 12, 6],
    'stop_after_empty_queries' => ['Stop after this many empty queries in a row', 'Many empty queries usually mean the search engine is refusing us.', 'int', 2, 30, 8],
];

// key => [label, help, default]
const TUNING_SWITCHES = [
    'usa_only' => ['U.S. jobs only', 'Skip jobs that are outside the United States or unverified.', true],
    'exclude_internships' => ['Skip internships and co-ops', 'Leave out jobs titled intern, internship or co-op.', true],
    'related_titles' => ['Also match closely related titles', 'Recognise titles such as Multimedia Designer or Production Artist when you typed Designer or Production Specialist.', true],
];

// status => what it means for the reader
const HEALTH_STATUSES = ['ok' => 'Working', 'no matches' => 'Working, no matching titles', 'blocked' => 'Blocked',
    'down' => 'Down', 'off' => 'Turned off'];

function read_tuning_settings(): array
{
    return setting_json('tuning', []);
}

/** A tuning value, or its standard value when it was never changed. */
function tuning(string $key)
{
    $values = read_tuning_settings();
    if (isset(TUNING_FIELDS[$key])) {
        return $values[$key] ?? TUNING_FIELDS[$key][5];
    }
    return $values[$key] ?? TUNING_SWITCHES[$key][2] ?? null;
}

/** [new settings, error]. Only the known options change; anything else saved is kept. */
function apply_tuning_form(array $form, array $current): array
{
    $updated = $current;
    foreach (TUNING_FIELDS as $key => [$label, , $kind, $low, $high]) {
        $raw = trim((string) ($form[$key] ?? ''));
        if (!is_numeric($raw) || ($kind === 'int' && (string) (int) $raw !== $raw)) {
            return [null, "$label must be a number."];
        }
        $value = $kind === 'int' ? (int) $raw : (float) $raw;
        if ($value < $low || $value > $high) {
            return [null, "$label must be between $low and $high."];
        }
        $updated[$key] = $kind === 'float' && floor($value) == $value ? (int) $value : $value;
    }
    foreach (TUNING_SWITCHES as $key => $_) {
        $updated[$key] = ($form[$key] ?? '') === 'on';
    }
    return [$updated, null];
}

/** 'blocked' when a site refused us (401, 403, 429, a bot wall), otherwise 'down'. */
function classify_error(string $error): string
{
    return preg_match('/\b(?:401|403|429)\b|not authorized|forbidden|captcha|too many requests/i', $error) ? 'blocked' : 'down';
}

/** Notes what one board or feed did in the running search (saved to the report when the run ends). */
function note_board(array &$boards, string $name, string $kind, string $status, int $matches = 0, int $saved = 0, string $detail = ''): void
{
    $boards["$kind|$name"] = ['name' => $name, 'kind' => $kind, 'status' => $status, 'matches' => $matches,
        'saved' => $saved, 'detail' => mb_substr($detail, 0, 200)];
}

function write_board_health(array $boards, string $run): void
{
    if (!$boards) {
        return; // a run that noted nothing (an early stop) keeps the previous report
    }
    $list = array_values($boards);
    usort($list, fn($a, $b) => [$a['kind'], mb_strtolower($a['name'])] <=> [$b['kind'], mb_strtolower($b['name'])]);
    set_setting_json('board_health', ['updated' => gmdate('c'), 'run' => $run, 'boards' => $list]);
}

function read_board_health(): ?array
{
    $health = setting_json('board_health', null);
    return is_array($health) && isset($health['boards']) ? $health : null;
}
