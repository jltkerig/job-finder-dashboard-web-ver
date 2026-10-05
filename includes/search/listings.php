<?php
// Conservative, source-aware extraction of individual job openings: does a title match yours, does a posting
// shut out U.S. applicants, is the work remote, hybrid or on-site, and the openings on a page. Ported from the
// desktop app's job_listings.py.

declare(strict_types=1);

require_once __DIR__ . '/html.php';

const ATS_HOSTS = ['greenhouse.io', 'lever.co', 'ashbyhq.com', 'workdayjobs.com', 'smartrecruiters.com', 'icims.com',
    'jobvite.com', 'bamboohr.com'];
const JOB_PATH = '#/(?:jobs?|positions?|openings?|careers?)/(?:[^/?\#]+/)*[^/?\#]+/?$#i';

const DROPPED_WORDS = ['senior', 'junior', 'remote', 'hybrid', 'job', 'jobs', 'the', 'a'];
// Words that put a title in a different trade from a designer/developer search.
const OFF_FIELD_WORDS = ['interior', 'landscape', 'fashion', 'apparel', 'industrial', 'mechanical', 'electrical',
    'structural', 'architectural', 'jewelry', 'floral', 'kitchen', 'furniture', 'merchandising', 'merchandiser'];
// Extra words that may sit around or between the searched words without changing the job.
const COMPATIBLE_EXTRA_WORDS = ['ux', 'ui', 'digital', 'creative', 'visual', 'interaction', 'interactive', 'graphic',
    'content', 'product', 'brand', 'marketing', 'email', 'motion', 'print', 'wordpress', 'and', 'or', 'frontend', 'front',
    'end', 'specialist', 'associate', 'lead', 'staff', 'principal', 'intern', 'web', 'website', 'ii', 'iii', 'iv', 'v', 'i'];

/** One form per word, so "graphic design" matches "Graphic Designer(s)" and "web" matches "website". */
function word_stem(string $word): string
{
    if ($word === 'website') {
        return 'web';
    }
    foreach (['ers', 'er', 's'] as $suffix) {
        if (str_ends_with($word, $suffix) && strlen($word) - strlen($suffix) >= 4) {
            return substr($word, 0, -strlen($suffix));
        }
    }
    return $word;
}

function title_tokens(?string $value): array
{
    $text = mb_strtolower((string) $value);
    foreach (['front[ -]?end' => 'frontend', 'back[ -]?end' => 'backend', 'full[ -]?stack' => 'fullstack'] as $old => $new) {
        $text = preg_replace('/\b' . $old . '\b/u', $new, $text);
    }
    preg_match_all('/[a-z0-9]+/', $text, $m);
    $out = [];
    foreach ($m[0] as $word) {
        if (!in_array($word, DROPPED_WORDS, true)) {
            $out[] = word_stem($word);
        }
    }
    return $out;
}

