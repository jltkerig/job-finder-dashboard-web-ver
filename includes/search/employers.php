<?php
// Employers' own career sites and hiring boards: Workday, Oracle, iCIMS, SuccessFactors, UltiPro, Greenhouse, Lever,
// Ashby, BambooHR, SmartRecruiters, ADP, Paylocity, Workable, NEOGOV, Recruitee and Teamtailor. Each publishes its
// openings without a login. Also recognising a board from a page address, boards found in web searches, and
// finding a job on the company's own board from its name. Ported from the desktop app's employer_jobs.py,
// ats_discovery.py, ats_lookup.py and ats_feeds.py.

declare(strict_types=1);

require_once __DIR__ . '/listings.php';
require_once __DIR__ . '/location.php';
require_once __DIR__ . '/http.php';

const EMPLOYER_SOURCE = 'Employer careers';
const MAX_KEYWORDS = 16;
const MAX_EMPLOYER_PAGES = 3;
const MAX_DETAILS = 30;
const MAX_DISCOVERED = 40;
const MIN_SEARCHES_BEFORE_DROP = 5;
const ACCEPT_HTML = 'text/html,application/xhtml+xml';

/** Requests to employer systems: a user agent, a timeout, and a short pause between calls. */
function employer_get(string $url, array $params = [], string $accept = 'application/json', array $headers = []): array
{
    employer_pause();
    if ($params) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }
    return http_request($url, ['headers' => array_merge(["Accept: $accept"], $headers), 'timeout' => 30]);
}

function employer_post(string $url, array $payload): array
{
    employer_pause();
    return http_request($url, ['json' => $payload, 'headers' => ['Accept: application/json'], 'timeout' => 30]);
}

function employer_pause(): void
{
    static $last = 0.0;
    $wait = 0.4 - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int) ($wait * 1e6));
    }
    $last = microtime(true);
}

function employer_json(string $url, array $params = [])
{
    $response = employer_get($url, $params);
    if ($response['status'] !== 200) {
        throw new RuntimeException(parse_url($url, PHP_URL_HOST) . " answered HTTP {$response['status']}");
    }
    return json_decode($response['body'], true) ?? [];
}

function work_arrangement_word(?string $text): ?string
{
    $text = str_replace('_', ' ', mb_strtolower((string) $text));
    if (str_contains($text, 'hybrid')) {
        return 'Hybrid';
    }
    if (str_contains($text, 'remote') || str_contains($text, 'virtual') || str_contains($text, 'telecommut')) {
        return 'Remote';
    }
    return preg_match('/on[- ]?site|in[- ]?office|in[- ]?person/', $text) ? 'Onsite' : null;
}

function schedule_word(?string $text): string
{
    $text = mb_strtolower((string) $text);
    return str_contains($text, 'full') ? 'FULL_TIME' : (str_contains($text, 'part') ? 'PART_TIME' : '');
}

