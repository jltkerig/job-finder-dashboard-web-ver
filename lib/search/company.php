<?php
// Which company a posting belongs to, how much to trust its page (Career Credibility), the employer's own website
// and careers page for a listing found on a job board, and whether a job board repost has closed. Ported from the
// desktop app's company_names.py, company_site.py, employer_site.py and closed_jobs.py.

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/listings.php';

// A posting on a recognised applicant-system board (Workday, Greenhouse, ...) is the employer's own listing.
const OFFICIAL_BOARD_CREDIBILITY = 8;
const CAREER_CREDIBILITY_THRESHOLD = 3;
const USA_CREDIBILITY_THRESHOLD = 5;

const CAREER_STRONG_TERMS = ['careers', 'career opportunities', 'job openings', 'open positions', 'current openings',
    'join our team', 'join us', 'work with us', 'work for us', 'apply now'];
const CAREER_WEAK_TERMS = ['career', 'jobs', 'employment opportunities', 'hiring', 'opportunities'];
const CAREER_NEGATIVE_TERMS = ['unemployment benefits', 'unemployment insurance', 'file a claim', 'benefits claim',
    'workforce services', 'job seeker services'];
const ATS_DOMAINS = ['greenhouse.io', 'lever.co', 'myworkdayjobs.com', 'workday.com', 'icims.com', 'jobvite.com',
    'smartrecruiters.com', 'ashbyhq.com', 'bamboohr.com', 'paylocity.com'];
const SOCIAL_DOMAINS = ['facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com'];
const DIRECTORY_MARKETPLACE_DOMAINS = ['bark.com', 'sortlist.com', 'clutch.co', 'goodfirms.co', 'designrush.com', 'yelp.com',
    'thumbtack.com', 'expertise.com', 'upcity.com', 'agencyspotter.com'];
const JOB_TITLE_WORDS = '/\b(?:designer|developer|engineer|manager|specialist|coordinator|analyst|director|associate|intern|producer|editor|writer|assistant|technician|consultant|architect|administrator|officer|representative|supervisor|strategist|copywriter|artist|programmer)\b/i';

function domain_in(string $domain, array $list): bool
{
    foreach ($list as $item) {
        if ($domain === $item || str_ends_with($domain, ".$item")) {
            return true;
        }
    }
    return false;
}

function is_directory_or_marketplace_result(string $domain, string $title = '', ?Page $page = null): bool
{
    if (domain_in($domain, DIRECTORY_MARKETPLACE_DOMAINS)) {
        return true;
    }
    foreach (['/\bfind an? (?:\w+ ){0,2}(?:agency|agencies|designers?|developers?|companies|company|providers?|professionals?|freelancers?|experts?)\b/i',
                 '/\bbest (?:\w+ ){0,3}(?:agencies|designers|developers|companies)\b/i',
                 '/\btop (?:\d+ )?(?:\w+ ){0,3}(?:agencies|designers|developers|companies)\b/i',
                 '/\bcompare providers\b/i', '/\bget quotes\b/i'] as $pattern) {
        if (preg_match($pattern, $title)) {
            return true;
        }
    }
    if (preg_match('/\breviews?\b/i', $title) && !preg_match(JOB_TITLE_WORDS, $title)) {
        return true;
    }
    if ($page !== null && $page->html !== '') {
        $marker = mb_strtolower($page->title() . ' ' . $page->headings(['h1', 'h2'], 8));
        foreach (['service providers', 'compare agencies', 'agency directory', 'business directory', 'get free quotes', 'find professionals'] as $signal) {
            if (str_contains($marker, $signal)) {
                return true;
            }
        }
    }
    return false;
}

/** A financial-aid guidance page without a specific job posting. */
function is_student_employment_overview(Page $page): bool
{
    $headline = mb_strtolower($page->title() . ' ' . implode(' ', array_map('node_text', $page->all('//h1'))));
    $path = strtolower((string) parse_url($page->url, PHP_URL_PATH));
    $student = (bool) preg_match('/financial-aid|financialaid|types-of-aid|work-study/', $path);
    $general = (bool) preg_match('/campus employment|student employment|federal work-study|work study information|employment & internships/', $headline);
    return $student && $general && !str_contains($page->html, '"JobPosting"');
}

