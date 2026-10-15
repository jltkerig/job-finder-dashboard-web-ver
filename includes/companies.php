<?php
// The Companies page: businesses near a place (OpenStreetMap through Nominatim), the employers Job Finder has seen
// hiring, and the companies you enter yourself (kept in the settings table). An employer from your résumé shares its
// address, phone and website with that job in your Profile. Ported from the desktop app's local_companies.py,
// my_companies.py, employer_sync.py and address_lookup.py.

declare(strict_types=1);

require_once __DIR__ . '/places.php';
require_once __DIR__ . '/search/http.php';

const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const PHOTON_URL = 'https://photon.komoot.io/api/';
const COMPANIES_AGENT = 'JobFinder/1.0 (personal job search tool; companies near a place)';
const ADDRESS_AGENT = 'JobFinder/1.0 (personal job search tool; address suggestions)';
const COMPANY_FIELD_LIMITS = ['name' => 150, 'website' => 200, 'jobs_board' => 400, 'address' => 200, 'place' => 100, 'phone' => 40];
const COMPANY_NOTES_LIMIT = 500;
const MAX_MY_COMPANIES = 1000;
const ADDRESS_PARTS = ['street', 'city', 'state', 'zip'];
const COMPANY_MILE_CHOICES = [5, 10, 15, 25, 50];
const KNOWN_KIND = 'known';
const MINE_KIND = 'mine';

/** Kind key => [label, the words asked for]; shown in this order on the page. */
function company_kinds(): array
{
    return [
        'offices' => ['Offices and companies', ['company', 'insurance', 'office']],
        'staffing' => ['Recruiters and staffing', ['staffing', 'recruiting', 'Randstad', 'Aerotek', 'TEKsystems', 'Manpower']],
        'finance' => ['Banks', ['bank']],
        'legal' => ['Legal and real estate', ['real estate', 'attorney']],
        'healthcare' => ['Healthcare', ['clinic', 'doctors', 'dentist', 'pharmacy', 'hospital', 'veterinary', 'nursing home']],
        'industry' => ['Industry and trades', ['factory', 'warehouse', 'industrial', 'car repair', 'electrician']],
        'construction' => ['Construction and logistics', ['construction', 'carpenter', 'freight', 'logistics', 'airport']],
        'government' => ['Government and public services', ['fire station', 'post office', 'police', 'library', 'town hall', 'courthouse']],
        'education' => ['Schools and colleges', ['school', 'college', 'university', 'kindergarten']],
        'creative' => ['Design, media and tech', ['design', 'studio', 'gallery', 'photographer', 'printing', 'computer']],
        'auto' => ['Cars and fuel', ['car wash', 'fuel', 'car repair']],
        'personal' => ['Beauty and fitness', ['hairdresser', 'beauty', 'spa', 'fitness', 'gym']],
        'community' => ['Community and care', ['community centre', 'association', 'daycare']],
        'home' => ['Home and garden', ['garden centre', 'florist', 'furniture']],
        'retail' => ['Shops', ['supermarket', 'department store', 'hardware']],
        'food' => ['Food and lodging', ['restaurant', 'hotel', 'cafe', 'bakery']],
    ];
}

const DEFAULT_COMPANY_KINDS = ['offices', 'finance', 'healthcare', 'industry', 'education'];

// ---------- the one place the page searches around ----------

/** The place the page searches around unless another is typed: home, else the home ZIP, else the first saved town. */
function companies_home_place(array $profile): string
{
    foreach ([$profile['home_location'] ?? '', $profile['home_zip'] ?? ''] as $text) {
        if (trim((string) $text) !== '') {
            return trim((string) $text);
        }
    }
    foreach ($profile['cities'] ?? [] as $city) {
        if (trim((string) ($city['city'] ?? '')) !== '') {
            return trim((string) $city['city']);
        }
    }
    return '';
}

/** [[lat, lon], the name to show] for a ZIP or "Town, ST"; blank uses home. null when unknown. */
function resolve_company_place(string $text, array $profile): ?array
{
    $text = trim($text) !== '' ? trim($text) : companies_home_place($profile);
    if ($text === '') {
        return null;
    }
    $state = (string) ($profile['state'] ?? '');
    $point = preg_match('/^\d{5}(-\d{4})?$/', $text) ? zip_point(substr($text, 0, 5)) : place_point($text, $state);
    return $point ? [$point, $text] : null;
}

