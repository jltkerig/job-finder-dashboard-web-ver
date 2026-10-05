<?php
// Brave Search: the web search engine (the desktop app runs SearXNG in Docker, which a web host can't). Each
// query uses one of your monthly Brave credits, so every search is counted: this month, all time, and against an
// optional testing limit. Credits reset at 00:00 UTC on the 1st of each month and don't carry over.

declare(strict_types=1);

require_once __DIR__ . '/http.php';

function brave_key(): string
{
    return trim((string) (config()['brave']['key'] ?? ''));
}

/** Searches allowed in total while testing (config.php 'limit'); 0 = no limit. */
function brave_limit(): int
{
    return (int) (config()['brave']['limit'] ?? 0);
}

/** Searches your plan includes each month (config.php 'monthly'), 0 if not set. */
function brave_monthly(): int
{
    return (int) (config()['brave']['monthly'] ?? 0);
}

function brave_used(): int
{
    return (int) setting('brave_used', '0');
}

function brave_month(?int $now = null): string
{
    return gmdate('Y-m', $now ?? time());
}

function brave_next_reset(?int $now = null): int
{
    $now = $now ?? time();
    return gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now));
}

/** Searches this month (0 again once a new month starts), in total, since when, and per month. */
function brave_counts(?int $now = null): array
{
    $month = setting('brave_month');
    return [
        'month' => $month === brave_month($now) ? (int) setting('brave_month_used', '0') : 0,
        'total' => (int) setting('brave_total', '0'),
        'since' => setting('brave_since'),
        'months' => json_decode((string) setting('brave_history', '{}'), true) ?: [],
    ];
}

function brave_record_search(?int $now = null): void
{
    $counts = brave_counts($now);
    $month = brave_month($now);
    $history = $counts['months'];
    $history[$month] = ($history[$month] ?? 0) + 1;
    set_setting('brave_used', (string) (brave_used() + 1));
    set_setting('brave_month', $month);
    set_setting('brave_month_used', (string) ($counts['month'] + 1));
    set_setting('brave_total', (string) ($counts['total'] + 1));
    set_setting('brave_history', json_encode($history));
    if ($counts['since'] === null) {
        set_setting('brave_since', gmdate('Y-m-d', $now ?? time()));
    }
}

/** Why no more Brave searches may be made right now, or null when one may. */
function brave_blocked_reason(): ?string
{
    if (brave_key() === '') {
        return 'Brave Search has no API key yet (add it to config.php).';
    }
    if (brave_limit() && brave_used() >= brave_limit()) {
        return 'Brave Search testing limit reached (' . brave_limit() . ' searches). Reset it on the Tuning page or change it in config.php.';
    }
    if (brave_monthly() && brave_counts()['month'] >= brave_monthly()) {
        return "This month's Brave Search credits are used up. They reset on " . gmdate('F j', brave_next_reset()) . '.';
    }
    return null;
}

/** One page of web results for a query: [['url', 'title', 'description'], ...]. Counted before it is sent. */
function brave_web_search(string $query, int $page = 1): array
{
    static $last = 0.0;
    if (brave_blocked_reason() !== null) {
        return [];
    }
    $delay = (float) tuning('search_query_delay_seconds');
    $wait = $delay - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int) ($wait * 1e6));
    }
    $search = tuning('usa_only') ? "$query United States" : $query;
    brave_record_search(); // counted before sending, so a failure still counts
    $last = microtime(true);
    try {
        $response = http_request('https://api.search.brave.com/res/v1/web/search?' . http_build_query([
            'q' => mb_substr($search, 0, 400), 'count' => 20, 'offset' => max(0, min(9, $page - 1)), 'country' => 'us', 'search_lang' => 'en']),
            ['headers' => ['Accept: application/json', 'X-Subscription-Token: ' . brave_key()], 'timeout' => 20]);
    } catch (Throwable $e) {
        return [];
    } finally {
        $last = microtime(true);
    }
    if ($response['status'] !== 200) {
        set_setting('brave_last_error', "HTTP {$response['status']}");
        return [];
    }
    $data = json_decode($response['body'], true);
    $results = [];
    foreach ($data['web']['results'] ?? [] as $item) {
        if (!empty($item['url'])) {
            $results[] = ['url' => (string) $item['url'], 'title' => html_to_text((string) ($item['title'] ?? '')),
                'description' => html_to_text((string) ($item['description'] ?? ''))];
        }
    }
    return $results;
}
