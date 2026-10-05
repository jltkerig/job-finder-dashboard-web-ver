<?php
// Judging one opening (internship? closed to U.S. applicants? in your places? U.S. evidence?), the related titles
// a search also matches, and saving a job. Ported from the desktop app's judging.py, relevance.py, shared.py and
// storage.py.

declare(strict_types=1);

require_once __DIR__ . '/company.php';
require_once __DIR__ . '/location.php';
require_once APP_ROOT . '/includes/skills.php';
require_once APP_ROOT . '/includes/onet.php';

const TITLE_FAMILIES = [
    ['/\bdesign(?:er)?\b/i', ['Multimedia Designer', 'Brand Designer', 'Creative Designer', 'Marketing Designer', 'Email Designer', 'Communications Designer']],
    ['/\bproduction\b/i', ['Production Artist', 'Web Production Specialist', 'Digital Production Specialist']],
    ['/\bproducer\b/i', ['Website Producer', 'Digital Producer']],
    ['/\bcontent designer\b/i', ['UX Writer']],
];

/** True for an internship or co-op, when internships are skipped (Tuning). */
function is_internship(?string $title, ?string $schedule = ''): bool
{
    return tuning('exclude_internships') && (preg_match('/\b(?:intern|interns|internship|internships|co-?op)\b/i', (string) $title)
        || preg_match('/\bintern/i', (string) $schedule));
}

/** The typed titles plus up to two related O*NET titles for each. */
function expand_job_titles(array $titles): array
{
    $expanded = $titles;
    $seen = array_flip(array_map('mb_strtolower', $titles));
    foreach ($titles as $title) {
        $words = explode(' ', mb_strtolower($title));
        $role = end($words);
        $added = 0;
        foreach (related_title_suggestions($title, 12) as $suggestion) {
            $lower = mb_strtolower($suggestion);
            if (in_array($role, explode(' ', $lower), true) && !isset($seen[$lower])) {
                $expanded[] = $suggestion;
                $seen[$lower] = true;
                if (++$added === 2) {
                    break;
                }
            }
        }
    }
    return $expanded;
}

/** Extra titles matched (not searched for), from the families the typed titles belong to (Tuning switch). */
function related_family_titles(array $typed): array
{
    if (!tuning('related_titles')) {
        return [];
    }
    $have = array_flip(array_map('mb_strtolower', $typed));
    $extras = [];
    foreach (TITLE_FAMILIES as [$pattern, $titles]) {
        if (array_filter($typed, fn($t) => preg_match($pattern, $t))) {
            foreach ($titles as $title) {
                if (!isset($have[mb_strtolower($title)]) && !in_array($title, $extras, true)) {
                    $extras[] = $title;
                }
            }
        }
    }
    return $extras;
}

/**
 * The U.S., location and remote checks every source shares. $ctx holds the search's state text, selected_states,
 * statewide_states and city_targets. Returns the outcome; outcome['skip'] is why the job is out, or null.
 */
function assess_opening(array $opening, Page $page, string $job_url, array $ctx): array
{
    $text = ($opening['description'] ?? '') ?: $page->text();
    $arrangement = $opening['type'] ?? null ?: detect_work_arrangement((string) $opening['title'], $page);
    $outcome = ['skip' => null, 'arrangement' => $arrangement, 'location' => null, 'detail_score' => 0, 'country' => null,
        'state_name' => null, 'remote_limited_to' => [], 'city' => null, 'lat' => null, 'lon' => null, 'miles' => null];
    if (is_internship($opening['title'] ?? '', $opening['schedule'] ?? '')) {
        $outcome['skip'] = 'Internship';
        return $outcome;
    }
    if (excludes_us($opening['location'] ?? '', $text)) {
        $outcome['skip'] = 'Posting restricts applicants outside the US';
        return $outcome;
    }
    $location = analyze_usa_location($page, (string) ($opening['location'] ?? ''), "job posting $job_url");
    // An individual opening was already confirmed, so it meets the career-credibility threshold.
    $detail_score = max(CAREER_CREDIBILITY_THRESHOLD, score_career_page($page)['score']);
    $from_careers_site = (bool) array_filter($opening['evidence'] ?? [], fn($e) => str_contains((string) $e, 'careers site ('));
    if (identify_board($job_url) || $from_careers_site) {
        $detail_score = max($detail_score, OFFICIAL_BOARD_CREDIBILITY);
    }
    // The listing's own location beats anything found elsewhere on the page.
    $state_name = find_state_from_text((string) ($opening['location'] ?? '')) ?? $location['state'] ?? (($opening['location'] ?? '') ?: null);
    $code = US_STATES[mb_strtolower((string) $state_name)] ?? strtoupper((string) $state_name);
    $outcome['location'] = $location;
    $outcome['detail_score'] = $detail_score;
    $outcome['country'] = $location['country'];
    $outcome['state_name'] = $state_name;
    $within = true;
    $skip_reason = 'Outside selected location';
    $selected = $ctx['selected_states'];
    $statewide = $ctx['statewide_states'];
    if ($arrangement === 'Remote') {
        $limited = remote_state_restrictions($text, in_array($code, state_codes(), true) ? $code : null,
            $opening['locations'] ?? [$opening['location'] ?? '']);
        $limited = array_values(array_unique(array_merge($limited, $opening['remote_states'] ?? [])));
        $outcome['remote_limited_to'] = $limited;
        if ($limited && $selected && !array_intersect($limited, $selected)) {
            $within = false;
            sort($limited);
            $skip_reason = 'Remote job limited to residents of ' . implode(', ', $limited);
        }
    } elseif ($statewide && in_array($code, $statewide, true)) {
        // a statewide pick covers it, even when city radii are also selected
    } elseif ($ctx['city_targets']) {
        [$within, $outcome['city'], $outcome['lat'], $outcome['lon'], $outcome['miles']] = distance_to_city_targets(
            $page, ($opening['location'] ?? '') ?: (string) $opening['title'], $ctx['state'], $ctx['city_targets'],
            !is_third_party($job_url, $opening['company'] ?? ''));
    } elseif ($statewide) {
        $within = false;
    }
    if (!$within) {
        $outcome['skip'] = $skip_reason;
    } elseif (tuning('usa_only') && $location['score'] < USA_CREDIBILITY_THRESHOLD) {
        $outcome['skip'] = 'US eligibility unverified';
    }
    return $outcome;
}