/** The searched words must appear together (Web Producer is not Web Series Producer). */
function matching_title(?string $title, array $wanted): bool
{
    static $off = null, $compatible = null;
    $off ??= array_flip(array_map('word_stem', OFF_FIELD_WORDS));
    $compatible ??= array_flip(array_map('word_stem', COMPATIBLE_EXTRA_WORDS));
    $actual_list = title_tokens($title);
    $actual = array_flip($actual_list);
    // "Lead, Digital Designer (Apparel & Footwear)": the bracketed department is not part of the job's name.
    $core = array_flip(title_tokens(preg_split('/\s[-–|]\s|\(/u', (string) $title)[0]));
    foreach ($wanted as $phrase) {
        $words_list = title_tokens($phrase);
        $words = array_flip($words_list);
        if (!$words || array_diff_key(array_intersect_key($core, $off), $words)) {
            continue;
        }
        $size = count($words_list);
        for ($i = 0; $i <= count($actual_list) - $size; $i++) {
            if (array_slice($actual_list, $i, $size) === $words_list) {
                return true;
            }
        }
        $extras = array_diff_key($actual, $words);
        if (!array_diff_key($words, $actual)) {
            $ok = true;
            foreach (array_keys($extras) as $word) {
                if (!isset($compatible[(string) $word]) && !ctype_digit((string) $word)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        if ($size >= 3 && count(array_intersect_key($words, $actual)) >= $size - 1) {
            return true;
        }
    }
    return false;
}

// A trailing ISO country code ("London, GB"); codes that are also U.S. state abbreviations are left out on purpose.
const NON_US_COUNTRY_CODE = '/,\s*(?:gb|uk|fr|ie|au|nz|jp|cn|br|mx|za|it|es|nl|se|no|dk|fi|pl|pt|ch|at|be|sg|ae|ro|cz|hu|gr|tr|ph|ng|ke|eg|kr|tw|hk|th|vn|my|pk|bd|ua|ru)\s*$/i';

/** True when a posting explicitly limits applicants to places outside the United States. */
function excludes_us(?string $location, ?string $description = ''): bool
{
    $location = (string) $location;
    $lower = mb_strtolower($location);
    $evidence = mb_strtolower($location . ' ' . mb_substr((string) $description, 0, 1500));
    if (preg_match('/\b(?:united states|usa|us only|worldwide|anywhere|global)\b|\bu\.s\.(?!\w)/u', $lower)) {
        return false;
    }
    $countries = 'canada|united kingdom|uk|great britain|england|scotland|wales|ireland|germany|france|spain|italy|'
        . 'netherlands|poland|portugal|sweden|norway|denmark|finland|switzerland|austria|belgium|india|australia|'
        . 'new zealand|philippines|brazil|mexico|japan|china|singapore|israel|europe|emea|apac|latam';
    $requirement = "(?:remote|applicants?|candidates?).{0,45}?(?:only|must be|must reside|must live|based|located|residing)\\s+"
        . "(?:(?:based|located|residing)\\s+)?(?:in|of)\\s+(?:the\\s+)?(?:$countries)\\b"
        . "|\\b(?:residents?|citizens?)\\s+of\\s+(?:the\\s+)?(?:$countries)\\b"
        . "|\\b(?:$countries)\\b[\\s\\-–,()]*(?:residents?\\s+)?only\\b";
    return (bool) (preg_match("/\\b(?:$countries)\\b/u", $lower) || preg_match(NON_US_COUNTRY_CODE, $location)
        || preg_match("/$requirement/u", $evidence));
}

const HYBRID_TEXT = '/\b(?:hybrid|part(?:ly|ially) remote|split between (?:home|remote) and (?:the )?office)\b/i';
const REMOTE_TEXT = '/\b(?:remote|work(?:ing)? from home|wfh|telecommut(?:e|ing)|home[ -]based|distributed team)\b/i';
const ONSITE_TEXT = '/\b(?:on[ -]?site|in[ -]?office|in[ -]?person|office[ -]based|on[ -]?premises)\b/i';
const DESCRIBED_REMOTE = '/\b(?:fully remote|100% remote|remote[- ]first|remote (?:position|role|job|opportunity)|(?:this|the) (?:role|position|job|opportunity) is (?:fully |100% )?remote|work from home|wfh|telecommut(?:e|ing))\b/i';
// "remote work stipend" is a perk of a job, not where the job is done.
const REMOTE_PERKS = '/\bremote[- ](?:work(?:ing)?\s+)?(?:stipend|allowance|policy|policies|equipment|set-?up|tools|culture|benefits?|perks?|reimbursement)\b|\bwork[- ]from[- ]home\s+(?:stipend|allowance|equipment|set-?up|reimbursement)\b/i';
// "Remote" as part of a job's subject ("remote sensing"), not its location.
const REMOTE_SUBJECT = '/\bremote[- ](?:sensing|sensors?|controls?|controlled|operated|operations?|monitoring|desktop|access|areas?|communities|villages?)\b/i';
const NEGATED_REMOTE = "/\\b(?:not|isn'?t|aren'?t|cannot be|can'?t be|non)[- ](?:(?:a|an|the|fully|100%|completely|entirely|eligible for)\\s+)*remote\\b|\\bno (?:remote|work[- ]from[- ]home|telecommut\\w*)\\b|\\bremote (?:work |working )?(?:is|are) (?:not|unavailable)\\b|\\b(?:does|do|will) not (?:offer|allow|support|permit) (?:remote|work[- ]from[- ]home|telecommut\\w*)\\b|\\bwithout remote\\b/i";
// In-person interviews, events and occasional travel do not make a job on-site.
const ONSITE_OCCASIONS = '/\b(?:in[- ]person|on[- ]?site)\s+(?:interviews?|meetings?|events?|training|orientation|onboarding|assessments?|rounds?|retreats?|offsites?|team\s+\w+)\b|\binterviews?\s+(?:will be\s+|are\s+)?(?:conducted\s+|held\s+)?(?:in[- ]person|on[- ]?site)\b|\b(?:occasional(?:ly)?|periodic(?:ally)?|quarterly|annual(?:ly)?|monthly)\s+(?:in[- ]person|on[- ]?site|travel)\b/i';

/** [text with perks, subjects, interview wording and negated "remote" taken out, whether remote was negated] */
function clean_arrangement_text(?string $text): array
{
    $text = (string) $text;
    foreach ([REMOTE_PERKS, REMOTE_SUBJECT, ONSITE_OCCASIONS] as $pattern) {
        $text = preg_replace($pattern, ' ', $text);
    }
    $negated = (bool) preg_match(NEGATED_REMOTE, $text);
    return [preg_replace(NEGATED_REMOTE, ' NOTREMOTE ', $text), $negated];
}

/** Which of Remote, Hybrid and Onsite the text states outright. */
function arrangement_types(?string $text): array
{
    [$text, $negated] = clean_arrangement_text($text);
    $text = preg_replace(HYBRID_TEXT, 'HYBRID', $text);
    $found = [];
    foreach (['Hybrid' => HYBRID_TEXT, 'Remote' => REMOTE_TEXT, 'Onsite' => ONSITE_TEXT] as $name => $pattern) {
        if (preg_match($pattern, $text)) {
            $found[$name] = true;
        }
    }
    if ($negated && !isset($found['Hybrid'])) {
        unset($found['Remote']);
        $found['Onsite'] = true; // "not remote" means the work is done in person
    }
    return array_keys($found);
}

/** Hybrid, Remote, Onsite or null for a JobPosting. */
function posting_arrangement(string $title, string $location, string $location_type, string $description, bool $remote_flag): ?string
{
    $description = mb_substr($description, 0, 2500);
    [$description_text, $description_negated] = clean_arrangement_text($description);
    $title_and_place = arrangement_types("$title $location");
    if (preg_match(HYBRID_TEXT, "$location_type $title $location $description")) {
        return 'Hybrid';
    }
    if ($remote_flag || in_array('Remote', $title_and_place, true) || preg_match(DESCRIBED_REMOTE, $description_text)) {
        return 'Remote';
    }
    if (in_array('Onsite', $title_and_place, true) || $description_negated || preg_match(ONSITE_TEXT, $description_text)) {
        return 'Onsite';
    }
    return null;
}

function ld_plain($value): string
{
    if (is_array($value) && !array_is_list($value)) {
        return (string) ($value['name'] ?? $value['value'] ?? '');
    }
    return is_scalar($value) ? (string) $value : '';
}

function ld_locations($value): string
{
    if (is_array($value) && array_is_list($value)) {
        return implode(', ', array_filter(array_map('ld_locations', $value), 'strlen'));
    }
    if (!is_array($value)) {
        return ld_plain($value);
    }
    $address = $value['address'] ?? $value;
    if (is_array($address) && !array_is_list($address)) {
        return implode(', ', array_filter(array_map(fn($k) => ld_plain($address[$k] ?? ''),
            ['addressLocality', 'addressRegion', 'addressCountry']), 'strlen'));
    }
    return ld_plain($address);
}

function ld_salary($value): string
{
    if (is_array($value) && array_is_list($value)) {
        $value = $value[0] ?? null;
    }
    if (!is_array($value)) {
        return '';
    }
    $currency = (string) ($value['currency'] ?? '');
    $amount = $value['value'] ?? $value;
    if (is_array($amount)) {
        $low = $amount['minValue'] ?? $amount['value'] ?? null;
        $high = $amount['maxValue'] ?? null;
        $unit = (string) ($amount['unitText'] ?? '');
    } else {
        [$low, $high, $unit] = [$amount, null, ''];
    }
    if (!$low) {
        return '';
    }
    $symbol = $currency === 'USD' ? '$' : ($currency ? "$currency " : '');
    $text = $symbol . $low . ($high && (string) $high !== (string) $low ? "–$symbol$high" : '');
    return mb_substr($text . ($unit ? ' / ' . mb_strtolower($unit) : ''), 0, 120);
}

/** A posting date as YYYY-MM-DD, or null when missing, unreadable or in the future. */
function ld_freshness($value): ?string
{
    if (!$value || !is_scalar($value)) {
        return null;
    }
    $time = strtotime((string) $value);
    if ($time === false) {
        return null;
    }
    $day = gmdate('Y-m-d', $time);
    return $day > gmdate('Y-m-d') ? null : $day;
}

function page_has_job_posting(Page $page): bool
{
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $node) {
            if (ld_has_type($node, ['jobposting'])) {
                return true;
            }
        }
    }
    return false;
}

/** A page marked as an article, unless it also holds a real job posting. */
function is_article_page(Page $page): bool
{
    return $page->meta('property', 'og:type') === 'article' && !page_has_job_posting($page);
}

const PROJECT_WORK = '/\b(?:freelanc(?:e|er)|gig|project[- ]based|independent contractor)\b/i';

/** Only individual postings with a matching title and a direct address. */
function extract_jobs(Page $page, array $wanted): array
{
    if (is_article_page($page)) {
        return [];
    }
    $results = [];
    $seen = [];
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $node) {
            if (!ld_has_type($node, ['jobposting'])) {
                continue;
            }
            $title = trim(ld_plain($node['title'] ?? $node['name'] ?? ''));
            $direct = canonical_url(url_join($page->url, ld_plain($node['url'] ?? $node['applyUrl'] ?? $page->url) ?: $page->url));
            if (!matching_title($title, $wanted) || $direct === '' || isset($seen[$direct])) {
                continue;
            }
            $expiry = ld_freshness($node['validThrough'] ?? null);
            if ($expiry && $expiry < gmdate('Y-m-d')) {
                continue;
            }
            $location = ld_locations($node['jobLocation'] ?? null);
            $location_type = ld_plain($node['jobLocationType'] ?? '');
            $remote = str_contains(strtolower($location_type), 'remote') || str_contains(strtolower($location_type), 'telecommute');
            if ($remote) {
                $location = ld_locations($node['applicantLocationRequirements'] ?? null) ?: ($location ?: 'Remote');
            }
            if (preg_match('/\bremote\b/i', clean_arrangement_text($location)[0])) {
                $remote = true;
            }
            $employer = ld_plain($node['hiringOrganization'] ?? '');
            $description = html_to_text(ld_plain($node['description'] ?? ''));
            $arrangement = posting_arrangement($title, $location, $location_type, $description, $remote);
            $evidence = ['JobPosting data', 'title matches search', 'direct listing link'];
            $posted = ld_freshness($node['datePosted'] ?? null);
            if ($posted) {
                $evidence[] = "posted $posted";
            }
            $schedule = ld_plain($node['employmentType'] ?? '');
            if (is_array($node['employmentType'] ?? null) && array_is_list($node['employmentType'])) {
                $schedule = implode(', ', array_map('strval', $node['employmentType']));
            }
            if (preg_match(PROJECT_WORK, "$schedule $title " . mb_substr($description, 0, 2500))) {
                $schedule = 'Freelance / Gig';
            }
            $results[] = ['title' => mb_substr($title, 0, 255), 'url' => $direct, 'company' => mb_substr($employer, 0, 255),
                'location' => mb_substr($location, 0, 100), 'type' => $arrangement, 'schedule' => mb_substr($schedule, 0, 100),
                'salary' => ld_salary($node['baseSalary'] ?? null), 'posted' => $posted, 'evidence' => $evidence,
                'description' => $description];
            $seen[$direct] = true;
        }
    }
    if ($results || is_non_job_path(parse_url($page->url, PHP_URL_PATH))) {
        return $results;
    }
    // A page without structured data qualifies only if it looks like one job and has its own apply action.
    $title = $page->h1();
    if (!matching_title($title, $wanted)) {
        return [];
    }
    $apply = null;
    foreach ($page->links() as [, $text]) {
        if (preg_match('/^(?:apply(?: now| for (?:this )?job)?|submit application)$/i', $text)) {
            $apply = $text;
            break;
        }
    }
    $host = strtolower((string) parse_url($page->url, PHP_URL_HOST));
    $on_ats = false;
    foreach (ATS_HOSTS as $ats) {
        if (str_ends_with($host, $ats)) {
            $on_ats = true;
        }
    }
    if ($apply === null || !(preg_match(JOB_PATH, (string) parse_url($page->url, PHP_URL_PATH)) || $on_ats)) {
        return [];
    }
    $page_text = mb_substr($page->text(), 0, 20000);
    $project = preg_match(PROJECT_WORK, $title . ' ' . mb_substr($page_text, 0, 2500));
    return [['title' => mb_substr($title, 0, 255), 'url' => canonical_url($page->url), 'company' => '', 'location' => '',
        'type' => null, 'schedule' => $project ? 'Freelance / Gig' : '', 'salary' => '', 'posted' => null,
        'evidence' => ['job detail page', 'title matches search', 'apply action'], 'description' => $page_text]];
}