/** Career Credibility 0-10 for a page, with the evidence (see the Scores page). */
function score_career_page(Page $page): array
{
    if (is_student_employment_overview($page)) {
        return ['score' => 0, 'evidence' => ['Student employment overview, not an individual job posting']];
    }
    $title = $page->title();
    $headings = $page->headings();
    $headline = mb_strtolower("{$page->url} $title $headings");
    $searchable = mb_strtolower("{$page->url} $title $headings " . $page->text());
    $score = 0;
    $evidence = [];
    $headline_hits = array_values(array_filter(CAREER_STRONG_TERMS, fn($t) => str_contains($headline, $t)));
    $strong_hits = array_values(array_filter(CAREER_STRONG_TERMS, fn($t) => str_contains($searchable, $t)));
    if ($headline_hits) {
        $score += min(4, 2 + count($headline_hits));
        $evidence[] = 'career language in URL/title/heading: ' . implode(', ', array_slice($headline_hits, 0, 3));
    } elseif ($strong_hits) {
        $score += min(2, count($strong_hits));
        $evidence[] = 'career language in page body: ' . implode(', ', array_slice($strong_hits, 0, 3));
    }
    $weak_hits = array_values(array_filter(CAREER_WEAK_TERMS, fn($t) => str_contains($searchable, $t)));
    if ($weak_hits) {
        $score += 1;
        $evidence[] = 'supporting career language: ' . implode(', ', array_slice($weak_hits, 0, 3));
    }
    $job_links = $apply_links = $ats_links = 0;
    foreach ($page->links() as [$href, $text]) {
        $text = mb_strtolower($text);
        $href_lower = strtolower($href);
        foreach (['job', 'career', 'position', 'opening'] as $term) {
            if (str_contains($text, $term) || str_contains($href_lower, $term)) {
                $job_links++;
                break;
            }
        }
        if (str_contains($text, 'apply') || str_contains($href_lower, 'apply')) {
            $apply_links++;
        }
        if (is_valid_url($href) && domain_in(get_domain($href), ATS_DOMAINS)) {
            $ats_links++;
        }
    }
    if ($job_links) {
        $score += 1;
        $evidence[] = "$job_links job/career links";
    }
    if ($apply_links) {
        $score += 2;
        $evidence[] = "$apply_links apply links";
    }
    if ($ats_links) {
        $score += 2;
        $evidence[] = "$ats_links ATS links";
    }
    $negative = array_values(array_filter(CAREER_NEGATIVE_TERMS, fn($t) => str_contains($searchable, $t)));
    if ($negative) {
        $score -= min(5, 2 * count($negative));
        $evidence[] = 'non-hiring language: ' . implode(', ', array_slice($negative, 0, 3));
    }
    return ['score' => max(0, min(10, $score)), 'evidence' => $evidence];
}

// --- Company names -------------------------------------------------------------------------------------------

/** The company name without a leading legal-entity code ("003 Humana Inc." -> "Humana Inc."). */
function tidy_company_name(?string $name): string
{
    return trim(preg_replace('/^\s*0\d{1,4}\s+(?=[A-Za-z])/', '', (string) $name));
}

function clean_company_name(?string $name, string $domain): ?string
{
    if (!$name) {
        return null;
    }
    $cleaned = tidy_company_name($name);
    foreach ([' | ', ' - ', ' – ', ' — ', ' :: '] as $separator) {
        if (str_contains($cleaned, $separator)) {
            $cleaned = trim(explode($separator, $cleaned)[0]);
        }
    }
    if (in_array(mb_strtolower($cleaned), ['home', 'homepage', 'welcome', 'careers', 'jobs', 'official website'], true)
        || mb_strlen($cleaned) > 120) {
        return null;
    }
    return $cleaned ?: $domain;
}

/** The company in a page title such as "Senior Web Designer | Acme" (not the job title), or null. */
function company_from_title(?string $title): ?string
{
    $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s[|–—:-]\s|\s::\s/u', $title)), 'strlen'));
    if (count($parts) === 1) {
        $at = preg_split('/\s(?:at|@)\s/', $parts[0], 2);
        if (count($at) === 2 && preg_match(JOB_TITLE_WORDS, $at[0])) {
            return trim($at[1]);
        }
    }
    foreach ($parts as $part) {
        if (!preg_match(JOB_TITLE_WORDS, $part)) {
            return $part;
        }
    }
    return null;
}

