<?php
// JobAps job portals (jobapscloud.com/<agency>), such as the State of Maryland's job openings (agency "MD"). The
// portal publishes its whole list as one RSS feed, so a search reads every opening with one request; a job's own
// page carries JobPosting data with the full announcement. Maryland lists a county, not a town, for most jobs, so a
// job's town is read from its "Location of Position" address when that names a town Job Finder knows, and is
// otherwise the county's seat. Ported from the desktop app's employer_jobaps.py.

declare(strict_types=1);

const JOBAPS_COUNTY_SEATS = [
    'allegany' => 'Cumberland', 'anne arundel' => 'Annapolis', 'baltimore' => 'Baltimore', 'baltimore city' => 'Baltimore',
    'baltimore county' => 'Towson', 'calvert' => 'Prince Frederick', 'caroline' => 'Denton', 'carroll' => 'Westminster',
    'cecil' => 'Elkton', 'charles' => 'La Plata', 'dorchester' => 'Cambridge', 'frederick' => 'Frederick', 'garrett' => 'Oakland',
    'harford' => 'Bel Air', 'howard' => 'Columbia', 'kent' => 'Chestertown', 'montgomery' => 'Rockville',
    "prince george's" => 'Upper Marlboro', "queen anne's" => 'Centreville', 'somerset' => 'Princess Anne', "st. mary's" => 'Leonardtown',
    'talbot' => 'Easton', 'washington' => 'Hagerstown', 'washington county' => 'Hagerstown', 'wicomico' => 'Salisbury',
    'worcester' => 'Snow Hill',
];
const JOBAPS_ACRONYMS = ['IT', 'HR', 'II', 'III', 'IV', 'MD', 'GIS', 'CAD', 'EMS', 'RN', 'LPN', 'CDL', 'MBE', 'DNR', 'MVA', 'PC', 'AV', 'ID'];

/** An all-capitals class title ("IT PROGRAMMER ANALYST") as words ("IT Programmer Analyst"); other text as it is. */
function jobaps_tidy(string $text): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '' || $text !== mb_strtoupper($text)) {
        return $text;
    }
    return preg_replace_callback("/[A-Za-z][A-Za-z']*/", fn($m) => in_array($m[0], JOBAPS_ACRONYMS, true) ? $m[0] : ucfirst(strtolower($m[0])), $text);
}

/** The title to show and match. Maryland writes "CLASS TITLE (Working title)" or "Working title (CLASS TITLE)"; the working
 * title says what the job is, so it is used on its own (not when the brackets only name the unit). */
function jobaps_working_title(string $raw): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $raw));
    $text = preg_replace('/\s*-?\s*#\s*\d{2}-\d{6}-\d{4}\s*$/', '', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/\*[^*]*\*/', ' ', $text)));
    $unit = '/\b(?:division|unit|office|bureau|section|branch|department|administration|team|center)\s*$/i';
    if (preg_match('/^(.+?)\s*\(([^()]*)\)$/u', $text, $m)) {
        $first = trim($m[1]);
        $second = trim($m[2]);
        $first_upper = $first === mb_strtoupper($first);
        $second_upper = $second === mb_strtoupper($second);
        if ($first_upper !== $second_upper && $second !== '' && !preg_match($unit, $second)) {
            return jobaps_tidy($first_upper ? $second : $first);
        }
        if ($first_upper && !$second_upper && preg_match($unit, $second)) {
            return jobaps_tidy($first);
        }
    }
    return jobaps_tidy($text);
}

/** 26-004547-0006 from a job's address (R1=26&R2=004547&R3=0006) or from "... - #26-004547-0006"; '' if there is none. */
function jobaps_job_number(string $text): string
{
    if (preg_match('/#\s*(\d{2})-(\d{6})-(\d{4})\s*$/', trim($text), $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    parse_str((string) parse_url($text, PHP_URL_QUERY), $query);
    $query = array_change_key_case($query);
    return isset($query['r1'], $query['r2'], $query['r3']) ? "{$query['r1']}-{$query['r2']}-{$query['r3']}" : '';
}

/** "2026-10-14" from "10/14/2026 4:00:00 PM"; '' when there is no date. */
function jobaps_date(string $text): string
{
    return preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : '';
}

/** Every opening in the portal's RSS feed: [{id, title, url, department, salary, kind, opened, deadline, places}]. */
function jobaps_parse_feed(string $text): array
{
    $jobs = [];
    if (!preg_match_all('#<item>(.*?)</item>#s', $text, $items)) {
        return [];
    }
    foreach ($items[1] as $block) {
        $tag = function (string $name) use ($block): string {
            return preg_match("#<$name>(.*?)</$name>#s", $block, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        };
        $url = $tag('link');
        $title = $tag('title');
        $number = jobaps_job_number($url) ?: jobaps_job_number($title);
        if ($url === '' || $number === '') {
            continue;
        }
        $html = $tag('description');
        $facts = ['kind' => '', 'places' => []];
        foreach (['Department' => 'department', 'Salary' => 'salary', 'Date Opened' => 'opened', 'Filing Deadline' => 'deadline'] as $label => $key) {
            $facts[$key] = preg_match('#<strong>\s*' . preg_quote($label, '#') . ':\s*</strong>\s*([^<]*)#i', $html, $m)
                ? trim($m[1], " .\t\r\n") : '';
        }
        if (preg_match('#<strong>\s*Employment Type:\s*</strong>\s*<ul>\s*<li>([^<]*)</li>#i', $html, $m)) {
            $facts['kind'] = trim($m[1]);
        }
        if (preg_match('#<strong>\s*Work Location:\s*</strong>\s*<ul>(.*?)</ul>#is', $html, $m)
            && preg_match_all('#<li>([^<]*)</li>#', $m[1], $places)) {
            $facts['places'] = array_values(array_filter(array_map('trim', $places[1]), 'strlen'));
        }
        $jobs[] = ['id' => $number, 'title' => jobaps_working_title($title), 'url' => $url] + $facts;
    }
    return $jobs;
}

/** The town to measure from for a county the feed names ("Bel Air, MD, US" for Harford). */
function jobaps_seat(array $config, string $county): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $county));
    $state = strtoupper((string) ($config['state'] ?? ''));
    $town = $state === 'MD' ? (JOBAPS_COUNTY_SEATS[mb_strtolower($name)] ?? null) : null;
    return $state !== '' ? ($town ?? $name) . ", $state, US" : $name;
}

