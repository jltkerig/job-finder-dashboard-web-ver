<?php
// Downloading politely: a pause between requests to the same site, a size cap, only web pages (no PDFs or
// files), several pages at once when there are many, and a cache so a page is read at most once a day. Ported
// from the desktop app's fetching.py.

declare(strict_types=1);

require_once __DIR__ . '/html.php';

const USER_AGENT = 'Mozilla/5.0 (compatible; PersonalJobFinder/1.1; job search)';
const MAX_HTML_SIZE = 2000000;
const PAGE_CACHE_HOURS = 20;

/** The status of the last failed page download (404 and 410 mean the page was removed). */
$GLOBALS['last_failure_status'] = null;

/** One request. Returns ['status', 'body', 'url' (after redirects), 'type', 'headers' (lower-case names)]. */
function http_request(string $url, array $options = []): array
{
    $curl = curl_init($url);
    $headers = $options['headers'] ?? [];
    $response_headers = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $options['follow'] ?? true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_TIMEOUT => $options['timeout'] ?? 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => $options['agent'] ?? USER_AGENT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$response_headers) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $response_headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if (isset($options['json'])) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($options['json']));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }
    if (isset($options['max_bytes'])) {
        $limit = $options['max_bytes'];
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, fn($h, $total, $done) => $done > $limit ? 1 : 0);
    }
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false && $status === 0) {
        throw new RuntimeException($error ?: 'no answer');
    }
    return ['status' => $status, 'body' => (string) $body, 'url' => $final ?: $url, 'type' => $type, 'headers' => $response_headers];
}

/** JSON from an API (GET, or POST when $post is given); an error answer throws with its HTTP status. */
function http_json(string $url, ?array $post = null, array $headers = [], int $timeout = 30)
{
    $response = http_request($url, ['headers' => array_merge(['Accept: application/json'], $headers), 'timeout' => $timeout]
        + ($post !== null ? ['json' => $post] : []));
    if ($response['status'] !== 200) {
        throw new RuntimeException(parse_url($url, PHP_URL_HOST) . " answered HTTP {$response['status']}");
    }
    $data = json_decode($response['body'], true);
    if ($data === null) {
        throw new RuntimeException(parse_url($url, PHP_URL_HOST) . ' did not send JSON');
    }
    return $data;
}

/** Waits until this site may be asked again (Tuning: "Pause between requests to one site"). */
function wait_for_host(string $url): void
{
    static $next = [];
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $delay = (float) (function_exists('tuning') ? tuning('request_delay_seconds') : 1);
    $now = microtime(true);
    $start = max($now, $next[$host] ?? 0.0);
    $next[$host] = $start + $delay;
    if ($start > $now) {
        usleep((int) (($start - $now) * 1e6));
    }
}

function page_from_cache(string $key): ?array
{
    $row = row('SELECT status, body, final_url, fetched_at FROM page_cache WHERE url_key = ?', [sha1($key)]);
    if (!$row || strtotime($row['fetched_at'] . ' UTC') < time() - PAGE_CACHE_HOURS * 3600) {
        return null;
    }
    return $row;
}