/** One opening in the shape every source shares. */
function make_opening(array $employer, string $title, string $url, array $locations, ?string $html, string $posted = '',
                      string $schedule = '', ?string $arrangement = null, string $country = '', array $remote_states = [], string $category = ''): array
{
    $locations = array_values(array_unique(array_filter(array_map('strval', $locations), 'strlen')));
    // "Work At Home-Massachusetts" style places make a job remote and name the states it is open to.
    $remote_states = array_values(array_unique(array_merge($remote_states, place_states($locations))));
    if ($arrangement === null && $locations && !array_filter($locations, fn($p) => !is_remote_place($p))) {
        $arrangement = 'Remote';
    }
    return ['title' => mb_substr($title, 0, 255), 'url' => $url, 'company' => $employer['name'], 'location' => $locations[0] ?? '',
        'locations' => $locations, 'type' => $arrangement, 'schedule' => $schedule, 'salary' => '', 'posted' => $posted ?: null,
        'evidence' => ["{$employer['name']} careers site (" . ucwords($employer['system']) . ')', 'title matches search'],
        'description' => html_to_text(html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'html' => (string) $html,
        'country' => $country, 'remote_states' => $remote_states, 'category' => $category];
}

/** The listings ([title, id, path, location, raw]) an employer's board returns for one keyword. */
function board_search(array $config, string $keyword, array &$memo): array
{
    $system = $config['system'];
    // Boards that publish every opening in one request: read once per search, then title matching picks.
    if (in_array($system, ['greenhouse', 'lever', 'ashby', 'bamboohr', 'recruitee', 'teamtailor', 'ultipro', 'adp', 'paylocity'], true)) {
        if (!isset($memo['all'])) {
            $memo['all'] = board_whole($config);
        }
        return $memo['all'];
    }
    switch ($system) {
        case 'workday':
            $base = "https://{$config['host']}/wday/cxs/{$config['tenant']}/{$config['site']}";
            $listings = [];
            $offset = 0;
            for ($i = 0; $i < MAX_EMPLOYER_PAGES; $i++) {
                $response = employer_post("$base/jobs", ['appliedFacets' => (object) [], 'limit' => 20, 'offset' => $offset, 'searchText' => $keyword]);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("Workday answered HTTP {$response['status']}");
                }
                $data = json_decode($response['body'], true) ?? [];
                $posts = $data['jobPostings'] ?? [];
                foreach ($posts as $post) {
                    if (!empty($post['externalPath'])) {
                        $listings[] = ['title' => $post['title'] ?? '', 'path' => $post['externalPath'], 'id' => $post['externalPath'],
                            'location' => $post['locationsText'] ?? ''];
                    }
                }
                $offset += 20;
                if (count($posts) < 20 || $offset >= (int) ($data['total'] ?? 0)) {
                    break;
                }
            }
            return $listings;
        case 'oracle':
            $keyword = preg_replace('/[,;="]/', ' ', $keyword);
            $data = employer_json("https://{$config['host']}/hcmRestApi/resources/latest/recruitingCEJobRequisitions", [
                'onlyData' => 'true', 'expand' => 'requisitionList.secondaryLocations',
                'finder' => "findReqs;siteNumber={$config['site']},limit=25,keyword=$keyword,sortBy=POSTING_DATES_DESC"]);
            $items = $data['items'][0]['requisitionList'] ?? [];
            return array_values(array_map(fn($req) => ['title' => $req['Title'] ?? '', 'id' => (string) $req['Id'], 'path' => (string) $req['Id'],
                'location' => $req['PrimaryLocation'] ?? '', 'raw' => $req], array_filter($items, fn($r) => !empty($r['Id']))));
        case 'icims':
            $response = employer_get("https://{$config['host']}/jobs/search", ['ss' => 1, 'searchKeyword' => $keyword, 'in_iframe' => 1], ACCEPT_HTML);
            if ($response['status'] !== 200) {
                throw new RuntimeException("iCIMS answered HTTP {$response['status']}");
            }
            $page = new Page("https://{$config['host']}/jobs/search", $response['body']);
            $listings = [];
            foreach ($page->all('//a[@href]') as $a) {
                if (!preg_match('#/jobs/(\d+)/#', $a->getAttribute('href'), $m) || isset($listings[$m[1]])) {
                    continue;
                }
                $heading = $page->all('.//h3 | .//h2', $a)[0] ?? $a;
                $title = preg_replace('/^Title\s+/', '', node_text($heading));
                if ($title !== '') {
                    $listings[$m[1]] = ['title' => $title, 'id' => $m[1], 'path' => $a->getAttribute('href'), 'location' => ''];
                }
            }
            return array_values($listings);
        case 'successfactors':
            $listings = [];
            foreach ([0, 25] as $start) {
                $response = employer_get("https://{$config['host']}/search/", ['q' => $keyword, 'locationsearch' => '', 'startrow' => $start], ACCEPT_HTML);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("SuccessFactors answered HTTP {$response['status']}");
                }
                $page = new Page("https://{$config['host']}/search/", $response['body']);
                $found = $page->all("//a[contains(concat(' ', normalize-space(@class), ' '), ' jobTitle-link ')]");
                foreach ($found as $a) {
                    $href = $a->getAttribute('href');
                    if ($href !== '' && !isset($listings[$href])) {
                        $listings[$href] = ['title' => node_text($a), 'id' => $href, 'path' => $href, 'location' => ''];
                    }
                }
                if (count($found) < 25) {
                    break;
                }
            }
            return array_values($listings);
        case 'smartrecruiters':
            $listings = [];
            $offset = 0;
            for ($i = 0; $i < MAX_EMPLOYER_PAGES; $i++) {
                $data = employer_json("https://api.smartrecruiters.com/v1/companies/{$config['company']}/postings", ['q' => $keyword, 'limit' => 100, 'offset' => $offset]);
                $batch = $data['content'] ?? [];
                foreach ($batch as $job) {
                    $location = $job['location'] ?? [];
                    $place = $location['fullLocation'] ?? implode(', ', array_filter([$location['city'] ?? '', $location['region'] ?? '', strtoupper($location['country'] ?? '')]));
                    $listings[] = ['title' => $job['name'] ?? '', 'id' => (string) $job['id'], 'path' => (string) $job['id'], 'location' => $place, 'raw' => $job];
                }
                $offset += 100;
                if (count($batch) < 100 || $offset >= (int) ($data['totalFound'] ?? 0)) {
                    break;
                }
            }
            return $listings;
        case 'workable':
            $listings = [];
            $token = null;
            for ($i = 0; $i < MAX_EMPLOYER_PAGES; $i++) {
                $payload = ['query' => $keyword, 'location' => [], 'department' => [], 'worktype' => [], 'remote' => []];
                if ($token) {
                    $payload['token'] = $token;
                }
                $response = employer_post("https://apply.workable.com/api/v3/accounts/{$config['slug']}/jobs", $payload);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("Workable answered HTTP {$response['status']}");
                }
                $data = json_decode($response['body'], true) ?? [];
                foreach ($data['results'] ?? [] as $job) {
                    $location = $job['location'] ?? [];
                    $listings[] = ['title' => $job['title'] ?? '', 'id' => $job['shortcode'] ?? '', 'path' => $job['shortcode'] ?? '',
                        'location' => implode(', ', array_filter([$location['city'] ?? '', $location['region'] ?? '', $location['countryCode'] ?? ''])), 'raw' => $job];
                }
                $token = $data['nextPage'] ?? null;
                if (!$token) {
                    break;
                }
            }
            return $listings;
        case 'neogov':
            $listings = [];
            for ($p = 1; $p <= MAX_EMPLOYER_PAGES; $p++) {
                $response = employer_get('https://www.governmentjobs.com/careers/home/index', ['agency' => $config['agency'], 'keyword' => $keyword, 'page' => $p],
                    ACCEPT_HTML, ['X-Requested-With: XMLHttpRequest']);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("NEOGOV answered HTTP {$response['status']}");
                }
                $page = new Page('https://www.governmentjobs.com/', $response['body']);
                $fresh = 0;
                foreach ($page->all("//li[contains(concat(' ', normalize-space(@class), ' '), ' list-item ')][@data-job-id]") as $item) {
                    $link = $page->all(".//a[contains(@class, 'item-details-link')]", $item)[0] ?? ($page->all('.//a[@href]', $item)[0] ?? null);
                    $job_id = $item->getAttribute('data-job-id');
                    if (!$link || $job_id === '' || isset($listings[$job_id])) {
                        continue;
                    }
                    $fresh++;
                    $meta = array_map('node_text', $page->all(".//ul[contains(@class, 'list-meta')]/li", $item));
                    $listings[$job_id] = ['title' => node_text($link), 'id' => $job_id, 'path' => $link->getAttribute('href'), 'location' => $meta[0] ?? ''];
                }
                if (!$fresh) {
                    break;
                }
            }
            return array_values($listings);
    }
    throw new RuntimeException("Unknown board system $system");
}

