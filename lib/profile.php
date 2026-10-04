<?php
// Your saved profile (name, titles, places, skills, work history, education) and the suggestions made from it.
// Ported from the desktop app's profile_store.py.

declare(strict_types=1);

require_once __DIR__ . '/onet.php';
require_once __DIR__ . '/skills.php';

const WORK_PREFERENCES = ['Part-time', 'Full-time', 'Contract', 'Freelance / Gig', 'Remote', 'Hybrid', 'Onsite'];
const CITY_RADII = [5, 10, 15, 20, 30, 50];

function get_user_profile(): array
{
    $profile = ['first_name' => '', 'last_name' => '', 'state' => '', 'home_location' => '', 'home_zip' => '',
        'linkedin_url' => '', 'portfolio_url' => '', 'primary_job_title' => '', 'avatar_data' => '', 'skills' => [],
        'work_history' => [], 'education' => [], 'work_preferences' => [], 'job_titles' => [], 'cities' => []];
    $row = row('SELECT first_name, last_name, state, home_location, home_zip, linkedin_url, portfolio_url,
        primary_job_title, avatar_data, work_preferences FROM user_profile WHERE id = 1');
    if ($row) {
        foreach ($row as $key => $value) {
            $profile[$key] = (string) $value;
        }
        $profile['work_preferences'] = json_decode($row['work_preferences'] ?: '[]', true) ?: [];
    }
    $profile['job_titles'] = array_column(rows('SELECT job_title FROM user_profile_job_titles WHERE profile_id = 1 ORDER BY job_title'), 'job_title');
    $profile['cities'] = array_map(fn($r) => ['city' => $r['city'], 'radius_miles' => (int) $r['radius_miles']],
        rows('SELECT city, radius_miles FROM user_profile_cities WHERE profile_id = 1 ORDER BY city'));
    $profile['skills'] = array_column(rows('SELECT skill FROM user_profile_skills WHERE profile_id = 1 ORDER BY skill'), 'skill');
    $detail = implode(', ', array_keys(WORK_DETAIL_COLUMNS));
    $profile['work_history'] = array_map(fn($r) => array_map(fn($v) => (string) $v, $r),
        rows("SELECT company, role, dates, description, $detail FROM user_profile_work_history WHERE profile_id = 1 ORDER BY id"));
    $profile['education'] = array_map(fn($r) => array_map(fn($v) => (string) $v, $r),
        rows('SELECT ' . implode(', ', array_keys(EDUCATION_FIELDS)) . ' FROM user_profile_education WHERE profile_id = 1 ORDER BY id'));
    // Titles are always shown properly capitalized; searching ignores case, so this never changes what is found.
    $profile['primary_job_title'] = proper_title($profile['primary_job_title']);
    $titles = [];
    foreach ($profile['job_titles'] as $title) {
        $titles[proper_title($title)] = true;
    }
    $profile['job_titles'] = array_map('strval', array_keys($titles));
    return $profile;
}

/** A web address as typed, with https:// added when it was left off; anything that isn't a plain web address is dropped. */
function clean_link(?string $text): string
{
    $text = mb_substr(trim((string) $text), 0, 255);
    if ($text !== '' && !preg_match('#^https?://#i', $text)) {
        $text = "https://$text";
    }
    return preg_match('#^https?://[^\s<>"\']+\.[^\s<>"\']+$#i', $text) ? $text : '';
}

/** Each school as {school, degree, major, minor, start_date, end_date, gpa}; entries without a school are dropped. */
function clean_education(array $entries): array
{
    $cleaned = [];
    foreach (array_slice($entries, 0, 20) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = [];
        foreach (EDUCATION_FIELDS as $name => $size) {
            $row[$name] = mb_substr(trim((string) ($item[$name] ?? '')), 0, $size);
        }
        if ($row['degree'] !== '' && !in_array($row['degree'], EDUCATION_DEGREES, true)) {
            $row['degree'] = 'Other';
        }
        if ($row['school'] !== '') {
            $cleaned[] = $row;
        }
    }
    return $cleaned;
}

/** Saves the profile. Lists given as null are left as they are. */
function save_user_profile(array $p): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE user_profile SET first_name = ?, last_name = ?, state = ? WHERE id = 1',
            [$p['first_name'], $p['last_name'], $p['state']]);
        $single = ['home_location' => fn($v) => mb_substr($v, 0, 150), 'home_zip' => fn($v) => $v,
            'linkedin_url' => 'clean_link', 'portfolio_url' => 'clean_link',
            'primary_job_title' => fn($v) => mb_substr($v, 0, 255), 'avatar_data' => fn($v) => $v,
            'work_preferences' => fn($v) => json_encode(array_values($v))];
        foreach ($single as $column => $clean) {
            if (array_key_exists($column, $p) && $p[$column] !== null) {
                q("UPDATE user_profile SET $column = ? WHERE id = 1", [$clean($p[$column])]);
            }
        }
        q('DELETE FROM user_profile_job_titles WHERE profile_id = 1');
        foreach ($p['job_titles'] as $title) {
            q('INSERT INTO user_profile_job_titles (profile_id, job_title) VALUES (1, ?)', [$title]);
        }
        q('DELETE FROM user_profile_cities WHERE profile_id = 1');
        $seen = [];
        foreach ($p['cities'] ?? [] as $item) {
            $city = mb_substr(trim((string) ($item['city'] ?? '')), 0, 150);
            $radius = (int) ($item['radius'] ?? $item['radius_miles'] ?? 50);
            if ($city !== '' && in_array($radius, CITY_RADII, true) && !isset($seen[$city])) {
                $seen[$city] = true;
                q('INSERT INTO user_profile_cities (profile_id, city, radius_miles) VALUES (1, ?, ?)', [$city, $radius]);
            }
        }
        if (isset($p['skills'])) {
            q('DELETE FROM user_profile_skills WHERE profile_id = 1');
            foreach (normalize_skills($p['skills']) as $skill) {
                q('INSERT INTO user_profile_skills (profile_id, skill) VALUES (1, ?)', [$skill]);
            }
        }
        if (isset($p['work_history'])) {
            q('DELETE FROM user_profile_work_history WHERE profile_id = 1');
            $columns = array_keys(WORK_DETAIL_COLUMNS);
            $marks = implode(', ', array_fill(0, count($columns), '?'));
            foreach (array_slice($p['work_history'], 0, 50) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $company = mb_substr(trim((string) ($item['company'] ?? '')), 0, 150);
                $role = mb_substr(trim((string) ($item['role'] ?? '')), 0, 150);
                if ($company === '' && $role === '') {
                    continue;
                }
                $details = [];
                foreach (WORK_DETAIL_COLUMNS as $column => $size) {
                    $details[] = mb_substr(trim((string) ($item[$column] ?? '')), 0, $size);
                }
                q('INSERT INTO user_profile_work_history (profile_id, company, role, dates, description, '
                    . implode(', ', $columns) . ") VALUES (1, ?, ?, ?, ?, $marks)",
                    array_merge([$company, $role, mb_substr(trim((string) ($item['dates'] ?? '')), 0, 100),
                        mb_substr(trim((string) ($item['description'] ?? '')), 0, 3000)], $details));
            }
        }
        if (isset($p['education'])) {
            q('DELETE FROM user_profile_education WHERE profile_id = 1');
            $columns = array_keys(EDUCATION_FIELDS);
            foreach (clean_education($p['education']) as $item) {
                q('INSERT INTO user_profile_education (profile_id, ' . implode(', ', $columns) . ') VALUES (1, '
                    . implode(', ', array_fill(0, count($columns), '?')) . ')', array_values($item));
            }
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Could not save user profile: ' . $e->getMessage());
        return false;
    }
}

