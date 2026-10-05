<?php
// What your reference documents and uploaded résumé have that your profile doesn't yet: jobs, skills, missing job
// details (address, phone, supervisor) and people who could be references. Only suggestions: nothing is saved
// until you tick what to add on the Résumé Builder page. Ported from the desktop's document_import.py and
// profile_import.py.

declare(strict_types=1);

require_once APP_ROOT . '/lib/skills.php';
require_once APP_ROOT . '/lib/onet.php'; // fold()

const SUGGESTION_KINDS = ['job', 'skill', 'detail', 'reference'];
const SUGGEST_MONTH = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?';
const SUGGEST_WHEN = '(?:' . SUGGEST_MONTH . '\s+(?:19|20)\d{2}|\d{1,2}\/(?:19|20)\d{2}|(?:19|20)\d{2})';
const SUGGEST_DATES = '/^' . SUGGEST_WHEN . '\s*(?:[-–—]|to)\s*(?:' . SUGGEST_WHEN . '|present|current|now)$/iu';
const SUGGEST_PLACE = "/^(?:remote|hybrid|on[- ]?site|in[- ]office|[A-Za-z .'-]{2,40},\\s*(?:[A-Z]{2}|[A-Za-z ]{4,20}))$/i";
const SUGGEST_HEADINGS = '/^(?:professional\s+)?(?:summary|profile|objective|(?:employment|work|professional)?\s*(?:history|experience)|'
    . 'education(?:\s+history)?|skills|technical skills|certifications?|projects|awards|additional experience|'
    . 'volunteer(?:ing| experience)?|references|interests|languages)$/i';
const SUGGEST_ADDRESS = '/^\d+\s+\S/';
const SUGGEST_NOT_A_TITLE = '/^[\d\s()+.-]*$|@|https?:\/\/|^\d{5}(?:-\d{4})?$/';
const SUGGEST_CONTACT = '/phone:|e-?mail:|@|^\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}$/i';
const SUGGEST_STOP_WORDS = ['inc', 'llc', 'the', 'of', 'and', 'co', 'corp', 'company', 'ltd'];
const SUGGEST_EMAIL = '/^[\w.+-]+@([\w-]+(?:\.[\w-]+)+)$/u';
const SUGGEST_PHONE = '/^\+?1?[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}$/';
const SUGGEST_URL = '/^(?:https?:\/\/)?(?:www\.)?([\w-]+(?:\.[\w-]+)+)\/?\S*$/iu';
const SUGGEST_ZIP = '/^\d{5}(?:-\d{4})?$/';
const SUGGEST_STATE = '/^[A-Z]{2}$/';
const SUGGEST_PERSON = "/^[A-Z][a-z'’.-]+(?:\\s+[A-Z][a-z'’.-]*\\.?)?(?:\\s+[A-Z][a-z'’-]+){1,2}$/u";
const SUGGEST_NOT_A_PERSON = '/\b(?:manager|director|senior|junior|lead|chief|head|marketing|technology|design|designer|research|'
    . 'productions?|services|solutions|group|partners|media|studio|agency|company|corp|inc|llc|ltd|'
    . 'university|college|school|high|elementary|middle|academy|church|hall|county|city|hospital|center|bank|market|markets|foods?|web|digital|operations|'
    . 'president|officer|engineer|developer|specialist|coordinator|assistant|associate|analyst|ceo|cfo|cto)\b/i';
const DETAIL_KEYS = ['street', 'city', 'state', 'zip', 'phone', 'website', 'supervisor_name', 'supervisor_title', 'supervisor_email', 'supervisor_phone'];
const DETAIL_LABELS = ['street' => 'Street', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP', 'phone' => 'Phone', 'website' => 'Website',
    'supervisor_name' => 'Supervisor', 'supervisor_title' => 'Supervisor title', 'supervisor_email' => 'Supervisor email',
    'supervisor_phone' => "Supervisor's own phone"];

/** [kind => [key => true]] the user said no to; they are never suggested again. */
function dismissed_suggestions(): array
{
    $saved = read_json_file(resume_dir('dismissed-suggestions.json'));
    $out = [];
    foreach (SUGGESTION_KINDS as $kind) {
        $out[$kind] = array_fill_keys(array_map('strval', (array) ($saved[$kind] ?? [])), true);
    }
    return $out;
}

function dismiss_suggestion(string $kind, string $key): void
{
    if (!in_array($kind, SUGGESTION_KINDS, true) || $key === '') {
        throw new InvalidArgumentException('Unknown suggestion.');
    }
    $current = dismissed_suggestions();
    $current[$kind][$key] = true;
    ensure_resume_dirs();
    write_json_file(resume_dir('dismissed-suggestions.json'), array_map(function ($keys) {
        $list = array_map('strval', array_keys($keys));
        sort($list);
        return $list;
    }, $current));
}