/** The board's listings, in the shape board_search returns: [title, id, path, location, raw]. */
function jobaps_listings(array $config): array
{
    $agency = rawurlencode((string) $config['agency']);
    $response = employer_get("https://www.jobapscloud.com/$agency/rss.asp", [], 'application/rss+xml, application/xml, text/xml');
    if ($response['status'] !== 200) {
        throw new RuntimeException("JobAps answered HTTP {$response['status']}");
    }
    $body = $response['body'];
    if (!mb_check_encoding($body, 'UTF-8')) {
        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }
    return array_map(fn($job) => ['title' => $job['title'], 'id' => $job['id'], 'path' => $job['id'],
        'location' => $job['places'] ? jobaps_seat($config, $job['places'][0]) : '', 'raw' => $job], jobaps_parse_feed($body));
}

/** The opening for one listing: the job's page gives the full announcement and its address. */
function jobaps_detail(array $employer, array $listing): ?array
{
    $job = $listing['raw'];
    $response = employer_get($job['url'], [], ACCEPT_HTML);
    if ($response['status'] !== 200) {
        return null;
    }
    $page = new Page($job['url'], $response['body']);
    $posting = [];
    foreach ($page->json_ld() as $data) {
        foreach (array_is_list($data) ? $data : [$data] as $node) {
            if (is_array($node) && ($node['@type'] ?? '') === 'JobPosting') {
                $posting = $node;
            }
        }
    }
    $description = str_replace(['\\r\\n', '\\n'], '<br>', (string) ($posting['description'] ?? ''));
    $facts = [];
    foreach ([['Agency', $job['department']], ['Salary', $job['salary']], ['Apply by', $job['deadline']]] as [$label, $value]) {
        if ($value !== '') {
            $facts[] = '<p>' . htmlspecialchars("$label: $value") . '</p>';
        }
    }
    $state = strtoupper((string) ($employer['state'] ?? ''));
    $town = jobaps_address_town($page, $state);
    $places = array_merge($town !== '' ? ["$town, $state, US"] : [], array_map(fn($p) => jobaps_seat($employer, $p), $job['places']));
    $opening = make_opening($employer, $job['title'], $job['url'], $places ?: [(string) $listing['location']],
        implode('', $facts) . $description, substr((string) ($posting['datePosted'] ?? ''), 0, 10) ?: jobaps_date($job['opened']),
        schedule_word((string) ($job['kind'] ?: ($posting['employmentType'] ?? ''))));
    $opening['salary'] = $job['salary'];
    return $opening;
}

/** The town in the job's "Location of Position" address ("... 201 W. Preston Street Baltimore, MD 21201"), when it is a
 * town Job Finder knows; else ''. */
function jobaps_address_town(Page $page, string $state): string
{
    if ($state === '') {
        return '';
    }
    $text = '';
    foreach ($page->all('//h2') as $heading) {
        if (stripos(node_text($heading), 'location of position') === false) {
            continue;
        }
        for ($node = $heading->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement && strtolower($node->nodeName) === 'h2') {
                break;
            }
            $text .= ' ' . trim((string) $node->textContent);
        }
        break;
    }
    if (!preg_match("/([A-Za-z.' -]+?)\\s*,\\s*(?:" . preg_quote($state, '/') . '|Maryland)\b/', $text, $m)) {
        return '';
    }
    $words = preg_split('/\s+/', trim($m[1]));
    foreach ([3, 2, 1] as $size) {  // the longest words that make a known town: "Prince Frederick" before "Frederick"
        if (count($words) >= $size) {
            $candidate = implode(' ', array_slice($words, -$size));
            if (place_point("$candidate, $state", $state)) {
                return $candidate;
            }
        }
    }
    return '';
}
