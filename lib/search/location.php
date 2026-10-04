<?php
// Is a posting in the United States and in which state, is the work remote, hybrid or on-site, which states a
// remote job is limited to, and how far it is from the cities you chose. Ported from the desktop app's
// usa_location.py, remote_states.py and geo.py. Places are found in the bundled Census data first; only a place
// it doesn't know is looked up on OpenStreetMap's Nominatim (and remembered).

declare(strict_types=1);

require_once __DIR__ . '/listings.php';
require_once APP_ROOT . '/lib/places.php';

const US_STATES = ['alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
    'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
    'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
    'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI',
    'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
    'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC',
    'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA',
    'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX',
    'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
    'wisconsin' => 'WI', 'wyoming' => 'WY'];
// Abbreviations that are also credentials or words ("Jane Doe, MD"), so whole-page text needs more than a comma.
const AMBIGUOUS_ABBREVIATIONS = ['MD', 'PA', 'MA'];
// Abbreviations that are also ordinary words (IN, OR, ME...) only count right after a comma or bracket.
const WORD_LIKE_STATES = ['IN', 'OR', 'ME', 'HI', 'OK', 'OH'];

function state_codes(): array
{
    return array_values(US_STATES);
}

function state_name_pattern(bool $with_dc = false): string
{
    $names = array_keys(US_STATES);
    if ($with_dc) {
        $names[] = 'district of columbia';
    }
    usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($n) => preg_quote($n, '/'), $names));
}

function state_code_pattern(array $without = [], bool $with_dc = false): string
{
    $codes = array_diff(state_codes(), $without);
    if ($with_dc) {
        $codes[] = 'DC';
    }
    sort($codes);
    return implode('|', $codes);
}

/** True when the words just before a city read like a place ("Location: Towson", "office in Towson"). */
function has_location_cue(string $before): bool
{
    return (bool) preg_match('/(?:location|located|office|address|based in|campus|headquarter\w*)\b[^.]{0,45}$/i', mb_substr($before, -90));
}

/** The first U.S. state named in the text. strict is for whole-page text ("Jane Doe, MD" is a doctor). */
function find_state_from_text(?string $text, bool $strict = false): ?string
{
    $text = (string) $text;
    if (preg_match('/\b(?:' . state_name_pattern() . ')\b/', mb_strtolower($text), $m)) {
        return US_STATES[$m[0]];
    }
    $codes = state_code_pattern();
    if (preg_match_all("/,\\s*($codes)\\b|\\b($codes)\\s+\\d{5}(?:-\\d{4})?\\b/", $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $after_comma = isset($match[1]) && $match[1][1] >= 0 && $match[1][0] !== '';
            $code = $after_comma ? $match[1][0] : $match[2][0];
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            if ($strict && $after_comma && in_array($code, AMBIGUOUS_ABBREVIATIONS, true)
                && !has_location_cue(substr($text, 0, $start)) && !preg_match('/^\s+\d{5}\b/', substr($text, $end))) {
                continue;
            }
            return $code;
        }
    }
    return null;
}

// --- Remote jobs limited to some states --------------------------------------------------------------------

const RESIDENCY_TRIGGER = '/(?:must|need to|required to|have to|should)\s+(?:currently\s+)?(?:reside|live|be\s+(?:located|based|a\s+resident))|residents?\s+of|resident\s+in|(?:candidates|applicants)\s+(?:located\s+)?(?:in|from)\s+[A-Z]|open\s+only\s+to|only\s+(?:open|available)\s+to|based\s+in\s+[A-Z][A-Za-z ]{2,20}\s+only|residents?\s+only|out[- ]of[- ]state|in[- ]state\s+(?:candidates|residents|only)/i';
const OUT_OF_STATE = '/out[- ]of[- ]state|in[- ]state\s+(?:candidates|residents|only)/i';
const REMOTE_LABEL = '(?:work(?:ing)?[ -]*(?:at|from)[ -]*home|remote|virtual|telecommut\w*|telework\w*|wfh|home[ -]*based)';

