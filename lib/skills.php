<?php
// Skills: recognizing them in a listing or résumé, Job Fit, the skills jobs ask for, and reading a résumé's work
// history. Ported from the desktop app's profile_tools.py; conservative and explainable on purpose.

declare(strict_types=1);

require_once __DIR__ . '/skill_data.php';

const TECH_AFTER = '/^\s*(?:[,;\/|)•·]|\.(?:\s|$)|\s+(?:and|or|&)\s|\s+(?:Native|Developer|Engineer|framework|library|JS|Hooks|Router|CSS)\b|$)/u';
const TECH_BEFORE = '/(?:experience (?:with|in)|proficien\w+ (?:in|with)|knowledge of|familiar\w* with|expertise in|skills?:|using|such as|including|e\.g\.,?)\s*(?:[\w.+#-]+[,\/]\s*)*$/iu';

/** An everyday-word skill counts only when capitalized and used like a tool (a list, "experience with"). */
function named_as_skill(string $text, string $skill): bool
{
    if (!preg_match_all('/(?<![\w])' . preg_quote($skill, '/') . '(?![\w])/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    foreach ($matches[0] as [$found, $offset]) {
        $end = $offset + strlen($found);
        $after = substr($text, $end, 40);
        $before = substr($text, max(0, $offset - 60), min(60, $offset));
        if (preg_match(TECH_AFTER, $after) || preg_match(TECH_BEFORE, $before)) {
            return true;
        }
    }
    return false;
}

function detect_skills(string $text): array
{
    $text = substr($text, 0, 250000);
    $found = [];
    foreach (SKILL_ALIASES as $skill => $aliases) {
        foreach ($aliases as $alias) {
            if (in_array($skill, AMBIGUOUS_SKILLS, true) && $alias === mb_strtolower($skill)) {
                $matched = named_as_skill($text, $skill);
            } else {
                $matched = preg_match('/(?<![\w])' . preg_quote($alias, '/') . '(?![\w])/iu', $text) === 1;
            }
            if ($matched) {
                $found[] = $skill;
                break;
            }
        }
    }
    return $found;
}

/** Skill names as typed, each written the standard way ("html5" -> "HTML"), without repeats; at most 100. */
function normalize_skills(array $values): array
{
    static $by_alias = null;
    if ($by_alias === null) {
        $by_alias = [];
        foreach (SKILL_ALIASES as $name => $aliases) {
            foreach (array_merge([$name], $aliases) as $alias) {
                $by_alias[mb_strtolower($alias)] = $name;
            }
        }
    }
    $result = [];
    $seen = [];
    foreach ($values as $value) {
        $raw = mb_substr(trim((string) $value), 0, 80);
        if ($raw === '' || !preg_match('/[a-zA-Z]/', $raw)) {
            continue;
        }
        $canonical = $by_alias[mb_strtolower($raw)] ?? $raw;
        if (!isset($seen[mb_strtolower($canonical)])) {
            $seen[mb_strtolower($canonical)] = true;
            $result[] = $canonical;
        }
    }
    return array_slice($result, 0, 100);
}

/** [[skill, how many listings name it]] for skills the profile lacks, most asked-for first. */
function skill_demand(array $skill_lists, array $saved_skills, int $limit = 12): array
{
    $saved = array_flip(array_map('mb_strtolower', $saved_skills));
    $counts = [];
    foreach ($skill_lists as $raw) {
        $skills = is_string($raw) ? json_decode($raw, true) : $raw;
        foreach (normalize_skills(is_array($skills) ? $skills : []) as $skill) {
            if (!isset($saved[mb_strtolower($skill)])) {
                $counts[$skill] = ($counts[$skill] ?? 0) + 1;
            }
        }
    }
    uksort($counts, fn($a, $b) => [-$counts[$a], mb_strtolower((string) $a)] <=> [-$counts[$b], mb_strtolower((string) $b)]);
    $ranked = [];
    foreach (array_slice($counts, 0, $limit, true) as $skill => $count) {
        $ranked[] = [(string) $skill, $count];
    }
    return $ranked;
}

/** Visible text of a page (scripts, styles, menus, headers and footers left out). */
function page_text(?string $html): string
{
    $html = (string) $html;
    $html = preg_replace('#<(script|style|nav|footer|header|noscript|template)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
    $html = preg_replace('/<(br|p|div|li|h[1-6]|tr|td|th|section|article)\b[^>]*>/i', ' $0', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function listing_skills(?string $html): array
{
    return detect_skills(page_text($html));
}

function fit_score(array $user_skills, array $job_skills): array
{
    $user = array_flip(array_map('mb_strtolower', $user_skills));
    $job = normalize_skills($job_skills);
    if (!$user) {
        return ['score' => null, 'reason' => 'Add your skills on the Dashboard to see Job Fit.', 'matched' => [], 'missing' => []];
    }
    if (!$job) {
        return ['score' => null, 'reason' => 'The listing has too little skill information to calculate Job Fit.',
            'matched' => [], 'missing' => []];
    }
    $matched = array_values(array_filter($job, fn($s) => isset($user[mb_strtolower($s)])));
    $missing = array_values(array_filter($job, fn($s) => !isset($user[mb_strtolower($s)])));
    return ['score' => (int) round(100 * count($matched) / count($job)),
        'reason' => 'Skills found on the listing page; review the job requirements before applying.',
        'matched' => $matched, 'missing' => $missing];
}

/** Skills that go with the one being typed: the curated list first, then those named alongside it in the jobs found. */
function related_skills(string $term, array $listing_skill_lists = [], int $limit = 8): array
{
    $names = normalize_skills([$term]);
    if (!$names) {
        return [];
    }
    $key = mb_strtolower($names[0]);
    $result = [];
    foreach (RELATED_SKILLS as $known => $skills) {
        if (mb_strtolower((string) $known) === $key) {
            $result = array_merge($result, $skills);
        }
    }
    $counts = [];
    foreach ($listing_skill_lists as $raw) {
        $skills = normalize_skills((array) (is_string($raw) ? json_decode($raw, true) : $raw));
        if (in_array($key, array_map('mb_strtolower', $skills), true)) {
            foreach ($skills as $other) {
                if (mb_strtolower($other) !== $key) {
                    $counts[$other] = ($counts[$other] ?? 0) + 1;
                }
            }
        }
    }
    uksort($counts, fn($a, $b) => [-$counts[$a], mb_strtolower((string) $a)] <=> [-$counts[$b], mb_strtolower((string) $b)]);
    $result = array_merge($result, array_map('strval', array_keys($counts)));
    $out = [];
    $seen = [];
    foreach ($result as $skill) {
        $lower = mb_strtolower($skill);
        if (!isset($seen[$lower]) && $lower !== $key) {
            $seen[$lower] = true;
            $out[] = $skill;
        }
    }
    return array_slice($out, 0, $limit);
}

// --- Reading a résumé ----------------------------------------------------------------------------------------

const MONTHS = ['jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
    'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9,
    'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12];

function month_pattern(): string
{
    $names = array_keys(MONTHS);
    usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', $names);
}

/** "January 2025 - May 2025" -> "2025-01 – 2025-05" (the form's month format); years only stay as years. */
function month_dates(string $line): ?string
{
    $m = month_pattern();
    $pattern = "/^(?:(?P<m1>$m)\.?\s+)?(?P<y1>(?:19|20)\d{2})\s*[-–—]\s*(?:(?P<m2>$m)\.?\s+(?P<y2>(?:19|20)\d{2})|(?P<y3>(?:19|20)\d{2})|(?P<now>present|current|now))$/iu";
    if (!preg_match($pattern, trim($line), $match)) {
        return null;
    }
    $start_month = MONTHS[strtolower($match['m1'] ?? '')] ?? null;
    $start = $start_month ? sprintf('%s-%02d', $match['y1'], $start_month) : $match['y1'];
    if (!empty($match['now'])) {
        $end = 'Present';
    } elseif (!empty($match['y2'])) {
        $end = sprintf('%s-%02d', $match['y2'], MONTHS[strtolower($match['m2'])]);
    } else {
        $end = $match['y3'];
    }
    return "$start – $end";
}

function clean_lines(string $text): array
{
    $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $text);
    $text = str_replace("\u{00A0}", ' ', $text);
    return array_map(fn($line) => trim(preg_replace('/\s+/u', ' ', $line)), preg_split('/\r\n|\r|\n/', $text));
}

/** Jobs listed under the résumé's experience heading: [{role, company, dates, description}] (suggestions to check). */
function parse_work_history(string $text, int $limit = 12): array
{
    $experience = '/^(?:(?:work|employment|professional|relevant|career)\s+)?(?:history|experience)$|^(?:work|employment|professional)\s+(?:history|experience)$/i';
    $other = '/^(?:education(?:\s+history)?|skills|technical skills|core competencies|certifications?|additional experience|volunteer(?:ing)?(?: experience)?|projects|awards|references|professional summary|summary|objective|interests)$/i';
    $place = '/^(?:remote|hybrid|on[- ]?site|[A-Za-z .\'-]+,\s*[A-Z]{2})$/i';
    $bullet = '/^[●•▪■◦‣\-*]$/u';
    $lines = clean_lines($text);
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match($experience, $line)) {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        return [];
    }
    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (preg_match($other, $lines[$i])) {
            $end = $i;
            break;
        }
    }
    $section = array_slice($lines, $start + 1, $end - $start - 1);
    $dated = [];
    foreach ($section as $i => $line) {
        $dates = month_dates($line);
        if ($dates) {
            $dated[] = [$i, $dates];
        }
    }
    $header_start = function (int $at, int $floor) use ($section, $bullet): int {
        $top = $at;
        $found = 0;
        for ($index = $at - 1; $index > $floor; $index--) {
            $line = $section[$index];
            if ($line === '') {
                continue;
            }
            if (preg_match($bullet, $line) || str_ends_with($line, '.') || mb_strlen($line) > 70) {
                break;
            }
            $top = $index;
            if (++$found === 3) {
                break;
            }
        }
        return $top;
    };
    $starts = [];
    $floor = -1;
    foreach ($dated as [$at]) {
        $starts[] = $header_start($at, $floor);
        $floor = $at;
    }
    $entries = [];
    foreach ($dated as $n => [$at, $dates]) {
        $names = array_values(array_filter(array_slice($section, $starts[$n], $at - $starts[$n]),
            fn($line) => $line !== '' && !preg_match($place, $line)));
        if (!$names) {
            continue;
        }
        $stop = $starts[$n + 1] ?? count($section);
        $bullets = [];
        $current = '';
        foreach (array_slice($section, $at + 1, $stop - $at - 1) as $line) {
            if (preg_match($bullet, $line)) {
                if ($current !== '') {
                    $bullets[] = $current;
                }
                $current = '';
            } elseif ($line !== '') {
                $current = trim("$current $line");
            }
        }
        if ($current !== '') {
            $bullets[] = $current;
        }
        $entries[] = ['role' => mb_substr($names[0], 0, 150), 'company' => mb_substr($names[1] ?? '', 0, 150),
            'dates' => $dates, 'description' => mb_substr(implode("\n", array_map(fn($b) => "• $b", $bullets)), 0, 3000)];
        if (count($entries) >= $limit) {
            break;
        }
    }
    return $entries;
}

function resume_suggestions(string $text): array
{
    $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/\s+/u', ' ', $l)), preg_split('/\r\n|\r|\n/', $text)), 'strlen'));
    $name = '';
    foreach (array_slice($lines, 0, 12) as $line) {
        $words = count(explode(' ', $line));
        if (preg_match("/^[A-Za-z][A-Za-z' .-]{2,79}$/", $line) && $words >= 2 && $words <= 4
            && !preg_match('/resume|curriculum|profile|experience/i', $line)) {
            $name = $line;
            break;
        }
    }
    $location = '';
    foreach (array_slice($lines, 0, 35) as $line) {
        if (preg_match("/\b[A-Za-z][A-Za-z .'-]{1,45},\s*(?:[A-Z]{2}|Maryland|Delaware|Virginia|Pennsylvania|New York|California)\b/", $line, $m)) {
            $location = $m[0];
            break;
        }
    }
    $history = parse_work_history($text);
    if (!$history) {
        $month = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        $date = "/\b(?:$month\s+)?(?:19|20)\d{2}\s*[-–—]\s*(?:$month\s+)?(?:present|current|(?:19|20)\d{2})\b/iu";
        foreach ($lines as $i => $line) {
            if (preg_match($date, $line)) {
                $before = array_values(array_filter(array_slice($lines, max(0, $i - 4), $i - max(0, $i - 4)),
                    fn($s) => mb_strlen($s) < 110 && !preg_match("/^(?:remote|hybrid|on[- ]?site|[A-Za-z .'-]+,\s*[A-Z]{2})$/i", $s)));
                if (count($before) >= 2) {
                    $history[] = ['role' => $before[count($before) - 2], 'company' => $before[count($before) - 1],
                        'dates' => mb_substr($line, 0, 100)];
                }
            }
            if (count($history) >= 12) {
                break;
            }
        }
    }
    $parts = $name === '' ? [] : explode(' ', $name);
    return ['first_name' => $parts[0] ?? '', 'last_name' => implode(' ', array_slice($parts, 1)),
        'home_location' => $location, 'skills' => detect_skills($text), 'work_history' => $history];
}