// ---------- OpenStreetMap places ----------

function company_viewbox(float $lat, float $lon, float $miles): string
{
    $d_lat = $miles / 69.0;
    $d_lon = $miles / (69.17 * max(cos(deg2rad($lat)), 0.2));
    return sprintf('%.5f,%.5f,%.5f,%.5f', $lon - $d_lon, $lat + $d_lat, $lon + $d_lon, $lat - $d_lat);
}

function osm_address(array $address): string
{
    $street = trim(($address['house_number'] ?? '') . ' ' . ($address['road'] ?? ''));
    $town = '';
    foreach (['city', 'town', 'village', 'hamlet', 'suburb'] as $key) {
        if (!empty($address[$key])) {
            $town = $address[$key];
            break;
        }
    }
    $state = (string) ($address['ISO3166-2-lvl4'] ?? '');
    $state = preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $state) ? substr($state, strrpos($state, '-') + 1) : (string) ($address['state'] ?? '');
    $line = implode(', ', array_filter([$street, $town, $state], fn($p) => $p !== ''));
    return !empty($address['postcode']) ? trim("$line {$address['postcode']}") : $line;
}

function company_website(array $extra): string
{
    $raw = trim((string) ($extra['website'] ?? $extra['contact:website'] ?? $extra['url'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }
    return preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $raw) ? $raw : '';
}

/** Companies (nearest first) from Nominatim answers: name, type, address, website, phone, point and miles away. */
function parse_osm_places(array $items, array $center, ?float $miles = null): array
{
    $found = [];
    $seen = [];
    foreach ($items as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '' || !isset($item['lat'], $item['lon']) || !is_numeric($item['lat']) || !is_numeric($item['lon'])) {
            continue;
        }
        $point = [(float) $item['lat'], (float) $item['lon']];
        $away = miles_between($center, $point);
        if ($miles !== null && $away > $miles) {
            continue;
        }
        $address = osm_address($item['address'] ?? []);
        $key = mb_strtolower($name) . '|' . (mb_strtolower($address) ?: sprintf('%.3f,%.3f', $point[0], $point[1]));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $extra = $item['extratags'] ?? [];
        $type = (string) ($item['type'] ?? '');
        if ($type === '' || in_array($type, ['yes', 'unclassified'], true)) {
            $type = (string) ($item['category'] ?? '');
        }
        $found[] = ['id' => ($item['osm_type'] ?? 'node') . '/' . ($item['osm_id'] ?? ''), 'name' => $name,
            'type' => ucfirst(str_replace('_', ' ', $type)), 'address' => $address, 'website' => company_website($extra),
            'phone' => (string) ($extra['phone'] ?? $extra['contact:phone'] ?? ''),
            'lat' => round($point[0], 6), 'lon' => round($point[1], 6), 'miles' => round($away, 1)];
    }
    usort($found, fn($a, $b) => [$a['miles'], mb_strtolower($a['name'])] <=> [$b['miles'], mb_strtolower($b['name'])]);
    return $found;
}

/** Nearby places of the given kinds. One question per word, at most one a second; answers are kept for 6 hours. */
function search_local_companies(float $lat, float $lon, float $miles, array $kinds): array
{
    @set_time_limit(90);
    $all = company_kinds();
    $center = [$lat, $lon];
    $box = company_viewbox($lat, $lon, $miles);
    $merged = [];
    $answered = 0;
    $asked = 0;
    foreach ($kinds as $kind) {
        foreach ($all[$kind][1] ?? [] as $word) {
            $key = 'osm|' . round($lat, 2) . '|' . round($lon, 2) . '|' . $miles . '|' . $word;
            $cached = companies_cache_get($key);
            if ($cached === null) {
                $asked++;
                $items = nominatim_ask($word, $box);
                if ($items === null) {
                    continue;  // this word failed: the others still count
                }
                companies_cache_put($key, $items);
            } else {
                $items = $cached;
            }
            $answered++;
            $merged = array_merge($merged, $items);
        }
    }
    if ($answered === 0 && $asked > 0) {
        throw new RuntimeException("OpenStreetMap's service did not answer. Try again in a minute.");
    }
    return parse_osm_places($merged, $center, $miles);
}

