<?php
// Jobs in the database: reading them for the Search, Dashboard and Settings pages, and what a listing's buttons do
// (keep, reject, restore, block, notes and status, delete). Also the block lists, Recent Searches and Search Skips.
// Ported from the desktop app's listing_queries.py, listing_actions.py, blocklists.py, search_history.py and
// search_skips.py.

declare(strict_types=1);

require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/places.php';

// Remote job feeds and job sites list many employers' jobs: their domain is the board's, never offered for blocking.
const FEED_NAMES = ['Remote OK', 'Remotive', 'We Work Remotely', 'USAJOBS', 'Adzuna', 'National Labor Exchange'];
// Jobs you captured with the Web Job Scraper extension (shown even when the site's domain is blocked).
const CAPTURE_SOURCES = ['LinkedIn', 'Indeed', 'Glassdoor', 'Maryland Workforce Exchange', 'USAJOBS'];
const SAVED_STATUSES = ['Saved', 'Applied', 'Talking With Recruiter', 'Interview'];
const CLOSED_KEEP_DAYS = 7;
const REJECT_REASONS = ['wrong_role', 'wrong_location', 'not_a_job', 'duplicate', 'other'];
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

function get_kept_companies(string $status = '', string $state = '', string $title = '', string $sort = 'date_desc'): array
{
    $where = ['is_kept = 1', 'is_rejected = 0'];
    $args = [];
    if ($status !== '') {
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
    }
    return $rows;
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
    return array_map('intval', $row);
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
    q("UPDATE companies SET closed_at = ? WHERE job_open_status = 'Closed' AND closed_at IS NULL", [now_utc()]);
    q("UPDATE companies SET closed_at = NULL WHERE job_open_status <> 'Closed' AND closed_at IS NOT NULL");
    $marks = implode(', ', array_fill(0, count(SAVED_STATUSES), '?'));
    return q("DELETE FROM companies WHERE job_open_status = 'Closed' AND closed_at IS NOT NULL AND closed_at < ?
        AND is_kept = 0 AND application_status NOT IN ($marks)",
        array_merge([now_utc(-CLOSED_KEEP_DAYS * 86400)], SAVED_STATUSES))->rowCount();
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

function update_kept(int $id, string $status, string $notes): void
{
    $status = in_array($status, APPLICATION_STATUSES, true) ? $status : 'None';
    q('UPDATE companies SET application_status = ?, notes = ? WHERE id = ? AND is_kept = 1', [$status, mb_substr($notes, 0, 5000), $id]);
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
