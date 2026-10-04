<?php
// U.S. places and ZIP codes (U.S. Census Bureau 2023 Gazetteer, public domain): the city boxes' type-ahead, and how
// far a job is from home with a rough 6 a.m. drive time. Worked out on this server; nothing is looked up online.
// Ported from the desktop app's places.py and travel.py.

declare(strict_types=1);

const STATE_NAMES = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
    'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
    'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
    'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
    'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
    'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
    'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
    'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
    'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
    'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico',
];
// States next to each other, so places near home come first ("Lancaster" -> Lancaster, PA for someone in MD).
const NEIGHBORS = [
    'MD' => ['DE', 'PA', 'VA', 'WV', 'DC'], 'DE' => ['MD', 'PA', 'NJ'], 'PA' => ['MD', 'DE', 'NJ', 'NY', 'OH', 'WV'],
    'VA' => ['MD', 'DC', 'WV', 'KY', 'TN', 'NC'], 'DC' => ['MD', 'VA'], 'NJ' => ['NY', 'PA', 'DE'],
    'WV' => ['MD', 'PA', 'OH', 'KY', 'VA'], 'NY' => ['NJ', 'PA', 'CT', 'MA', 'VT'],
];
const ROAD_FACTOR = 1.3;
// [road miles up to, average mph at 6 a.m.]: slow streets near home, then arterials, then highways.
const DRIVE_SPEEDS = [[3, 20], [10, 30], [25, 42], [60, 52], [1e9, 58]];

function place_key(string $text): string
{
    return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text, 'UTF-8')));
}

/** Every Census place as [key, name, state, population, lat, lon], biggest first. */
function us_places(): array
{
    return cached_data('us-places', function () {
        $rows = [];
        foreach (file(DATA_DIR . '/us-places.tsv', FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$name, $state, $population, $lat, $lon] = explode("\t", $line);
            $rows[] = [place_key($name), $name, $state, (int) $population, (float) $lat, (float) $lon];
        }
        return $rows;
    });
}

/** [key => [[state, population, lat, lon], ...]] biggest first. */
function us_place_index(): array
{
    return cached_data('us-place-index', function () {
        $index = [];
        foreach (us_places() as [$key, , $state, $population, $lat, $lon]) {
            $index[$key][] = [$state, $population, $lat, $lon];
        }
        return $index;
    });
}

function us_zips(): array
{
    return cached_data('us-zips', function () {
        $points = [];
        foreach (file(DATA_DIR . '/us-zips.tsv', FILE_IGNORE_NEW_LINES) as $line) {
            if ($line !== '' && $line[0] !== '#') {
                [$zip, $lat, $lon] = explode("\t", $line);
                $points[$zip] = [(float) $lat, (float) $lon];
            }
        }
        return $points;
    });
}

function near_states(string $home): array
{
    $home = strtoupper(substr(trim($home), 0, 2));
    return $home === '' ? [] : array_merge([$home], NEIGHBORS[$home] ?? []);
}

/** "Lancaster" -> ["Lancaster, PA", "Lancaster, CA", ...]: names starting with what was typed, near home first. */
function city_matches(string $text, string $home_state = '', int $limit = 12): array
{
    [$name_part, $state_part] = array_pad(explode(',', $text, 2), 2, '');
    $key = place_key($name_part);
    $state = strtoupper(substr(trim($state_part), 0, 2));
    if (strlen($key) < 2) {
        return [];
    }
    $near = near_states($home_state);
    $states = [];
    if ($state === '') {
        foreach (STATE_NAMES as $full) {
            if (str_starts_with(place_key($full), $key)) {
                $states[] = $full;
            }
        }
    }
    $found = [];
    foreach (us_places() as [$name_key, $name, $place_state, $population]) {
        if ($state !== '' && !str_starts_with($place_state, $state)) {
            continue;
        }
        if (str_starts_with($name_key, $key)) {
            $found[] = [!in_array($place_state, $near, true), $name_key !== $key, -$population, "$name, $place_state"];
        }
    }
    sort($found);
    return array_slice(array_merge($states, array_column($found, 3)), 0, $limit);
}

function state_code(string $text): string
{
    $text = trim($text);
    if (isset(STATE_NAMES[strtoupper($text)])) {
        return strtoupper($text);
    }
    $code = array_search(mb_strtolower($text), array_map('mb_strtolower', STATE_NAMES), true);
    return $code === false ? '' : $code;
}

function zip_point(?string $zip): ?array
{
    $zip = substr(preg_replace('/\D/', '', (string) $zip), 0, 5);
    return us_zips()[$zip] ?? null;
}

/** [lat, lon] for "Bel Air, MD", "Baltimore, Maryland, United States" or "Baltimore Metropolitan Area". */
function place_point(?string $text, string $home_state = ''): ?array
{
    $text = preg_replace('/\([^)]*\)/', ' ', (string) $text);
    $parts = array_values(array_filter(array_map('trim', explode(',', $text)), 'strlen'));
    if (!$parts) {
        return null;
    }
    $name = preg_replace('/\b(?:greater|metropolitan|metro|area|region)\b/i', ' ', $parts[0]);
    $candidates = us_place_index()[place_key($name)] ?? null;
    if (!$candidates) {
        return null;
    }
    $state = isset($parts[1]) ? state_code($parts[1]) : '';
    if ($state !== '') {
        foreach ($candidates as $c) {
            if ($c[0] === $state) {
                return [$c[2], $c[3]];
            }
        }
        return null;
    }
    $near = near_states($home_state);
    usort($candidates, fn($a, $b) => [!in_array($a[0], $near, true), -$a[1]] <=> [!in_array($b[0], $near, true), -$b[1]]);
    return [$candidates[0][2], $candidates[0][3]];
}

/** Straight-line miles between two [lat, lon] points. */
function miles_between(array $a, array $b): float
{
    [$lat1, $lon1] = $a;
    [$lat2, $lon2] = $b;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $h = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
    return 3958.8 * 2 * asin(sqrt($h));
}

/** Estimated minutes behind the wheel for a trip of this straight-line length at 6 a.m. */
function drive_minutes(float $straight): float
{
    $road = $straight * ROAD_FACTOR;
    $minutes = $done = 0.0;
    foreach (DRIVE_SPEEDS as [$limit, $mph]) {
        $stretch = min($road, $limit) - $done;
        if ($stretch > 0) {
            $minutes += $stretch / $mph * 60;
            $done += $stretch;
        }
        if ($done >= $road) {
            break;
        }
    }
    return $minutes;
}

/** ["miles" => 12, "minutes" => 20, "text" => "12 mi · ~20 min"] or null when home or the place isn't known. */
function describe_trip(?string $home_zip, ?array $job_point = null, string $job_text = '', string $home_state = ''): ?array
{
    $home = zip_point($home_zip);
    $point = $job_point ?: place_point($job_text, $home_state);
    if (!$home || !$point) {
        return null;
    }
    $straight = miles_between($home, $point);
    $minutes = drive_minutes($straight);
    $shown = $minutes >= 3 ? max(5, (int) round($minutes / 5.0) * 5) : max(1, (int) round($minutes));
    $miles = (int) round($straight);
    $lasts = $shown < 120 ? "~$shown min" : '~' . round($shown / 60) . ' hr';
    return ['miles' => $miles, 'minutes' => $shown,
        'text' => $miles ? number_format($miles) . " mi · $lasts" : "under 1 mi · $lasts"];
}