/** One Nominatim question; null when the service did not answer. Keeps a second between questions. */
function nominatim_ask(string $word, string $box): ?array
{
    static $last = 0.0;
    $wait = 1.1 - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int) ($wait * 1000000));
    }
    $url = NOMINATIM_URL . '?' . http_build_query(['q' => $word, 'format' => 'jsonv2', 'viewbox' => $box, 'bounded' => 1,
        'limit' => 40, 'addressdetails' => 1, 'extratags' => 1]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $last = microtime(true);
        try {
            $response = http_request($url, ['agent' => COMPANIES_AGENT, 'timeout' => 20, 'headers' => ['Accept: application/json']]);
        } catch (Throwable $error) {
            return null;
        }
        if ($response['status'] === 429 && $attempt === 0) {
            sleep(5);
            continue;
        }
        if ($response['status'] !== 200) {
            return null;
        }
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }
    return null;
}

function companies_cache_get(string $key): ?array
{
    $stored = setting_json('companies_cache', []);
    $entry = $stored[md5($key)] ?? null;
    return is_array($entry) && ($entry['at'] ?? 0) > time() - 6 * 3600 ? $entry['items'] : null;
}

function companies_cache_put(string $key, array $items): void
{
    $stored = setting_json('companies_cache', []);
    $cutoff = time() - 6 * 3600;
    $stored = array_filter($stored, fn($entry) => is_array($entry) && ($entry['at'] ?? 0) > $cutoff);
    if (count($stored) > 120) {
        $stored = array_slice($stored, -60, null, true);
    }
    $stored[md5($key)] = ['at' => time(), 'items' => $items];
    set_setting_json('companies_cache', $stored);
}

// ---------- employers Job Finder has seen hiring ----------

function known_employers(float $lat, float $lon, float $miles, int $limit = 400): array
{
    $degrees = $miles / 69.0 + 0.02;
    $cos = max(cos(deg2rad($lat)), 0.2);
    $rows = rows("SELECT id, name, domain, city, state, latitude, longitude, career_job_title FROM companies
        WHERE is_rejected = 0 AND name IS NOT NULL AND name <> '' AND latitude IS NOT NULL AND longitude IS NOT NULL
          AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ? ORDER BY date_found DESC",
        [$lat - $degrees, $lat + $degrees, $lon - $degrees / $cos, $lon + $degrees / $cos]);
    $found = [];
    foreach ($rows as $row) {
        $away = miles_between([$lat, $lon], [(float) $row['latitude'], (float) $row['longitude']]);
        if ($away > $miles) {
            continue;
        }
        $key = mb_strtolower((string) $row['name']) . '|' . mb_strtolower((string) ($row['city'] ?? ''));
        if (isset($found[$key])) {
            continue;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) ($row['domain'] ?? '')));
        $own_site = $host !== '' && str_contains($host, '.') && !is_third_party('https://' . $host, (string) $row['name']);
        $found[$key] = ['id' => 'known/' . $row['id'], 'name' => $row['name'],
            'type' => $row['career_job_title'] ? mb_substr('Hiring: ' . $row['career_job_title'], 0, 60) : 'Employer',
            'address' => implode(', ', array_filter([$row['city'] ?? '', $row['state'] ?? ''])),
            'website' => $own_site ? "https://$host" : '', 'phone' => '',
            'lat' => round((float) $row['latitude'], 6), 'lon' => round((float) $row['longitude'], 6), 'miles' => round($away, 1)];
    }
    $list = array_values($found);
    usort($list, fn($a, $b) => [$a['miles'], mb_strtolower($a['name'])] <=> [$b['miles'], mb_strtolower($b['name'])]);
    return array_slice($list, 0, $limit);
}

// ---------- your own companies ----------

function my_companies_load(): array
{
    $list = setting_json('my_companies', []);
    return array_values(array_filter(is_array($list) ? $list : [], fn($item) => is_array($item) && !empty($item['id']) && !empty($item['name'])));
}

function my_companies_save(array $companies): void
{
    set_setting_json('my_companies', array_values($companies));
}

function company_name_key(string $name): string
{
    $text = preg_replace('/\b(?:inc|incorporated|llc|ltd|corp|corporation|co|company)\b\.?/i', ' ', mb_strtolower($name));
    return preg_replace('/[^a-z0-9]+/', '', $text);
}