function job_key(array $job): string
{
    return fold(($job['role'] ?? '') . '|' . ($job['company'] ?? ''));
}

function suggest_lines(string $text): array
{
    $lines = array_map(fn($l) => trim(preg_replace('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', '', $l)), preg_split('/\R/u', $text));
    return array_values(array_filter($lines, 'strlen'));
}

function company_words(?string $text): array
{
    preg_match_all('/[a-z0-9]+/u', fold((string) $text), $m);
    return array_values(array_unique(array_filter($m[0], fn($w) => strlen($w) > 1 && !in_array($w, SUGGEST_STOP_WORDS, true))));
}

function is_job_title_line(string $line): bool
{
    return $line !== '' && !preg_match(SUGGEST_NOT_A_TITLE, $line) && !preg_match(SUGGEST_DATES, $line) && !preg_match(SUGGEST_ADDRESS, $line)
        && mb_strlen($line) <= 80 && !str_ends_with($line, '.') && preg_match('/\p{L}/u', $line);
}

/** Jobs written as: title, dates, company (one or two lines), optional address, then what was done. */
function jobs_in(string $text): array
{
    $lines = suggest_lines($text);
    $anchors = [];
    foreach ($lines as $i => $line) {
        if ($i > 0 && preg_match(SUGGEST_DATES, $line) && is_job_title_line($lines[$i - 1])) {
            $anchors[] = $i;
        }
    }
    $jobs = [];
    foreach ($anchors as $n => $i) {
        $end = isset($anchors[$n + 1]) ? $anchors[$n + 1] - 1 : count($lines);
        // a job ends at the next section heading ("Education History", "References"...), not just the next job
        for ($k = $i + 1; $k < $end; $k++) {
            if (preg_match(SUGGEST_HEADINGS, $lines[$k])) {
                $end = $k;
                break;
            }
        }
        $body = array_slice($lines, $i + 1, max(0, $end - $i - 1));
        $company_lines = [];
        $rest = [];
        $broke = false;
        foreach ($body as $k => $line) {
            if (preg_match(SUGGEST_ADDRESS, $line)) {
                // an address ending in a comma carries on to the next line ("Baltimore," / "MD")
                $rest = array_slice($body, str_ends_with($line, ',') ? $k + 2 : $k + 1);
                while ($rest && (preg_match(SUGGEST_PLACE, $rest[0]) || preg_match('/^[A-Z]{2}(?:\s+\d{5})?$/', $rest[0]))) {
                    array_shift($rest); // "Timonium, MD" left over from the address
                }
                $broke = true;
                break;
            }
            if (count($company_lines) === 2 || str_ends_with($line, '.') || mb_strlen($line) > 60) {
                $rest = array_slice($body, $k);
                $broke = true;
                break;
            }
            $company_lines[] = $line;
        }
        if (!$broke) {
            $rest = [];
        }
        $company = trim(implode(' ', $company_lines));
        if ($company === '' || preg_match(SUGGEST_CONTACT, $company) || preg_match(SUGGEST_NOT_A_TITLE, $company)) {
            continue;
        }
        $description = implode(' ', array_filter($rest, fn($l) => !preg_match(SUGGEST_CONTACT, $l) && !preg_match(SUGGEST_HEADINGS, $l)));
        $jobs[] = ['role' => mb_substr($lines[$i - 1], 0, 150), 'company' => mb_substr($company, 0, 150), 'dates' => mb_substr($lines[$i], 0, 100),
            'description' => mb_substr($description, 0, 3000)];
    }
    return $jobs;
}

function job_is_known(array $job, array $history): bool
{
    $role = fold($job['role']);
    $company = company_words($job['company']);
    foreach ($history as $w) {
        if (fold($w['role'] ?? '') === $role && (!$company || array_intersect($company, company_words($w['company'] ?? '')))) {
            return true;
        }
    }
    return false;
}

/** The documents' text, one entry per document: [record, text]. */
function document_texts(): array
{
    $out = [];
    foreach (load_documents() as $record) {
        try {
            $out[] = [$record, document_text($record['id'])];
        } catch (Throwable) {
            continue;
        }
    }
    return $out;
}