function state_code_of(string $word): string
{
    $lower = mb_strtolower($word);
    return $lower === 'district of columbia' ? 'DC' : (US_STATES[$lower] ?? strtoupper($word));
}

/** States a remote job says its applicant must live in; "cannot be out of state" with none named means its own. */
function remote_state_restrictions(?string $text, ?string $job_state = null, array $places = []): array
{
    $text = mb_substr((string) $text, 0, 8000);
    $names = state_name_pattern();
    $restricted = [];
    $abbr = '/[,(]\s*(' . state_code_pattern() . ')\b|\b(' . state_code_pattern(WORD_LIKE_STATES) . ')\b/';
    if (preg_match_all(RESIDENCY_TRIGGER, $text, $triggers, PREG_OFFSET_CAPTURE)) {
        foreach ($triggers[0] as [$found, $offset]) {
            $window = substr($text, $offset, strlen($found) + 90);
            $named = [];
            if (preg_match_all("/\\b(?:$names)\\b/i", $window, $m)) {
                foreach ($m[0] as $name) {
                    $named[US_STATES[mb_strtolower($name)]] = true;
                }
            }
            if (preg_match_all($abbr, $window, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $named[($hit[1] ?? '') !== '' ? $hit[1] : $hit[2]] = true;
                }
            }
            if ($named) {
                $restricted += $named;
            } elseif ($job_state && preg_match(OUT_OF_STATE, $window)) {
                $restricted[$job_state] = true;
            }
        }
    }
    return array_keys($restricted + array_flip(restriction_states($places, $text)));
}

function is_remote_place(?string $place): bool
{
    return (bool) preg_match('/\b' . REMOTE_LABEL . '\b/i', (string) $place);
}

/** State codes named by work-from-home location strings ("Work At Home-Florida", "Remote - TX"). */
function place_states(array $places): array
{
    $state = '(?:' . state_name_pattern(true) . '|' . state_code_pattern([], true) . ')';
    $found = [];
    foreach ($places as $place) {
        foreach (['/\b' . REMOTE_LABEL . "\\s*(?:[-–—:,\\/(]|\\bin\\b)\\s*($state)\\b\\)?/iu",
                     "/(?<![A-Za-z])($state)\\s*[-–—:,\\/(]\\s*" . REMOTE_LABEL . '\b/iu'] as $pattern) {
            if (preg_match_all($pattern, (string) $place, $m)) {
                foreach ($m[1] as $word) {
                    $found[state_code_of($word)] = true;
                }
            }
        }
    }
    return array_keys($found);
}

/** State codes in a sentence such as "open to candidates in the following states: CA, NY, TX". */
function list_states(?string $text): array
{
    $text = mb_substr((string) $text, 0, 8000);
    $lead = '/(?:hiring|hire|hires|eligible|approved|open|available|residents?|candidates|applicants)\s+(?:only\s+)?(?:in|to|for|from|of)\s+(?:the\s+)?(?:following|these|listed)\s+states?[^:.\n]{0,40}[:\-]|(?:remote|work(?:ing)? from home)[^.\n]{0,50}\b(?:in|within)\s+(?:the\s+)?(?:following\s+)?states?\s*(?:of\s*)?[:\-]|(?:eligible|approved)\s+(?:states?|locations?)\s*[:\-]/i';
    $found = [];
    if (preg_match_all($lead, $text, $leads, PREG_OFFSET_CAPTURE)) {
        foreach ($leads[0] as [$hit, $offset]) {
            if (preg_match('/salary|\bpay\b|compensation|wage|range|\brate\b|bonus|benefit/i', substr($text, max(0, $offset - 90), min(90, $offset) + strlen($hit)))) {
                continue; // pay-transparency lists name states without limiting who can apply
            }
            $window = substr($text, $offset + strlen($hit), 260);
            $window = preg_split('/\n\s*\n|\.\s+[A-Z]/', $window)[0];
            $any = '/\b(' . state_name_pattern(true) . ')\b|(?<![A-Za-z])(' . state_code_pattern([], true) . ')(?![A-Za-z])/i';
            if (preg_match_all($any, $window, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $found[state_code_of(($match[1] ?? '') !== '' ? $match[1] : $match[2])] = true;
                }
            }
        }
    }
    return array_keys($found);
}