/** Saves a job; the posting's own address is the key. True when it was new, false when an earlier copy was updated. */
function save_company(array $job): bool
{
    $now = now_utc();
    $key = sha1(canonical_url($job['source_url']) ?: $job['source_url']);
    $values = [
        'name' => $job['name'], 'career_job_title' => $job['title'], 'career_credibility' => $job['career_credibility'],
        'domain' => $job['domain'], 'career_url' => $job['career_url'], 'source_url' => $job['source_url'],
        'source_type' => $job['source_type'] ?? 'Brave Search', 'country' => $job['country'] ?? null, 'state' => $job['state'] ?? null,
        'city' => $job['city'] ?? null, 'latitude' => $job['latitude'] ?? null, 'longitude' => $job['longitude'] ?? null,
        'distance_miles' => $job['distance_miles'] ?? null, 'usa_credibility' => $job['usa_credibility'] ?? 0,
        'work_arrangement' => $job['work_arrangement'] ?? null, 'listing_skills' => json_encode($job['skills'] ?? []),
        'listing_details' => json_encode($job['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $existing = value('SELECT id FROM companies WHERE source_key = ?', [$key]);
    if ($existing) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($values)));
        q("UPDATE companies SET $set, job_open_status = 'Open', last_checked = ?, result_updated_at = ? WHERE id = ?",
            array_merge(array_values($values), [$now, $now, $existing]));
        return false;
    }
    $columns = implode(', ', array_keys($values));
    $marks = implode(', ', array_fill(0, count($values), '?'));
    q("INSERT INTO companies (source_key, $columns, job_open_status, date_found, last_checked) VALUES (?, $marks, 'Open', ?, ?)",
        array_merge([$key], array_values($values), [$now, $now]));
    return true;
}

/** Postings you rejected, never found again: their addresses, written the standard way. */
function rejected_posting_urls(): array
{
    $urls = [];
    foreach (rows('SELECT source_url FROM companies WHERE is_rejected = 1 AND source_url IS NOT NULL') as $row) {
        $urls[canonical_url($row['source_url'])] = true;
    }
    return $urls;
}

// --- The same job saved from another site ----------------------------------------------------------------------

function job_company_key(?string $name): string
{
    $text = preg_replace('/\b(?:inc|incorporated|llc|l\.l\.c|ltd|limited|corp|corporation|co|company|plc|lp|llp)\b\.?/i', ' ', mb_strtolower((string) $name));
    preg_match_all('/[a-z0-9]+/', $text, $m);
    return implode(' ', $m[0]);
}

function job_title_key(?string $title): string
{
    $text = preg_replace('/\((?:remote|hybrid|on-?site)\)/i', ' ', mb_strtolower((string) $title));
    $text = preg_replace('/\bfront[ -]?end\b/', 'frontend', $text);
    preg_match_all('/[a-z0-9]+/', $text, $m);
    return implode(' ', array_filter($m[0], fn($w) => !in_array($w, ['remote', 'hybrid', 'onsite', 'the', 'a', 'job'], true)));
}

/** What identifies one real job across sites (remote jobs: company and title; others also the city), or null. */
function job_match_key(?string $company, ?string $title, ?string $location, ?string $arrangement): ?string
{
    $who = job_company_key($company);
    $what = job_title_key($title);
    if ($who === '' || $what === '') {
        return null;
    }
    if (mb_strtolower((string) $arrangement) === 'remote') {
        return "$who|$what|remote";
    }
    $text = trim(preg_replace('/\((?:remote|hybrid|on-?site)\)/i', ' ', (string) $location));
    if ($text === '' || !str_contains($text, ',') || preg_match('/\bremote\b|united states/i', $text)) {
        return null;
    }
    preg_match_all('/[a-z0-9]+/', mb_strtolower(explode(',', $text)[0]), $m);
    $city = implode(' ', $m[0]);
    return $city !== '' ? "$who|$what|$city" : null;
}