/** ['jobs' => [...], 'skills' => [...]]: what the documents add, each job tagged with the document it came from. */
function document_suggestions(array $profile, ?array $texts = null): array
{
    $history = $profile['work_history'] ?? [];
    $have = array_fill_keys(array_map('fold', $profile['skills'] ?? []), true);
    $jobs = $skills = [];
    foreach ($texts ?? document_texts() as [$record, $text]) {
        foreach (jobs_in($text) as $job) {
            if (!job_is_known($job, $history) && !job_is_known($job, $jobs)) {
                $jobs[] = $job + ['source' => $record['label']];
            }
        }
        foreach (detect_skills($text) as $skill) {
            if (!isset($have[fold($skill)]) && !in_array($skill, $skills, true)) {
                $skills[] = $skill;
            }
        }
    }
    $no = dismissed_suggestions();
    $jobs = array_values(array_filter($jobs, fn($j) => !isset($no['job'][job_key($j)])));
    $skills = array_values(array_filter($skills, fn($s) => !isset($no['skill'][fold($s)])));
    foreach ($jobs as $n => $job) {
        $jobs[$n]['key'] = (string) $n;
        $jobs[$n]['dismiss'] = job_key($job);
    }
    return ['jobs' => $jobs, 'skills' => $skills];
}

// --- Contact lists: job addresses, phones, supervisors and other people (possible references) -------------------

function is_person_line(string $line): bool
{
    $line = substr_count($line, ',') === 1 ? trim(explode(',', $line)[0]) : $line; // a name may share its line with a title
    return preg_match(SUGGEST_PERSON, $line) && !preg_match(SUGGEST_NOT_A_PERSON, $line) && !preg_match(SUGGEST_HEADINGS, $line)
        && !preg_match('/\b(?:history|experience|education|skills|references|summary|objective|awards|projects)\b/i', $line);
}

