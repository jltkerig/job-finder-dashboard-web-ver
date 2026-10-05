<?php
// Remote-job feeds (Remote OK, Remotive, We Work Remotely) and job sites searched by title and place (National
// Labor Exchange, USAJOBS, Adzuna). Each provider's own listing stays the link and the provider is named, as their
// terms ask. Ported from the desktop app's job_feeds.py, remote_ok.py and job_sites.py.

declare(strict_types=1);

require_once __DIR__ . '/listings.php';
require_once __DIR__ . '/http.php';

const FEED_AGENT = 'PersonalJobFinder/1.1 (job search)';
// Four categories = four requests a day at most, inside Remotive's advice of about 4 a day.
const REMOTIVE_CATEGORIES = ['design', 'software-development', 'marketing', 'writing'];
const WWR_FEEDS = ['remote-design-jobs', 'remote-front-end-programming-jobs', 'remote-full-stack-programming-jobs', 'all-other-remote-jobs'];

/** load()'s result, reusing a saved copy younger than max_age seconds (or any saved copy if load() fails). */
function cached_feed(string $name, int $max_age, callable $load): array
{
    $stored = setting_json("feed:$name", null);
    if (is_array($stored) && time() - (int) ($stored['fetched_at'] ?? 0) < $max_age) {
        return $stored['data'];
    }
    try {
        $data = $load();
    } catch (Throwable $e) {
        if (is_array($stored)) {
            return $stored['data'];
        }
        throw $e;
    }
    set_setting_json("feed:$name", ['fetched_at' => time(), 'data' => $data]);
    return $data;
}

function feed_list(): array
{
    return [
        'Remote OK' => ['domain' => 'remoteok.com', 'hosts' => ['remoteok.com', 'www.remoteok.com'], 'fetch' => 'fetch_remote_ok', 'wide' => false],
        'Remotive' => ['domain' => 'remotive.com', 'hosts' => ['remotive.com', 'www.remotive.com'], 'fetch' => 'fetch_remotive', 'wide' => true],
        'We Work Remotely' => ['domain' => 'weworkremotely.com', 'hosts' => ['weworkremotely.com', 'www.weworkremotely.com'], 'fetch' => 'fetch_we_work_remotely', 'wide' => true],
    ];
}

function fetch_remote_ok(): array
{
    return cached_feed('remoteok', 3600, function () {
        $response = http_request('https://remoteok.com/api', ['agent' => FEED_AGENT, 'timeout' => 20, 'headers' => ['Accept: application/json']]);
        if ($response['status'] !== 200) {
            throw new RuntimeException("Remote OK answered HTTP {$response['status']}");
        }
        $jobs = [];
        foreach (json_decode($response['body'], true) ?: [] as $item) {
            if (is_array($item) && !empty($item['id']) && !empty($item['position'])) {
                $jobs[] = ['id' => (string) $item['id'], 'position' => (string) $item['position'], 'company' => (string) ($item['company'] ?? ''),
                    'location' => (string) ($item['location'] ?? ''), 'url' => (string) ($item['url'] ?? $item['apply_url'] ?? ''),
                    'description' => mb_substr((string) ($item['description'] ?? ''), 0, 200000),
                    'posted' => isset($item['date']) ? substr((string) $item['date'], 0, 10) : ''];
            }
        }
        return $jobs;
    });
}

function fetch_remotive(): array
{
    $jobs = [];
    $failures = 0;
    foreach (REMOTIVE_CATEGORIES as $category) {
        try {
            $items = cached_feed("remotive-$category", 24 * 3600, function () use ($category) {
                $data = http_json('https://remotive.com/api/remote-jobs?' . http_build_query(['category' => $category]), null, ['User-Agent: ' . FEED_AGENT], 20);
                return array_map(fn($i) => ['id' => (string) ($i['id'] ?? $i['url']), 'position' => (string) ($i['title'] ?? ''),
                    'company' => (string) ($i['company_name'] ?? ''), 'location' => (string) ($i['candidate_required_location'] ?? ''),
                    'url' => (string) ($i['url'] ?? ''), 'description' => mb_substr((string) ($i['description'] ?? ''), 0, 200000),
                    'salary' => (string) ($i['salary'] ?? ''), 'posted' => substr((string) ($i['publication_date'] ?? ''), 0, 10)], $data['jobs'] ?? []);
            });
        } catch (Throwable $e) {
            $failures++;
            continue;
        }
        foreach ($items as $item) {
            if ($item['url'] !== '') {
                $jobs[$item['url']] ??= $item;
            }
        }
    }
    if ($failures === count(REMOTIVE_CATEGORIES)) {
        throw new RuntimeException('Remotive is unavailable');
    }
    return array_values($jobs);
}

