<?php
// Jobs in the database: reading them for the Search, Dashboard and Settings pages, and what a listing's buttons do
// (keep, reject, restore, block, notes and status, delete). Also the block lists, Recent Searches and Search Skips.
// Ported from the desktop app's listing_queries.py, listing_actions.py, blocklists.py, search_history.py and
// search_skips.py.

declare(strict_types=1);

require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/places.php';

// Remote job feeds and job sites list many employers' jobs: their domain is the board's, never offered for blocking.
const FEED_NAMES = ['Remote OK', 'Remotive', 'We Work Remotely', 'Hacker News', 'USAJOBS', 'Adzuna', 'National Labor Exchange'];
// Jobs you captured with the Web Job Scraper extension (shown even when the site's domain is blocked).
const CAPTURE_SOURCES = ['LinkedIn', 'Indeed', 'Glassdoor', 'Maryland Workforce Exchange', 'USAJOBS'];
const SAVED_STATUSES = ['Saved', 'Applied', 'Talking With Recruiter', 'Interview'];
const CLOSED_KEEP_DAYS = 7;
// The reasons offered when rejecting, with their labels (the same list as the desktop Job Finder).
const REJECT_REASON_LABELS = ['other' => 'Other', 'wrong_role' => 'Wrong role', 'wrong_location' => 'Wrong location',
    'bad_job_fit' => 'Bad Job Fit', 'not_a_job' => 'Not a job', 'duplicate' => 'Duplicate', 'applied_before' => 'Applied Before', 'closed' => 'Job is closed'];
const REJECT_REASONS = ['other', 'wrong_role', 'wrong_location', 'bad_job_fit', 'not_a_job', 'duplicate', 'applied_before', 'closed'];
const APPLIED_STATUSES = ['Applied', 'Talking With Recruiter', 'Interview'];
const UNSAVED_KEEP_DAYS = 14;  // an unsaved result is deleted this long after it was first saved here
const SKIPS_PER_PAGE = 25;
// How long a page that clearly isn't a job is left alone before it is checked again (hours); other reasons retry next search.
const SKIP_TTL_HOURS = ["Directory or marketplace page" => 24, "Article or student employment guide" => 24,
    "No matching individual opening" => 4, "Job is closed (expired)" => 72];

const COMPANY_COLUMNS = 'id, name, career_job_title, career_credibility, domain, career_url, source_url, source_type,
    country, state, city, latitude, longitude, distance_miles, usa_credibility, work_arrangement, date_found,
    last_checked, result_updated_at, is_kept, job_open_status, application_status, notes, listing_skills,
    listing_details, is_rejected, rejected_at, rejection_reason';

/** Articles and service pages; a /services/ folder under /careers/ still holds real jobs. */
function is_non_job_path(?string $path): bool
{
    $path = (string) $path;
    if (preg_match('#/(?:news|blog|stories|magazine|financial-aid|financialaid|types-of-aid|work-study)(?:/|$)#i', $path)) {
        return true;
    }
    return preg_match('#/services?(?:/|$)#i', $path)
        && !preg_match('#/(?:jobs?|careers?|positions?|openings?|vacanc(?:y|ies)|opportunit(?:y|ies)|employment)(?:/|$)#i', $path);
}

function is_pdf_url(?string $url): bool
{
    return str_ends_with(strtolower((string) parse_url((string) $url, PHP_URL_PATH)), '.pdf');
}

