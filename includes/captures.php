<?php
// Jobs saved by the Web Job Scraper Firefox extension. The extension writes a jobs.json per site per day (on this PC,
// Desktop\web-job-scraper\searches\...); on the web you upload those files on the Search page. Each job is filtered
// like the desktop's --import-captures (your profile's titles and places, blocked companies, rejected postings) and
// saved. Looking up each company's own website takes a page fetch or more, so it runs while time allows and
// cron.php finishes the rest. Ported from the desktop's capture_import.py and web_captures.py.

declare(strict_types=1);

require_once APP_ROOT . '/includes/listings.php';
require_once APP_ROOT . '/includes/search/runner.php';
require_once APP_ROOT . '/includes/skills.php';

// Extension site key => [source_type saved in companies, domain]
const CAPTURE_SITES = [
    'linkedin' => ['LinkedIn', 'linkedin.com'], 'indeed' => ['Indeed', 'indeed.com'], 'glassdoor' => ['Glassdoor', 'glassdoor.com'],
    'mwe' => ['Maryland Workforce Exchange', 'mwejobs.maryland.gov'], 'usajobs' => ['USAJOBS', 'usajobs.gov'],
];
const CAPTURE_MARK = 'web-job-scraper';
const CAPTURE_MAX_FILE = 20 * 1024 * 1024;
const CAPTURE_LEVELS = ['seen' => 0, 'opened' => 1];
const METRO_AREAS = [
    ['/dallas[-\/ ]+fort worth|\bdfw\b/', 'Dallas, TX'], ['/houston[- ]the woodlands|greater houston/', 'Houston, TX'],
    ['/washington[ ,]+d\.?c\.?(?: metro| area| metropolitan)|\bdmv\b|washington metro/', 'Washington, DC'],
    ['/baltimore[-\/ ]+washington|greater baltimore|baltimore metro/', 'Baltimore, MD'],
    ['/(?:san francisco )?bay area|silicon valley/', 'San Francisco, CA'], ['/greater los angeles|los angeles metro|\bsocal\b/', 'Los Angeles, CA'],
    ['/greater boston|boston metro/', 'Boston, MA'], ['/new york city metro|nyc metro|tri-state area/', 'New York, NY'],
    ['/greater chicago|chicagoland/', 'Chicago, IL'], ['/greater philadelphia|philadelphia metro|delaware valley/', 'Philadelphia, PA'],
    ['/greater atlanta|atlanta metro/', 'Atlanta, GA'], ['/research triangle|raleigh[-\/ ]+durham/', 'Raleigh, NC'],
    ['/twin cities/', 'Minneapolis, MN'], ['/phoenix metro|greater phoenix/', 'Phoenix, AZ'], ['/greater seattle|seattle metro/', 'Seattle, WA'],
];

/** [site, jobs] from a capture file's text, or null when it isn't one. Jobs without a title or link are left out. */
function read_capture(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || ($data['source'] ?? null) !== CAPTURE_MARK || !isset(CAPTURE_SITES[$data['site'] ?? '']) || !is_array($data['jobs'] ?? null)) {
        return null;
    }
    $jobs = [];
    foreach ($data['jobs'] as $job) {
        if (!is_array($job)) {
            continue;
        }
        $url = canonical_url((string) ($job['url'] ?? ''));
        // badges LinkedIn adds to titles ("Web Designer (Verified job)"); older files still have them
        $title = trim(preg_replace('/\s*\(verified job\)\s*$|\s+with verification\s*$/i', '', (string) ($job['title'] ?? '')));
        if ($url !== '' && $title !== '') {
            $jobs[] = ['url' => $url, 'title' => $title] + $job;
        }
    }
    return [$data['site'], $jobs];
}

// --- Matching the same job across sites ---

function capture_company_key(?string $name): string
{
    $text = preg_replace('/\b(?:inc|incorporated|llc|l\.l\.c|ltd|limited|corp|corporation|co|company|plc|lp|llp)\b\.?/i', ' ', mb_strtolower((string) $name));
    preg_match_all('/[a-z0-9]+/', $text, $m);
    return implode(' ', $m[0]);
}