function fetch_we_work_remotely(): array
{
    $jobs = [];
    $failures = 0;
    foreach (WWR_FEEDS as $slug) {
        try {
            $items = cached_feed("wwr-$slug", 6 * 3600, function () use ($slug) {
                $response = http_request("https://weworkremotely.com/categories/$slug.rss", ['agent' => FEED_AGENT, 'timeout' => 20]);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("We Work Remotely answered HTTP {$response['status']}");
                }
                $xml = @simplexml_load_string($response['body']);
                if ($xml === false) {
                    throw new RuntimeException('We Work Remotely feed could not be read');
                }
                $items = [];
                foreach ($xml->channel->item as $node) {
                    $title = trim((string) $node->title);
                    $link = trim((string) $node->link) ?: trim((string) $node->guid);
                    if ($title === '' || $link === '') {
                        continue;
                    }
                    [$company, $position] = str_contains($title, ': ') ? explode(': ', $title, 2) : ['', $title];
                    $expires = trim((string) $node->expires_at);
                    if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) {
                        continue;
                    }
                    $posted = strtotime((string) $node->pubDate);
                    $items[] = ['id' => trim((string) $node->guid) ?: $link, 'position' => trim($position), 'company' => trim($company),
                        'location' => trim((string) $node->region), 'url' => $link, 'description' => (string) $node->description,
                        'posted' => $posted ? gmdate('Y-m-d', $posted) : '', 'type' => trim((string) $node->type)];
                }
                return $items;
            });
        } catch (Throwable $e) {
            $failures++;
            continue;
        }
        foreach ($items as $item) {
            $jobs[$item['url']] ??= $item;
        }
    }
    if ($failures === count(WWR_FEEDS)) {
        throw new RuntimeException('We Work Remotely is unavailable');
    }
    return array_values($jobs);
}

/** Feed listings with a matching title, open to U.S. applicants, and linked on the provider's own site. */
function feed_matching(string $name, array $feed, array $jobs, array $titles, bool $usa_only): array
{
    $out = [];
    $eligible_words = $feed['wide'] ? '/\b(?:Anywhere|Worldwide|Global|World|Americas?|North(?:ern)? America)\b/i' : '/\b(?:Anywhere|Worldwide|Global)\b/i';
    foreach ($jobs as $job) {
        $position = (string) ($job['position'] ?? '');
        if (!matching_title($position, $titles)) {
            continue;
        }
        $location = (string) ($job['location'] ?? '');
        $explicit_us = (bool) preg_match('/\b(?:USA?|United States)\b/i', $location);
        $eligible = $explicit_us || preg_match($eligible_words, $location);
        if ($usa_only && !$eligible) {
            continue;
        }
        $url = (string) ($job['url'] ?? '');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || !in_array(parse_url($url, PHP_URL_HOST), $feed['hosts'], true)) {
            continue;
        }
        $description = mb_substr((string) ($job['description'] ?? ''), 0, 200000);
        $out[] = ['name' => mb_substr(trim((string) ($job['company'] ?? '')), 0, 255), 'title' => mb_substr($position, 0, 255), 'url' => $url,
            'location' => $location, 'html' => $description, 'text' => html_to_text($description), 'posted' => $job['posted'] ?? null,
            'usa_score' => $explicit_us ? 6 : ($eligible ? 5 : 0)];
    }
    return $out;
}

// --- Job sites searched by title and place --------------------------------------------------------------------