function restriction_states(array $places = [], string $text = ''): array
{
    return array_values(array_unique(array_merge(place_states($places), list_states($text))));
}

/** Every state the user selected, from the state box, statewide picks and city names. */
function selected_state_codes(string $state_text, array $cities, array $statewide): array
{
    $codes = array_flip($statewide);
    $parts = preg_split('/[,\/;]/', $state_text);
    foreach ($cities as $item) {
        if (is_array($item)) {
            $pieces = explode(',', (string) ($item['city'] ?? ''));
            $parts[] = end($pieces);
        }
    }
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if (isset(US_STATES[mb_strtolower($part)])) {
            $codes[US_STATES[mb_strtolower($part)]] = true;
        } elseif (in_array(strtoupper($part), state_codes(), true)) {
            $codes[strtoupper($part)] = true;
        }
    }
    return array_keys($codes);
}

// --- Is it in the United States? ----------------------------------------------------------------------------

/** Wording that names a state without saying where the job is (incorporation, laws, newspapers, office lists). */
function state_boilerplate(): string
{
    return "/\\b[Aa]n?\\s+[A-Z][a-z]+(?:\\s[A-Z][a-z]+)?\\s+(?i:corporation|company|limited liability company|llc|nonprofit|non-profit|partnership)"
        . "|(?i:incorporated|organized|registered|chartered|headquartered|domiciled)\\s+(?i:in|under the laws of)\\s+(?i:the\\s+state\\s+of\\s+)?[A-Z][a-z]+(?:\\s[A-Z][a-z]+)?"
        . "|\\b[A-Z][a-z]+(?:\\s[A-Z][a-z]+)?\\s+(?:Consumer\\s+Privacy|Privacy|Fair\\s+Chance|Fair\\s+Employment|Pay\\s+Transparency|Equal\\s+Pay|Paid\\s+Sick|Human\\s+Rights|Civil\\s+Rights|Workers'?\\s+Compensation)\\b"
        . "|\\b(?:Washington|New\\s+York)\\s+(?:Post|Times|Examiner|Mutual|Life|Yankees|Mets|Giants|Jets|Knicks|Rangers|Nationals|Capitals|Wizards|Commanders|Magazine)\\b"
        . "|\\bIndiana\\s+Jones\\b|\\bVirginia\\s+Woolf\\b|\\bKansas\\s+City\\b"
        . "|\\b(?i:offices)\\b\\s*(?i:in|include|located in|:)?\\s*[^.]{0,120}/u";
}

function inspect_json_ld(Page $page): array
{
    $score = 0;
    $state = null;
    $evidence = [];
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $obj) {
            $address = $obj['address'] ?? null;
            if (is_array($address) && !array_is_list($address)) {
                $country = strtolower(trim(ld_plain($address['addressCountry'] ?? '')));
                $region = strtoupper(trim(ld_plain($address['addressRegion'] ?? '')));
                if (in_array($country, ['us', 'usa', 'united states', 'united states of america'], true)) {
                    $score += 5;
                    $evidence[] = 'structured addressCountry=US';
                }
                if (in_array($region, state_codes(), true)) {
                    $state = $region;
                    $score += 3;
                    $evidence[] = "structured addressRegion=$region";
                }
            }
            if (ld_has_type($obj, ['jobposting'])) {
                $raw = strtolower(json_encode($obj['jobLocation'] ?? $obj));
                if (str_contains($raw, 'united states') || str_contains($raw, '"us"') || str_contains($raw, '"usa"')) {
                    $score += 4;
                    $evidence[] = 'JobPosting location indicates US';
                }
            }
        }
    }
    return [$score, $state, $evidence];
}