/** The title's words, keeping level words ("Senior Web Designer" is not "Web Designer"); work-place notes dropped. */
function capture_title_key(?string $title): string
{
    $text = preg_replace('/\((?:remote|hybrid|on-?site)\)/i', ' ', mb_strtolower((string) $title));
    $text = preg_replace('/\bfront[ -]?end\b/', 'frontend', $text);
    preg_match_all('/[a-z0-9]+/', $text, $m);
    return implode(' ', array_filter($m[0], fn($w) => !in_array($w, ['remote', 'hybrid', 'onsite', 'the', 'a', 'job'], true)));
}

/** The city part of "Austin, TX (Hybrid)"; empty for remote or state-only places. */
function capture_city_key(?string $location): string
{
    $text = trim(preg_replace('/\((?:remote|hybrid|on-?site)\)/i', ' ', (string) $location));
    if ($text === '' || !str_contains($text, ',') || preg_match('/\bremote\b|united states/i', $text)) {
        return '';
    }
    preg_match_all('/[a-z0-9]+/', mb_strtolower(explode(',', $text)[0]), $m);
    return implode(' ', $m[0]);
}

/** What identifies one real job across sites, or null when there is too little to be sure. */
function capture_match_key(?string $company, ?string $title, ?string $location, ?string $arrangement): ?string
{
    $who = capture_company_key($company);
    $what = capture_title_key($title);
    if ($who === '' || $what === '') {
        return null;
    }
    if (mb_strtolower((string) $arrangement) === 'remote') {
        return "$who|$what|remote";
    }
    $city = capture_city_key($location);
    return $city !== '' ? "$who|$what|$city" : null;
}

/** listing_details for a re-imported job: new values win, but an opened job keeps its description. */
function merge_capture_details(array $old, array $new): array
{
    $merged = $old;
    foreach ($new as $key => $value) {
        if (($value === null || $value === '' || $value === []) && !empty($merged[$key])) {
            continue;
        }
        $merged[$key] = $value;
    }
    if ((CAPTURE_LEVELS[$old['capture_level'] ?? ''] ?? 0) > (CAPTURE_LEVELS[$new['capture_level'] ?? ''] ?? 0)) {
        $merged['capture_level'] = $old['capture_level'];
        if (!empty($old['description'])) {
            $merged['description'] = $old['description'];
        }
    }
    if (!empty($old['also_on'])) {
        $merged['also_on'] = $old['also_on'];
    }
    return $merged;
}

/** Adds another site's link to a job's details; false when it was already there. */
function add_also_on(array &$details, string $site, string $url): bool
{
    $links = (array) ($details['also_on'] ?? []);
    foreach ($links as $link) {
        if (is_array($link) && ($link['url'] ?? null) === $url) {
            return false;
        }
    }
    $links[] = ['site' => CAPTURE_SITES[$site][0] ?? $site, 'url' => $url];
    $details['also_on'] = $links;
    return true;
}

/** A small page made from the captured fields, for the location and U.S. checks that read page text. */
function capture_page_html(array $job): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $html = "<h1>{$e($job['title'] ?? '')}</h1><p>{$e($job['company'] ?? '')}</p>";
    if (!empty($job['location'])) {
        $html .= "<p class=\"job-location\">{$e($job['location'])}</p>";
    }
    if (!empty($job['description'])) {
        $html .= "<div>{$e($job['description'])}</div>";
    }
    return "<html><body>$html</body></html>";
}

/** The place a description says the job is in ("Role is full time in Irving, TX", "Dallas-Fort Worth Metroplex"); "" when unsure. */
function location_from_description(?string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if ($text === '') {
        return '';
    }
    $codes = implode('|', state_codes());
    $city = "[A-Z][A-Za-z.'’]+(?:[-\\/ ][A-Z][A-Za-z.'’]+){0,4}";
    if (preg_match("/(?i:\\b(?:role|position|job|work|office|located|location|based|headquartered|onsite|on-site|hybrid|full[- ]time|part[- ]time)\\b[^.\\n]{0,30}?(?:\\bin\\b|:|-|–)\\s*)($city),\\s*($codes)\\b/u", $text, $m)) {
        return "{$m[1]}, {$m[2]}";
    }
    $lower = mb_strtolower($text);
    foreach (METRO_AREAS as [$pattern, $place]) {
        if (preg_match($pattern, $lower)) {
            return $place;
        }
    }
    return '';
}