function same_company_name(string $first, string $second): bool
{
    $key = company_name_key($first);
    return $key !== '' && $key === company_name_key($second);
}

function company_site_address(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $text)) {
        $text = 'https://' . ltrim($text, '/');
    }
    return preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $text) ? $text : '';
}

function company_link(string $text): string
{
    $text = trim($text);
    if ($text === '' || preg_match('/\s/', $text)) {
        return '';
    }
    if (!preg_match('#^https?://#i', $text) && !preg_match('#^(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}(?:[/?\#].*)?$#', $text)) {
        return '';
    }
    return company_site_address($text);
}

function company_point(string $place, string $address, string $home_state = ''): ?array
{
    $place = trim($place);
    if (preg_match('/^\d{5}(-\d{4})?$/', $place)) {
        $point = zip_point(substr($place, 0, 5));
    } else {
        $point = $place !== '' ? place_point($place, $home_state) : null;
    }
    if ($point) {
        return $point;
    }
    return preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $m) ? zip_point($m[1]) : null;
}

/** The stored form of what was typed; throws InvalidArgumentException with a message for the page. */
function clean_company(array $fields, string $home_state = ''): array
{
    $one = fn($v) => trim((string) preg_replace('/\s+/', ' ', (string) $v));
    $values = [];
    foreach (COMPANY_FIELD_LIMITS as $key => $limit) {
        $values[$key] = mb_substr($one($fields[$key] ?? ''), 0, $limit);
    }
    $values['notes'] = mb_substr(trim((string) ($fields['notes'] ?? '')), 0, COMPANY_NOTES_LIMIT);
    $parts = [];
    foreach (ADDRESS_PARTS as $key) {
        $parts[$key] = $one($fields[$key] ?? '');
    }
    if (array_filter($parts) !== []) {  // the form sends the address in four boxes: kept as one line, the town is the place
        $values['address'] = mb_substr(implode(', ', array_filter($parts)), 0, COMPANY_FIELD_LIMITS['address']);
        $values['place'] = mb_substr($parts['city'] !== '' && $parts['state'] !== '' ? "{$parts['city']}, {$parts['state']}" : $parts['zip'],
            0, COMPANY_FIELD_LIMITS['place']);
    }
    if ($values['name'] === '') {
        throw new InvalidArgumentException("Enter the company's name.");
    }
    $raw_site = $values['website'];
    $values['website'] = company_site_address($raw_site);
    if ($raw_site !== '' && $values['website'] === '') {
        throw new InvalidArgumentException("That website doesn't look right. Use an address like acme.com.");
    }
    $values['jobs_board'] = mb_substr($one($fields['jobs_board'] ?? $fields['jobs_url'] ?? ''), 0, COMPANY_FIELD_LIMITS['jobs_board']);
    $point = company_point($values['place'], $values['address'], $home_state);
    if ($values['place'] !== '' && !$point && !preg_match('/\d{5}/', $values['address'])) {
        throw new InvalidArgumentException("That town wasn't found. Use a town and state such as Bel Air, MD, or a 5-digit ZIP code, or leave it empty.");
    }
    $values['lat'] = $point ? round($point[0], 6) : null;
    $values['lon'] = $point ? round($point[1], 6) : null;
    return $values;
}

function my_company_add(array $fields, string $home_state = ''): array
{
    $companies = my_companies_load();
    if (count($companies) >= MAX_MY_COMPANIES) {
        throw new InvalidArgumentException('The list is full (' . MAX_MY_COMPANIES . ' companies). Delete some first.');
    }
    $entry = clean_company($fields, $home_state) + ['id' => substr(bin2hex(random_bytes(8)), 0, 12), 'added' => gmdate('c')];
    $companies[] = $entry;
    my_companies_save($companies);
    return $entry;
}