/** {country, state, score (0-10), evidence} for how clearly a page is a U.S. job. */
function analyze_usa_location(Page $page, string $extra_text = '', string $label = 'page'): array
{
    $location_text = $extra_text;
    $body = preg_replace(state_boilerplate(), ' ', $page->body_text());
    $combined = trim("$location_text $body");
    $lower = mb_strtolower($combined);
    $evidence = [];
    $country = null;
    $score = 0;
    $listing_says_us = (bool) preg_match('/(?<![A-Za-z])U\.?S\.?A?(?![A-Za-z])/', $location_text);
    if (str_contains($lower, 'united states') || preg_match('/\busa\b|\bu\.s\.a?\.?\b/', $lower) || $listing_says_us) {
        $score += 4;
        $country = 'United States';
        $evidence[] = "$label: U.S. country mention (+4)";
    }
    $state = find_state_from_text($location_text) ?? find_state_from_text($body, true);
    if ($state) {
        $score += 3;
        $country = 'United States';
        $evidence[] = "$label: state $state (+3)";
    } elseif (preg_match('/remote\s*[-–—,\/|]?\s*(?:us|usa|united states)|(?:us|usa|united states)\s*[-–—,\/|]?\s*remote/u', $lower)) {
        $state = 'US Remote';
        $country = 'United States';
        $score += 2;
        $evidence[] = "$label: U.S. remote role (+2)";
    }
    if (preg_match('/\b(?:' . state_code_pattern() . ')\s+\d{5}(?:-\d{4})?\b|(?i:\bzip(?: code)?)\s*:?\s*\d{5}\b/', $combined)) {
        $score += 1;
        $evidence[] = "$label: ZIP pattern (+1)";
    }
    if (str_ends_with(get_domain($page->url), '.edu')
        && preg_match("/\\b[A-Za-z][A-Za-z .'-]{1,45},\\s*(" . state_code_pattern() . ")\\s+\\d{5}(?:-\\d{4})?\\b/", $combined, $m)) {
        $score = max($score, 8);
        $state = $m[1];
        $country = 'United States';
        $evidence[] = "$label: .edu page with U.S. campus address (at least 8)";
    }
    [$json_score, $json_state, $json_evidence] = inspect_json_ld($page);
    if ($json_score) {
        $score += 2;
        $evidence = array_merge($evidence, $json_evidence);
    }
    if ($json_state) {
        $state = $json_state;
        $country = 'United States';
    }
    $score = min($score, 10);
    if ($score > 0 && $country === null) {
        $country = 'Possible United States';
    }
    return ['country' => $country, 'state' => $state, 'score' => $score, 'evidence' => $evidence];
}

function merge_location_data(array $base, array $incoming): array
{
    if ($incoming['score'] > $base['score']) {
        $base['country'] = $incoming['country'] ?: $base['country'];
    }
    if (!empty($incoming['state']) && empty($base['state'])) {
        $base['state'] = $incoming['state'];
    }
    if (($incoming['country'] ?? null) === 'United States') {
        $base['country'] = 'United States';
    }
    $base['score'] = min(10, max($base['score'], $incoming['score']));
    $base['evidence'] = array_merge($base['evidence'] ?? [], $incoming['evidence'] ?? []);
    return $base;
}