function extract_company_name(Page $page, string $search_title, string $domain): string
{
    $og = $page->meta('property', 'og:site_name');
    if ($og && ($name = clean_company_name($og, $domain))) {
        return $name;
    }
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $node) {
            if (ld_has_type($node, ['organization', 'corporation', 'localbusiness', 'professionalservice', 'website'])
                && !empty($node['name']) && ($name = clean_company_name(trim(ld_plain($node['name'])), $domain))) {
                return $name;
            }
        }
    }
    if ($page->title() !== '' && ($name = clean_company_name(company_from_title($page->title()), $domain))) {
        return $name;
    }
    return clean_company_name(company_from_title($search_title), $domain) ?? $domain;
}

// --- The employer's own website ------------------------------------------------------------------------------

const LEGAL_WORDS = ['inc', 'llc', 'ltd', 'co', 'corp', 'corporation', 'company', 'the', 'group', 'holdings', 'limited', 'lp',
    'llp', 'pllc', 'incorporated'];
const GENERIC_TAIL = ['technologies', 'technology', 'tech', 'solutions', 'systems', 'services', 'labs', 'lab', 'studio',
    'studios', 'software', 'digital', 'media', 'consulting', 'partners', 'group', 'design', 'designs', 'agency', 'associates',
    'international', 'global', 'worldwide', 'supplies', 'supply', 'products', 'enterprises', 'industries', 'goods', 'brands',
    'wholesale', 'distribution', 'distributors', 'company', 'co', 'corp', 'corporation', 'holdings', 'usa'];
const HIRING_HOSTS = ['icims.com', 'myworkdayjobs.com', 'greenhouse.io', 'lever.co', 'jobvite.com', 'smartrecruiters.com',
    'ashbyhq.com', 'bamboohr.com', 'workable.com', 'recruitee.com', 'applytojob.com', 'jazz.hr', 'taleo.net',
    'successfactors.com', 'successfactors.eu', 'ultipro.com', 'paylocity.com', 'dayforcehcm.com', 'paycomonline.net',
    'recruitingbypaycor.com', 'teamtailor.com', 'breezy.hr', 'phenompeople.com', 'avature.net', 'darwinbox.in',
    'darwinbox.com', 'fountain.com', 'paradox.ai', 'recruiterbox.com', 'trakstar.com', 'bullhornstaffing.com',
    'recruitcrm.io', 'beamery.com', 'oraclecloud.com', 'adp.com', 'governmentjobs.com'];

function name_tokens(?string $name): array
{
    preg_match_all('/[a-z0-9]+/', mb_strtolower((string) $name), $m);
    return array_values(array_filter($m[0], fn($w) => !in_array($w, LEGAL_WORDS, true)));
}

function name_slug(?string $name): string
{
    return implode('', name_tokens($name));
}

function host_root(string $host): string
{
    $labels = array_values(array_filter(explode('.', preg_replace('/^www\./', '', strtolower($host))), 'strlen'));
    $n = count($labels);
    if ($n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], ['co', 'com', 'org', 'gov', 'edu', 'ac', 'net'], true)) {
        return $labels[$n - 3];
    }
    return $n >= 2 ? $labels[$n - 2] : ($labels[0] ?? '');
}

function host_matches_company(string $host, ?string $company): bool
{
    $slug = name_slug($company);
    $root = host_root($host);
    $tokens = name_tokens($company);
    if ($slug === '' || $root === '') {
        return false;
    }
    return $root === $slug || (strlen($slug) >= 4 && str_contains($root, $slug)) || (strlen($root) >= 5 && str_contains($slug, $root))
        || (strlen($tokens[0]) >= 4 && $tokens[0] === $root);
}

/** True when the listing lives on a site that is not the employer's own. */
function is_third_party(string $posting_url, ?string $company): bool
{
    return !host_matches_company(get_domain($posting_url), $company);
}