/** Every opening on a board that publishes them all at once. */
function board_whole(array $config): array
{
    $list = fn(array $jobs, callable $make) => array_values(array_map($make, $jobs));
    switch ($config['system']) {
        case 'greenhouse':
            $jobs = employer_json("https://boards-api.greenhouse.io/v1/boards/{$config['slug']}/jobs", ['content' => 'true'])['jobs'] ?? [];
            return $list($jobs, fn($j) => ['title' => $j['title'] ?? '', 'id' => (string) ($j['id'] ?? ''), 'path' => (string) ($j['id'] ?? ''),
                'location' => $j['location']['name'] ?? '', 'raw' => $j]);
        case 'lever':
            $data = employer_json('https://api' . (!empty($config['eu']) ? '.eu' : '') . ".lever.co/v0/postings/{$config['slug']}", ['mode' => 'json']);
            return array_is_list($data) ? $list($data, fn($j) => ['title' => $j['text'] ?? '', 'id' => (string) ($j['id'] ?? ''),
                'path' => (string) ($j['id'] ?? ''), 'location' => $j['categories']['location'] ?? '', 'raw' => $j]) : [];
        case 'ashby':
            $jobs = array_filter(employer_json("https://api.ashbyhq.com/posting-api/job-board/{$config['slug']}")['jobs'] ?? [], fn($j) => $j['isListed'] ?? true);
            return $list($jobs, fn($j) => ['title' => $j['title'] ?? '', 'id' => (string) ($j['id'] ?? ''), 'path' => (string) ($j['id'] ?? ''),
                'location' => $j['location'] ?? '', 'raw' => $j]);
        case 'bamboohr':
            $jobs = employer_json("https://{$config['slug']}.bamboohr.com/careers/list")['result'] ?? [];
            return $list($jobs, fn($j) => ['title' => $j['jobOpeningName'] ?? '', 'id' => (string) ($j['id'] ?? ''), 'path' => (string) ($j['id'] ?? ''),
                'location' => bamboo_place($j['atsLocation'] ?? $j['location'] ?? []), 'raw' => $j]);
        case 'recruitee':
            $jobs = employer_json("https://{$config['slug']}.recruitee.com/api/offers/")['offers'] ?? [];
            return $list($jobs, fn($j) => ['title' => $j['title'] ?? '', 'id' => (string) ($j['id'] ?? ''), 'path' => (string) ($j['id'] ?? ''),
                'location' => implode(', ', array_filter([$j['city'] ?? '', $j['state_code'] ?? '', $j['country_code'] ?? ''])) ?: ($j['location'] ?? ''), 'raw' => $j]);
        case 'teamtailor':
            $response = employer_get("https://{$config['slug']}.teamtailor.com/jobs.rss", [], 'application/rss+xml,application/xml');
            if ($response['status'] !== 200) {
                throw new RuntimeException("{$config['slug']}.teamtailor.com answered HTTP {$response['status']}");
            }
            $xml = @simplexml_load_string($response['body']);
            $jobs = [];
            foreach ($xml ? $xml->channel->item : [] as $item) {
                $places = [];
                foreach ($item->xpath('.//*[local-name()="location"]') ?: [] as $loc) {
                    $city = $loc->xpath('./*[local-name()="city"]')[0] ?? null;
                    $country = $loc->xpath('./*[local-name()="country"]')[0] ?? null;
                    $places[] = implode(', ', array_filter([trim((string) $city), trim((string) $country)]));
                }
                $remote = $item->xpath('./*[local-name()="remoteStatus"]')[0] ?? '';
                $jobs[] = ['id' => trim((string) ($item->guid ?: $item->link)), 'title' => trim((string) $item->title), 'link' => trim((string) $item->link),
                    'description' => (string) $item->description, 'places' => array_values(array_filter($places)), 'remote' => mb_strtolower(trim((string) $remote))];
            }
            return $list($jobs, fn($j) => ['title' => $j['title'], 'id' => $j['id'], 'path' => $j['id'], 'location' => $j['places'][0] ?? '', 'raw' => $j]);
        case 'ultipro':
            $base = 'https://' . ($config['host'] ?? 'recruiting.ultipro.com') . "/{$config['tenant']}/JobBoard/{$config['board']}";
            $jobs = [];
            for ($skip = 0; $skip < 200; $skip += 50) {
                $response = employer_post("$base/JobBoardView/LoadSearchResults", [
                    'opportunitySearch' => ['Top' => 50, 'Skip' => $skip, 'QueryString' => '', 'Filters' => [],
                        'OrderBy' => [['Value' => 'postedDateDesc', 'PropertyName' => 'PostedDate', 'Ascending' => false]]],
                    'matchCriteria' => ['PreferredJobs' => [], 'Educations' => [], 'LicenseAndCertifications' => [], 'Skills' => [],
                        'hasNoLicenses' => false, 'SkippedSkills' => []]]);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("UltiPro answered HTTP {$response['status']}");
                }
                $data = json_decode($response['body'], true) ?? [];
                $batch = $data['opportunities'] ?? [];
                $jobs = array_merge($jobs, $batch);
                if (count($batch) < 50 || $skip + 50 >= (int) ($data['totalCount'] ?? 0)) {
                    break;
                }
            }
            return $list(array_filter($jobs, fn($j) => !empty($j['Id'])), fn($j) => ['title' => $j['Title'] ?? '', 'id' => (string) $j['Id'],
                'path' => (string) $j['Id'], 'location' => ultipro_place($j['Locations'][0] ?? []), 'raw' => $j]);
        case 'adp':
            $api = 'https://' . ($config['host'] ?? 'workforcenow.adp.com') . '/mascsr/default/careercenter/public/events/staffing/v1/job-requisitions';
            $jobs = employer_json($api, adp_params($config))['jobRequisitions'] ?? [];
            return $list($jobs, fn($j) => ['title' => $j['requisitionTitle'] ?? '', 'id' => (string) ($j['itemID'] ?? ''), 'path' => (string) ($j['itemID'] ?? ''),
                'location' => adp_places($j)[0] ?? '', 'raw' => $j]);
        case 'paylocity':
            $response = employer_get("https://recruiting.paylocity.com/recruiting/jobs/All/{$config['guid']}", [], ACCEPT_HTML);
            if ($response['status'] !== 200) {
                throw new RuntimeException("Paylocity answered HTTP {$response['status']}");
            }
            if (!preg_match('/window\.pageData\s*=\s*(\{.*?\});\s*<\/script>/s', $response['body'], $m)) {
                throw new RuntimeException('Paylocity page had no job data');
            }
            $jobs = json_decode($m[1], true)['Jobs'] ?? [];
            return $list($jobs, fn($j) => ['title' => $j['JobTitle'] ?? '', 'id' => (string) ($j['JobId'] ?? ''), 'path' => (string) ($j['JobId'] ?? ''),
                'location' => paylocity_place($j), 'raw' => $j]);
    }
    return [];
}

function bamboo_place(array $location): string
{
    return implode(', ', array_filter([$location['city'] ?? '', $location['state'] ?? $location['stateProvince'] ?? '',
        $location['country'] ?? $location['addressCountry'] ?? ''], 'strlen'));
}

function ultipro_place(array $location): string
{
    $address = $location['Address'] ?? [];
    $country = $address['Country'] ?? [];
    $suffix = in_array(strtoupper((string) ($country['Code'] ?? '')), ['US', 'USA'], true) ? 'US' : ($country['Name'] ?? $country['Code'] ?? '');
    return implode(', ', array_filter([$address['City'] ?? '', $address['State']['Code'] ?? '', $suffix], 'strlen'));
}

function adp_params(array $config): array
{
    return ['cid' => $config['cid'], 'ccId' => $config['ccId'] ?? '19000101_000001', 'lang' => 'en_US', 'locale' => 'en_US'];
}

function adp_places(array $job): array
{
    $places = [];
    foreach ($job['requisitionLocations'] ?? [] as $location) {
        $address = $location['address'] ?? [];
        $city = $address['cityName'] ?? '';
        $state = $address['countrySubdivisionLevel1']['codeValue'] ?? '';
        if ($city !== '') {
            $places[] = implode(', ', array_filter([$city, $state, $state ? 'US' : ''], 'strlen'));
        }
    }
    return $places;
}

function paylocity_place(array $job): string
{
    $location = $job['JobLocation'] ?? [];
    $country = in_array(strtoupper((string) ($location['Country'] ?? '')), ['US', 'USA'], true) ? 'US' : ($location['Country'] ?? '');
    return implode(', ', array_filter([$location['City'] ?? '', $location['State'] ?? '', $country], 'strlen'));
}