/** A short fingerprint of the saved profile; a form saved after the profile changed elsewhere is refused. */
function profile_version(array $profile): string
{
    $shared = [];
    foreach (['education', 'first_name', 'home_location', 'home_zip', 'job_titles', 'last_name', 'linkedin_url',
                 'portfolio_url', 'primary_job_title', 'skills', 'work_history'] as $key) {
        $shared[$key] = $profile[$key] ?? null;
    }
    return substr(sha1(json_encode($shared)), 0, 16);
}

function related_job_title_suggestions(string $raw_titles): array
{
    $selected = array_values(array_filter(array_map('trim', explode(',', $raw_titles)), 'strlen'));
    $selected_lower = array_flip(array_map('mb_strtolower', $selected));
    $suggestions = [];
    foreach ($selected as $title) {
        $key = trim(preg_replace('/\s+/', ' ', mb_strtolower($title)));
        $candidates = array_merge(RELATED_JOB_TITLES[$key] ?? [], related_title_suggestions($title));
        if (!$candidates) {
            if (str_contains($key, 'designer')) {
                $candidates = ['Web Designer', 'UI Designer', 'UX/UI Designer', 'Digital Designer', 'Visual Designer'];
            } elseif (str_contains($key, 'developer')) {
                $candidates = ['Web Developer', 'Front End Developer', 'Frontend Developer', 'UI Developer', 'WordPress Developer'];
            } elseif (str_contains($key, 'content')) {
                $candidates = ['Web Content Specialist', 'Content Coordinator', 'Digital Content Specialist', 'CMS Specialist', 'Website Coordinator'];
            }
        }
        foreach ($candidates as $candidate) {
            $lower = mb_strtolower($candidate);
            if (!isset($selected_lower[$lower]) && !in_array($lower, array_map('mb_strtolower', $suggestions), true)) {
                $suggestions[] = proper_title($candidate);
            }
        }
    }
    return array_slice($suggestions, 0, 8);
}