function same_site(string $host, string $base): bool
{
    $host = preg_replace('/^www\./', '', $host);
    $base = preg_replace('/^www\./', '', $base);
    return $host !== '' && $base !== '' && ($host === $base || str_ends_with($host, ".$base"));
}

/** The company's name as whole words in the text ("Wise" is not in "Otherwise Solutions"). */
function names_match(string $company, string $text): bool
{
    $tokens = name_tokens($company);
    while (count($tokens) > 1 && in_array(end($tokens), GENERIC_TAIL, true)) {
        array_pop($tokens);
    }
    if (!$tokens) {
        return false;
    }
    preg_match_all('/[a-z0-9]+/', mb_strtolower($text), $m);
    $words = $m[0];
    $size = count($tokens);
    for ($i = 0; $i <= count($words) - $size; $i++) {
        if (array_slice($words, $i, $size) === $tokens) {
            return true;
        }
    }
    return false;
}

function is_excluded_employer_host(string $host): bool
{
    return domain_is_blocked($host) || has_blocked_country_domain($host)
        || domain_in($host, array_merge(SOCIAL_DOMAINS, ATS_DOMAINS, DIRECTORY_MARKETPLACE_DOMAINS));
}

/** Country endings (.ca, .co.uk ...) skipped when the search is U.S. only. */
function has_blocked_country_domain(string $domain): bool
{
    static $endings = null;
    if (!tuning('usa_only')) {
        return false;
    }
    $endings ??= array_values(array_filter(array_map('trim', file(DATA_DIR . '/blocked_country_domains.txt')),
        fn($l) => $l !== '' && $l[0] !== '#'));
    foreach ($endings as $ending) {
        if (str_ends_with($domain, $ending)) {
            return true;
        }
    }
    return false;
}

/** Possible homepages for the employer: from the listing itself (trusted), then guesses from its name. */
function employer_site_candidates(string $company, string $posting_url, Page $posting): array
{
    $posting_host = get_domain($posting_url);
    $usable = fn(string $url) => is_valid_url($url) && get_domain($url) !== '' && get_domain($url) !== $posting_host
        && !is_excluded_employer_host(get_domain($url));
    $out = [];
    foreach ($posting->json_ld() as $data) {
        foreach (json_nodes($data) as $node) {
            $organization = ld_has_type($node, ['jobposting']) ? ($node['hiringOrganization'] ?? null) : null;
            if (is_array($organization)) {
                foreach (['url', 'sameAs'] as $key) {
                    foreach ((array) ($organization[$key] ?? []) as $value) {
                        if (is_string($value) && $usable($value)) {
                            $out[] = [$value, 'listing data (hiringOrganization)', true];
                        }
                    }
                }
            }
        }
    }
    foreach ($posting->links() as [$href, $text]) {
        $text = mb_strtolower($text);
        foreach (['website', 'visit website', 'company website', 'official site'] as $term) {
            if (str_contains($text, $term) && $usable($href)) {
                $out[] = [$href, 'company website link on the listing', true];
                break;
            }
        }
    }
    // Schools: initials (jhu) and the name without "University" (johnshopkins).
    preg_match_all('/[a-z0-9]+/', mb_strtolower($company), $m);
    $tokens = array_values(array_filter($m[0], fn($t) => !in_array($t, LEGAL_WORDS, true) && !in_array($t, ['of', 'the', 'at', 'and', 'for'], true)));
    $school = ['university', 'college', 'institute', 'polytechnic', 'academy', 'school'];
    if ($tokens && array_intersect($tokens, $school)) {
        $slugs = [];
        if (count($tokens) >= 3) {
            $slugs[] = implode('', array_map(fn($t) => $t[0], $tokens));
        }
        $core = array_diff($tokens, $school);
        if ($core) {
            $slugs[] = implode('', $core);
        }
        foreach (array_unique($slugs) as $slug) {
            if (strlen($slug) >= 3) {
                foreach (['', 'www.'] as $prefix) {
                    $out[] = ["https://$prefix$slug.edu/", 'school domain guess', false];
                }
            }
        }
    }
    $generic = '/\b(?:office|department|dept|division|bureau|agency|county|city|state|university|college|school|human resources|hr|careers|jobs)\b/i';
    if (strlen(name_slug($company)) >= 4 && !preg_match($generic, $company)) {
        $slugs = [name_slug($company)];
        $tokens = name_tokens($company);
        while (count($tokens) > 1 && in_array(end($tokens), GENERIC_TAIL, true)) {
            array_pop($tokens);
            $trimmed = implode('', $tokens);
            if (strlen($trimmed) >= 4 && !in_array($trimmed, $slugs, true)) {
                $slugs[] = $trimmed;
            }
        }
        foreach ($slugs as $slug) {
            foreach (['com', 'org'] as $tld) {
                foreach (['', 'www.'] as $prefix) {
                    $url = "https://$prefix$slug.$tld/";
                    if ($usable($url)) {
                        $out[] = [$url, 'domain guess', false];
                    }
                }
            }
        }
    }
    return $out;
}