function page_to_cache(string $key, int $status, ?string $body, string $final_url): void
{
    $hash = sha1($key);
    q('DELETE FROM page_cache WHERE url_key = ?', [$hash]);
    q('INSERT INTO page_cache (url_key, url, status, body, final_url, fetched_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$hash, $key, $status, $body, $final_url, now_utc()]);
}

function clear_old_page_cache(): void
{
    q('DELETE FROM page_cache WHERE fetched_at < ?', [now_utc(-PAGE_CACHE_HOURS * 3600)]);
}

/** A web page as a Page, or null (not HTML, too big, a PDF, unavailable). Read from the cache when fresh. */
function fetch_page(string $url, bool $use_cache = true): ?Page
{
    if (!is_valid_url($url) || is_pdf_url($url)) {
        return null;
    }
    $key = canonical_url($url);
    if ($use_cache && ($cached = page_from_cache($key))) {
        if ((int) $cached['status'] !== 200 || $cached['body'] === null) {
            $GLOBALS['last_failure_status'] = (int) $cached['status'] ?: null;
            return null;
        }
        return new Page($cached['final_url'] ?: $url, $cached['body']);
    }
    wait_for_host($url);
    return store_fetched($key, $url, fetch_raw_page($url));
}

/** Downloads one page; ['status', 'body', 'url', 'type'] or null when it couldn't be reached. */
function fetch_raw_page(string $url): ?array
{
    $timeout = (int) (function_exists('tuning') ? tuning('website_timeout_seconds') : 15);
    try {
        return http_request($url, ['timeout' => $timeout, 'max_bytes' => MAX_HTML_SIZE,
            'headers' => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5']]);
    } catch (Throwable $e) {
        return null;
    }
}

function store_fetched(string $key, string $url, ?array $response): ?Page
{
    if ($response === null) {
        $GLOBALS['last_failure_status'] = null;
        return null;
    }
    $ok = $response['status'] === 200 && stripos($response['type'], 'text/html') !== false
        && strlen($response['body']) <= MAX_HTML_SIZE;
    if (!$ok) {
        $GLOBALS['last_failure_status'] = $response['status'] >= 400 ? $response['status'] : null;
        page_to_cache($key, $response['status'] ?: 0, null, $response['url']);
        return null;
    }
    page_to_cache($key, 200, $response['body'], $response['url']);
    return new Page($response['url'], $response['body']);
}

/** Downloads several pages at once (Tuning: "Pages downloaded at once") so the checks after find them cached. */
function prefetch_pages(array $urls, ?float $deadline = null): void
{
    $todo = [];
    foreach (array_unique($urls) as $url) {
        if ($url && is_valid_url($url) && !is_pdf_url($url) && !page_from_cache(canonical_url($url))) {
            $todo[] = $url;
        }
    }
    if (count($todo) < 2) {
        return;
    }
    $parallel = (int) (function_exists('tuning') ? tuning('parallel_page_fetches') : 6);
    $timeout = (int) (function_exists('tuning') ? tuning('website_timeout_seconds') : 15);
    if ($deadline !== null) {
        $timeout = (int) max(3, min($timeout, $deadline - microtime(true)));
    }
    // One page per site at a time keeps the per-site pause; different sites download together.
    $by_host = [];
    foreach ($todo as $url) {
        $by_host[strtolower((string) parse_url($url, PHP_URL_HOST))][] = $url;
    }
    $batch = [];
    foreach ($by_host as $list) {
        $batch[] = $list[0];
    }
    foreach (array_chunk($batch, max(1, $parallel)) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($chunk as $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => USER_AGENT, CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5']]);
            curl_multi_add_handle($multi, $curl);
            $handles[$url] = $curl;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.5);
        } while ($running > 0);
        foreach ($handles as $url => $curl) {
            $body = curl_multi_getcontent($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $response = $status ? ['status' => $status, 'body' => (string) $body, 'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
                'url' => (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL)] : null;
            if ($response !== null) {
                store_fetched(canonical_url($url), $url, $response);
            }
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
        if ($deadline !== null && microtime(true) > $deadline) {
            break;
        }
    }
}

/** Any text file (sitemap.xml, robots.txt) as ['url', 'text'], or null; size-capped. */
function fetch_text(string $url, int $max_bytes = 6000000): ?array
{
    if (!is_valid_url($url)) {
        return null;
    }
    wait_for_host($url);
    try {
        $response = http_request($url, ['timeout' => (int) tuning('website_timeout_seconds'), 'max_bytes' => $max_bytes]);
    } catch (Throwable $e) {
        return null;
    }
    return $response['status'] === 200 ? ['url' => $response['url'], 'text' => $response['body']] : null;
}