/** 'Hybrid', 'Remote' or 'Onsite' when the description says so plainly ("in our office 4 days a week" is Hybrid), else null. */
function arrangement_from_description(?string $text): ?string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (preg_match('/\bhybrid\b|\b(?:one|two|three|four|[1-4])\s+days?\s+(?:a|per|each|every)\s+week\s+(?:in|at|from)\s+(?:the|our)\s+(?:office|workplace)'
        . '|\b(?:in|at|from)\s+(?:the|our)\s+office\s+(?:one|two|three|four|[1-4])\s+days?\b|\bwork\s+(?:in|from)\s+(?:the|our)\s+office\s+(?:one|two|three|four|[1-4])\s+days?\b/i', $text)) {
        return 'Hybrid';
    }
    $remote = (bool) preg_match('/\b(?:fully|completely|100%)\s+remote\b|\bthis\s+is\s+a\s+remote\s+(?:position|role|job)\b|\bwork\s+from\s+anywhere\b/i', $text);
    $onsite = (bool) preg_match('/\bon[- ]?site\b(?!\s+(?:interview|visit))|\bin[- ]office\s+(?:role|position|job)\b|\b(?:five|5)\s+days\s+(?:a|per)\s+week\s+in\s+(?:the|our)\s+office\b'
        . '|\bmust\s+(?:work|be)\s+(?:in|at|on)\s+(?:the|our)\s+office\b|\b(?:role|position|job)\s+is\s+(?:full|part)[- ]time\s+in\s+[A-Z]/i', $text);
    if ($remote && $onsite) {
        return null;
    }
    return $remote ? 'Remote' : ($onsite ? 'Onsite' : null);
}

function row_details(?array $row): array
{
    $details = json_decode((string) ($row['listing_details'] ?? ''), true);
    return is_array($details) ? $details : [];
}

// --- One import ---

final class CaptureImport
{
    public array $wanted = [];
    public array $counts = ['added' => 0, 'updated' => 0, 'linked' => 0, 'rejected' => 0, 'skipped' => 0];
    public array $log = [];
    private array $ctx;
    private array $rejected;
    private array $blocked;
    private array $by_url = [];
    private array $by_key = [];