/** A hiring-system board link that belongs to this company (its careers page), or null. */
function hiring_system_link(array $links, string $company): ?string
{
    $slug = name_slug($company);
    foreach ($links as $target) {
        $host = strtolower((string) parse_url($target, PHP_URL_HOST));
        if (!domain_in($host, HIRING_HOSTS)) {
            continue;
        }
        $address = preg_replace('/[^a-z0-9]/', '', strtolower($host . parse_url($target, PHP_URL_PATH)));
        if ($slug !== '' && str_contains($address, $slug)) {
            return parse_url($target, PHP_URL_SCHEME) . '://' . parse_url($target, PHP_URL_HOST) . parse_url($target, PHP_URL_PATH);
        }
    }
    return null;
}

function find_careers_page(Page $home, string $company): ?Page
{
    $host = get_domain($home->url);
    $origin = parse_url($home->url, PHP_URL_SCHEME) . '://' . parse_url($home->url, PHP_URL_HOST) . '/';
    $linked = $offsite = [];
    foreach ($home->links() as [$href, $text, $raw]) {
        if (!preg_match('/career|\bjobs?\b|join (?:us|our team)|work with us|employment|hiring/i', "$text $raw")) {
            continue;
        }
        $target = preg_replace('/#.*$/', '', $href);
        if (is_valid_url($target) && same_site(get_domain($target), $host) && !is_pdf_url($target)) {
            $linked[] = $target;
        } elseif (is_valid_url($target)) {
            $offsite[] = $target;
        }
    }
    $board = $offsite ? hiring_system_link($offsite, $company) : null;
    if ($board) {
        return new Page($board, '');
    }
    $seen = [];
    $ranked = [];
    $fetches = 0;
    $paths = ['/careers/', '/careers', '/jobs/', '/jobs', '/join-us/', '/work-with-us/', '/about/careers/', '/company/careers/', '/join-our-team/'];
    foreach (array_merge(array_slice($linked, 0, 3), array_map(fn($p) => url_join($origin, $p), $paths)) as $target) {
        $key = canonical_url($target);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if ($fetches >= 5) {
            break;
        }
        $fetches++;
        $page = fetch_page($target);
        if ($page === null || !same_site(get_domain($page->url), $host)) {
            continue;
        }
        if (preg_match('/\b(?:404|page not found|not found)\b/i', $page->title() . ' ' . $page->h1())) {
            continue;
        }
        $path = (string) parse_url($page->url, PHP_URL_PATH) ?: '/';
        $careers_like = preg_match('#/(?:careers?|jobs?|join[a-z-]*|work-with-us|employment)(?:/|$)#i', $path)
            || preg_match('/^(?:jobs|careers?|employment|hr|join|apply)\./i', get_domain($page->url));
        $score = score_career_page($page)['score'];
        if (!$careers_like && $score < 3) {
            continue;
        }
        $ranked[] = [[(int) $careers_like, $score, -strlen($path)], $page];
        if ($careers_like && preg_match('#^/(?:careers?|jobs?)/?$#i', $path)) {
            break;
        }
    }
    if (!$ranked) {
        return null;
    }
    usort($ranked, fn($a, $b) => $b[0] <=> $a[0]);
    return $ranked[0][1];
}

