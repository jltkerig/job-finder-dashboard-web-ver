<?php
// Schools listed under a résumé's education heading, to pre-fill the Dashboard's Education section. Ported from the
// desktop app's education_parse.py. Anything it cannot place is left blank rather than guessed.

declare(strict_types=1);

function education_regex(string $name)
{
    static $patterns = null;
    if ($patterns === null) {
        $end = '(?=[\s,.:;()]|$)';
        $patterns = [
            'heading' => '/^(?:education(?:al)?(?:\s+(?:history|background|and\s+training|&\s+training|summary))?|academic\s+(?:history|background)|schooling)\s*:?$/i',
            'more' => '/^(?:honou?rs(?:\s*(?:&|and)\s*awards)?|activities|languages|publications|training|affiliations|memberships|leadership|achievements|accomplishments|community|hobbies|portfolio|contact|links|tools|software|expertise|highlights)\s*:?$/i',
            'other' => '/^(?:education(?:\s+history)?|skills|technical skills|core competencies|certifications?|additional experience|volunteer(?:ing)?(?: experience)?|projects|awards|references|professional summary|summary|objective|interests)$/i',
            'experience' => '/^(?:(?:work|employment|professional|relevant|career)\s+)?(?:history|experience)$|^(?:work|employment|professional)\s+(?:history|experience)$/i',
            'school' => '/\b(?:university|college|institute|school|academy|polytechnic|seminary|conservatory)\b/i',
            'place' => "/^(?:remote|hybrid|on[- ]?site|[A-Za-z .'-]+,\\s*[A-Z]{2})$/i",
            'gpa' => '/\bGPA\b\s*[:\-]?\s*([0-4](?:\.\d{1,2})?)/i',
            'minor' => '/^minor(?:\s+in|\s*:)?\s+(.+)$/i',
            'major_split' => '/\s*(?:,|\s[-–—]\s|:|\s+in\s+)\s*/iu',
            'degree_word' => '/\b(?:diploma|ged|certificate)\b/i',
        ];
        // The first match wins, so the narrower degrees come before the broader ones. Abbreviations are case-sensitive.
        $patterns['degrees'] = [
            ['Professional Degree', '/\bjuris doctor\b|\bdoctor of (?:medicine|dental|pharmacy|law)\b/i', true],
            ['Professional Degree', '/\b(?:J\.?D|M\.?D|D\.?D\.?S|Pharm\.?D)\.?' . $end . '/', false],
            ['Doctorate', '/\bdoctor(?:ate|al)?\b|\bph\.?\s?d\b|\bEd\.?D\b/i', true],
            ["Master's Degree", "/\\bmaster(?:'s|’s|s)?\\b/iu", true],
            ["Master's Degree", '/\b(?:M\.?S|M\.?A|M\.?B\.?A|M\.?F\.?A|M\.?Ed|M\.?Eng)\.?' . $end . '/', false],
            ["Bachelor's Degree", "/\\bbachelor(?:'s|’s|s)?\\b/iu", true],
            ["Bachelor's Degree", '/\b(?:B\.?S|B\.?A|B\.?F\.?A|B\.?Sc|B\.?Eng)\.?' . $end . '/', false],
            ["Associate's Degree", "/\\bassociate(?:'s|’s|s)?\\b/iu", true],
            ["Associate's Degree", '/\b(?:A\.?A\.?S|A\.?A|A\.?S)\.?' . $end . '/', false],
            ['High School Diploma', '/\bhigh school\b/i', true],
            ['GED', '/\bG\.?E\.?D\b/', false],
            ['Certificate', '/\bcertificate\b|\bdiploma\b/i', true],
        ];
    }
    return $patterns[$name];
}

/** [degree label, matched text] for the degree a line starts with, or null; only the start of the line counts. */
function degree_of(string $line): ?array
{
    foreach (education_regex('degrees') as [$label, $pattern, $ignore_case]) {
        if (preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE) && $match[0][1] <= ($ignore_case ? 25 : 0)) {
            return [$label, $match[0][0]];
        }
    }
    return null;
}

function major_of(string $line): string
{
    $parts = preg_split(education_regex('major_split'), $line, 2);
    return count($parts) === 2 && trim($parts[1]) !== '' ? mb_substr(trim($parts[1], ' .,;'), 0, 150) : '';
}