function my_company_get(string $id): ?array
{
    foreach (my_companies_load() as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

function my_company_update(string $id, array $fields, string $home_state = ''): ?array
{
    $companies = my_companies_load();
    foreach ($companies as $index => $item) {
        if ($item['id'] === $id) {
            $companies[$index] = array_merge($item, clean_company($fields, $home_state));
            my_companies_save($companies);
            return $companies[$index];
        }
    }
    return null;
}

function my_company_delete(string $id): bool
{
    $companies = my_companies_load();
    $kept = array_values(array_filter($companies, fn($item) => $item['id'] !== $id));
    if (count($kept) === count($companies)) {
        return false;
    }
    foreach ($companies as $item) {
        if ($item['id'] === $id && ($item['source'] ?? '') === 'resume') {
            $removed = setting_json('my_companies_removed', []);
            $removed[] = company_name_key((string) $item['name']);
            set_setting_json('my_companies_removed', array_values(array_unique($removed)));
        }
    }
    my_companies_save($kept);
    return true;
}

/** Employers in a work history that are not in the list yet are added, once each (not ones you deleted). */
function sync_my_companies_from_work_history(array $work_history, string $home_state = ''): int
{
    $companies = my_companies_load();
    $have = [];
    foreach ($companies as $item) {
        $have[company_name_key((string) $item['name'])] = true;
    }
    foreach (setting_json('my_companies_removed', []) as $key) {
        $have[(string) $key] = true;
    }
    $added = 0;
    foreach ($work_history as $job) {
        if (!is_array($job)) {
            continue;
        }
        $name = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($job['company'] ?? ''))), 0, COMPANY_FIELD_LIMITS['name']);
        $key = company_name_key($name);
        if ($name === '' || $key === '' || isset($have[$key])) {
            continue;
        }
        $have[$key] = true;
        $town = implode(', ', array_filter([trim((string) ($job['city'] ?? '')), trim((string) ($job['state'] ?? ''))]));
        $zip = trim((string) ($job['zip'] ?? ''));
        $place = $town !== '' && str_contains($town, ',') ? $town : $zip;
        $fields = ['name' => $name, 'website' => $job['website'] ?? '', 'address' => implode(', ', array_filter([trim((string) ($job['street'] ?? '')), $town, $zip])),
            'place' => $place, 'phone' => $job['phone'] ?? '',
            'notes' => 'From your résumé: ' . implode(', ', array_filter([trim((string) ($job['role'] ?? '')), trim((string) ($job['dates'] ?? ''))]))];
        try {
            $entry = clean_company($fields, $home_state);
        } catch (InvalidArgumentException $error) {  // a place or website that can't be read: the company is still worth listing
            $entry = clean_company(['name' => $name, 'notes' => $fields['notes']], $home_state);
        }
        $companies[] = $entry + ['id' => substr(bin2hex(random_bytes(8)), 0, 12), 'source' => 'resume', 'added' => gmdate('c')];
        $added++;
    }
    if ($added) {
        my_companies_save(array_slice($companies, 0, MAX_MY_COMPANIES));
    }
    return $added;
}

/** An entered company as the page lists it: miles from the center, or null when it has no map point. */
function my_company_result(array $item, ?array $center = null): array
{
    $miles = null;
    if ($center !== null && isset($item['lat'], $item['lon']) && $item['lat'] !== null && $item['lon'] !== null) {
        $miles = round(miles_between($center, [(float) $item['lat'], (float) $item['lon']]), 1);
    }
    $board = (string) ($item['jobs_board'] ?? $item['jobs_url'] ?? '');
    $url = company_link($board);
    $system = '';
    if ($url !== '' && function_exists('identify_board') && ($config = identify_board($url))) {
        $system = ucfirst((string) ($config['system'] ?? ''));
    }
    return ['id' => 'mine/' . $item['id'], 'mine' => true, 'name' => $item['name'], 'type' => 'My company',
        'address' => $item['address'] ?? '', 'place' => $item['place'] ?? '', 'website' => $item['website'] ?? '',
        'jobs_board' => $board, 'jobs_url' => $url, 'jobs_system' => $system, 'phone' => $item['phone'] ?? '',
        'notes' => $item['notes'] ?? ''] + split_address((string) ($item['address'] ?? ''), (string) ($item['place'] ?? ''))
        + ['lat' => $item['lat'] ?? null, 'lon' => $item['lon'] ?? null, 'miles' => $miles];
}

/** All of your companies, nearest first when a center is given, then the ones that cannot be measured. */
function my_companies_everything(?array $center = null): array
{
    $results = array_map(fn($item) => my_company_result($item, $center), my_companies_load());
    $measured = array_filter($results, fn($r) => $r['miles'] !== null);
    $unmeasured = array_filter($results, fn($r) => $r['miles'] === null);
    usort($measured, fn($a, $b) => [$a['miles'], mb_strtolower($a['name'])] <=> [$b['miles'], mb_strtolower($b['name'])]);
    usort($unmeasured, fn($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));
    return array_merge($measured, $unmeasured);
}