final class SiteBlocked extends RuntimeException
{
}

function site_list(): array
{
    $keys = config();
    return [
        'National Labor Exchange' => ['domain' => 'usnlx.com', 'configured' => true, 'setup' => ''],
        'USAJOBS' => ['domain' => 'usajobs.gov', 'configured' => !empty($keys['usajobs']['key']) && !empty($keys['usajobs']['email']),
            'setup' => "Add your USAJOBS key and email to config.php (free key: developer.usajobs.gov)."],
        'Adzuna' => ['domain' => 'adzuna.com', 'configured' => !empty($keys['adzuna']['app_id']) && !empty($keys['adzuna']['app_key']),
            'setup' => 'Add your Adzuna app id and key to config.php (free keys: developer.adzuna.com).'],
    ];
}

function site_pause(string $site, float $delay): void
{
    static $last = [];
    $wait = $delay - (microtime(true) - ($last[$site] ?? 0.0));
    if ($wait > 0) {
        usleep((int) ($wait * 1e6));
    }
    $last[$site] = microtime(true);
}

function full_state_place(string $place): string
{
    [$city, $state] = array_pad(explode(',', $place, 2), 2, '');
    $full = STATE_NAMES[strtoupper(trim($state))] ?? null;
    return $full ? trim($city) . ", $full" : trim($place);
}