/** YYYY-MM for each "Month YYYY" in a line, in order; years on their own are not months and are left out. */
function line_months(string $line): array
{
    $found = [];
    if (preg_match_all('/\b(' . month_pattern() . ')\.?\s+((?:19|20)\d{2})\b/i', $line, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $found[] = sprintf('%s-%02d', $m[2], MONTHS[strtolower($m[1])]);
        }
    }
    return $found;
}

/** Schools under the résumé's education heading: [{school, degree, major, minor, start_date, end_date, gpa}]. */
function parse_education(string $text, int $limit = 6): array
{
    $lines = clean_lines($text);
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match(education_regex('heading'), $line)) {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        return [];
    }
    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
        $line = $lines[$i];
        if ((preg_match(education_regex('other'), $line) && !preg_match(education_regex('heading'), $line))
            || preg_match(education_regex('experience'), $line) || preg_match(education_regex('more'), $line)) {
            $end = $i;
            break;
        }
    }
    $blank = fn(string $school = '') => ['school' => $school, 'degree' => '', 'major' => '', 'minor' => '', 'start_date' => '', 'end_date' => '', 'gpa' => ''];
    $entries = [];
    $current = null;
    $degree_first = false;  // the degree line came before its school's name, so a new degree is not at the same school
    foreach (array_slice($lines, $start + 1, $end - $start - 1) as $line) {
        if ($line === '') {
            continue;
        }
        $has_gpa = preg_match(education_regex('gpa'), $line, $gpa) === 1;
        if ($has_gpa && $current !== null) {
            $current['gpa'] = $gpa[1];
        }
        if (preg_match(education_regex('minor'), $line, $minor) && $current !== null) {
            $current['minor'] = mb_substr(trim($minor[1], ' .,;'), 0, 150);
            continue;
        }
        $degree = degree_of($line);
        $first_part = explode(',', $line)[0];
        // "Perryville High School" is a school; "High School Diploma" is a degree.
        if ($degree && (!preg_match(education_regex('school'), $first_part) || preg_match(education_regex('degree_word'), $line))) {
            if ($current === null || $current['degree'] !== '') {
                $school = $current && !$degree_first ? $current['school'] : '';
                if ($current) {
                    $entries[] = $current;
                }
                $current = $blank($school);
            }
            $current['degree'] = $degree[0];
            $major = major_of($line);
            if (preg_match(education_regex('school'), $major)) {  // "Master of Education, Springfield University"
                if ($current['school'] === '') {
                    $current['school'] = mb_substr($major, 0, 200);
                    $degree_first = true;
                }
                $major = '';
            }
            $current['major'] = $major;
            $months = line_months($line);
            if ($months) {
                $current['end_date'] = end($months);
            }
            continue;
        }
        $months = line_months($line);
        if ($months && !preg_match(education_regex('school'), $line)) {
            if ($current !== null) {
                if (count($months) > 1) {
                    $current['start_date'] = $months[0];
                }
                $current['end_date'] = end($months);
            }
            continue;
        }
        if (preg_match(education_regex('place'), $line) || preg_match('/^(?:19|20)\d{2}$/', $line) || $has_gpa) {
            continue;
        }
        if (preg_match(education_regex('school'), $line) || ($current === null && mb_strlen($line) <= 90 && !str_ends_with($line, '.'))) {
            if ($current !== null && $current['school'] === '') {
                $degree_first = $degree_first || $current['degree'] !== '';
                $current['school'] = mb_substr($line, 0, 200);
                continue;
            }
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = $blank(mb_substr($line, 0, 200));
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }
    return array_slice(array_values(array_filter($entries, fn($e) => $e['school'] !== '')), 0, $limit);
}

/** Saves the schools read from the résumé when no education is saved yet, so every page lists them. Saved education is
 * never replaced. Returns the saved schools, or [] when nothing was saved. */
function save_schools_if_none(array $schools): array
{
    $rows = clean_education($schools);
    if (!$rows || (int) value('SELECT COUNT(*) FROM user_profile_education WHERE profile_id = 1') > 0) {
        return [];
    }
    $columns = array_keys(EDUCATION_FIELDS);
    foreach ($rows as $item) {
        q('INSERT INTO user_profile_education (profile_id, ' . implode(', ', $columns) . ') VALUES (1, '
            . implode(', ', array_fill(0, count($columns), '?')) . ')', array_values($item));
    }
    return $rows;
}