/** Remote, Hybrid or Onsite when the title or the page states it plainly; null otherwise. */
function detect_work_arrangement(string $job_title = '', ?Page $page = null): ?string
{
    if ($job_title !== '') {
        $types = arrangement_types($job_title);
        if (count($types) > 1) {
            return null;
        }
        if ($types) {
            return $types[0];
        }
    }
    if ($page === null || $page->html === '') {
        return null;
    }
    foreach ($page->all("//script[@type='application/ld+json']") as $script) {
        if (preg_match('/"jobLocationType"\s*:\s*"TELECOMMUTE"/i', $script->textContent)) {
            return 'Remote';
        }
    }
    preg_match_all('/\b(?:work (?:arrangement|location|model)|workplace|location type|this (?:role|position|job) is)\s*[:\-]?\s*([^.;]{0,75})/i',
        $page->text(), $m);
    $types = arrangement_types(implode(' ', $m[1]));
    return count($types) === 1 ? $types[0] : null;
}

// --- Distance from your cities ------------------------------------------------------------------------------

const ZIP_CODE = '/^\d{5}(?:-\d{4})?$/';
const REGION_ALIASES = ['dmv' => 'Washington, DC', 'dc metro' => 'Washington, DC', 'washington dc' => 'Washington, DC',
    'national capital' => 'Washington, DC', 'tri-state' => null, 'delmarva' => null];

/** "Greater Baltimore Area" -> "Baltimore"; "DMV" -> "Washington, DC". */
function clean_region_name(?string $place): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $place));
    $words = '/\b(?:greater|metropolitan|metro|area|region|metroplex)\b/i';
    $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', trim(preg_replace($words, ' ', $text), " ,-"))));
    if (array_key_exists($key, REGION_ALIASES)) {
        return REGION_ALIASES[$key] ?? $text;
    }
    if (preg_match($words, $text) && $key !== '') {
        return trim(preg_replace('/\s+/', ' ', preg_replace($words, ' ', $text)), " ,-");
    }
    return $text;
}

/** [lat, lon, display name] for a place: the bundled Census data first, then Nominatim (remembered). */
function geocode_location(string $query, bool $require_place_match = false): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }
    // A ZIP code, or "City, ST", from the bundled Census files: no lookup online.
    if (preg_match('/^(\d{5})/', $query, $zip) && ($point = zip_point($zip[1]))) {
        return ['lat' => $point[0], 'lon' => $point[1], 'display_name' => $zip[1]];
    }
    $point = place_point($query);
    if ($point) {
        return ['lat' => $point[0], 'lon' => $point[1], 'display_name' => $query];
    }
    $key = mb_substr(mb_strtolower(preg_replace('/\s+/', ' ', $query)), 0, 240) . ' [v2]';
    $cached = setting_json('geocode_cache', []);
    if (array_key_exists($key, $cached)) {
        $hit = $cached[$key];
        if (!$hit || ($require_place_match && !place_matches($query, $hit['display_name'] ?? ''))) {
            return null;
        }
        return $hit;
    }
    static $last = 0.0;
    $wait = 1.05 - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int) ($wait * 1e6));
    }
    $last = microtime(true);
    $result = null;
    try {
        $body = http_request('https://nominatim.openstreetmap.org/search?' . http_build_query(
            ['q' => $query, 'format' => 'jsonv2', 'limit' => 5, 'countrycodes' => 'us']), [
            'headers' => ['User-Agent: JobFinderWeb/' . APP_VERSION . ' (https://github.com/jltkerig/job-finder-dashboard)'],
            'timeout' => 15]);
        $found = json_decode($body['status'] === 200 ? $body['body'] : '[]', true) ?: [];
        if ($found) {
            $ranks = ['city' => 0, 'town' => 0, 'administrative' => 0, 'municipality' => 1, 'borough' => 1, 'suburb' => 2,
                'census_designated_place' => 2, 'village' => 3, 'hamlet' => 4, 'statistical' => 5, 'neighbourhood' => 5];
            usort($found, fn($a, $b) => [$ranks[$a['type'] ?? ''] ?? 4, -(float) ($a['importance'] ?? 0)]
                <=> [$ranks[$b['type'] ?? ''] ?? 4, -(float) ($b['importance'] ?? 0)]);
            $result = ['lat' => (float) $found[0]['lat'], 'lon' => (float) $found[0]['lon'],
                'display_name' => mb_substr((string) ($found[0]['display_name'] ?? ''), 0, 1000)];
        }
    } catch (Throwable $e) {
        return null; // not remembered: tried again next time
    }
    $cached[$key] = $result;
    if (count($cached) > 3000) {
        $cached = array_slice($cached, -3000, null, true);
    }
    set_setting_json('geocode_cache', $cached);
    if ($result && $require_place_match && !place_matches($query, $result['display_name'])) {
        return null;
    }
    return $result;
}