/** Your companies within miles of a point (nearest first), then the ones with no map point. */
function my_companies_near(float $lat, float $lon, float $miles): array
{
    $results = array_map(fn($item) => my_company_result($item, [$lat, $lon]), my_companies_load());
    $near = array_filter($results, fn($r) => $r['miles'] !== null && $r['miles'] <= $miles);
    $unmeasured = array_filter($results, fn($r) => $r['miles'] === null);
    usort($near, fn($a, $b) => [$a['miles'], mb_strtolower($a['name'])] <=> [$b['miles'], mb_strtolower($b['name'])]);
    usort($unmeasured, fn($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));
    return array_merge($near, $unmeasured);
}

// ---------- sharing address, phone and website with the work history ----------

function state_from_text(string $text): string
{
    $text = rtrim(trim($text), '.');
    if (preg_match('/^[A-Za-z]{2}$/', $text) && isset(STATE_NAMES[strtoupper($text)])) {
        return strtoupper($text);
    }
    foreach (STATE_NAMES as $code => $name) {
        if (strcasecmp($name, $text) === 0) {
            return $code;
        }
    }
    return '';
}

/** The address as the profile keeps it: ['street', 'city', 'state', 'zip'], '' for a part that is not there. */
function split_address(string $address, string $place = ''): array
{
    $parts = array_values(array_filter(array_map('trim', explode(',', str_replace("\n", ',', $address))), fn($p) => $p !== ''));
    $state = $zip = $city = '';
    if ($parts && preg_match('/^(?:(?<state>[A-Za-z][A-Za-z .]*?)\s+)?(?<zip>\d{5})(?:-\d{4})?$/', end($parts), $m)
        && (($m['state'] ?? '') === '' || state_from_text($m['state']) !== '')) {
        $zip = $m['zip'];
        $state = state_from_text($m['state'] ?? '');
        array_pop($parts);
    }
    if ($parts && $state === '' && state_from_text(end($parts)) !== '') {
        $state = state_from_text((string) array_pop($parts));
    }
    $last = $parts ? end($parts) : '';
    $last_is_town = $parts && !preg_match('/^(?:suite|ste\.?|apt\.?|unit|floor|fl\.?|room|rm\.?|bldg\.?|building)\b|^#/i', $last)
        && !preg_match('/^[\d\s#-]+$/', $last);
    if ($last_is_town && (count($parts) >= 2 || (($state !== '' || $zip !== '') && !preg_match('/\d/', $last)))) {
        $city = (string) array_pop($parts);
    }
    $place = trim($place);
    if ($place !== '') {
        if (preg_match('/^\d{5}(-\d{4})?$/', $place)) {
            $zip = $zip !== '' ? $zip : substr($place, 0, 5);
        } elseif (str_contains($place, ',')) {
            $at = strrpos($place, ',');
            $town = trim(substr($place, 0, $at));
            $region = trim(substr($place, $at + 1));
            $city = $city !== '' ? $city : $town;
            $state = $state !== '' ? $state : state_from_text($region);
        }
    }
    return ['street' => implode(', ', $parts), 'city' => $city, 'state' => $state, 'zip' => $zip];
}

function join_address_parts(array $parts): string
{
    return implode(', ', array_filter([$parts['street'] ?? '', $parts['city'] ?? '', $parts['state'] ?? '', $parts['zip'] ?? ''], fn($p) => $p !== ''));
}

function company_details(array $entry): array
{
    return split_address((string) ($entry['address'] ?? ''), (string) ($entry['place'] ?? ''))
        + ['phone' => trim((string) ($entry['phone'] ?? '')), 'website' => trim((string) ($entry['website'] ?? ''))];
}

function same_detail(string $key, string $a, string $b): bool
{
    if ($key === 'phone') {
        return preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b);
    }
    if ($key === 'website') {
        $bare = fn($t) => rtrim(preg_replace('#^https?://(?:www\.)?#i', '', mb_strtolower(trim($t))), '/');
        return $bare($a) === $bare($b);
    }
    $norm = fn($t) => trim((string) preg_replace('/\s+/', ' ', mb_strtolower($t)));
    return $norm($a) === $norm($b);
}