/** Likely posting addresses on a careers page, not generic footer links. */
function job_links(Page $page, int $limit = 24): array
{
    $origin = strtolower((string) parse_url($page->url, PHP_URL_HOST));
    $ranked = [];
    foreach ($page->links() as [$absolute, $text]) {
        $href = canonical_url($absolute);
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $on_ats = false;
        foreach (ATS_HOSTS as $ats) {
            if (str_ends_with($host, $ats)) {
                $on_ats = true;
            }
        }
        if ($href === '' || ($host !== $origin && !$on_ats)) {
            continue;
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        if (is_non_job_path($path) || is_pdf_url($href)) {
            continue;
        }
        $words = count(array_unique(title_tokens($text)));
        if (preg_match(JOB_PATH, $path) || (str_contains(strtolower($path), 'job') && $words >= 2) || ($on_ats && $words >= 2)) {
            $ranked[$href] = true;
        }
    }
    return array_slice(array_keys($ranked), 0, $limit);
}

function pagination_links(Page $page, int $limit = 3): array
{
    $origin = parse_url($page->url, PHP_URL_HOST);
    $here = canonical_url($page->url);
    $result = [];
    foreach ($page->links() as [$absolute, $text, , $a]) {
        $label = mb_strtolower($text);
        $rel = strtolower($a->getAttribute('rel'));
        if (!in_array($label, ['next', 'next page', '2', '3'], true) && !in_array('next', explode(' ', $rel), true)) {
            continue;
        }
        $target = canonical_url($absolute);
        if ($target !== '' && parse_url($target, PHP_URL_HOST) === $origin && $target !== $here && !is_pdf_url($target)) {
            $result[$target] = true;
        }
    }
    return array_slice(array_keys($result), 0, $limit);
}
