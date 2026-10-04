<?php
// Occupation and software skill suggestions from the bundled O*NET 31.0 files (resources/onet-31.0), read on this
// server; nothing is looked up online. Ported from the desktop app's onet_data.py.

declare(strict_types=1);

const SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];
const KEEP_UPPER = ['ux', 'ui', 'qa', 'it', 'seo', 'sem', 'cms', 'html', 'css', 'php', 'sql', 'api', 'hr', 'crm', 'erp', 'pr',
    'ai', 'ml', 'vp', 'ceo', 'cfo', 'cto', 'coo', 'aws', 'ii', 'iii', 'iv', '3d', '2d', 'ar', 'vr', 'b2b', 'b2c', 'ehr', 'emr',
    'cad', 'gis', 'rn', 'lpn', 'cdl', 'hvac', 'usa', 'us', 'uk', 'ios', 'net', 'sap', 'etl', 'bi', 'pc', 'av', 'tv'];
const SPECIAL_WORDS = ['wordpress' => 'WordPress', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript',
    'linkedin' => 'LinkedIn', 'devops' => 'DevOps', 'powerpoint' => 'PowerPoint', 'photoshop' => 'Photoshop', 'ios' => 'iOS',
    'macos' => 'macOS', 'youtube' => 'YouTube', 'salesforce' => 'Salesforce', 'github' => 'GitHub', 'nodejs' => 'Node.js'];

function fold(string $text): string
{
    return mb_strtolower($text, 'UTF-8');
}

/** "Web-Designer!!" -> "web designer": the form used to compare titles. */
function title_key(string $text): string
{
    return trim(preg_replace('/[^a-z0-9]+/', ' ', fold($text)));
}

function proper_word(string $word): string
{
    $lower = fold($word);
    if (isset(SPECIAL_WORDS[$lower])) {
        return SPECIAL_WORDS[$lower];
    }
    if (in_array($lower, KEEP_UPPER, true)) {
        return $lower === 'ios' ? 'iOS' : strtoupper($lower);
    }
    $rest = mb_substr($word, 1);
    if (preg_match('/\p{Lu}/u', $rest) && preg_match('/\p{Ll}/u', $word)) {
        return $word; // already written in its own style (WordPress, eBay)
    }
    return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
}

/** A job title in proper capitalization: every word capitalized except small joining words, acronyms kept in capitals. */
function proper_title(?string $text): string
{
    $words = explode(' ', trim(preg_replace('/\s+/u', ' ', (string) $text)));
    $out = [];
    $count = count($words);
    foreach ($words as $n => $word) {
        if ($word === '') {
            continue;
        }
        $parts = preg_split('/([\/\-])/', $word, -1, PREG_SPLIT_DELIM_CAPTURE);
        $done = '';
        foreach ($parts as $part) {
            if ($part === '/' || $part === '-' || $part === '') {
                $done .= $part;
            } elseif (in_array(fold($part), SMALL_WORDS, true) && $n > 0 && $n < $count - 1 && count($parts) === 1) {
                $done .= fold($part);
            } else {
                $done .= proper_word($part);
            }
        }
        $out[] = $done;
    }
    return implode(' ', $out);
}

/** Reads a tab-separated O*NET file as rows of [column => value]. */
function onet_rows(string $file): Generator
{
    $handle = fopen(DATA_DIR . "/onet-31.0/$file", 'r');
    $header = null;
    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($header === null) {
            $header = explode("\t", preg_replace('/^\xEF\xBB\xBF/', '', $line));
            continue;
        }
        $values = explode("\t", $line);
        if (count($values) === count($header)) {
            yield array_combine($header, $values);
        }
    }
    fclose($handle);
}

/** Builds a data file once and keeps it as a PHP array in data/cache, which loads in a few milliseconds. */
function cached_data(string $name, callable $build)
{
    static $loaded = [];
    if (isset($loaded[$name])) {
        return $loaded[$name];
    }
    $file = CACHE_DIR . "/$name-" . APP_VERSION . '.php';
    if (is_file($file)) {
        return $loaded[$name] = require $file;
    }
    $data = $build();
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $temp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temp, '<?php return ' . var_export($data, true) . ';') !== false) {
        @rename($temp, $file);
    }
    return $loaded[$name] = $data;
}

/** [occupations code => title, titles key => [codes], by_occupation code => [keys], software code => [name => priority]] */
function onet_catalog(): array
{
    return cached_data('onet-catalog', function () {
        $occupations = [];
        foreach (onet_rows('Occupation Data.txt') as $row) {
            $occupations[$row['O*NET-SOC Code']] = $row['Title'];
        }
        $titles = [];
        foreach (onet_rows('Job Titles.txt') as $row) {
            $titles[title_key($row['Job Title'])][$row['O*NET-SOC Code']] = true;
        }
        foreach ($occupations as $code => $title) {
            $titles[title_key($title)][$code] = true;
        }
        $by_occupation = [];
        foreach ($titles as $name => $codes) {
            foreach ($codes as $code => $_) {
                $by_occupation[$code][] = (string) $name;
            }
            $titles[$name] = array_keys($codes);
        }
        $software = [];
        foreach (onet_rows('Software Skills.txt') as $row) {
            $name = trim($row['Workplace Example']);
            if ($name !== '' && mb_strlen($name) <= 80) {
                $code = $row['O*NET-SOC Code'];
                $priority = ($row['In Demand'] === 'Y') * 2 + ($row['Hot Technology'] === 'Y');
                $software[$code][$name] = max($priority, $software[$code][$name] ?? 0);
            }
        }
        return ['occupations' => $occupations, 'titles' => $titles, 'by_occupation' => $by_occupation, 'software' => $software];
    });
}