/** True when the map result is the place that was asked for, not a road or a similar name elsewhere. */
function place_matches(string $query, string $display_name): bool
{
    $name = function (string $value): string {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\bst\.? /', 'saint ', $value);
        $value = preg_replace('/\bmt\.? /', 'mount ', $value);
        return preg_replace('/\bft\.? /', 'fort ', $value);
    };
    $city = $name(explode(',', $query)[0]);
    $first = $name(explode(',', $display_name)[0]);
    if ($city === '' || $first === '') {
        return false;
    }
    if ($first === $city || in_array($first, ["city of $city", "town of $city", "village of $city"], true)) {
        return true;
    }
    foreach (['city', 'town', 'village', 'township', 'borough', 'county', 'cdp'] as $suffix) {
        if ($first === "$city $suffix") {
            return true;
        }
    }
    return false;
}

/** What to look up for a typed location: a ZIP code, "City, ST", or a city with each searched state. */
function geocode_queries(string $city, string $state_text): array
{
    $city = trim($city);
    if (preg_match(ZIP_CODE, $city)) {
        return [substr($city, 0, 5)];
    }
    if (str_contains($city, ',')) {
        return [$city];
    }
    $states = array_filter(array_map('trim', preg_split('/[,\/;]/', $state_text)), 'strlen');
    return $states ? array_map(fn($s) => "$city, $s", $states) : [$city];
}

/** The cities you chose as {city, radius, lat, lon}; states are statewide picks, not centers. */
function prepare_city_targets(string $state, array $cities): array
{
    $targets = [];
    foreach ($cities as $item) {
        $city = trim((string) ($item['city'] ?? ''));
        $radius = (int) ($item['radius'] ?? $item['radius_miles'] ?? 50);
        if ($city === '' || !in_array($radius, CITY_RADII, true) || isset(US_STATES[mb_strtolower($city)])) {
            continue;
        }
        foreach (geocode_queries($city, $state) as $query) {
            $point = geocode_location($query);
            if ($point) {
                $targets[] = ['city' => $city, 'radius' => $radius, 'lat' => $point['lat'], 'lon' => $point['lon']];
                break;
            }
        }
    }
    return $targets;
}