/** One listing's full opening (description, places, dates), or null when it can't be read. */
function board_detail(array $employer, array $listing): ?array
{
    $config = $employer;
    $raw = $listing['raw'] ?? [];
    switch ($config['system']) {
        case 'workday':
            $base = "https://{$config['host']}/wday/cxs/{$config['tenant']}/{$config['site']}";
            $response = employer_get($base . $listing['path']);
            if ($response['status'] !== 200) {
                return null;
            }
            $info = json_decode($response['body'], true)['jobPostingInfo'] ?? [];
            if (empty($info['title'])) {
                return null;
            }
            $country = (string) ($info['country']['descriptor'] ?? '');
            $suffix = str_contains(mb_strtolower($country), 'united states') ? ', US' : ($country ? ", $country" : '');
            $places = array_merge([(string) ($info['location'] ?? '')], array_map('strval', $info['additionalLocations'] ?? []));
            $locations = array_map(fn($p) => $p . $suffix, array_filter($places, 'strlen'));
            $virtual = (bool) array_filter($places, fn($p) => str_contains(mb_strtolower($p), 'virtual'));
            $arrangement = work_arrangement_word($info['remoteType'] ?? null) ?? ($virtual ? 'Remote' : null);
            $states = [];
            if ($virtual) {
                foreach ($places as $p) {
                    if (preg_match('/\b([A-Z]{2})\d{2}\b/', $p, $m)) {
                        $states[] = $m[1];
                    }
                }
            }
            $url = $info['externalUrl'] ?? "https://{$config['host']}/{$config['site']}{$listing['path']}";
            return make_opening($employer, $info['title'], $url, $locations, $info['jobDescription'] ?? '', (string) ($info['startDate'] ?? ''),
                schedule_word($info['timeType'] ?? ''), $arrangement, $country, $states);
        case 'oracle':
            $base = "https://{$config['host']}/hcmRestApi/resources/latest";
            $html = $raw['ShortDescriptionStr'] ?? '';
            try {
                $body = employer_json("$base/recruitingCEJobRequisitionDetails", ['expand' => 'all', 'onlyData' => 'true',
                    'finder' => "ById;Id=\"{$listing['id']}\",siteNumber={$config['site']}"])['items'][0] ?? null;
                if ($body) {
                    $html = trim(implode(' ', array_map(fn($k) => (string) ($body[$k] ?? ''), ['ExternalDescriptionStr', 'ExternalResponsibilitiesStr', 'ExternalQualificationsStr']))) ?: $html;
                }
            } catch (Throwable $e) {
            }
            $locations = array_merge([$raw['PrimaryLocation'] ?? ''], array_map(fn($p) => $p['Name'] ?? '', $raw['secondaryLocations'] ?? []));
            return make_opening($employer, $raw['Title'] ?? '', "https://{$config['host']}/hcmUI/CandidateExperience/en/sites/{$config['site']}/job/{$listing['id']}",
                $locations, $html, (string) ($raw['PostedDate'] ?? ''), '', work_arrangement_word($raw['WorkplaceType'] ?? $raw['WorkplaceTypeCode'] ?? null),
                '', [], (string) ($raw['JobFamily'] ?? ''));
        case 'icims':
            $public = preg_replace('/[?#].*$/', '', url_join("https://{$config['host']}/", $listing['path']));
            $response = employer_get(url_join("https://{$config['host']}/", $listing['path']), [], ACCEPT_HTML);
            if ($response['status'] !== 200) {
                return null;
            }
            $found = extract_jobs(new Page($public, $response['body']), [$listing['title']]);
            if (!$found) {
                return null;
            }
            $job = $found[0];
            return make_opening($employer, $job['title'], $public, [$job['location']], $job['description'], (string) $job['posted'], $job['schedule'], $job['type']);
        case 'successfactors':
            $url = "https://{$config['host']}{$listing['path']}";
            $response = employer_get($url, [], ACCEPT_HTML);
            if ($response['status'] !== 200) {
                return null;
            }
            $page = new Page($url, $response['body']);
            $prop = function (string $name) use ($page): string {
                $tag = $page->first("//*[@itemprop='$name']");
                return $tag ? trim($tag->getAttribute('content') ?: node_text($tag)) : '';
            };
            $place = implode(', ', array_filter([$prop('addressLocality'), $prop('addressRegion'), $prop('addressCountry')], 'strlen'));
            $description = $page->first("//*[@itemprop='description']") ?? $page->first("//span[contains(@class, 'jobdescription')]");
            $posted = '';
            $date = DateTime::createFromFormat('D M d H:i:s \U\T\C Y', $prop('datePosted'));
            if ($date) {
                $posted = $date->format('Y-m-d');
            }
            return make_opening($employer, $prop('title') ?: $listing['title'], $url, [$place],
                $description ? $page->dom()->saveHTML($description) : '', $posted);
        case 'ultipro':
            $base = 'https://' . ($config['host'] ?? 'recruiting.ultipro.com') . "/{$config['tenant']}/JobBoard/{$config['board']}";
            $url = "$base/OpportunityDetail?opportunityId={$raw['Id']}";
            $html = $raw['BriefDescription'] ?? '';
            try {
                $page = employer_get($url, [], ACCEPT_HTML);
                if ($page['status'] === 200 && preg_match('/"Description":"((?:[^"\\\\]|\\\\.)*)"/', $page['body'], $m)) {
                    $html = json_decode('"' . $m[1] . '"') ?? $html;
                }
            } catch (Throwable $e) {
            }
            $full = $raw['FullTime'] ?? null;
            return make_opening($employer, $raw['Title'] ?? '', $url, array_map('ultipro_place', $raw['Locations'] ?? []), $html,
                substr((string) ($raw['PostedDate'] ?? ''), 0, 10), $full === true ? 'FULL_TIME' : ($full === false ? 'PART_TIME' : ''), null,
                (string) ($raw['Locations'][0]['Address']['Country']['Name'] ?? ''), [], (string) ($raw['JobCategoryName'] ?? ''));
        case 'greenhouse':
            $opening = make_opening($employer, $raw['title'] ?? '', $raw['absolute_url'] ?? '', [$raw['location']['name'] ?? ''],
                html_entity_decode((string) ($raw['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                substr((string) ($raw['first_published'] ?? $raw['updated_at'] ?? ''), 0, 10));
            $opening['company'] = $raw['company_name'] ?? $employer['name'];
            return $opening;
        case 'lever':
            $categories = $raw['categories'] ?? [];
            $places = array_merge([$categories['location'] ?? ''], $categories['allLocations'] ?? []);
            $posted = !empty($raw['createdAt']) ? gmdate('Y-m-d', (int) ($raw['createdAt'] / 1000)) : '';
            return make_opening($employer, $raw['text'] ?? '', $raw['hostedUrl'] ?? '', $places,
                ($raw['description'] ?? '') . ($raw['additional'] ?? ''), $posted, schedule_word($categories['commitment'] ?? ''),
                work_arrangement_word($raw['workplaceType'] ?? null));
        case 'ashby':
            $places = array_merge([$raw['location'] ?? ''], array_map(fn($p) => $p['location'] ?? '', $raw['secondaryLocations'] ?? []));
            return make_opening($employer, $raw['title'] ?? '', $raw['jobUrl'] ?? '', $places,
                $raw['descriptionHtml'] ?? $raw['descriptionPlain'] ?? '', substr((string) ($raw['publishedAt'] ?? ''), 0, 10),
                schedule_word(str_replace('Time', ' time', (string) ($raw['employmentType'] ?? ''))),
                work_arrangement_word($raw['workplaceType'] ?? null) ?? (!empty($raw['isRemote']) ? 'Remote' : null));
        case 'bamboohr':
            $base = "https://{$config['slug']}.bamboohr.com/careers";
            $info = [];
            try {
                $info = employer_json("$base/{$raw['id']}/detail")['result']['jobOpening'] ?? [];
            } catch (Throwable $e) {
            }
            return make_opening($employer, $info['jobOpeningName'] ?? ($raw['jobOpeningName'] ?? ''), $info['jobOpeningShareUrl'] ?? "$base/{$raw['id']}",
                [bamboo_place($info['atsLocation'] ?? []) ?: bamboo_place($raw['atsLocation'] ?? $raw['location'] ?? [])], $info['description'] ?? '',
                substr((string) ($info['datePosted'] ?? ''), 0, 10), schedule_word($raw['employmentStatusLabel'] ?? ''),
                !empty($raw['isRemote']) ? 'Remote' : null);
        case 'recruitee':
            return make_opening($employer, $raw['title'] ?? '', $raw['careers_url'] ?? "https://{$config['slug']}.recruitee.com/o/{$raw['slug']}",
                [$listing['location']], ($raw['description'] ?? '') . ($raw['requirements'] ?? ''), substr((string) ($raw['published_at'] ?? ''), 0, 10),
                schedule_word($raw['employment_type_code'] ?? ''), !empty($raw['remote']) ? 'Remote' : null);
        case 'teamtailor':
            $remote = $raw['remote'] ?? '';
            return make_opening($employer, $raw['title'], $raw['link'], $raw['places'], $raw['description'], '', '',
                $remote === 'fully' ? 'Remote' : ($remote === 'hybrid' ? 'Hybrid' : null));
        case 'smartrecruiters':
            $info = !empty($raw['ref']) ? employer_json($raw['ref']) : [];
            $sections = $info['jobAd']['sections'] ?? [];
            $html = implode(' ', array_map(fn($k) => (string) ($sections[$k]['text'] ?? ''), ['companyDescription', 'jobDescription', 'qualifications', 'additionalInformation']));
            return make_opening($employer, $raw['name'] ?? '', $info['postingUrl'] ?? "https://jobs.smartrecruiters.com/{$config['company']}/{$raw['id']}",
                [$listing['location']], $html, substr((string) ($raw['releasedDate'] ?? ''), 0, 10),
                schedule_word($raw['typeOfEmployment']['label'] ?? ''), !empty($raw['location']['remote']) ? 'Remote' : null);
        case 'adp':
            $host = $config['host'] ?? 'workforcenow.adp.com';
            $api = "https://$host/mascsr/default/careercenter/public/events/staffing/v1/job-requisitions";
            $html = '';
            try {
                $html = employer_json("$api/{$raw['itemID']}", adp_params($config))['requisitionDescription'] ?? '';
            } catch (Throwable $e) {
            }
            $remote = (bool) array_filter($raw['requisitionLocations'] ?? [], fn($p) => str_contains(mb_strtolower((string) ($p['nameCode']['shortName'] ?? '')), 'remote'));
            $places = adp_places($raw) ?: ($remote ? ['Remote, US'] : []);
            $params = adp_params($config);
            $url = "https://$host/mascsr/default/mdf/recruitment/recruitment.html?cid={$params['cid']}&ccId={$params['ccId']}&jobId=" . ($raw['clientRequisitionID'] ?? '') . '&lang=en_US';
            return make_opening($employer, $raw['requisitionTitle'] ?? '', $url, $places, $html, substr((string) ($raw['postDate'] ?? ''), 0, 10),
                schedule_word($raw['workLevelCode']['shortName'] ?? ''), $remote && count($places) <= 1 ? 'Remote' : null);
        case 'paylocity':
            return make_opening($employer, $raw['JobTitle'] ?? '', "https://recruiting.paylocity.com/recruiting/jobs/Details/{$raw['JobId']}",
                [paylocity_place($raw)], $raw['Description'] ?? '', substr((string) ($raw['PublishedDate'] ?? ''), 0, 10), '',
                !empty($raw['IsRemote']) ? 'Remote' : null);
        case 'workable':
            $job = employer_json("https://apply.workable.com/api/v2/accounts/{$config['slug']}/jobs/{$listing['id']}");
            $location = $job['location'] ?? [];
            $country = ($location['countryCode'] ?? '') === 'US' ? 'US' : ($location['country'] ?? '');
            $place = implode(', ', array_filter([$location['city'] ?? '', $location['region'] ?? '', $country], 'strlen'));
            return make_opening($employer, $job['title'] ?? '', "https://apply.workable.com/{$config['slug']}/j/{$listing['id']}/", [$place],
                implode(' ', array_map(fn($k) => (string) ($job[$k] ?? ''), ['description', 'requirements', 'benefits'])),
                substr((string) ($job['published'] ?? ''), 0, 10), schedule_word($job['type'] ?? ''),
                work_arrangement_word($job['workplace'] ?? null) ?? (!empty($job['remote']) ? 'Remote' : null));
        case 'neogov':
            $url = 'https://www.governmentjobs.com' . $listing['path'];
            $response = employer_get($url, [], ACCEPT_HTML);
            if ($response['status'] !== 200) {
                return null;
            }
            $page = new Page($url, $response['body']);
            $posting = null;
            foreach ($page->json_ld() as $data) {
                foreach (array_is_list($data) ? $data : [$data] as $node) {
                    if (is_array($node) && ($node['@type'] ?? '') === 'JobPosting') {
                        $posting = $node;
                        break 2;
                    }
                }
            }
            if (!$posting) {
                return null;
            }
            $places = [];
            $locations = $posting['jobLocation'] ?? [];
            foreach (array_is_list((array) $locations) ? $locations : [$locations] as $location) {
                $address = $location['address'] ?? [];
                $place = trim((string) ($address['addressLocality'] ?? ''));
                $region = trim((string) ($address['addressRegion'] ?? ''));
                if ($place !== '' && $region !== '' && !preg_match('/\b' . preg_quote($region, '/') . '\b/', $place)) {
                    $place .= ", $region";
                }
                if ($place !== '' && in_array(strtoupper((string) ($address['addressCountry'] ?? '')), ['US', 'USA', 'UNITED STATES'], true)) {
                    $place .= ', US';
                }
                if ($place !== '') {
                    $places[] = $place;
                }
            }
            return make_opening($employer, $posting['title'] ?? $listing['title'], $url, $places ?: [$listing['location'] . ', US'],
                $posting['description'] ?? '', substr((string) ($posting['datePosted'] ?? ''), 0, 10), schedule_word((string) ($posting['employmentType'] ?? '')));
    }
    return null;
}

/** 'Open', 'Closed' or 'Unknown' for one of this employer's posting addresses (used by Refresh). */
function board_status(array $config, string $url): string
{
    try {
        switch ($config['system']) {
            case 'workday':
                if (!preg_match('#/job/.+$#', (string) parse_url($url, PHP_URL_PATH), $m)) {
                    return 'Unknown';
                }
                $response = employer_get("https://{$config['host']}/wday/cxs/{$config['tenant']}/{$config['site']}{$m[0]}");
                if (in_array($response['status'], [404, 410], true)) {
                    return 'Closed';
                }
                if ($response['status'] !== 200) {
                    return 'Unknown';
                }
                $info = json_decode($response['body'], true)['jobPostingInfo'] ?? [];
                if (!$info || ($info['posted'] ?? null) === false) {
                    return 'Closed';
                }
                $end = substr((string) ($info['endDate'] ?? ''), 0, 10);
                return $end && $end < gmdate('Y-m-d') ? 'Closed' : 'Open';
            case 'oracle':
                if (!preg_match('#/job/(\d+)#', (string) parse_url($url, PHP_URL_PATH), $m)) {
                    return 'Unknown';
                }
                $response = employer_get("https://{$config['host']}/hcmRestApi/resources/latest/recruitingCEJobRequisitionDetails",
                    ['expand' => 'all', 'onlyData' => 'true', 'finder' => "ById;Id=\"{$m[1]}\",siteNumber={$config['site']}"]);
                if (in_array($response['status'], [404, 410], true)) {
                    return 'Closed';
                }
                return $response['status'] !== 200 ? 'Unknown' : (!empty(json_decode($response['body'], true)['items']) ? 'Open' : 'Closed');
            case 'smartrecruiters':
                if (!preg_match('#/(\d{6,})#', (string) parse_url($url, PHP_URL_PATH), $m)) {
                    return 'Unknown';
                }
                $code = employer_get("https://api.smartrecruiters.com/v1/companies/{$config['company']}/postings/{$m[1]}")['status'];
                return $code === 200 ? 'Open' : (in_array($code, [404, 410], true) ? 'Closed' : 'Unknown');
            case 'workable':
                if (!preg_match('#/j/([A-Za-z0-9]+)#', $url, $m)) {
                    return 'Unknown';
                }
                $code = employer_get("https://apply.workable.com/api/v2/accounts/{$config['slug']}/jobs/{$m[1]}")['status'];
                return $code === 200 ? 'Open' : (in_array($code, [404, 410], true) ? 'Closed' : 'Unknown');
            case 'icims':
            case 'successfactors':
            case 'ultipro':
            case 'neogov':
                $response = employer_get($url, [], ACCEPT_HTML);
                if (in_array($response['status'], [404, 410], true)) {
                    return 'Closed';
                }
                if ($response['status'] !== 200) {
                    return 'Unknown';
                }
                $body = $response['body'];
                if ($config['system'] === 'neogov' && preg_match('/no longer (?:available|accepting)|posting (?:has )?(?:closed|expired)|job (?:is )?closed/i', $body)) {
                    return 'Closed';
                }
                $marker = ['icims' => '"JobPosting"', 'successfactors' => 'itemprop="title"', 'ultipro' => '"RequisitionNumber"', 'neogov' => '"JobPosting"'][$config['system']];
                return str_contains($body, $marker) ? 'Open' : 'Closed';
            default:
                // Whole-board systems: open while the board still lists it.
                $ids = array_column(board_whole($config), 'id');
                $patterns = ['greenhouse' => '/gh_jid=(\d+)|\/jobs\/(\d+)/', 'lever' => '/\/([0-9a-f]{8}-[0-9a-f-]{27})/',
                    'ashby' => '/\/([0-9a-f]{8}-[0-9a-f-]{27})/', 'bamboohr' => '/\/careers\/(\d+)|[?&]id=(\d+)/',
                    'paylocity' => '/\/Details\/(\d+)/i', 'teamtailor' => '/\/jobs\/(\d+)/'];
                if (!isset($patterns[$config['system']]) || !preg_match($patterns[$config['system']], $url, $m)) {
                    return 'Unknown';
                }
                $wanted = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
                foreach ($ids as $id) {
                    if ((string) $id === $wanted || str_contains((string) $id, $wanted)) {
                        return 'Open';
                    }
                }
                return 'Closed';
        }
    } catch (Throwable $e) {
        return 'Unknown';
    }
}

// --- Which board a page address belongs to -----------------------------------------------------------------

/** Settings for the hiring board this address belongs to, or null. */
function identify_board(?string $url): ?array
{
    $parts_url = parse_url((string) $url);
    $host = strtolower($parts_url['host'] ?? '');
    $path = $parts_url['path'] ?? '';
    $parts = array_values(array_filter(explode('/', $path), 'strlen'));
    $not_board = ['embed', 'api', 'v1', 'static', 'assets', 'login', 'www', 'help', 'support', 'developers'];
    if ($host === '' || !in_array(strtolower($parts_url['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    $first = strtolower($parts[0] ?? '');
    if (preg_match('/^recruiting\d*\.ultipro\.com$/', $host) && count($parts) >= 3 && strtolower($parts[1]) === 'jobboard') {
        return ['system' => 'ultipro', 'host' => $host, 'tenant' => $parts[0], 'board' => $parts[2]];
    }
    if (preg_match('/^([a-z0-9-]+)\.(wd\d+)\.myworkdayjobs\.com$/', $host, $m)) {
        foreach ($parts as $part) {
            if (!preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/', $part) && !in_array(strtolower($part), ['wday', 'login', 'job', 'details'], true)) {
                return ['system' => 'workday', 'host' => $host, 'tenant' => $m[1], 'site' => $part];
            }
        }
        return null;
    }
    if (preg_match('/^[a-z0-9-]+\.fa(?:\.[a-z0-9-]+)?\.oraclecloud\.com$/', $host)) {
        return preg_match('#/hcmUI/CandidateExperience/[^/]+/sites/([^/?\#]+)#', $path, $m) ? ['system' => 'oracle', 'host' => $host, 'site' => $m[1]] : null;
    }
    if (str_ends_with($host, '.icims.com') && !preg_match('/^(?:employees-|referral-|www\.)/', $host)) {
        return ['system' => 'icims', 'host' => $host];
    }
    if (in_array($host, ['boards.greenhouse.io', 'job-boards.greenhouse.io', 'boards.eu.greenhouse.io', 'job-boards.eu.greenhouse.io'], true)) {
        return $parts && !in_array($first, $not_board, true) ? ['system' => 'greenhouse', 'slug' => $parts[0]] : null;
    }
    if (in_array($host, ['jobs.lever.co', 'jobs.eu.lever.co'], true)) {
        return $parts && !in_array($first, $not_board, true) ? ['system' => 'lever', 'slug' => $parts[0], 'eu' => $host === 'jobs.eu.lever.co'] : null;
    }
    if ($host === 'jobs.ashbyhq.com') {
        return $parts && !in_array($first, $not_board, true) ? ['system' => 'ashby', 'slug' => $parts[0]] : null;
    }
    if (preg_match('/^([a-z0-9-]+)\.bamboohr\.com$/', $host, $m) && !in_array($m[1], $not_board, true)) {
        return ['system' => 'bamboohr', 'slug' => $m[1]];
    }
    if ($host === 'workforcenow.adp.com' && str_contains(strtolower($path), 'recruitment')) {
        parse_str($parts_url['query'] ?? '', $query);
        return !empty($query['cid']) ? ['system' => 'adp', 'host' => $host, 'cid' => $query['cid'], 'ccId' => $query['ccId'] ?? '19000101_000001'] : null;
    }
    if ($host === 'recruiting.paylocity.com') {
        return preg_match('#/jobs/[A-Za-z]+/([0-9a-f]{8}-[0-9a-f-]{27})#i', $path, $m) ? ['system' => 'paylocity', 'guid' => strtolower($m[1])] : null;
    }
    if (str_ends_with($host, 'governmentjobs.com') && count($parts) >= 2 && $first === 'careers'
        && !in_array(strtolower($parts[1]), ['home', 'search', 'jobs', 'api'], true)) {
        return ['system' => 'neogov', 'agency' => strtolower($parts[1])];
    }
    if ($host === 'apply.workable.com') {
        return $parts && !in_array($first, array_merge($not_board, ['j']), true) ? ['system' => 'workable', 'slug' => $parts[0]] : null;
    }
    if (preg_match('/^([a-z0-9-]+)\.recruitee\.com$/', $host, $m) && !in_array($m[1], $not_board, true)) {
        return ['system' => 'recruitee', 'slug' => $m[1]];
    }
    if (preg_match('/^([a-z0-9-]+)\.teamtailor\.com$/', $host, $m) && !in_array($m[1], array_merge($not_board, ['career', 'app']), true)) {
        return ['system' => 'teamtailor', 'slug' => $m[1]];
    }
    if (in_array($host, ['jobs.smartrecruiters.com', 'careers.smartrecruiters.com'], true)) {
        return $parts && !ctype_digit($parts[0]) && !in_array($first, $not_board, true) ? ['system' => 'smartrecruiters', 'company' => $parts[0]] : null;
    }
    return null;
}

/** A readable company name from a board's address parts, used when nothing better is known. */
function board_pretty_name(array $config): string
{
    $raw = $config['slug'] ?? $config['company'] ?? $config['agency'] ?? $config['tenant'] ?? '';
    if ($config['system'] === 'icims') {
        $raw = preg_replace('/^(?:careers|jobs|career)-/', '', explode('.', $config['host'])[0]);
    }
    if ($config['system'] === 'ultipro') {
        $raw = '';
    }
    $words = trim(preg_replace('/[-_]+/', ' ', (string) $raw));
    return $words !== '' ? ucwords(strtolower($words)) : ($config['host'] ?? 'Job board');
}

/** A stable identity for a board, so the same one is never searched twice. */
function board_key(array $config): string
{
    $key = [];
    foreach (['system', 'host', 'tenant', 'site', 'board', 'slug', 'company', 'eu', 'cid', 'ccId', 'guid', 'agency'] as $field) {
        if (isset($config[$field]) && $config[$field] !== '' && $config[$field] !== null) {
            $key[$field] = mb_strtolower(is_bool($config[$field]) ? ($config[$field] ? 'True' : 'False') : (string) $config[$field]);
        }
    }
    ksort($key);
    return json_encode($key);
}

/** Employers to search: the watched list, then boards found in earlier searches (useful ones first). */
function load_employers(): array
{
    $configs = json_decode((string) @file_get_contents(DATA_DIR . '/watched_employers.json'), true) ?: [];
    $found = array_filter(setting_json('discovered_employers', []), fn($c) => is_array($c)
        && !(($c['searches'] ?? 0) >= MIN_SEARCHES_BEFORE_DROP && empty($c['matches'])));
    usort($found, fn($a, $b) => ($b['matches'] ?? 0) <=> ($a['matches'] ?? 0));
    $all = array_merge($configs, array_map(fn($c) => $c + ['discovered' => true], $found));
    $out = [];
    $seen = [];
    $systems = ['workday', 'oracle', 'icims', 'successfactors', 'ultipro', 'greenhouse', 'lever', 'ashby', 'bamboohr',
        'smartrecruiters', 'adp', 'paylocity', 'workable', 'neogov', 'recruitee', 'teamtailor'];
    foreach ($all as $config) {
        if (!is_array($config) || ($config['enabled'] ?? true) === false || !in_array($config['system'] ?? '', $systems, true) || empty($config['name'])) {
            continue;
        }
        $key = board_key($config);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $config['domain'] ??= parse_url('//' . ($config['host'] ?? 'boards.example'), PHP_URL_HOST);
            $out[] = $config;
        }
    }
    return $out;
}

function save_discovered(array $config): void
{
    unset($config['discovered']);
    $stored = setting_json('discovered_employers', []);
    $key = board_key($config);
    $today = gmdate('Y-m-d');
    $known = false;
    foreach ($stored as &$item) {
        if (board_key($item) === $key) {
            $item['last_seen'] = $today;
            $known = true;
        }
    }
    unset($item);
    if (!$known) {
        $stored[] = $config + ['first_seen' => $today, 'last_seen' => $today];
    }
    usort($stored, fn($a, $b) => [($b['matches'] ?? 0), $b['last_seen'] ?? ''] <=> [($a['matches'] ?? 0), $a['last_seen'] ?? '']);
    set_setting_json('discovered_employers', array_slice($stored, 0, MAX_DISCOVERED));
}

function record_board_result(array $config, int $matches): void
{
    $stored = setting_json('discovered_employers', []);
    $key = board_key($config);
    foreach ($stored as &$item) {
        if (board_key($item) === $key) {
            $item['searches'] = (int) ($item['searches'] ?? 0) + 1;
            $item['matches'] = (int) ($item['matches'] ?? 0) + $matches;
            set_setting_json('discovered_employers', $stored);
            return;
        }
    }
}

function employer_allows(array $employer, string $title, string $place): bool
{
    $title = mb_strtolower($title);
    $place = mb_strtolower($place);
    $keywords = array_map('mb_strtolower', $employer['title_keywords'] ?? []);
    if ($keywords && !array_filter($keywords, fn($w) => str_contains($title, $w))) {
        return false;
    }
    foreach ($employer['exclude_locations'] ?? [] as $excluded) {
        if (str_contains($place, mb_strtolower($excluded))) {
            return false;
        }
    }
    return true;
}

/** Openings on an employer's board matching the titles, with details (one go; Refresh and lookups use this). */
function employer_find_openings(array $employer, array $titles): array
{
    $wanted = array_merge($titles, $employer['extra_titles'] ?? []);
    $keywords = array_slice(array_values(array_unique(array_filter(array_map(fn($t) => mb_strtolower(trim($t)), $wanted)))), 0, MAX_KEYWORDS);
    $memo = [];
    $listings = [];
    foreach ($keywords as $keyword) {
        foreach (board_search($employer, $keyword, $memo) as $listing) {
            $listings[$listing['id']] ??= $listing;
        }
    }
    $openings = [];
    foreach (array_slice(array_filter($listings, fn($l) => matching_title($l['title'], $wanted)
        && employer_allows($employer, $l['title'], (string) $l['location'])), 0, MAX_DETAILS) as $listing) {
        $opening = board_detail($employer, $listing);
        if ($opening) {
            $opening['locations'] = array_values(array_filter($opening['locations'], fn($p) => employer_allows(['exclude_locations' => $employer['exclude_locations'] ?? []], '', $p)));
            if ($opening['locations']) {
                $opening['location'] = $opening['locations'][0];
                $openings[] = $opening;
            }
        }
    }
    return $openings;
}

/** The employer whose board a posting address belongs to (for Refresh), or null. */
function employer_for_url(string $url, array $employers): ?array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    foreach ($employers as $employer) {
        $owns = match ($employer['system']) {
            'workday', 'oracle', 'icims', 'successfactors' => $host === strtolower($employer['host'] ?? ''),
            'ultipro' => $host === strtolower($employer['host'] ?? 'recruiting.ultipro.com') && stripos(parse_url($url, PHP_URL_PATH) . '/', "/{$employer['tenant']}/") !== false,
            'neogov' => str_ends_with($host, 'governmentjobs.com') && stripos(parse_url($url, PHP_URL_PATH) . '/', "/careers/{$employer['agency']}/") !== false,
            'adp' => $host === strtolower($employer['host'] ?? 'workforcenow.adp.com') && str_contains($url, "cid={$employer['cid']}"),
            default => false,
        };
        if ($owns) {
            return $employer;
        }
    }
    $config = identify_board($url);
    return $config ? $config + ['name' => board_pretty_name($config), 'domain' => get_domain($url)] : null;
}

// --- The company's own posting on a hiring board --------------------------------------------------------------

/** Board names a company might use: "Aegis AI" -> aegis-ai, aegisai; "Acme Labs" -> acme-labs, acmelabs, acme. */
function board_slug_candidates(string $company): array
{
    $tokens = name_tokens($company);
    $variants = [];
    while ($tokens) {
        foreach ([implode('-', $tokens), implode('', $tokens)] as $slug) {
            if (strlen($slug) >= 3 && !in_array($slug, $variants, true)) {
                $variants[] = $slug;
            }
        }
        if (count($tokens) > 1 && in_array(end($tokens), GENERIC_TAIL, true)) {
            array_pop($tokens);
        } else {
            break;
        }
    }
    return array_slice($variants, 0, 3);
}

/** {system, url, title, board} for the company's own posting of this job, or null. */
function company_board_posting(string $company, string $job_title, ?float $deadline = null): ?array
{
    static $cache = [];
    $company = trim(preg_replace('/\s+/', ' ', $company));
    $normal = fn($t) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $t)));
    $key = mb_strtolower($company) . '|' . $normal($job_title);
    if ($company === '' || $job_title === '') {
        return null;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $found = null;
    $systems = [['ashby', 'slug'], ['greenhouse', 'slug'], ['lever', 'slug'], ['workable', 'slug'], ['smartrecruiters', 'company'],
        ['bamboohr', 'slug'], ['recruitee', 'slug'], ['teamtailor', 'slug']];
    foreach (board_slug_candidates($company) as $slug) {
        foreach ($systems as [$system, $field]) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break 2;
            }
            try {
                $openings = employer_find_openings(['system' => $system, $field => $slug, 'name' => $company, 'domain' => "$slug.example"], [$job_title]);
            } catch (Throwable $e) {
                continue;
            }
            $wanted = $normal($job_title);
            $exact = array_values(array_filter($openings, fn($o) => $normal($o['title']) === $wanted));
            $starts = array_values(array_filter($openings, fn($o) => str_starts_with($normal($o['title']), "$wanted ")));
            $match = $exact[0] ?? (count($starts) === 1 ? $starts[0] : null);
            if ($match && $match['url']) {
                $found = ['system' => $system, 'url' => canonical_url($match['url']) ?: $match['url'], 'title' => $match['title'], 'board' => $slug];
                break 2;
            }
        }
    }
    return $cache[$key] = $found;
}

/** Matching postings on a public Lever or Greenhouse board page (jobs.lever.co/acme). */
function public_board_links(string $board_url, array $wanted): array
{
    $parts_url = parse_url($board_url);
    if (($parts_url['scheme'] ?? '') !== 'https') {
        return [];
    }
    $host = $parts_url['host'] ?? '';
    $parts = array_values(array_filter(explode('/', $parts_url['path'] ?? ''), 'strlen'));
    if (count($parts) !== 1 || !ctype_alnum(str_replace(['-', '_'], '', $parts[0]))) {
        return [];
    }
    $site = $parts[0];
    if (in_array($host, ['jobs.lever.co', 'jobs.eu.lever.co'], true)) {
        $api = 'https://' . ($host === 'jobs.eu.lever.co' ? 'api.eu.lever.co' : 'api.lever.co') . "/v0/postings/$site?mode=json&limit=100";
    } elseif (in_array($host, ['boards.greenhouse.io', 'job-boards.greenhouse.io'], true)) {
        $api = "https://boards-api.greenhouse.io/v1/boards/$site/jobs";
    } else {
        return [];
    }
    try {
        $payload = http_json($api, null, [], 10);
    } catch (Throwable $e) {
        return [];
    }
    $jobs = array_is_list($payload) ? $payload : ($payload['jobs'] ?? []);
    $urls = [];
    foreach (array_slice($jobs, 0, 100) as $item) {
        if (is_array($item) && matching_title($item['title'] ?? $item['text'] ?? '', $wanted)) {
            $link = $item['hostedUrl'] ?? $item['absolute_url'] ?? null;
            if (is_string($link) && str_starts_with($link, 'https://')) {
                $urls[] = $link;
            }
        }
    }
    return $urls;
}