/** The exact opening on the employer's careers page (or a page it links to), or null. */
function find_posting_on_site(string $careers_url, string $job_title, array $titles): ?string
{
    $page = fetch_page($careers_url);
    if ($page === null) {
        return null;
    }
    $wanted = $job_title !== '' ? [$job_title] : $titles;
    foreach (extract_jobs($page, $wanted) as $opening) {
        if ($opening['url']) {
            return $opening['url'];
        }
    }
    $host = get_domain($careers_url);
    $fetched = 0;
    foreach (job_links($page, 8) as $link) {
        if ($fetched >= 3) {
            break;
        }
        if (!same_site(get_domain($link), $host)) {
            continue;
        }
        $fetched++;
        $detail = fetch_page($link);
        foreach ($detail ? extract_jobs($detail, $wanted) : [] as $opening) {
            if ($opening['url']) {
                return $opening['url'];
            }
        }
    }
    return null;
}

/** The opening's page found in the employer's sitemap, checked against the page itself. */
function find_in_sitemap(string $domain, string $job_title): ?string
{
    preg_match_all('/[a-z0-9]+/', mb_strtolower($job_title), $m);
    $filler = ['and', 'the', 'for', 'of', 'an', 'in', 'at', 'to', 'with', 'senior', 'sr', 'jr', 'junior', 'lead', 'ii', 'iii'];
    $words = array_values(array_filter($m[0], fn($w) => !in_array($w, $filler, true) && strlen($w) > 1));
    if ($domain === '' || !$words) {
        return null;
    }
    $origin = "https://$domain";
    $queue = [];
    $robots = fetch_text("$origin/robots.txt", 500000);
    if ($robots) {
        foreach (preg_split('/\r?\n/', $robots['text']) as $line) {
            if (stripos($line, 'sitemap:') === 0) {
                $queue[] = trim(substr($line, 8));
            }
        }
    }
    foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/job-sitemap.xml'] as $path) {
        $queue[] = $origin . $path;
    }
    $urls = [];
    $seen = [];
    $files = 0;
    while ($queue && $files < 8) {
        $address = array_shift($queue);
        if (isset($seen[$address])) {
            continue;
        }
        $seen[$address] = true;
        $files++;
        $response = fetch_text($address);
        if (!$response) {
            continue;
        }
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $response['text'], $locs);
        $nested = [];
        foreach (array_slice($locs[1], 0, 8000) as $loc) {
            $last = strtolower(basename((string) parse_url($loc, PHP_URL_PATH)));
            if (str_ends_with($last, '.xml') || str_ends_with($last, '.xml.gz') || str_contains($last, 'sitemap')) {
                $nested[] = $loc;
            } else {
                $urls[] = $loc;
            }
        }
        usort($nested, fn($a, $b) => (preg_match('/job|career/i', $a) ? 0 : 1) <=> (preg_match('/job|career/i', $b) ? 0 : 1));
        $queue = array_merge($nested, $queue);
    }
    $candidates = [];
    foreach ($urls as $url) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('/job|career|position|opening|vacanc|employment|hiring|apply|join|work-with/i', $path)) {
            continue;
        }
        preg_match_all('/[a-z0-9]+/', strtolower($path), $p);
        $path_words = array_flip($p[0]);
        if (!array_diff($words, array_keys($path_words))) {
            $candidates[] = [count($path_words), $url];
        }
    }
    sort($candidates);
    foreach (array_slice($candidates, 0, 3) as [, $url]) {
        $page = fetch_page($url);
        if (!$page) {
            continue;
        }
        preg_match_all('/[a-z0-9]+/', mb_strtolower($page->title() . ' ' . $page->h1()), $h);
        if (!array_diff($words, $h[0])) {
            return $page->url;
        }
    }
    return null;
}