/** Openings for a title near a place (a city, ZIP code or state) from one job site, most relevant first. */
function site_search(string $site, string $keyword, string $place = '', ?int $radius = null): array
{
    $listings = [];
    if ($site === 'National Labor Exchange') {
        for ($page = 1; $page <= 2; $page++) {
            site_pause($site, 1.5);
            $params = ['q' => $keyword, 'page' => $page] + ($place !== '' ? ['location' => $place] : []) + ($radius ? ['r' => $radius] : []);
            $response = http_request('https://prod-search-api.jobsyn.org/api/v1/solr/jobs?' . http_build_query($params),
                ['headers' => ['Accept: application/json', 'x-origin: usnlx.com'], 'timeout' => 30]);
            if (in_array($response['status'], [403, 429], true)) {
                throw new SiteBlocked("$site answered HTTP {$response['status']}");
            }
            if ($response['status'] !== 200) {
                throw new RuntimeException("$site answered HTTP {$response['status']}");
            }
            $data = json_decode($response['body'], true) ?? [];
            foreach ($data['jobs'] ?? [] as $job) {
                if (empty($job['guid'])) {
                    continue;
                }
                $guid = strtoupper((string) $job['guid']);
                $city = (string) ($job['city_exact'] ?? '');
                $state = (string) ($job['state_short_exact'] ?? '');
                [$lat, $lon] = array_pad(array_map('floatval', explode(',', (string) ($job['GeoLocation'] ?? ''))), 2, null);
                $listings[] = ['guid' => $guid, 'title' => trim((string) ($job['title_exact'] ?? '')), 'company' => trim((string) ($job['company_exact'] ?? '')),
                    'url' => "https://usnlx.com/$guid/job/", 'location' => (string) ($job['location_exact'] ?? '') ?: implode(', ', array_filter([$city, $state])),
                    'city' => $city, 'state' => $state, 'country' => (string) ($job['country_exact'] ?? ''), 'lat' => $lat ?: null, 'lon' => $lon ?: null,
                    'posted' => substr((string) ($job['date_new'] ?? $job['date_added'] ?? ''), 0, 10),
                    'description' => trim(html_entity_decode((string) ($job['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            }
            if (empty($data['pagination']['has_more_pages'])) {
                break;
            }
        }
        return $listings;
    }
    if ($site === 'USAJOBS') {
        $keys = config()['usajobs'];
        for ($page = 1; $page <= 2; $page++) {
            site_pause($site, 1.0);
            $params = ['Keyword' => $keyword, 'ResultsPerPage' => 25, 'Page' => $page, 'Fields' => 'Full', 'WhoMayApply' => 'public', 'DatePosted' => 30];
            if ($place !== '') {
                $params['LocationName'] = full_state_place($place);
                if ($radius) {
                    $params['Radius'] = $radius;
                }
            }
            $response = http_request('https://data.usajobs.gov/api/search?' . http_build_query($params), ['agent' => $keys['email'],
                'headers' => ['Host: data.usajobs.gov', 'Authorization-Key: ' . $keys['key']], 'timeout' => 30]);
            if ($response['status'] === 429) {
                throw new SiteBlocked("$site answered HTTP 429");
            }
            if (in_array($response['status'], [401, 403], true)) {
                throw new RuntimeException("$site refused the API key (HTTP {$response['status']}). Check the USAJOBS key and email in config.php.");
            }
            if ($response['status'] !== 200) {
                throw new RuntimeException("$site answered HTTP {$response['status']}");
            }
            $items = json_decode($response['body'], true)['SearchResult']['SearchResultItems'] ?? [];
            foreach ($items as $item) {
                $job = $item['MatchedObjectDescriptor'] ?? [];
                $id = trim((string) ($job['PositionID'] ?? $item['MatchedObjectId'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $first = $job['PositionLocation'][0] ?? [];
                $shown = trim((string) ($first['LocationName'] ?? $job['PositionLocationDisplay'] ?? ''));
                $remote = (bool) preg_match('/anywhere in the u\.?s|remote/i', $shown);
                $details = $job['UserArea']['Details'] ?? [];
                $duties = $details['MajorDuties'] ?? null;
                $parts = [$details['JobSummary'] ?? null, is_array($duties) ? implode("\n", $duties) : $duties, $job['QualificationSummary'] ?? null,
                    $details['Requirements'] ?? null, $details['Education'] ?? null];
                $pay = $job['PositionRemuneration'][0] ?? [];
                if (!empty($pay['MinimumRange'])) {
                    $parts[] = "Pay: \${$pay['MinimumRange']} - \${$pay['MaximumRange']} per " . ($pay['Description'] ?? 'year');
                }
                $listings[] = ['guid' => $id, 'title' => trim((string) ($job['PositionTitle'] ?? '')),
                    'company' => trim((string) ($job['OrganizationName'] ?? $job['DepartmentName'] ?? '')), 'url' => "https://www.usajobs.gov/job/$id",
                    'location' => $remote ? 'United States (Remote)' : $shown, 'city' => $remote ? '' : trim(explode(',', $shown)[0]),
                    'state' => $remote ? '' : trim((string) ($first['CountrySubDivisionCode'] ?? '')),
                    'country' => trim((string) ($first['CountryCode'] ?? 'United States')),
                    'lat' => isset($first['Latitude']) ? (float) $first['Latitude'] : null, 'lon' => isset($first['Longitude']) ? (float) $first['Longitude'] : null,
                    'posted' => substr((string) ($job['PublicationStartDate'] ?? ''), 0, 10),
                    'description' => implode("\n\n", array_filter(array_map(fn($p) => trim(html_entity_decode((string) $p, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $parts), 'strlen'))];
            }
            if (count($items) < 25) {
                break;
            }
        }
        return $listings;
    }
    if ($site === 'Adzuna') {
        $keys = config()['adzuna'];
        site_pause($site, 3.0);
        $params = ['what' => $keyword, 'results_per_page' => 50, 'max_days_old' => 30, 'content-type' => 'application/json',
            'app_id' => $keys['app_id'], 'app_key' => $keys['app_key']];
        if ($place !== '') {
            $params['where'] = full_state_place($place);
            if ($radius) {
                $params['distance'] = max(1, (int) round($radius * 1.609344));
            }
        }
        $response = http_request('https://api.adzuna.com/v1/api/jobs/us/search/1?' . http_build_query($params), ['headers' => ['Accept: application/json'], 'timeout' => 30]);
        if ($response['status'] === 429) {
            throw new SiteBlocked("$site answered HTTP 429 (its free limit was reached)");
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new RuntimeException("$site refused the keys (HTTP {$response['status']}). Check the Adzuna keys in config.php.");
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("$site answered HTTP {$response['status']}");
        }
        foreach (json_decode($response['body'], true)['results'] ?? [] as $item) {
            $where = $item['location'] ?? [];
            $area = array_map('strval', $where['area'] ?? []);
            $shown = trim((string) ($where['display_name'] ?? ''));
            $remote = (bool) preg_match('/\bremote\b/i', ($item['title'] ?? '') . " $shown");
            $state = $area[1] ?? '';
            $city = count($area) > 2 ? end($area) : trim(explode(',', $shown)[0]);
            $code = state_code($state);
            if ($code !== '' && $city !== '' && ($area[0] ?? '') === 'US') {
                $shown = "$city, $code";
            }
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($item['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $parts = [$text];
            if (!empty($item['salary_min']) && !empty($item['salary_max'])) {
                $parts[] = 'Pay: $' . number_format((float) $item['salary_min']) . ' - $' . number_format((float) $item['salary_max']) . ' per year'
                    . ((string) ($item['salary_is_predicted'] ?? '') === '1' ? ' (estimated by Adzuna)' : '');
            }
            $parts[] = 'Jobs by Adzuna (https://www.adzuna.com)';
            // The link's `se` code changes with every search; without it the same ad keeps the same link.
            $url = preg_replace('/([?&])se=[^&]*&?/', '$1', (string) ($item['redirect_url'] ?? ''));
            $url = rtrim($url, '?&');
            if (empty($item['id']) || $url === '') {
                continue;
            }
            $listings[] = ['guid' => (string) $item['id'], 'title' => trim(html_entity_decode((string) ($item['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'company' => trim((string) ($item['company']['display_name'] ?? '')), 'url' => $url,
                'location' => $remote ? 'United States (Remote)' : $shown, 'city' => $remote ? '' : $city, 'state' => $remote ? '' : $state,
                'country' => 'United States', 'lat' => isset($item['latitude']) ? (float) $item['latitude'] : null,
                'lon' => isset($item['longitude']) ? (float) $item['longitude'] : null, 'posted' => substr((string) ($item['created'] ?? ''), 0, 10),
                'description' => implode("\n\n", array_filter($parts, 'strlen'))];
        }
        return $listings;
    }
    return [];
}

/** 'Open' while a job site still lists the job, 'Closed' once it's gone, 'Unknown' when that can't be asked. */
function site_status(string $site, string $url): string
{
    if ($site !== 'National Labor Exchange' || !preg_match('#/([0-9A-Fa-f]{32})/job#', $url, $m)) {
        return 'Unknown'; // USAJOBS and Adzuna can't look up one listing; a real job is never marked closed by guessing
    }
    try {
        site_pause($site, 1.5);
        $data = http_json('https://prod-search-api.jobsyn.org/api/v1/solr/jobs?' . http_build_query(['q' => 'guid:' . strtoupper($m[1])]),
            null, ['x-origin: usnlx.com']);
    } catch (Throwable $e) {
        return 'Unknown';
    }
    return (int) ($data['pagination']['total'] ?? 0) > 0 ? 'Open' : 'Closed';
}

/** [place, radius] pairs to search: each city with its radius, each whole state, or the state box. */
function search_places(string $state_text, array $cities): array
{
    $places = [];
    foreach ($cities as $item) {
        $city = trim((string) ($item['city'] ?? ''));
        if ($city === '') {
            continue;
        }
        $places[] = isset(US_STATES[mb_strtolower($city)]) ? [$city, null] : [$city, (int) ($item['radius'] ?? 25) ?: 25];
    }
    if (!$places && $state_text !== '') {
        foreach (preg_split('/[,\/;]/', $state_text) as $part) {
            if (trim($part) !== '') {
                $places[] = [trim($part), null];
            }
        }
    }
    return array_values(array_unique($places, SORT_REGULAR));
}

/** The site's own location field names a U.S. state or the United States ("Austin, TX", "United States (Remote)"). */
function site_location_in_us(?string $location): bool
{
    return (bool) (find_state_from_text((string) $location) || preg_match('/united states|(?<![A-Za-z])USA?(?![A-Za-z])/i', (string) $location));
}