/** [city, region] where the job is: the JobPosting's location, any structured address, then "City, ST" in the text. */
function extract_job_city(Page $page, string $fallback_text = '', bool $allow_footer = false): array
{
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $obj) {
            if (!ld_has_type($obj, ['jobposting'])) {
                continue;
            }
            foreach (json_nodes($obj['jobLocation'] ?? $obj['applicantLocationRequirements'] ?? []) as $location) {
                $address = $location['address'] ?? null;
                if (is_array($address) && trim(ld_plain($address['addressLocality'] ?? '')) !== '') {
                    return [trim(ld_plain($address['addressLocality'])), trim(ld_plain($address['addressRegion'] ?? ''))];
                }
            }
        }
    }
    foreach ($page->json_ld() as $data) {
        foreach (json_nodes($data) as $obj) {
            $address = $obj['address'] ?? null;
            if (is_array($address) && !array_is_list($address) && trim(ld_plain($address['addressLocality'] ?? '')) !== '') {
                return [trim(ld_plain($address['addressLocality'])), trim(ld_plain($address['addressRegion'] ?? ''))];
            }
        }
    }
    $noise = ['contact', 'email', 'call', 'address', 'location', 'located', 'office', 'offices', 'visit', 'our', 'at', 'in',
        'based', 'dr', 'mr', 'ms', 'mrs', 'team', 'meet', 'join', 'apply', 'posted', 'nw', 'ne', 'sw', 'se', 'north', 'south',
        'east', 'west'];
    $trim = function (string $city) use ($noise): string {
        $words = explode(' ', $city);
        while ($words && in_array(rtrim(mb_strtolower($words[0]), '.'), $noise, true)) {
            array_shift($words);
        }
        return implode(' ', $words);
    };
    $full_text = $allow_footer ? $page->text() : '';
    $body = preg_replace('/\bD\.\s?C\.?(?=\s*\d{5}\b|\s|$)/', 'DC', $page->body_text());
    $codes = state_codes();
    $candidates = [];
    foreach ([0 => $fallback_text, 1 => $body] as $source => $text) {
        if (!preg_match_all("/\\b([A-Z][A-Za-z'.]+(?:[ -][A-Z][A-Za-z'.]+){0,2}),\\s*([A-Z]{2})\\b(\\s+\\d{5})?/", $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $match) {
            $code = $match[2][0];
            if (!in_array($code, $codes, true) && $code !== 'DC') {
                continue;
            }
            $city = $trim($match[1][0]);
            if ($city === '') {
                continue;
            }
            $start = $match[0][1];
            $rank = [$source, isset($match[3]) && $match[3][0] !== '' ? 0 : 1, has_location_cue(substr($text, 0, $start)) ? 0 : 1, $start];
            $candidates[] = [$rank, $city, $code];
        }
    }
    if ($candidates) {
        usort($candidates, fn($a, $b) => $a[0] <=> $b[0]);
        return [$candidates[0][1], $candidates[0][2]];
    }
    // On the employer's own site, the street address in the footer is where the office is.
    if ($allow_footer) {
        $full_text = preg_replace('/\bD\.\s?C\.?(?=\s*\d{5}\b)/', 'DC', $full_text);
        if (preg_match_all("/\\b([A-Z][A-Za-z'.]+(?:[ -][A-Z][A-Za-z'.]+){0,2}),\\s*([A-Z]{2})\\s+\\d{5}\\b/", $full_text, $m, PREG_SET_ORDER)) {
            $usable = array_values(array_filter($m, fn($x) => in_array($x[2], $codes, true) || $x[2] === 'DC'));
            if ($usable) {
                $last = end($usable);
                $city = $trim($last[1]);
                if ($city !== '') {
                    return [$city, $last[2]];
                }
            }
        }
    }
    return [null, null];
}

/** [within radius, city, lat, lon, miles] for the nearest of your cities. */
function distance_to_city_targets(Page $page, string $fallback_text, string $state, array $targets, bool $allow_footer = false): array
{
    if (!$targets) {
        return [true, null, null, null, null];
    }
    [$city, $detected_state] = extract_job_city($page, $fallback_text, $allow_footer);
    if (!$city) {
        return [false, null, null, null, null];
    }
    $city = clean_region_name($city);
    if (str_contains($city, ',')) {
        [$city, $alias_state] = array_map('trim', explode(',', $city, 2));
        $detected_state = $alias_state ?: $detected_state;
    }
    $point = geocode_location("$city, " . ($detected_state ?: $state), true);
    if (!$point) {
        return [false, $city, null, null, null];
    }
    $best = null;
    foreach ($targets as $target) {
        $distance = miles_between([$point['lat'], $point['lon']], [$target['lat'], $target['lon']]);
        $best = $best === null ? $distance : min($best, $distance);
        if ($distance <= $target['radius']) {
            return [true, $city, $point['lat'], $point['lon'], round($distance, 2)];
        }
    }
    return [false, $city, $point['lat'], $point['lon'], $best === null ? null : round($best, 2)];
}
