<?php
// Saved Companies (search results kept with Save) and what the Companies page remembers between visits: the last
// search's results and the results you hid. Same behaviour as the desktop app's saved_companies.py and its
// /companies/page-state route. Kept in the settings table.

declare(strict_types=1);
require_once __DIR__ . '/companies.php';

const SAVED_COMPANY_FIELDS = ['name', 'website', 'address', 'phone', 'place', 'notes'];
const MAX_SAVED_COMPANIES = 1000;

function saved_companies_load(): array
{
    $list = setting_json('saved_companies', []);
    return array_values(array_filter(is_array($list) ? $list : [], fn($item) => is_array($item) && !empty($item['id']) && !empty($item['name'])));
}

/** Save a company. One already saved under the same name is returned as it is: [entry, added]. */
function saved_company_add(array $fields, string $home_state = ''): array
{
    $companies = saved_companies_load();
    foreach ($companies as $item) {
        if (same_company_name((string) $item['name'], (string) ($fields['name'] ?? ''))) {
            return [$item, false];
        }
    }
    if (count($companies) >= MAX_SAVED_COMPANIES) {
        throw new InvalidArgumentException('The list is full (' . MAX_SAVED_COMPANIES . ' companies). Remove some first.');
    }
    $given = [];
    foreach (SAVED_COMPANY_FIELDS as $key) {
        $given[$key] = $fields[$key] ?? '';
    }
    $values = clean_company($given, $home_state);
    $entry = [];
    foreach (SAVED_COMPANY_FIELDS as $key) {
        $entry[$key] = $values[$key] ?? '';
    }
    $entry['lat'] = $values['lat'] ?? null;
    $entry['lon'] = $values['lon'] ?? null;
    $entry['id'] = substr(bin2hex(random_bytes(8)), 0, 12);
    $entry['saved'] = gmdate('c');
    set_setting_json('saved_companies', array_merge($companies, [$entry]));
    return [$entry, true];
}

function saved_company_delete(string $id): bool
{
    $companies = saved_companies_load();
    $kept = array_values(array_filter($companies, fn($item) => $item['id'] !== $id));
    if (count($kept) === count($companies)) {
        return false;
    }
    set_setting_json('saved_companies', $kept);
    return true;
}

function saved_company_result(array $item, ?array $center = null): array
{
    $miles = null;
    if ($center !== null && isset($item['lat'], $item['lon']) && $item['lat'] !== null && $item['lon'] !== null) {
        $miles = round(miles_between($center, [(float) $item['lat'], (float) $item['lon']]), 1);
    }
    return ['id' => $item['id'], 'name' => $item['name'], 'address' => $item['address'] ?? '', 'website' => $item['website'] ?? '',
        'phone' => $item['phone'] ?? '', 'place' => $item['place'] ?? '', 'notes' => $item['notes'] ?? '', 'miles' => $miles, 'added' => $item['saved'] ?? ''];
}

/** Nearest first when a center is given, then the ones that cannot be measured, each by name. */
function saved_companies_everything(?array $center = null): array
{
    $results = array_map(fn($item) => saved_company_result($item, $center), saved_companies_load());
    $measured = array_filter($results, fn($r) => $r['miles'] !== null);
    $unmeasured = array_filter($results, fn($r) => $r['miles'] === null);
    usort($measured, fn($a, $b) => [$a['miles'], mb_strtolower($a['name'])] <=> [$b['miles'], mb_strtolower($b['name'])]);
    usort($unmeasured, fn($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));
    return array_merge($measured, $unmeasured);
}

function companies_page_state(): array
{
    $state = setting_json('companies_page', []);
    return is_array($state) ? $state : [];
}

/** Keep the last search's results and/or the hidden list from a page's save; anything else is ignored. */
function companies_page_state_save(array $data): void
{
    $state = companies_page_state();
    if (isset($data['results']['companies']) && is_array($data['results']['companies'])) {
        $state['results'] = $data['results'];
    }
    if (isset($data['hidden']) && is_array($data['hidden'])) {
        $state['hidden'] = array_slice(array_map(fn($key) => mb_substr((string) $key, 0, 400), $data['hidden']), -2000);
    }
    set_setting_json('companies_page', $state);
}
