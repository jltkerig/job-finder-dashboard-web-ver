<?php
// The Companies page's calls: a search around a place, your own companies (list, add, edit, delete) and address
// suggestions. Same answers as the desktop Job Finder's companies_routes.py.

declare(strict_types=1);
require_once APP_ROOT . '/includes/companies.php';

$profile = get_user_profile();
$state = (string) ($profile['state'] ?? '');

/** A company's change is shared with the matching job(s) in the work history; returns how many jobs matched. */
function company_request_fields(): array
{
    $data = request_json();
    return is_array($data) ? $data : [];
}

switch ($path) {
    case '/companies/search':
        $found = resolve_company_place((string) ($_GET['place'] ?? ''), $profile);
        if ($found === null) {
            if (trim((string) ($_GET['place'] ?? '')) === '') {
                api_error('E3501', 'Type a town and state (Harrisburg, PA) or a ZIP code to search around.', 400);
            }
            api_error('E3502', 'That place wasn\'t found. Use a town and state such as Harrisburg, PA, or a 5-digit ZIP code.', 404);
        }
        [[$lat, $lon], $shown] = $found;
        $miles = min(max((float) ($_GET['miles'] ?? 15) ?: 15.0, 1.0), 50.0);
        $asked = array_map('trim', explode(',', (string) ($_GET['kinds'] ?? '')));
        $valid = array_values(array_filter($asked, fn($key) => isset(company_kinds()[$key])));
        try {
            if ($asked === [KNOWN_KIND]) {
                $companies = known_employers($lat, $lon, $miles);
            } elseif ($asked === [MINE_KIND]) {
                $companies = my_companies_near($lat, $lon, $miles);
            } else {
                $companies = search_local_companies($lat, $lon, $miles, $valid ?: DEFAULT_COMPANY_KINDS);
            }
        } catch (RuntimeException $error) {
            api_error('E3503', $error->getMessage(), 502);
        }
        json_out(['status' => 'ok', 'place' => $shown, 'center' => ['lat' => $lat, 'lon' => $lon], 'miles' => $miles,
            'count' => count($companies), 'companies' => $companies]);

    case '/companies/mine':
        if ($method === 'GET') {
            $found = resolve_company_place((string) ($_GET['place'] ?? ''), $profile);
            $companies = my_companies_everything($found ? $found[0] : null);
            json_out(['status' => 'ok', 'place' => $found ? $found[1] : '', 'count' => count($companies), 'companies' => $companies]);
        }
        try {
            $entry = my_company_add(company_request_fields(), $state);
        } catch (InvalidArgumentException $error) {
            api_error('E3511', $error->getMessage(), 400);
        }
        $synced = push_company_to_profile($entry);  // a job at this company gets its empty details filled in
        json_out(['status' => 'ok', 'company' => my_company_result($entry), 'jobs_synced' => $synced, 'profile_synced' => true]);

    case '/companies/mine/update':
        $before = my_company_get($route_key);
        if ($before === null) {
            api_error('E3512', 'That company is no longer in your list.', 404);
        }
        $fields = company_request_fields();
        try {
            $changed = array_merge($before, clean_company($fields, $state));
        } catch (InvalidArgumentException $error) {
            api_error('E3511', $error->getMessage(), 400);
        }
        // The Profile first: an employer from your résumé is shown from the Profile again, so a change kept only here
        // would be undone the next time the page opens.
        $synced = null;
        try {
            $synced = push_company_to_profile($changed, $before);
        } catch (Throwable $error) {
            error_log('Companies: could not update the Profile: ' . $error->getMessage());
        }
        if ($synced === null && ($before['source'] ?? '') === 'resume') {
            api_error('E3513', 'Couldn\'t update the job\'s details in your Profile, so nothing was saved. Try again.', 500);
        }
        $entry = my_company_update($route_key, $fields, $state);
        if ($entry === null) {
            api_error('E3512', 'That company is no longer in your list.', 404);
        }
        json_out(['status' => 'ok', 'company' => my_company_result($entry), 'jobs_synced' => $synced ?? 0, 'profile_synced' => $synced !== null]);

    case '/companies/mine/delete':
        if (!my_company_delete($route_key)) {
            api_error('E3512', 'That company is no longer in your list.', 404);
        }
        json_out(['status' => 'ok']);

    case '/companies/address-suggest':
        $typed = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($typed) < 4) {
            json_out(['status' => 'ok', 'suggestions' => []]);
        }
        $found = resolve_company_place((string) ($_GET['place'] ?? ''), $profile);
        try {
            json_out(['status' => 'ok', 'suggestions' => suggest_addresses($typed, $found ? $found[0] : null)]);
        } catch (RuntimeException $error) {
            api_error('E3521', $error->getMessage(), 502);
        }
}