/** Skills for the Add Skill box: those tied to your primary title first, then every skill Job Finder recognizes. */
function profile_skill_suggestions(array $profile): array
{
    $selected = $profile['primary_job_title'] ?: ($profile['job_titles'][0] ?? '');
    $aliases = [];
    foreach (SKILL_ALIASES as $name => $variants) {
        foreach (array_merge([$name], $variants) as $alias) {
            $aliases[mb_strtolower($alias)] = $name;
        }
    }
    $ranked = [];
    foreach (occupation_skill_suggestions($selected, 150) as $example) {
        $lower = mb_strtolower($example);
        $canonical = $aliases[$lower] ?? null;
        if ($canonical === null) {
            foreach ($aliases as $alias => $name) {
                if (strlen((string) $alias) >= 3 && preg_match('/(?<!\w)' . preg_quote((string) $alias, '/') . '(?!\w)/u', $lower)) {
                    $canonical = $name;
                    break;
                }
            }
        }
        if ($canonical && !in_array($canonical, $ranked, true)) {
            $ranked[] = $canonical;
        }
    }
    $saved = array_flip(array_map('mb_strtolower', $profile['skills']));
    $all = array_unique(array_merge($ranked, array_keys(SKILL_ALIASES)));
    return array_values(array_filter($all, fn($s) => !isset($saved[mb_strtolower((string) $s)])));
}

/** The skills most often named by the jobs found (not rejected) that your profile doesn't list. */
function listing_skill_demand(array $saved_skills, int $limit = 12): array
{
    $lists = array_column(rows('SELECT listing_skills FROM companies WHERE is_rejected = 0 AND listing_skills IS NOT NULL'), 'listing_skills');
    return skill_demand($lists, $saved_skills, $limit);
}

function related_skills_for(string $term, int $limit = 8): array
{
    $lists = array_column(rows('SELECT listing_skills FROM companies WHERE is_rejected = 0 AND listing_skills IS NOT NULL
        AND listing_skills LIKE ? LIMIT 800', ['%' . mb_substr($term, 0, 60) . '%']), 'listing_skills');
    return related_skills($term, $lists, $limit);
}

/** Re-reads saved listings for skills the current skills list recognizes (only ever adds). Returns how many changed. */
function refresh_job_fit(): int
{
    $changed = 0;
    foreach (rows('SELECT id, listing_skills, listing_details FROM companies WHERE listing_details IS NOT NULL') as $row) {
        $details = json_decode($row['listing_details'] ?: '{}', true);
        $old = json_decode($row['listing_skills'] ?: '[]', true);
        $description = is_array($details) ? ($details['description'] ?? null) : null;
        if (!is_string($description) || trim($description) === '' || !is_array($old)) {
            continue;
        }
        $known = array_flip(array_map('mb_strtolower', $old));
        $added = array_values(array_filter(listing_skills($description), fn($s) => !isset($known[mb_strtolower($s)])));
        if ($added) {
            q('UPDATE companies SET listing_skills = ? WHERE id = ?', [json_encode(array_merge($old, $added)), $row['id']]);
            $changed++;
        }
    }
    return $changed;
}

/** The home state (two letters) from the profile, for putting nearby places first. */
function home_state(): string
{
    $profile = row('SELECT state, home_location FROM user_profile WHERE id = 1') ?? [];
    foreach ([$profile['home_location'] ?? '', $profile['state'] ?? ''] as $text) {
        $parts = array_map('trim', explode(',', (string) $text));
        $code = state_code(end($parts) ?: '');
        if ($code !== '') {
            return $code;
        }
    }
    return '';
}