function occupation_codes(string $title): array
{
    $catalog = onet_catalog();
    $key = title_key($title);
    if ($key === '') {
        return [];
    }
    $exact = $catalog['titles'][$key] ?? null;
    if ($exact) {
        usort($exact, fn($a, $b) => [fold($catalog['occupations'][$a] ?? '') !== $key, $a]
            <=> [fold($catalog['occupations'][$b] ?? '') !== $key, $b]);
        return array_slice($exact, 0, 3);
    }
    // Only a close title match should introduce occupational skills.
    $candidates = [];
    foreach ($catalog['titles'] as $name => $codes) {
        if (str_starts_with((string) $name, "$key ")) {
            $candidates[] = $codes;
            if (count($candidates) > 1) {
                return [];
            }
        }
    }
    if (count($candidates) === 1) {
        $codes = $candidates[0];
        sort($codes);
        return array_slice($codes, 0, 2);
    }
    return [];
}

/** Software and tools associated with the occupation (examples, never inferred job requirements). */
function occupation_skill_suggestions(string $title, int $limit = 25): array
{
    $software = onet_catalog()['software'];
    $ranked = [];
    foreach (occupation_codes($title) as $code) {
        foreach ($software[$code] ?? [] as $name => $priority) {
            $ranked[$name] = max($priority, $ranked[$name] ?? 0);
        }
    }
    uksort($ranked, fn($a, $b) => [-$ranked[$a], fold((string) $a)] <=> [-$ranked[$b], fold((string) $b)]);
    return array_slice(array_map('strval', array_keys($ranked)), 0, $limit);
}

/** Every O*NET job title once, as written, with its key; shortest first. */
function onet_title_names(): array
{
    return cached_data('onet-title-names', function () {
        $names = [];
        foreach (onet_rows('Occupation Data.txt') as $row) {
            $names[title_key($row['Title'])] ??= trim($row['Title']);
        }
        foreach (onet_rows('Job Titles.txt') as $row) {
            $names[title_key($row['Job Title'])] ??= trim($row['Job Title']);
        }
        // Occupation names are plural ("Graphic Designers"); keep only the singular when both exist.
        foreach (array_keys($names) as $key) {
            $key = (string) $key;
            if (str_ends_with($key, 's') && isset($names[substr($key, 0, -1)])) {
                unset($names[$key]);
            }
        }
        $pairs = [];
        foreach ($names as $key => $title) {
            $pairs[] = [(string) $key, $title];
        }
        usort($pairs, fn($a, $b) => [strlen($a[0]), $a[0]] <=> [strlen($b[0]), $b[0]]);
        return $pairs;
    });
}

/** Job titles for type-ahead: each typed word must start a word of the title; titles starting with it come first. */
function title_matches(string $text, int $limit = 10): array
{
    $key = title_key($text);
    if (strlen($key) < 2) {
        return [];
    }
    $typed = explode(' ', $key);
    $starts = $others = [];
    foreach (onet_title_names() as [$name, $title]) {
        $words = explode(' ', $name);
        foreach ($typed as $part) {
            $hit = false;
            foreach ($words as $word) {
                if (str_starts_with($word, $part)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue 2;
            }
        }
        if (str_starts_with($name, $key)) {
            $starts[] = $title;
            if (count($starts) >= $limit) {
                break;
            }
        } elseif (count($others) < $limit) {
            $others[] = $title;
        }
    }
    return array_map('proper_title', array_slice(array_merge($starts, $others), 0, $limit));
}

function related_title_suggestions(string $title, int $limit = 8): array
{
    $by_occupation = onet_catalog()['by_occupation'];
    $key = title_key($title);
    $candidates = [];
    foreach (occupation_codes($title) as $code) {
        foreach ($by_occupation[$code] ?? [] as $name) {
            $candidates[$name] = true;
        }
    }
    unset($candidates[$key]);
    $tokens = array_flip(explode(' ', $key));
    $names = array_map('strval', array_keys($candidates));
    usort($names, function ($a, $b) use ($tokens) {
        $shared = fn($n) => count(array_intersect_key(array_flip(explode(' ', $n)), $tokens));
        return [-$shared($a), strlen($a), $a] <=> [-$shared($b), strlen($b), $b];
    });
    return array_map('proper_title', array_slice($names, 0, $limit));
}

/** "production specalist" -> "production specialist": a misspelled word fixed against O*NET's job-title words. */
function spelling_fix(string $title): string
{
    $known = cached_data('onet-words', function () {
        $words = [];
        foreach (onet_title_names() as [$name]) {
            foreach (explode(' ', $name) as $word) {
                if (strlen($word) >= 3) {
                    $words[$word] = true;
                }
            }
        }
        return array_keys($words);
    });
    static $set = null;
    $set ??= array_flip($known);
    $fixed = [];
    foreach (preg_split('/\s+/', trim($title)) as $word) {
        $bare = preg_replace('/[^a-z]/', '', fold($word));
        if (strlen($bare) >= 5 && !isset($set[$bare])) {
            $best = null;
            $best_ratio = 0.86;
            foreach ($known as $candidate) {
                if (abs(strlen((string) $candidate) - strlen($bare)) > 2) {
                    continue;
                }
                similar_text($bare, (string) $candidate, $percent);
                if ($percent / 100 >= $best_ratio) {
                    $best_ratio = $percent / 100;
                    $best = (string) $candidate;
                }
            }
            if ($best !== null) {
                $word = ctype_upper($word) ? strtoupper($best) : (ctype_upper($word[0] ?? '') ? ucfirst($best) : $best);
            }
        }
        $fixed[] = $word;
    }
    return implode(' ', $fixed);
}