    public function __construct()
    {
        $profile = get_user_profile();
        $titles = $profile['job_titles'];
        if ($profile['primary_job_title'] !== '' && !in_array($profile['primary_job_title'], $titles, true)) {
            $titles[] = $profile['primary_job_title'];
        }
        $this->wanted = $titles ? expand_job_titles($titles) : [];
        $have = array_map('mb_strtolower', $this->wanted);
        foreach (related_family_titles($titles) as $title) {
            if (!in_array(mb_strtolower($title), $have, true)) {
                $this->wanted[] = $title;
            }
        }
        $cities = array_map(fn($c) => ['city' => $c['city'], 'radius' => $c['radius_miles']], $profile['cities']);
        $statewide = [];
        foreach ($cities as $item) {
            if ($code = US_STATES[mb_strtolower(trim($item['city']))] ?? null) {
                $statewide[] = $code;
            }
        }
        $statewide = array_values(array_unique($statewide));
        $this->ctx = ['state' => $profile['state'], 'cities' => $cities, 'statewide_states' => $statewide,
            'selected_states' => selected_state_codes($profile['state'], $cities, $statewide), 'city_targets' => prepare_city_targets($profile['state'], $cities)];
        $this->rejected = rejected_posting_urls();
        $this->blocked = array_fill_keys(array_map('mb_strtolower', blocked_company_list()), true);
        foreach (rows('SELECT id, name, career_job_title, work_arrangement, city, state, source_url, source_type, listing_details, is_kept
            FROM companies WHERE is_rejected = 0') as $row) {
            $this->remember($row);
        }
    }

    private function remember(array $row): void
    {
        $details = row_details($row);
        if (!empty($row['source_url'])) {
            $this->by_url[canonical_url($row['source_url'])] = $row;
        }
        $place = ($details['location'] ?? '') ?: implode(', ', array_filter([$row['city'] ?? '', $row['state'] ?? ''], 'strlen'));
        $key = capture_match_key($row['name'], $row['career_job_title'], $place, $row['work_arrangement']);
        if ($key !== null) {
            $this->by_key[$key] ??= $row;
        }
    }

    private function assess(array $job, ?string $arrangement, string $location): array
    {
        if ($location === '') {
            return ['skip' => null, 'arrangement' => $arrangement, 'location' => ['score' => 0], 'detail_score' => CAREER_CREDIBILITY_THRESHOLD,
                'country' => null, 'state_name' => null, 'remote_limited_to' => [], 'city' => null, 'lat' => null, 'lon' => null, 'miles' => null];
        }
        $opening = ['title' => $job['title'], 'company' => (string) ($job['company'] ?? ''), 'description' => (string) ($job['description'] ?? ''),
            'type' => $arrangement, 'location' => $location, 'locations' => [$location], 'remote_states' => [], 'schedule' => '', 'evidence' => []];
        $outcome = assess_opening($opening, new Page($job['url'], capture_page_html($job)), $job['url'], $this->ctx);
        // a card has no page text to prove the job is in the U.S.; the site's own location field is trusted instead
        if ($outcome['skip'] === 'US eligibility unverified' && site_location_in_us($location)) {
            $outcome['skip'] = null;
            $outcome['country'] = 'United States';
            $outcome['location']['score'] = max($outcome['location']['score'], USA_CREDIBILITY_THRESHOLD);
        }
        return $outcome;
    }

    private function mark_applied(int $id): void
    {
        q("UPDATE companies SET is_kept = 1, is_rejected = 0, rejection_reason = NULL, rejected_at = NULL,
            application_status = CASE WHEN application_status IN ('None', 'Saved', 'Rejected') THEN 'Applied' ELSE application_status END
            WHERE id = ?", [$id]);
    }

    /** Filters and saves one captured job: [result, reason], result being added, updated, linked, rejected or skipped. */
    public function import_job(string $site, array $job): array
    {
        [$source_type, $domain] = CAPTURE_SITES[$site];
        $url = $job['url'];
        $title = $job['title'];
        $company = tidy_company_name((string) ($job['company'] ?? '')) ?: null;
        $applied = !empty($job['applied']);
        $row = $this->by_url[$url] ?? null;
        if (!$applied) {
            // a job you already applied to skips the filters: it is always saved, as Applied
            if (!$row && isset($this->rejected[$url])) {
                return ['skipped', 'Previously rejected'];
            }
            if ($company !== null && isset($this->blocked[mb_strtolower($company)])) {
                return ['skipped', 'Blocked company'];
            }
            if (!$this->wanted || !matching_title($title, $this->wanted)) {
                return ['skipped', "Title matches none of your profile's job titles"];
            }
            if (is_internship($title)) {
                return ['skipped', 'Internship'];
            }
        }
        $closed = !empty($job['closed']);
        $location = trim((string) ($job['location'] ?? ''));
        $description = (string) ($job['description'] ?? '');
        // a card without a place is read again from its description, and its work arrangement likewise
        $described = $location === '' ? location_from_description($description) : '';
        $location = $location ?: $described;
        $arrangement = ($job['work_arrangement'] ?? null) ?: (detect_work_arrangement($title, new Page('', "<p>" . htmlspecialchars($location) . '</p>'))
            ?: arrangement_from_description($description));
        $outcome = $this->assess($job, $arrangement, $location);
        if ($outcome['skip'] && !$applied) {
            // a job imported earlier as "Location unknown" is filtered again now that its place is known
            if ($row && !empty(row_details($row)['location_unknown']) && !$row['is_kept']) {
                q("UPDATE companies SET pre_reject_kept = is_kept, pre_reject_status = application_status, is_rejected = 1, is_kept = 0,
                    application_status = 'Rejected', rejected_at = ?, rejection_reason = 'wrong_location', rejected_by = 'system'
                    WHERE id = ? AND is_kept = 0 AND is_rejected = 0", [now_utc(), $row['id']]);
                unset($this->by_url[$url]);
                return ['rejected', $outcome['skip']];
            }
            return ['skipped', $outcome['skip']];
        }
        $arrangement = $outcome['arrangement'] ?: $arrangement;
        if (!$row) {
            $key = capture_match_key($company, $title, $location, $arrangement);
            $same = $key !== null ? ($this->by_key[$key] ?? null) : null;
            if ($same) {
                $details = row_details($same);
                if (add_also_on($details, $site, $url)) {
                    q('UPDATE companies SET listing_details = ? WHERE id = ?', [json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $same['id']]);
                    $this->by_key[$key]['listing_details'] = json_encode($details);
                }
                if ($applied) {
                    $this->mark_applied((int) $same['id']);
                }
                return ['linked', "same job as #{$same['id']} (" . ($same['source_type'] ?: 'saved') . ')' . ($applied ? '; marked Applied' : '')];
            }
            if ($closed && !$applied) {
                return ['skipped', 'No longer accepting applications'];
            }
        }
        $matched = null;
        foreach ($this->wanted as $wanted) {
            if (matching_title($title, [$wanted])) {
                $matched = $wanted;
                break;
            }
        }
        $details = merge_capture_details($row ? row_details($row) : [], [
            'captured_by' => CAPTURE_MARK, 'site' => $source_type, 'external_id' => (string) ($job['job_id'] ?? ''),
            'capture_level' => ($job['level'] ?? '') ?: 'seen', 'page_kind' => ($job['page_kind'] ?? '') ?: 'other',
            'location' => $location, 'salary' => (string) ($job['salary'] ?? ''), 'posted' => (string) ($job['posted'] ?? ''),
            'description' => mb_substr($description, 0, 20000), 'closes' => mb_substr((string) ($job['closes'] ?? ''), 0, 10),
            'matched_title' => $matched, 'location_unknown' => $location === '', 'location_from_description' => $described !== '',
            'remote_limited_to' => array_values($outcome['remote_limited_to']),
            'evidence' => array_merge(["Seen on $source_type", $applied ? 'you applied' : 'matching title'], $described !== '' ? ['place read from the description'] : []),
        ]);
        // the company's own website is looked up afterwards (see capture_lookups); what was found before is kept
        [$domain, $career_url] = capture_company_site($details, $url, $domain);
        $details['verification'] = verification_label($details, $source_type, $company, $url);
        $inserted = save_company(['name' => $company, 'title' => $title, 'career_credibility' => $outcome['detail_score'], 'domain' => $domain,
            'career_url' => $career_url, 'source_url' => $url, 'source_type' => $source_type, 'country' => $outcome['country'],
            'state' => mb_substr((string) ($outcome['state_name'] ?? ''), 0, 100) ?: null, 'usa_credibility' => $outcome['location']['score'],
            'city' => $outcome['city'], 'latitude' => $outcome['lat'], 'longitude' => $outcome['lon'], 'distance_miles' => $outcome['miles'],
            'work_arrangement' => $arrangement, 'skills' => listing_skills(htmlspecialchars($details['description'] ?? '')), 'details' => $details]);
        $saved = row('SELECT id, name, career_job_title, work_arrangement, city, state, source_url, source_type, listing_details, is_kept
            FROM companies WHERE source_key = ?', [sha1($url)]);
        if ($saved) {
            $this->remember($saved);
            if ($closed) {
                q("UPDATE companies SET job_open_status = 'Closed', last_checked = ?,
                    result_updated_at = CASE WHEN job_open_status <> 'Closed' THEN ? ELSE result_updated_at END WHERE id = ?", [now_utc(), now_utc(), $saved['id']]);
            }
            if ($applied) {
                $this->mark_applied((int) $saved['id']);
            }
        }
        $notes = array_keys(array_filter(['you applied: saved as Applied' => $applied, 'closed on the site' => $closed]));
        return [$inserted ? 'added' : 'updated', implode('; ', $notes)];
    }
}

/** The company website found for a job, applied to its details: [domain, View link]. */
function capture_company_site(array &$details, string $url, string $site_domain): array
{
    $site = $details['employer_site'] ?? [];
    $board = $details['ats_posting'] ?? [];
    $extra = (array) ($site['evidence'] ?? []);
    if ($board) {
        $extra[] = "posting found on the company's own " . ucwords((string) ($board['system'] ?? '')) . ' board';
    }
    $details['evidence'] = array_values(array_unique(array_merge((array) ($details['evidence'] ?? []), $extra)));
    if (!empty($site['domain'])) {
        $details['original_source'] = $url;
    }
    return [($site['domain'] ?? '') ?: $site_domain, ($site['posting_url'] ?? '') ?: (($board['url'] ?? '') ?: $url)];
}

/**
 * Imports uploaded capture files: [[name, text], ...]. Files already imported unchanged are skipped (by content).
 * Returns ['files', 'jobs', 'counts', 'log', 'error'].
 */
function import_capture_files(array $files): array
{
    $seen = setting_json('capture_imported', []);
    $work = [];
    $log = [];
    $read = 0;
    foreach ($files as [$name, $text]) {
        $capture = read_capture($text);
        if ($capture === null) {
            $log[] = "[E6005] Skipped $name: not a Web Job Scraper capture file.";
            continue;
        }
        $hash = sha1($text);
        if (isset($seen[$hash])) {
            $log[] = "Skipped $name: already imported.";
            continue;
        }
        $read++;
        foreach ($capture[1] as $job) {
            $work[] = [$hash, $capture[0], $job];
        }
        $seen[$hash] = now_utc();
    }
    $result = ['files' => $read, 'jobs' => count($work), 'counts' => [], 'log' => $log, 'error' => null];
    if (!$work) {
        $result['error'] = $read ? null : 'Nothing new to import.';
        return $result;
    }
    $run = new CaptureImport();
    if (!$run->wanted) {
        $result['error'] = '[E6003] Add job titles to your profile first.';
        return $result;
    }
    $failed = [];
    foreach ($work as [$hash, $site, $job]) {
        try {
            [$outcome, $reason] = $run->import_job($site, $job);
        } catch (Throwable $error) {
            error_log('[E6004] Captured job not saved: ' . $error->getMessage());
            [$outcome, $reason] = ['skipped', '[E6004] could not be saved'];
            $failed[$hash] = true; // that file is tried again next time
        }
        $run->counts[$outcome]++;
        $label = mb_substr($job['title'], 0, 70) . ' (' . CAPTURE_SITES[$site][0] . ')';
        $log[] = ucfirst($outcome) . ": $label" . ($reason !== '' ? " — $reason" : '');
    }
    foreach (array_keys($failed) as $hash) {
        unset($seen[$hash]);
    }
    // the record of imported files only keeps the last 500
    set_setting_json('capture_imported', array_slice($seen, -500, null, true));
    tidy_closed_jobs();
    $result['counts'] = $run->counts;
    $result['log'] = $log;
    return $result;
}

/** Captured jobs whose company website hasn't been looked up yet, newest first. */
function capture_lookups_pending(int $limit = 50): array
{
    $sources = implode(', ', array_fill(0, count(CAPTURE_SITES), '?'));
    $out = [];
    foreach (rows("SELECT id, name, career_job_title, source_url, source_type, listing_details FROM companies
        WHERE is_rejected = 0 AND source_type IN ($sources) ORDER BY date_found DESC", array_column(CAPTURE_SITES, 0)) as $row) {
        $details = row_details($row);
        if (empty($details['employer_checked']) && ($row['name'] ?? '') !== '') {
            $out[] = $row + ['details' => $details];
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

/**
 * Looks up the company's own website for captured jobs until $seconds run out: a domain guessed from the name and
 * checked to be that company, its careers page, this job on it, or the job on the company's hiring board. Only the
 * company's sites are visited, never LinkedIn. Returns how many were looked up.
 */
function run_capture_lookups(float $seconds): int
{
    $until = microtime(true) + $seconds;
    $done = 0;
    // one lookup can take ten seconds or more, so none starts in the last 15; and only one runs at a time
    $lock = fopen(sys_get_temp_dir() . '/jobfinder-capture-lookups-' . md5(APP_ROOT) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    foreach (capture_lookups_pending() as $row) {
        if ($until - microtime(true) < 15) {
            break;
        }
        $details = $row['details'];
        $details['employer_checked'] = true;
        $notes = [];
        $title = (string) $row['career_job_title'];
        $site = null;
        try {
            $site = resolve_employer_site((string) $row['name'], $title, (string) $row['source_url'], new Page((string) $row['source_url'], ''), [$title], $notes);
            if ($site) {
                $details['employer_site'] = ['domain' => $site['domain'], 'careers_url' => $site['careers_url'], 'method' => $site['method'],
                    'posting_found' => (bool) $site['posting_url'], 'posting_url' => $site['posting_url'], 'evidence' => array_values($site['evidence'])];
            }
            if (!($site['posting_url'] ?? null) && ($board = company_board_posting((string) $row['name'], $title, $until))) {
                $details['ats_posting'] = ['system' => $board['system'], 'url' => $board['url']];
            }
        } catch (Throwable $error) {
            error_log('Company website lookup failed: ' . $error->getMessage());
        }
        $source_domain = array_column(CAPTURE_SITES, 1, 0)[$row['source_type']] ?? '';
        [$domain, $career_url] = capture_company_site($details, (string) $row['source_url'], $source_domain);
        $details['verification'] = verification_label($details, $row['source_type'], $row['name'], $row['source_url']);
        q('UPDATE companies SET domain = ?, career_url = ?, listing_details = ? WHERE id = ?',
            [$domain, $career_url, json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]);
        $done++;
    }
    return $done;
}