/** {domain, careers_url, posting_url, method, evidence} for the employer's own site, or null. */
function resolve_employer_site(string $company, string $job_title, string $posting_url, Page $posting, array $titles, array &$notes): ?array
{
    static $cache = [];
    $company = trim(preg_replace('/\s+/', ' ', $company));
    if ($company === '' || $posting_url === '') {
        return null;
    }
    if (!is_third_party($posting_url, $company)) {
        $notes[] = "listing is already on the employer's own domain";
        return null;
    }
    $key = name_slug($company) ?: mb_strtolower($company);
    if (!array_key_exists($key, $cache)) {
        $site = null;
        $fetched = [];
        $found = null;
        foreach (employer_site_candidates($company, $posting_url, $posting) as [$url, $method, $trusted]) {
            $host = get_domain($url);
            if (isset($fetched[$host])) {
                continue;
            }
            $home = fetch_page($url);
            if ($home === null) {
                continue;
            }
            $fetched[$host] = true;
            $final = get_domain($home->url);
            if ($final === '' || is_excluded_employer_host($final) || $final === get_domain($posting_url)) {
                continue;
            }
            if (!$trusted) {
                $names = array_filter([$home->meta('property', 'og:site_name'), $home->title(), $home->h1()]);
                foreach ($home->json_ld() as $data) {
                    foreach (json_nodes($data) as $node) {
                        if (ld_has_type($node, ['organization', 'corporation', 'localbusiness', 'website', 'professionalservice'])) {
                            $names[] = ld_plain($node['name'] ?? '');
                        }
                    }
                }
                if (!array_filter($names, fn($n) => names_match($company, (string) $n))) {
                    $notes[] = "$host ($method) rejected: its page does not name $company";
                    continue;
                }
            }
            $found = [$home, $method];
            break;
        }
        if ($found) {
            [$home, $method] = $found;
            $host = get_domain($home->url);
            $careers = find_careers_page($home, $company);
            $site = $careers
                ? ['domain' => $host, 'careers_url' => $careers->url, 'method' => $method,
                    'evidence' => ["employer site: $host ($method)", "careers page found on the employer's site"]]
                : ['domain' => $host, 'careers_url' => null, 'method' => $method,
                    'evidence' => ["employer site: $host ($method)", "no careers page on the employer's site; apply through the listing"]];
            if (!$careers) {
                $notes[] = "$host found ($method) but it has no careers page";
            }
        } else {
            $notes[] = 'no employer website could be verified';
        }
        $cache[$key] = $site;
    }
    $site = $cache[$key];
    if ($site === null) {
        return null;
    }
    $result = $site;
    $listing_host = get_domain($posting_url);
    if ($site['domain'] && $listing_host !== $site['domain'] && str_ends_with($listing_host, '.' . $site['domain'])) {
        $result['domain'] = $listing_host;
        $result['posting_url'] = $posting_url;
        $result['evidence'][] = "this listing is on the employer's own site ($listing_host)";
        return $result;
    }
    $result['posting_url'] = $site['careers_url'] ? find_posting_on_site($site['careers_url'], $job_title, $titles) : null;
    if ($result['posting_url']) {
        $result['evidence'][] = "this opening found on the employer's site";
    } else {
        $result['posting_url'] = find_in_sitemap($site['domain'], $job_title);
        if ($result['posting_url']) {
            $result['evidence'][] = "this opening found in the employer's sitemap";
        }
    }
    return $result;
}

/** Plain-language answer to "is this a real posting by the company?", shown next to the company website. */
function verification_label(array $details, ?string $source_type, ?string $name, ?string $source_url): string
{
    if ($source_type === 'Employer careers') {
        return "Company's own careers site";
    }
    if (!empty($details['ats_posting'])) {
        return "Posted on the company's own " . ucwords((string) ($details['ats_posting']['system'] ?? '')) . ' hiring board';
    }
    $site = $details['employer_site'] ?? null;
    if ($site && !empty($site['domain']) && get_domain((string) $source_url) === $site['domain']) {
        return "Posted on the company's own site";
    }
    if ($site) {
        return !empty($site['posting_found']) ? "Listed on the company's website" : 'Company website found; this job is not listed there';
    }
    if ($source_url && !is_third_party($source_url, $name)) {
        return "Posted on the company's own site";
    }
    return 'No company website found; not verified';
}

/** True when the page is on the company's own site: a name match, or a subdomain of its verified website. */
function on_company_site(?string $url, ?string $name, array $details): bool
{
    $host = get_domain((string) $url);
    $site = $details['employer_site']['domain'] ?? '';
    return (bool) $url && (!is_third_party((string) $url, $name) || ($site && ($host === $site || str_ends_with($host, ".$site"))));
}