/** Writes the shared details to the jobs at the company (name matches ignoring Inc / LLC); returns how many jobs matched. */
function update_work_details(string $company_name, array $values, bool $fill_only): int
{
    $sizes = WORK_DETAIL_COLUMNS;
    $columns = array_values(array_filter(['street', 'city', 'state', 'zip', 'phone', 'website'], fn($c) => array_key_exists($c, $values)));
    if (!$columns) {
        return 0;
    }
    $ids = [];
    foreach (rows('SELECT id, company FROM user_profile_work_history WHERE profile_id = 1') as $row) {
        if (same_company_name((string) $row['company'], $company_name)) {
            $ids[] = (int) $row['id'];
        }
    }
    foreach ($ids as $id) {
        $sets = [];
        $args = [];
        foreach ($columns as $column) {
            $text = mb_substr(trim((string) $values[$column]), 0, (int) ($sizes[$column] ?? 255));
            if ($fill_only) {
                if ($text === '') {
                    continue;
                }
                $sets[] = "$column = CASE WHEN $column = '' OR $column IS NULL THEN ? ELSE $column END";
            } else {
                $sets[] = "$column = ?";
            }
            $args[] = $text;
        }
        if ($sets) {
            q('UPDATE user_profile_work_history SET ' . implode(', ', $sets) . ' WHERE id = ?', array_merge($args, [$id]));
        }
    }
    return count($ids);
}

/** Pushes a company entry's address, phone and website to the matching jobs. $before is the entry before an edit. */
function push_company_to_profile(array $entry, ?array $before = null): int
{
    $new = company_details($entry);
    if ($before === null) {
        $values = array_filter($new, fn($t) => $t !== '');
        $fill_only = true;
    } else {
        $old = company_details($before);
        $values = [];
        foreach ($new as $key => $text) {
            if (!same_detail($key, $text, $old[$key])) {
                $values[$key] = $text;
            }
        }
        if (array_intersect_key($values, array_flip(ADDRESS_PARTS))) {
            $values += array_intersect_key($new, array_flip(ADDRESS_PARTS));
        }
        $fill_only = false;
    }
    if (!$values) {
        return 0;
    }
    return update_work_details((string) (($before ?? $entry)['name']), $values, $fill_only);
}

/** Shows on the Companies page what the work history has for the employers that came from your résumé. */
function refresh_companies_from_work_history(array $work_history, string $home_state = ''): int
{
    $companies = my_companies_load();
    $changed = 0;
    foreach ($companies as $index => $item) {
        if (($item['source'] ?? '') !== 'resume') {
            continue;
        }
        $theirs = null;
        foreach ($work_history as $job) {
            if (!is_array($job) || !same_company_name((string) ($job['company'] ?? ''), (string) $item['name'])) {
                continue;
            }
            $candidate = [];
            foreach (['street', 'city', 'state', 'zip', 'phone', 'website'] as $key) {
                $candidate[$key] = trim((string) ($job[$key] ?? ''));
            }
            if (array_filter($candidate) !== []) {
                $theirs = $candidate;
                break;
            }
        }
        if ($theirs === null) {
            continue;
        }
        $mine = company_details($item);
        $merged = [];
        foreach (ADDRESS_PARTS as $key) {
            $merged[$key] = $theirs[$key] !== '' ? $theirs[$key] : $mine[$key];
        }
        $changes = [];
        foreach (ADDRESS_PARTS as $key) {
            if (!same_detail($key, $merged[$key], $mine[$key])) {
                $changes['address'] = join_address_parts($merged);
                if (($item['place'] ?? '') === '') {
                    $changes['place'] = $merged['city'] !== '' && $merged['state'] !== '' ? "{$merged['city']}, {$merged['state']}" : $merged['zip'];
                }
                break;
            }
        }
        if ($theirs['phone'] !== '' && !same_detail('phone', $theirs['phone'], $mine['phone'])) {
            $changes['phone'] = $theirs['phone'];
        }
        $site = company_site_address($theirs['website']);
        if ($site !== '' && !same_detail('website', $site, $mine['website'])) {
            $changes['website'] = $site;
        }
        if (!$changes) {
            continue;
        }
        try {
            $fields = [];
            foreach (array_merge(array_keys(COMPANY_FIELD_LIMITS), ['notes']) as $key) {
                $fields[$key] = $item[$key] ?? '';
            }
            $companies[$index] = array_merge($item, clean_company($changes + $fields, $home_state));
            $changed++;
        } catch (InvalidArgumentException $error) {
            continue;
        }
    }
    if ($changed) {
        my_companies_save($companies);
    }
    return $changed;
}