function url_host(?string $url): string
{
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function decode_details(array &$company): void
{
    $details = json_decode($company['listing_details'] ?? '' ?: '{}', true);
    $company['details'] = is_array($details) ? $details : [];
}

// --- Reading -----------------------------------------------------------------------------------------------

/** Search results: everything not rejected, minus blocked companies and domains, closed jobs and non-job pages. */
function get_companies(): array
{
    $rows = rows('SELECT ' . COMPANY_COLUMNS . ' FROM companies WHERE is_rejected = 0 ORDER BY date_found DESC, id DESC');
    $blocked_domains = blocked_domain_list();
    $blocked_names = array_flip(array_map('mb_strtolower', blocked_company_list()));
    $visible = [];
    foreach ($rows as $row) {
        // A job found to be closed disappears from the results (and is deleted after a week); saved ones stay.
        if ($row['job_open_status'] === 'Closed' && !$row['is_kept'] && !in_array($row['application_status'], SAVED_STATUSES, true)) {
            continue;
        }
        if (isset($blocked_names[mb_strtolower((string) $row['name'])])) {
            continue;
        }
        if (!$row['is_kept']) {
            foreach (['source_url', 'career_url'] as $key) {
                if (is_non_job_path(parse_url((string) $row[$key], PHP_URL_PATH)) || str_starts_with(url_host($row[$key]), 'catalystmag.')) {
                    continue 2;
                }
            }
        }
        // The blocked-domain list is for web-search results: jobs you captured on those sites, and jobs from the remote
        // feeds and job sites (whose domain is the board's, such as remoteok.com), still show.
        if (!in_array($row['source_type'], CAPTURE_SOURCES, true) && !in_array($row['source_type'], FEED_NAMES, true)
            && domain_is_blocked((string) $row['domain'], $blocked_domains)) {
            continue;
        }
        decode_details($row);
        $row['source_host'] = url_host($row['source_url']);
        $visible[] = $row;
    }
    return $visible;
}

const APPLIED_STAGES = ['Applied', 'Talking With Recruiter', 'Interview'];

/** (company, title) for spotting one job found on several boards: the company's first real word and the plain title. */
function duplicate_key(array $company): ?string
{
    $filler = ['the', 'inc', 'llc', 'ltd', 'co', 'corp', 'company', 'group', 'government', 'gov', 'of'];
    preg_match_all('/[a-z0-9]+/', mb_strtolower((string) ($company['name'] ?? '')), $m);
    $words = array_values(array_diff($m[0], $filler));
    $name = $words ? (strlen($words[0]) <= 2 ? implode('', array_slice($words, 0, 2)) : $words[0]) : '';
    $title = preg_replace('/\[[^\]]*\]|\((?:remote|hybrid|on-?site)\)/', ' ', mb_strtolower((string) ($company['career_job_title'] ?? '')));
    preg_match_all('/[a-z0-9]+/', $title, $t);
    $title = implode(' ', $t[0]);
    return $name !== '' && $title !== '' ? "$name|$title" : null;
}

/** The Search page's results without the jobs you saved (Keep), applied to or added to the Apply Queue, or their copies
 * on other boards: those live on the Dashboard. $everything is the list the copies are looked for in. */
function hide_dashboard_jobs(array $companies, array $everything): array
{
    $mine = [];
    $is_mine = fn($c) => !empty($c['is_kept']) || in_array($c['application_status'] ?? '', APPLIED_STAGES, true)
        || !empty($c['details']['apply_queued']);
    foreach ($everything as $company) {
        if ($is_mine($company) && ($key = duplicate_key($company)) !== null) {
            $mine[$key] = true;
        }
    }
    return array_values(array_filter($companies, function ($c) use ($is_mine, $mine) {
        $key = duplicate_key($c);
        return !$is_mine($c) && ($key === null || !isset($mine[$key]));
    }));
}

function get_kept_companies(string $status = '', string $state = '', string $title = '', string $sort = 'date_desc'): array
{
    $where = ['is_kept = 1', 'is_rejected = 0'];
    $args = [];
    if ($status === '__applied') {
        $where[] = "application_status IN ('Applied', 'Talking With Recruiter', 'Interview')";
    } elseif ($status === '__not_applied') {
        $where[] = "COALESCE(application_status, 'None') NOT IN ('Applied', 'Talking With Recruiter', 'Interview')";
    } elseif ($status !== '') {
        $where[] = 'application_status = ?';
        $args[] = $status;
    }
    if ($state !== '') {
        $where[] = 'state LIKE ?';
        $args[] = "%$state%";
    }
    if ($title !== '') {
        $where[] = 'career_job_title LIKE ?';
        $args[] = "%$title%";
    }
    $order = ['date_asc' => 'date_found ASC', 'company' => 'name ASC', 'title' => 'career_job_title ASC',
        'status' => 'application_status ASC, date_found DESC', 'verified' => 'last_checked DESC'][$sort] ?? 'date_found DESC';
    $rows = rows('SELECT ' . COMPANY_COLUMNS . ' FROM companies WHERE ' . implode(' AND ', $where) . " ORDER BY $order", $args);
    foreach ($rows as &$row) {
        decode_details($row);
        $row['applied_on'] = clean_applied_date($row['details']['applied_on'] ?? '');
        $row['applied_label'] = $row['applied_on'] !== '' ? date('M d, Y', (int) strtotime($row['applied_on'] . ' 12:00:00')) : '';
    }
    return $rows;
}

/** A date as YYYY-MM-DD, or '' when it is empty or not a real date. */
function clean_applied_date($value): string
{
    $value = trim((string) $value);
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
}

function get_rejected_companies(): array
{
    return rows('SELECT ' . COMPANY_COLUMNS . ' FROM companies WHERE is_rejected = 1 ORDER BY rejected_at DESC, date_found DESC');
}

function get_dashboard_counts(): array
{
    $row = row("SELECT COUNT(*) AS saved,
        SUM(CASE WHEN application_status = 'Applied' THEN 1 ELSE 0 END) AS applied,
        SUM(CASE WHEN application_status = 'Talking With Recruiter' THEN 1 ELSE 0 END) AS recruiter,
        SUM(CASE WHEN application_status = 'Interview' THEN 1 ELSE 0 END) AS interview,
        SUM(CASE WHEN job_open_status = 'Closed' THEN 1 ELSE 0 END) AS closed
        FROM companies WHERE is_kept = 1 AND is_rejected = 0") ?? [];
    $counts = array_map('intval', $row);
    $counts['rejected'] = (int) value('SELECT COUNT(*) FROM companies WHERE is_rejected = 1');
    return $counts;
}

function add_job_fit(array &$companies, array $skills): void
{
    foreach ($companies as &$company) {
        $found = json_decode($company['listing_skills'] ?? '' ?: '[]', true);
        $company['job_fit'] = fit_score($skills, is_array($found) ? $found : []);
    }
}

function add_drive_times(array &$companies, string $home_zip, string $home_state = ''): void
{
    foreach ($companies as &$company) {
        $point = ($company['latitude'] !== null && $company['longitude'] !== null)
            ? [(float) $company['latitude'], (float) $company['longitude']] : null;
        $place = implode(', ', array_filter([$company['city'], $company['state']]));
        $company['drive'] = $home_zip !== '' ? describe_trip($home_zip, $point, $place, $home_state) : null;
    }
}

/** Unsaved jobs closed for more than a week are deleted; saved ones are never deleted. */
function tidy_closed_jobs(): int
{
    tidy_old_jobs();  // every place that tidies closed jobs also drops old unsaved ones
    q("UPDATE companies SET closed_at = ? WHERE job_open_status = 'Closed' AND closed_at IS NULL", [now_utc()]);
    q("UPDATE companies SET closed_at = NULL WHERE job_open_status <> 'Closed' AND closed_at IS NOT NULL");
    $marks = implode(', ', array_fill(0, count(SAVED_STATUSES), '?'));
    return q("DELETE FROM companies WHERE job_open_status = 'Closed' AND closed_at IS NOT NULL AND closed_at < ?
        AND is_rejected = 0 AND is_kept = 0 AND application_status NOT IN ($marks)",
        array_merge([now_utc(-CLOSED_KEEP_DAYS * 86400)], SAVED_STATUSES))->rowCount();
}

/**
 * Unsaved results are deleted UNSAVED_KEEP_DAYS days after they were first saved here (date_found is when this site
 * first saved the job; it does not change when the job is seen again, so this is not the posting's age). Saved and
 * applied jobs stay, and rejected ones stay so a rejected job is still recognised.
 */
function tidy_old_jobs(): int
{
    $marks = implode(', ', array_fill(0, count(SAVED_STATUSES), '?'));
    return q("DELETE FROM companies WHERE is_rejected = 0 AND is_kept = 0 AND COALESCE(application_status, 'None') NOT IN ($marks)
        AND date_found < ?", array_merge(SAVED_STATUSES, [now_utc(-UNSAVED_KEEP_DAYS * 86400)]))->rowCount();
}

// --- What the buttons do -----------------------------------------------------------------------------------

function reject_listing(int $id, string $reason): ?array
{
    $reason = in_array($reason, REJECT_REASONS, true) ? $reason : 'other';
    $row = row('SELECT domain FROM companies WHERE id = ?', [$id]);
    if (!$row) {
        return null;
    }
    q("UPDATE companies
        SET pre_reject_kept = CASE WHEN is_rejected = 0 THEN is_kept ELSE pre_reject_kept END,
            pre_reject_status = CASE WHEN is_rejected = 0 THEN application_status ELSE pre_reject_status END,
            is_rejected = 1, is_kept = 0, application_status = 'Rejected',
            rejected_at = ?, rejection_reason = ?, rejected_by = 'user'
        WHERE id = ?", [now_utc(), $reason, $id]);
    return $row;
}

/** Change why a rejected listing was rejected. It becomes the user's decision, so a later search will not restore it. */
function change_rejection_reason(int $id, string $reason): bool
{
    if (!row('SELECT 1 FROM companies WHERE id = ? AND is_rejected = 1', [$id])) {
        return false;
    }
    q("UPDATE companies SET rejection_reason = ?, rejected_by = 'user' WHERE id = ? AND is_rejected = 1", [$reason, $id]);
    return true;
}

function restore_rejected(int $id): ?array
{
    $row = row('SELECT domain, pre_reject_kept FROM companies WHERE id = ? AND is_rejected = 1', [$id]);
    if (!$row) {
        return null;
    }
    // Older rejections have no saved state; they return as unsaved search results.
    q("UPDATE companies SET is_rejected = 0, is_kept = COALESCE(pre_reject_kept, 0),
        application_status = COALESCE(pre_reject_status, 'None'), rejected_at = NULL, rejection_reason = NULL,
        rejected_by = NULL, pre_reject_kept = NULL, pre_reject_status = NULL WHERE id = ?", [$id]);
    return $row;
}

function keep_listings(array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    return q("UPDATE companies SET is_kept = 1, application_status = 'Saved' WHERE id IN ($marks)", $ids)->rowCount();
}

function unsave_listing(int $id): void
{
    q("UPDATE companies SET is_kept = 0, application_status = 'None' WHERE id = ?", [$id]);
}

/**
 * Status and notes of a saved job, and the date applied (kept in listing_details). $applied_on is null when the form had
 * no date field, '' when it was cleared. Becoming Applied uses today's date when none is saved or typed; a job that was
 * already Applied before dates existed stays without one until you enter it.
 */
function update_kept(int $id, string $status, string $notes, ?string $applied_on = null): void
{
    $status = in_array($status, APPLICATION_STATUSES, true) ? $status : 'None';
    $before = row('SELECT application_status, listing_details FROM companies WHERE id = ? AND is_kept = 1', [$id]);
    if (!$before) {
        return;
    }
    $details = json_decode((string) ($before['listing_details'] ?: '{}'), true);
    $details = is_array($details) ? $details : [];
    $had = clean_applied_date($details['applied_on'] ?? '');
    $chosen = $applied_on === null ? $had : clean_applied_date($applied_on);
    if ($status === 'Applied' && $chosen === '' && $before['application_status'] !== 'Applied') {
        $chosen = date('Y-m-d');  // the site's own time zone (config timezone)
    }
    if ($chosen !== '') {
        $details['applied_on'] = $chosen;
    } else {
        unset($details['applied_on']);
    }
    q('UPDATE companies SET application_status = ?, notes = ?, listing_details = ? WHERE id = ? AND is_kept = 1',
        [$status, mb_substr($notes, 0, 5000), json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
}

/** Deletes the saved jobs ticked on the Dashboard (the page asks "Are you sure?" first). Returns how many. */
function delete_kept_many(array $ids): int
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 1000);
    if (!$ids) {
        return 0;
    }
    return q('DELETE FROM companies WHERE is_kept = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
}

function delete_kept(int $id): void
{
    q('DELETE FROM companies WHERE id = ? AND is_kept = 1', [$id]);
}

// --- Block lists -------------------------------------------------------------------------------------------

function clean_domain(?string $domain): string
{
    $domain = strtolower(trim((string) $domain));
    return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
}

function blocked_domain_list(): array
{
    return array_column(rows('SELECT domain FROM blocked_domains ORDER BY domain'), 'domain');
}

function domain_is_blocked(string $domain, ?array $blocked = null): bool
{
    $domain = clean_domain($domain);
    if ($domain === '') {
        return false;
    }
    foreach ($blocked ?? blocked_domain_list() as $item) {
        if ($domain === $item || str_ends_with($domain, ".$item")) {
            return true;
        }
    }
    return false;
}

function block_domain(?string $domain): void
{
    $domain = clean_domain($domain);
    if ($domain !== '' && !value('SELECT 1 FROM blocked_domains WHERE domain = ?', [$domain])) {
        q("INSERT INTO blocked_domains (domain, source, blocked_at) VALUES (?, 'User', ?)", [$domain, date('c')]);
    }
}

function unblock_domain(?string $domain): void
{
    q('DELETE FROM blocked_domains WHERE domain = ?', [clean_domain($domain)]);
}

function blocked_company_list(): array
{
    $names = array_column(rows('SELECT name FROM blocked_companies'), 'name');
    usort($names, fn($a, $b) => strcasecmp($a, $b));
    return $names;
}

function block_company(?string $name): bool
{
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name)), 0, 150);
    if ($name === '') {
        return false;
    }
    $key = mb_strtolower($name);
    if (!value('SELECT 1 FROM blocked_companies WHERE name_key = ?', [$key])) {
        q("INSERT INTO blocked_companies (name_key, name, source, blocked_at) VALUES (?, ?, 'User', ?)", [$key, $name, date('c')]);
    }
    return true;
}

function unblock_company(?string $name): void
{
    q('DELETE FROM blocked_companies WHERE name_key = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name)))]);
}

/** [name => [source, blocked_at]] for the Settings lists. */
function block_details(string $kind): array
{
    $details = [];
    if ($kind === 'domains') {
        foreach (rows('SELECT domain, source, blocked_at FROM blocked_domains') as $row) {
            $details[$row['domain']] = ['source' => $row['source'], 'blocked_at' => $row['blocked_at']];
        }
    } else {
        foreach (rows('SELECT name, source, blocked_at FROM blocked_companies') as $row) {
            $details[$row['name']] = ['source' => $row['source'], 'blocked_at' => $row['blocked_at']];
        }
    }
    return $details;
}

// --- Recent Searches ---------------------------------------------------------------------------------------

function record_search_history(string $job_title, string $state, array $cities = []): void
{
    $job_title = implode(', ', array_map('proper_title', array_filter(array_map('trim', explode(',', $job_title)), 'strlen')));
    $cities_text = json_encode($cities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // The same search again just moves the earlier entry back to the top.
    $earlier = value("SELECT id FROM search_history WHERE LOWER(TRIM(job_title)) = ? AND state = ?
        AND COALESCE(cities_json, '[]') = ? ORDER BY searched_at DESC LIMIT 1", [mb_strtolower(trim($job_title)), $state, $cities_text]);
    if ($earlier) {
        q('UPDATE search_history SET searched_at = ? WHERE id = ?', [now_utc(), $earlier]);
    } else {
        q('INSERT INTO search_history (job_title, state, cities_json, searched_at) VALUES (?, ?, ?, ?)',
            [$job_title, $state, $cities_text, now_utc()]);
    }
}

/** Newest first, one entry for each distinct search, with the main title split from the others. */
function get_search_history(int $limit = 10): array
{
    $seen = [];
    $result = [];
    foreach (rows('SELECT job_title, state, cities_json, searched_at FROM search_history ORDER BY searched_at DESC LIMIT '
        . max($limit * 10, 100)) as $row) {
        $key = mb_strtolower(trim($row['job_title'])) . '|' . mb_strtolower(trim($row['state'])) . '|' . trim($row['cities_json'] ?: '[]');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $parts = array_values(array_filter(array_map('trim', explode(',', $row['job_title'])), 'strlen'));
        $row['main_title'] = $parts[0] ?? '';
        $row['other_titles'] = array_slice($parts, 1);
        $result[] = $row;
        if (count($result) >= $limit) {
            break;
        }
    }
    return $result;
}

// --- Search Skips --------------------------------------------------------------------------------------------

function record_skip(string $url, string $title, string $reason): void
{
    $key = sha1($url);
    q('DELETE FROM search_skips WHERE url_key = ?', [$key]);
    q('INSERT INTO search_skips (url_key, url, title, reason, checked_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $url, mb_substr($title, 0, 255), mb_substr($reason, 0, 80), gmdate('Y-m-d\TH:i:s\Z')]);
}

function clear_skip(string $url): void
{
    q('DELETE FROM search_skips WHERE url_key = ?', [sha1($url)]);
}

/** True when this page was skipped recently enough that a search shouldn't look at it again yet. */
function recently_skipped(string $url): bool
{
    $row = row('SELECT reason, checked_at FROM search_skips WHERE url_key = ?', [sha1($url)]);
    if (!$row || !isset(SKIP_TTL_HOURS[$row['reason']])) {
        return false;
    }
    return strtotime($row['checked_at']) + SKIP_TTL_HOURS[$row['reason']] * 3600 > time();
}

/** [skips on this page, total, page, pages] newest first, optionally only those matching a search. */
function search_skips_page(string $query, int $page): array
{
    $where = "reason <> 'Passed'";
    $args = [];
    if ($query !== '') {
        $where .= ' AND (title LIKE ? OR reason LIKE ? OR url LIKE ?)';
        $args = ["%$query%", "%$query%", "%$query%"];
    }
    $total = (int) value("SELECT COUNT(*) FROM search_skips WHERE $where", $args);
    $pages = max(1, (int) ceil($total / SKIPS_PER_PAGE));
    $page = min(max($page, 1), $pages);
    $items = rows("SELECT url, title, reason, checked_at FROM search_skips WHERE $where ORDER BY checked_at DESC LIMIT "
        . SKIPS_PER_PAGE . ' OFFSET ' . (($page - 1) * SKIPS_PER_PAGE), $args);
    foreach ($items as &$item) {
        $item['next_check'] = 'Next search';
        if (isset(SKIP_TTL_HOURS[$item['reason']])) {
            $expires = strtotime($item['checked_at']) + SKIP_TTL_HOURS[$item['reason']] * 3600;
            if ($expires > time()) {
                $item['next_check'] = date('M d, Y h:i A', $expires);
            }
        }
    }
    return [$items, $total, $page, $pages];
}

/** The most recent skips, for "Why were some pages skipped?" on the Search page. */
function recent_skips(int $limit = 20): array
{
    return rows("SELECT url, title, reason FROM search_skips WHERE reason <> 'Passed' ORDER BY checked_at DESC LIMIT $limit");
}

/** LinkedIn's Easy Apply mark, in the look LinkedIn gives the button on its job tracker: its blue, with its "in" square. */
function easy_apply_badge(): string
{
    return '<span class="badge easy-apply-badge" title="LinkedIn Easy Apply: you apply on LinkedIn without leaving it"><svg aria-hidden="true" viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M15 2v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1M5 6H3v7h2zm.25-2A1.25 1.25 0 1 0 4 5.25 1.25 1.25 0 0 0 5.25 4M13 9.29c0-2.2-.73-3.49-2.86-3.49A2.71 2.71 0 0 0 7.89 7V6H6v7h2V9.73a1.73 1.73 0 0 1 1.54-1.92h.12C10.82 7.8 11 8.94 11 9.73V13h2z"/></svg>Easy Apply</span>';
}

/** The Job Apply Stats: jobs applied to by month (the last 6), those before that, and those with no date saved. Jobs
 * applied to outside Job Finder (the "applied_elsewhere" setting) count in their months too. */
function applied_by_month(?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $shown = [];
    $year = (int) $today->format('Y');
    $month = (int) $today->format('n');
    for ($i = 0; $i < 6; $i++) {
        $shown[] = [sprintf('%04d-%02d', $year, $month), (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('M Y')];
        if (--$month === 0) {
            $month = 12;
            $year--;
        }
    }
    $oldest = $shown[5][0];
    $dates = [];
    $marks = implode(', ', array_fill(0, count(APPLIED_STAGES), '?'));
    foreach (rows("SELECT listing_details FROM companies WHERE is_kept = 1 AND is_rejected = 0 AND application_status IN ($marks)", APPLIED_STAGES) as $row) {
        $details = json_decode((string) ($row['listing_details'] ?? ''), true);
        $dates[] = clean_applied_date(is_array($details) ? ($details['applied_on'] ?? '') : '');
    }
    require_once APP_ROOT . '/includes/companies.php';
    foreach (applied_elsewhere() as $entry) {
        $dates[] = $entry['applied_on'];
    }
    $counts = [];
    $earlier = $undated = 0;
    foreach ($dates as $date) {
        $key = substr($date, 0, 7);
        if ($key === '') {
            $undated++;
        } elseif ($key < $oldest) {
            $earlier++;
        } else {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }
    return ['months' => array_map(fn($m) => ['key' => $m[0], 'label' => $m[1], 'count' => $counts[$m[0]] ?? 0], $shown),
        'earlier' => $earlier, 'undated' => $undated];
}


// --- Notes on a listing's Details, and the Dashboard's Clear closed jobs ----------------------------------------------

const NOTES_LIMIT = 5000;

/** Note text with each web address made a link (opens in a new tab). Everything else is escaped. */
function linkify(?string $text): string
{
    $text = (string) $text;
    $out = '';
    $end = 0;
    if (preg_match_all('~https?://[^\s<>"\']+~', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$found, $at]) {
            $url = rtrim($found, '.,;:!?)');
            $out .= h(substr($text, $end, $at - $end)) . '<a href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($url) . '</a>';
            $end = $at + strlen($url);
        }
    }
    return $out . h(substr($text, $end));
}

/** Saves the notes on a listing; returns what was kept. */
function save_listing_notes(int $id, string $notes): string
{
    $notes = mb_substr(trim($notes), 0, NOTES_LIMIT);
    q('UPDATE companies SET notes = ? WHERE id = ?', [$notes !== '' ? $notes : null, $id]);
    return $notes;
}

/** Deletes every saved job whose posting is closed (the page asks "are you sure?" first). */
function delete_closed_kept(): int
{
    return q("DELETE FROM companies WHERE is_kept = 1 AND job_open_status = 'Closed'")->rowCount();
}