// --- Closed reposts ------------------------------------------------------------------------------------------

/** Why a job board listing is closed (its Apply link leads to an expired notice), or null if it looks open. */
function apply_link_closed(Page $page): ?array
{
    $page_host = strtolower((string) parse_url($page->url, PHP_URL_HOST));
    $noise = '/sentry|google|gstatic|facebook|twitter|doubleclick|cloudflare|schema\.org|w3\.org|amazonaws|jsdelivr|cdn\.|linkedin\.com\/(?:share|sharing)|\.(?:png|jpe?g|gif|svg|webp|css|js|ico|woff2?)(?:\?|$)/i';
    $targets = [];
    $add = function (?string $candidate) use (&$targets, $page, $page_host, $noise) {
        if (!$candidate) {
            return;
        }
        $url = url_join($page->url, html_entity_decode(str_replace(['\\/', '\\u0026'], ['/', '&'], $candidate)));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (is_valid_url($url) && $host !== '' && $host !== $page_host && !preg_match($noise, $url) && !in_array($url, $targets, true)) {
            $targets[] = $url;
        }
    };
    $label = "/^\\s*apply(?:\\s+(?:now|here|online|today|externally|for (?:this )?(?:job|position)|on (?:the )?(?:company|employer)(?:'s)? (?:site|website|page)))?\\s*[.!]?\\s*$/i";
    foreach ($page->all('//a | //button') as $tag) {
        if (preg_match($label, node_text($tag))) {
            foreach (['href', 'data-href', 'data-url', 'data-apply-url', 'formaction'] as $attribute) {
                $add($tag->getAttribute($attribute) ?: null);
            }
        }
    }
    preg_match_all('#https?://[^\s"\'<>\\\\]+#', str_replace(['\\/', '\\u0026'], ['/', '&'], $page->html), $m);
    foreach ($m[0] as $url) {
        if (preg_match('/redirect|apply|outbound|external|goto|click|track|jobid/i', parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))) {
            $add($url);
        }
    }
    $closed_url = '/expired|no[-_]?longer|job[-_]?closed|position[-_]?filled|job[-_]?not[-_]?found/i';
    $closed_text = '/no longer (?:available|accepting|open)|(?:job|position|posting|listing|opening|vacancy|role) (?:is|has been|was|has) (?:closed|filled|expired|removed|withdrawn|cancelled|canceled)|this (?:job|position|posting) (?:has )?expired|applications? (?:are|is) (?:now )?closed/i';
    foreach (array_slice($targets, 0, 2) as $target) {
        $current = $target;
        $response = null;
        for ($hop = 0; $hop <= 5; $hop++) {
            wait_for_host($current);
            try {
                $response = http_request($current, ['follow' => false, 'timeout' => (int) tuning('website_timeout_seconds')]);
            } catch (Throwable $e) {
                $response = null;
                break;
            }
            $location = $response['headers']['location'] ?? null;
            if (in_array($response['status'], [301, 302, 303, 307, 308], true) && $location) {
                $current = url_join($current, $location);
                if (preg_match($closed_url, parse_url($current, PHP_URL_PATH) . '?' . parse_url($current, PHP_URL_QUERY))) {
                    return ['closed' => true, 'reason' => 'its Apply link redirects to an expired-job page', 'url' => $current, 'apply_link' => $target];
                }
                continue;
            }
            break;
        }
        if ($response === null) {
            continue;
        }
        if (in_array($response['status'], [404, 410], true)) {
            return ['closed' => true, 'reason' => "its Apply link leads to HTTP {$response['status']}", 'url' => $current, 'apply_link' => $target];
        }
        if ($response['status'] === 200) {
            $landing = new Page($current, $response['body']);
            $title = $landing->title();
            $text = mb_substr($landing->text(), 0, 1500);
            if (preg_match($closed_text, $title) || preg_match($closed_text, $text) || preg_match('/\bexpired\b/i', $title)) {
                return ['closed' => true, 'reason' => 'its Apply link leads to a page saying the job is no longer available',
                    'url' => $current, 'apply_link' => $target];
            }
        }
    }
    return null;
}