// ---------- address suggestions as you type ----------

/** U.S. street addresses from Photon that start like $typed, nearest $near first. Throws RuntimeException when it can't be reached. */
function suggest_addresses(string $typed, ?array $near = null): array
{
    $typed = trim((string) preg_replace('/\s+/', ' ', $typed));
    if (mb_strlen($typed) < 4) {
        return [];
    }
    $params = ['q' => $typed, 'limit' => 10, 'lang' => 'en', 'bbox' => '-179.2,18.8,-66.9,71.5'];
    if ($near) {
        $params['lat'] = round($near[0], 2);
        $params['lon'] = round($near[1], 2);
    }
    $key = 'addr|' . mb_strtolower($typed) . '|' . ($near ? round($near[0], 2) . ',' . round($near[1], 2) : '');
    $cached = companies_cache_get($key);
    if ($cached === null) {
        try {
            $response = http_request(PHOTON_URL . '?' . http_build_query($params), ['agent' => ADDRESS_AGENT, 'timeout' => 8,
                'headers' => ['Accept: application/json']]);
        } catch (Throwable $error) {
            throw new RuntimeException('The address service could not be reached.');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("The address service answered HTTP {$response['status']}.");
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('The address service sent an answer that could not be read.');
        }
        $cached = $data['features'] ?? [];
        companies_cache_put($key, $cached);
    }
    return format_address_features($cached);
}

function format_address_features(array $features): array
{
    $found = [];
    $seen = [];
    foreach ($features as $feature) {
        $p = $feature['properties'] ?? [];
        $street = trim((string) ($p['street'] ?? ''));
        if (strtoupper((string) ($p['countrycode'] ?? '')) !== 'US' || $street === '') {
            continue;
        }
        $line = trim(trim((string) ($p['housenumber'] ?? '')) . ' ' . $street);
        $town = trim((string) ($p['city'] ?? $p['locality'] ?? $p['district'] ?? $p['county'] ?? ''));
        $state = trim((string) ($p['statecode'] ?? ''));
        if ($state === '') {
            $state = state_from_text((string) ($p['state'] ?? '')) ?: trim((string) ($p['state'] ?? ''));
        }
        $postcode = trim((string) ($p['postcode'] ?? ''));
        $region = trim("$state $postcode");
        $address = implode(', ', array_filter([$line, $town, $region], fn($x) => $x !== ''));
        $key = mb_strtolower($address);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $name = trim((string) ($p['name'] ?? ''));
        $business = $name !== '' && in_array($p['osm_key'] ?? '', ['amenity', 'shop', 'office', 'craft', 'healthcare'], true)
            && mb_strtolower($name) !== mb_strtolower($street) ? $name : '';
        $found[] = ['street' => $line, 'city' => $town, 'state' => $state, 'zip' => substr($postcode, 0, 5), 'address' => $address,
            'place' => $town !== '' && $state !== '' ? "$town, $state" : '', 'name' => $business,
            'detail' => $name !== '' && mb_strtolower($name) !== mb_strtolower($street) ? $name : ''];
        if (count($found) >= 6) {
            break;
        }
    }
    return $found;
}

// ---------- applications made outside Job Finder (counted in the Job Apply Stats) ----------

/** [{"company", "title", "applied_on"}] kept in the settings table; entries without a real date are ignored. */
function applied_elsewhere(): array
{
    $entries = [];
    foreach (setting_json('applied_elsewhere', []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $date = clean_applied_date($item['applied_on'] ?? '');
        if ($date !== '') {
            $entries[] = ['company' => mb_substr(trim((string) ($item['company'] ?? '')), 0, 150),
                'title' => mb_substr(trim((string) ($item['title'] ?? '')), 0, 255), 'applied_on' => $date];
        }
    }
    return $entries;
}