function web_domain(string $text): string
{
    if (preg_match(SUGGEST_EMAIL, $text, $m) || preg_match(SUGGEST_URL, $text, $m)) {
        $domain = strtolower($m[1]);
        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
    return '';
}

/**
 * ['jobs' => [[company, street, city, state, zip, phone, website, dates, supervisor...]], 'people' => [[name, title,
 * phone, email, company, note]]] from a contact list laid out like: company, street, city, state, ZIP, phone, dates,
 * supervisor name, title, email; and further down, people as name then phone, title and email in any order.
 */
function contacts_in(string $text): array
{
    $lines = suggest_lines($text);
    $used = [];
    $jobs = [];
    foreach ($lines as $i => $line) {
        if (!preg_match(SUGGEST_DATES, $line)) {
            continue;
        }
        $job = ['dates' => $line];
        $k = $i - 1;
        foreach (['phone' => SUGGEST_PHONE, 'zip' => SUGGEST_ZIP, 'state' => SUGGEST_STATE] as $key => $pattern) {
            if ($k >= 0 && preg_match($pattern, $lines[$k])) {
                $job[$key] = $lines[$k];
                $used[$k] = true;
                $k--;
            }
        }
        if ($k >= 0 && isset($job['zip']) && !preg_match(SUGGEST_ADDRESS, $lines[$k]) && web_domain($lines[$k]) === '') {
            $job['city'] = $lines[$k];
            $used[$k--] = true;
        }
        if ($k >= 0 && preg_match(SUGGEST_ADDRESS, $lines[$k])) {
            $job['street'] = $lines[$k];
            $used[$k--] = true;
        }
        if ($k >= 0 && web_domain($lines[$k]) === '' && !preg_match(SUGGEST_EMAIL, $lines[$k]) && !preg_match(SUGGEST_DATES, $lines[$k])) {
            $job['company'] = $lines[$k];
            $used[$k--] = true;
        }
        if ($k >= 0 && preg_match(SUGGEST_URL, $lines[$k]) && str_contains($lines[$k], '.')) {
            $job['website'] = $lines[$k];
            $used[$k] = true;
        }
        if (empty($job['company']) || (empty($job['street']) && empty($job['phone']))) {
            continue;
        }
        $n = $i + 1;
        if ($n < count($lines) && is_person_line($lines[$n])) {
            $job['supervisor_name'] = $lines[$n];
            $used[$n++] = true;
            if ($n < count($lines) && !preg_match(SUGGEST_EMAIL, $lines[$n]) && !preg_match(SUGGEST_PHONE, $lines[$n]) && !preg_match(SUGGEST_DATES, $lines[$n])) {
                $job['supervisor_title'] = $lines[$n];
                $used[$n++] = true;
            }
            if ($n < count($lines) && preg_match(SUGGEST_EMAIL, $lines[$n])) {
                $job['supervisor_email'] = $lines[$n];
                $used[$n] = true;
            }
        }
        $used[$i] = true;
        $jobs[] = $job;
    }
    $people = [];
    foreach ($jobs as $job) {
        if (!empty($job['supervisor_name'])) {
            $people[] = ['name' => $job['supervisor_name'], 'title' => $job['supervisor_title'] ?? '', 'email' => $job['supervisor_email'] ?? '',
                'phone' => '', 'company' => $job['company'], 'note' => ''];
        }
    }
    for ($i = 0; $i < count($lines);) {
        if (isset($used[$i]) || !is_person_line($lines[$i])) {
            $i++;
            continue;
        }
        [$name, $title] = array_pad(explode(',', $lines[$i], 2), 2, ''); // "Kimberly Russell, Office Clerk"
        $person = ['name' => trim($name), 'title' => trim($title), 'phone' => '', 'email' => '', 'company' => '', 'note' => ''];
        $n = $i + 1;
        while ($n < count($lines) && !isset($used[$n]) && !is_person_line($lines[$n]) && !preg_match(SUGGEST_DATES, $lines[$n])
            && !preg_match(SUGGEST_HEADINGS, $lines[$n])) {
            $line = $lines[$n];
            if (preg_match(SUGGEST_EMAIL, $line)) {
                $person['email'] = $person['email'] ?: $line;
            } elseif (preg_match(SUGGEST_PHONE, $line)) {
                $person['phone'] = $person['phone'] ?: $line;
            } elseif (mb_strlen($line) > 80 || str_ends_with($line, '.')) {
                $person['note'] = trim("{$person['note']} $line"); // "Supervisor reference known for 3 year(s)."
            } elseif ($person['title'] === '') {
                $person['title'] = $line;
            } elseif ($person['company'] === '') {
                $person['company'] = $line;
            }
            $n++;
        }
        if ($person['email'] !== '' || $person['phone'] !== '') {
            $people[] = $person;
        }
        $i = $n;
    }
    // one entry per person, details merged; a missing company comes from their email's domain
    $sites = [];
    foreach ($jobs as $job) {
        foreach (['website', 'supervisor_email'] as $key) {
            if (web_domain($job[$key] ?? '') !== '') {
                $sites[web_domain($job[$key])] = $job['company'];
            }
        }
    }
    $merged = [];
    foreach ($people as $person) {
        $key = fold($person['name']);
        $merged[$key] ??= ['name' => $person['name'], 'title' => '', 'phone' => '', 'email' => '', 'company' => '', 'note' => ''];
        foreach ($person as $field => $value) {
            $merged[$key][$field] = $merged[$key][$field] !== '' ? $merged[$key][$field] : $value;
        }
    }
    foreach ($merged as $key => $entry) {
        $merged[$key]['company'] = $entry['company'] !== '' ? $entry['company'] : ($sites[web_domain($entry['email'])] ?? '');
    }
    foreach ($jobs as $n => $job) {
        $person = $merged[fold($job['supervisor_name'] ?? '')] ?? null;
        if ($person && $person['phone'] !== '' && $person['phone'] !== ($job['phone'] ?? null)) {
            $jobs[$n]['supervisor_phone'] = $person['phone'];
        }
    }
    return ['jobs' => $jobs, 'people' => array_values($merged)];
}

function same_company(string $a, string $b): bool
{
    $first = company_words($a);
    $second = company_words($b);
    if (!$first || !$second) {
        return false;
    }
    $shared = array_intersect($first, $second);
    return count($shared) === count($first) || count($shared) === count($second) || count($shared) >= 2;
}

/** For jobs already in the work history: details a contact list has that the job is still missing. */
function detail_suggestions(array $profile, ?array $texts = null): array
{
    $found = [];
    $no = dismissed_suggestions()['detail'];
    foreach ($texts ?? document_texts() as [$record, $text]) {
        foreach (contacts_in($text)['jobs'] as $contact) {
            foreach ($profile['work_history'] ?? [] as $index => $job) {
                if (!same_company($contact['company'], $job['company'] ?? '') || isset($no[job_key($job)])) {
                    continue;
                }
                $missing = [];
                foreach (DETAIL_KEYS as $key) {
                    if (!empty($contact[$key]) && empty($job[$key])) {
                        $missing[$key] = $contact[$key];
                    }
                }
                if ($missing && !array_filter($found, fn($f) => $f['index'] === $index)) {
                    $found[] = ['index' => $index, 'role' => $job['role'] ?? '', 'company' => $job['company'] ?? '', 'details' => $missing,
                        'source' => $record['label'], 'dismiss' => job_key($job),
                        'supervisor' => ($job['supervisor_name'] ?? '') ?: ($contact['supervisor_name'] ?? '')];
                }
            }
        }
    }
    return $found;
}

function job_label(array $job): string
{
    return implode(' at ', array_filter([$job['role'] ?? '', $job['company'] ?? ''], 'strlen'));
}

/** The index of the user's job this person goes with: their "your job together", else their company; -1 if none. */
function job_index_for(array $person, array $profile): int
{
    $together = fold($person['user_job'] ?? '');
    foreach ($profile['work_history'] ?? [] as $index => $job) {
        if ($together !== '' && $together === fold(job_label($job))) {
            return $index;
        }
    }
    foreach ($profile['work_history'] ?? [] as $index => $job) {
        if (same_company($person['company'] ?? '', $job['company'] ?? '')) {
            return $index;
        }
    }
    return -1;
}

/** People named in the documents who aren't saved as references yet, linked to the user's job with them. */
function reference_suggestions(array $profile, array $saved_references, ?array $texts = null): array
{
    $saved = array_fill_keys(array_map(fn($r) => fold($r['name'] ?? ''), $saved_references), true);
    $supervising = [];
    foreach ($profile['work_history'] ?? [] as $job) {
        $name = fold($job['supervisor_name'] ?? '');
        if ($name !== '') {
            $supervising[$name][] = job_label($job);
        }
    }
    // Off the list once ignored, or once they are both a supervisor and a reference.
    $have = dismissed_suggestions()['reference'];
    foreach (array_keys($saved) as $name) {
        if (isset($supervising[$name])) {
            $have[$name] = true;
        }
    }
    $have[fold(trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')))] = true; // never the user
    $found = [];
    foreach ($texts ?? document_texts() as [$record, $text]) {
        $contacts = contacts_in($text);
        $supervisors = array_fill_keys(array_map(fn($j) => fold($j['supervisor_name'] ?? ''), $contacts['jobs']), true);
        $companies = array_filter(array_map(fn($j) => $j['company'] ?? '', array_merge(jobs_in($text), $contacts['jobs'])), 'strlen');
        foreach ($contacts['people'] as $person) {
            foreach ($companies as $company) {
                if (same_company($person['name'], $company)) {
                    continue 2; // a company name that wrapped onto its own line, not a person
                }
            }
            $key = fold($person['name']);
            if (isset($have[$key]) || array_filter($found, fn($f) => fold($f['name']) === $key)) {
                continue;
            }
            $job = null;
            foreach ($profile['work_history'] ?? [] as $w) {
                if (same_company($person['company'], $w['company'] ?? '')) {
                    $job = $w;
                    break;
                }
            }
            $found[] = array_merge($person, [
                'relationship' => isset($supervisors[$key]) || preg_match('/\bsupervisor\b/i', $person['note']) ? 'Supervisor' : '',
                'user_job' => $job ? job_label($job) : '',
                'company' => ($job['company'] ?? '') ?: $person['company'], 'source' => $record['label']]);
        }
    }
    foreach ($found as $n => $person) {
        $found[$n] += ['key' => (string) $n, 'dismiss' => fold($person['name']), 'job_index' => job_index_for($person, $profile),
            'supervisor_on' => $supervising[fold($person['name'])] ?? [], 'is_reference' => isset($saved[fold($person['name'])])];
    }
    return $found;
}

/** A sortable [year, month] from a job's dates, so added jobs land in order; undated jobs go last. */
function job_start(array $job): array
{
    $dates = (string) ($job['dates'] ?? '');
    if (!preg_match('/(?:(\d{1,2})\/)?((?:19|20)\d{2})/', $dates, $m)) {
        return [0, 0];
    }
    $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    $named = array_search(strtolower(substr(trim($dates), 0, 3)), $months, true);
    return [(int) $m[2], (int) ($m[1] ?? 0) ?: ($named === false ? 0 : $named + 1)];
}

/** Saves a new work history and extra skills onto the stored profile (everything else is kept). */
function save_work_history(array $profile, array $history, array $extra_skills = []): void
{
    $profile['work_history'] = $history;
    $profile['skills'] = array_merge($profile['skills'], $extra_skills);
    if (!save_user_profile($profile)) {
        throw new RuntimeException('Your profile could not be saved. Try again.');
    }
}

/** Jobs the uploaded résumé lists that the profile's work history doesn't have yet (matched on title and company). */
function resume_new_jobs(string $text, array $profile): array
{
    $known = [];
    foreach ($profile['work_history'] as $w) {
        $known[fold($w['role']) . '|' . fold($w['company'])] = true;
    }
    return array_values(array_filter(resume_suggestions($text)['work_history'],
        fn($j) => !isset($known[fold($j['role'] ?? '') . '|' . fold($j['company'] ?? '')])));
}
