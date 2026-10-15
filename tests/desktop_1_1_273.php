<?php
// Tests for what the web version took over from the desktop Job Finder v1.1.241 - v1.1.273: the Companies page,
// the Job Apply Stats, the education reader, JobAps (the State of Maryland) and Rippling. Included by run.php (it
// uses its check() and its throwaway database). Every company, person and address here is made up.

declare(strict_types=1);

require_once APP_ROOT . '/includes/companies.php';
require_once APP_ROOT . '/includes/education.php';

// --- Reading an address ---
check('address: street, town, state, zip', split_address('757 Example Rd Suite 301, Springfield, MD, 21015')
    === ['street' => '757 Example Rd Suite 301', 'city' => 'Springfield', 'state' => 'MD', 'zip' => '21015']);
check('address: state and zip together', split_address('100 Main St, Springfield, MD 21015')['zip'] === '21015'
    && split_address('12 Oak Ave, Springfield, Maryland 21015')['state'] === 'MD');
check('address: a suite is not the town', split_address('10 Main Street, Suite 4') === ['street' => '10 Main Street, Suite 4', 'city' => '', 'state' => '', 'zip' => '']);
check('address: the place box fills what is missing', split_address('10 Main Street', 'Springfield, MD')['city'] === 'Springfield');
check('address: joined back', join_address_parts(['street' => '1 A St', 'city' => 'Town', 'state' => 'MD', 'zip' => '21015']) === '1 A St, Town, MD, 21015');

// --- My companies ---
$_point = fn() => null;
$entry = my_company_add(['name' => 'Example Co', 'street' => '10 Main Street', 'city' => 'Bel Air', 'state' => 'MD', 'zip' => '21014',
    'phone' => '410-555-0101', 'website' => 'example.example.com', 'jobs_board' => 'Indeed'], 'MD');
check('a company is kept with its address as one line', $entry['address'] === '10 Main Street, Bel Air, MD, 21014' && $entry['place'] === 'Bel Air, MD');
check('a website gets https://', $entry['website'] === 'https://example.example.com');
check('the place gives a map point', $entry['lat'] !== null && $entry['lon'] !== null);
$shown = my_company_result($entry, [39.5, -76.3]);
check('listed with its parts and miles', $shown['street'] === '10 Main Street' && $shown['zip'] === '21014' && $shown['miles'] !== null);
check('a job board that is only a name is not a link', $shown['jobs_url'] === '' && $shown['jobs_board'] === 'Indeed');
$threw = false;
try {
    clean_company(['name' => '']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a name is required', $threw);
check('a duplicate name with Inc is the same company', same_company_name('Example Co, Inc.', 'example co'));

// --- Sharing with the work history ---
q("INSERT INTO user_profile (id, first_name, last_name, state) VALUES (1, 'Pat', 'Sample', 'MD') ON CONFLICT(id) DO NOTHING");
q("INSERT INTO user_profile_work_history (profile_id, company, role, dates, description, phone) VALUES (1, 'Example Co', 'Zed Designer', '2020', '', '111')");
q("INSERT INTO user_profile_work_history (profile_id, company, role, dates, description) VALUES (1, 'Example Co, Inc.', 'Zed Intern', '2019', '')");
q("INSERT INTO user_profile_work_history (profile_id, company, role, dates, description) VALUES (1, 'Other Place', 'Zed Cashier', '2010', '')");
check('a new company only fills what the job has no text for', push_company_to_profile($entry) === 2
    && value("SELECT phone FROM user_profile_work_history WHERE role = 'Zed Designer'") === '111'
    && value("SELECT city FROM user_profile_work_history WHERE role = 'Zed Designer'") === 'Bel Air'
    && value("SELECT website FROM user_profile_work_history WHERE role = 'Zed Intern'") === 'https://example.example.com');
$edited = array_merge($entry, ['phone' => '410-555-0199']);
check('an edit writes the changed box to every job there', push_company_to_profile($edited, $entry) === 2
    && value("SELECT phone FROM user_profile_work_history WHERE role = 'Zed Intern'") === '410-555-0199'
    && value("SELECT city FROM user_profile_work_history WHERE role = 'Zed Cashier'") === '');
check('a phone written another way is not a change', push_company_to_profile(array_merge($entry, ['phone' => '(410) 555-0101']), $entry) === 0);
check('the employers in a work history join the list once', sync_my_companies_from_work_history(get_user_profile()['work_history'], 'MD') >= 1
    && sync_my_companies_from_work_history(get_user_profile()['work_history'], 'MD') === 0
    && (bool) array_filter(my_companies_load(), fn($c) => $c['name'] === 'Other Place' && ($c['source'] ?? '') === 'resume'));
$other = array_values(array_filter(my_companies_load(), fn($c) => $c['name'] === 'Other Place'))[0];
check('a deleted résumé employer does not come back', my_company_delete($other['id'])
    && sync_my_companies_from_work_history(get_user_profile()['work_history'], 'MD') === 0);

// --- Nearby places (OpenStreetMap answers, as Nominatim sends them) ---
$items = [
    ['osm_type' => 'node', 'osm_id' => 1, 'lat' => '39.5400', 'lon' => '-76.3500', 'category' => 'office', 'type' => 'employment_agency',
        'name' => 'Example Staffing', 'address' => ['house_number' => '10', 'road' => 'Main Street', 'town' => 'Springfield', 'ISO3166-2-lvl4' => 'US-MD', 'postcode' => '21015'],
        'extratags' => ['website' => 'staffing.example.com', 'phone' => '+1 410 555 0100']],
    ['osm_type' => 'node', 'osm_id' => 2, 'lat' => '39.5401', 'lon' => '-76.3501', 'category' => 'office', 'type' => 'employment_agency',
        'name' => 'example staffing', 'address' => ['house_number' => '10', 'road' => 'Main Street', 'town' => 'Springfield', 'ISO3166-2-lvl4' => 'US-MD', 'postcode' => '21015']],
    ['osm_type' => 'node', 'osm_id' => 3, 'lat' => '41.5', 'lon' => '-76.3', 'category' => 'shop', 'type' => 'supermarket', 'name' => 'Far Market'],
    ['osm_type' => 'node', 'osm_id' => 4, 'lat' => 'x', 'lon' => 'y', 'name' => 'No Place'],
];
$places = parse_osm_places($items, [39.5359, -76.3483], 10);
check('places: nearest first, the same address once, far ones left out', count($places) === 1 && $places[0]['name'] === 'Example Staffing');
check('places: type, address and website', $places[0]['type'] === 'Employment agency' && $places[0]['address'] === '10 Main Street, Springfield, MD 21015'
    && $places[0]['website'] === 'https://staffing.example.com');
check('recruiters and staffing is a kind, not ticked at first', isset(company_kinds()['staffing']) && !in_array('staffing', DEFAULT_COMPANY_KINDS, true));

// --- Address suggestions (Photon answers) ---
$suggested = format_address_features([
    ['properties' => ['name' => 'Example Studio', 'housenumber' => '757', 'street' => 'Example Road', 'city' => 'Springfield', 'state' => 'Maryland',
        'postcode' => '21228', 'countrycode' => 'US', 'osm_key' => 'office']],
    ['properties' => ['name' => 'Bus stop', 'street' => 'Example Road', 'city' => 'Springfield', 'state' => 'Maryland', 'countrycode' => 'US', 'osm_key' => 'highway']],
    ['properties' => ['name' => 'Elsewhere', 'housenumber' => '1', 'street' => 'Rue X', 'city' => 'Paris', 'countrycode' => 'FR']],
    ['properties' => ['name' => 'Springfield', 'city' => 'Springfield', 'state' => 'Maryland', 'countrycode' => 'US']],
]);
check('suggestions: U.S. streets only, with the four parts', count($suggested) === 2 && $suggested[0]['street'] === '757 Example Road'
    && $suggested[0]['city'] === 'Springfield' && $suggested[0]['state'] === 'MD' && $suggested[0]['zip'] === '21228'
    && $suggested[0]['name'] === 'Example Studio' && $suggested[1]['name'] === '');

// --- Job Apply Stats ---
$_t = new DateTimeImmutable('2026-10-14');
$_base = applied_by_month($_t);
set_setting_json('applied_elsewhere', [['company' => 'Example Co', 'title' => 'Designer', 'applied_on' => '2026-09-23'],
    ['company' => 'No date', 'title' => 'x'], ['company' => 'Bad', 'applied_on' => '2026-13-45']]);
check('applied elsewhere: only entries with a real date', count(applied_elsewhere()) === 1);
q("INSERT INTO companies (source_key, name, career_job_title, source_url, source_type, date_found, is_kept, application_status, listing_details)
   VALUES ('stats-1', 'Beta Inc', 'UI Designer', 'https://beta.example.com/1', 'Employer careers', ?, 1, 'Applied', ?)",
    [now_utc(), json_encode(['applied_on' => '2026-10-02'])]);
q("INSERT INTO companies (source_key, name, career_job_title, source_url, source_type, date_found, is_kept, application_status, listing_details)
   VALUES ('stats-2', 'Gamma Inc', 'Web Designer', 'https://gamma.example.com/1', 'Employer careers', ?, 1, 'Interview', '{}')", [now_utc()]);
$stats = applied_by_month($_t);
$by = array_column($stats['months'], 'count', 'key');
$was = array_column($_base['months'], 'count', 'key');
check('stats: months, elsewhere and undated', $by['2026-10'] === $was['2026-10'] + 1 && $by['2026-09'] === $was['2026-09'] + 1
    && $stats['undated'] === $_base['undated'] + 1 && count($stats['months']) === 6);

// --- What leaves the Search results ---
$rows = [
    ['name' => 'Acme', 'career_job_title' => 'Web Designer', 'is_kept' => 1, 'application_status' => 'Saved', 'details' => []],
    ['name' => 'Acme', 'career_job_title' => 'Web Designer', 'is_kept' => 0, 'application_status' => 'None', 'details' => []],
    ['name' => 'Beta', 'career_job_title' => 'UI Designer', 'is_kept' => 0, 'application_status' => 'Applied', 'details' => []],
    ['name' => 'Gamma', 'career_job_title' => 'Art Director', 'is_kept' => 0, 'application_status' => 'None', 'details' => ['apply_queued' => true]],
    ['name' => 'Delta', 'career_job_title' => 'Illustrator', 'is_kept' => 0, 'application_status' => 'None', 'details' => []],
];
$left = hide_dashboard_jobs($rows, $rows);
check('saved, applied and queued jobs (and copies) leave the results', count($left) === 1 && $left[0]['name'] === 'Delta');
check('an Easy Apply badge is drawn', str_contains(easy_apply_badge(), 'Easy Apply'));

// --- Education from a résumé ---
$resume = "Pat Sample\nSpringfield, MD\n\nEDUCATION\nSpringfield University\nBachelor's Degree in Graphic Design\nMay 2014\n"
    . "Riverside College\nAssociate's Degree in Graphic Design\nGPA: 3.5\n\nSKILLS\nHTML, CSS\n";
$schools = parse_education($resume);
check('education: two schools with degrees', count($schools) === 2 && $schools[0]['degree'] === "Bachelor's Degree"
    && $schools[0]['school'] === 'Springfield University' && $schools[0]['major'] === 'Graphic Design' && $schools[0]['end_date'] === '2014-05'
    && $schools[1]['degree'] === "Associate's Degree" && $schools[1]['gpa'] === '3.5');
check('education: none without a heading', parse_education("Pat Sample\nSKILLS\nHTML") === []);
q('DELETE FROM user_profile_education');
check('education: saved when none is saved, and then left alone', count(save_schools_if_none($schools)) === 2 && save_schools_if_none($schools) === []
    && count(get_user_profile()['education']) === 2);
check('education: the résumé reader suggests them', count(resume_suggestions($resume)['education']) === 2);

// --- JobAps (the State of Maryland) ---
check('jobaps: working title', jobaps_working_title('ACCOUNTANT II (Accountant II, General Accounting) - #26-004547-0006') === 'Accountant II, General Accounting'
    && jobaps_working_title('Barber Stylist Instructor (TEACHER CONDITIONAL) - #26-005885-0004') === 'Barber Stylist Instructor'
    && jobaps_working_title('*REPOST* SYSTEMS CONTROL ACCOUNTANT SUPERVISOR (General Accounting Division) - #26-004582-0001') === 'Systems Control Accountant Supervisor'
    && jobaps_working_title('ADMINISTRATOR II (Web Developer) - #26-000001-0001') === 'Web Developer');
check('jobaps: job number from an address or a title', jobaps_job_number('https://www.jobapscloud.com/MD/sup/bulpreview.asp?b=&R1=26&R2=004582&R3=0001') === '26-004582-0001'
    && jobaps_job_number('Procurement Officer II - #26-003003-0016') === '26-003003-0016' && jobaps_job_number('https://www.jobapscloud.com/MD/') === '');
$link = 'https://www.jobapscloud.com/MD/sup/BulPreview.asp?R1=26&amp;R2=000101&amp;R3=0001';
$description = '<p><strong>Department:</strong> EXAMPLE - Digital Services</p><p><strong>Date Opened:</strong> 10/14/2026 4:00:00 PM.</p>'
    . '<p><strong>Filing Deadline:</strong> 10/28/2026 5:00:00 PM</p><p><strong>Salary:</strong> $60,000.00 - $90,000.00/year.</p>'
    . '<p><strong>Employment Type:</strong> <ul><li>Full-Time</li></ul></p><p><strong>Work Location:</strong> <ul><li>Harford</li><li>Baltimore City</li></ul></p>';
$feed = '<?xml version="1.0"?><rss><channel><item><title>ADMINISTRATOR II (Web Developer) - #26-000101-0001</title><link>' . $link . '</link>'
    . '<description>' . htmlspecialchars($description) . '</description></item></channel></rss>';
$jobs = jobaps_parse_feed($feed);
check('jobaps: the feed is read', count($jobs) === 1 && $jobs[0]['id'] === '26-000101-0001' && $jobs[0]['title'] === 'Web Developer'
    && $jobs[0]['places'] === ['Harford', 'Baltimore City'] && $jobs[0]['kind'] === 'Full-Time' && $jobs[0]['salary'] === '$60,000.00 - $90,000.00/year'
    && jobaps_date($jobs[0]['opened']) === '2026-10-14');
check('jobaps: a county becomes its seat', jobaps_seat(['state' => 'MD'], 'Harford') === 'Bel Air, MD, US'
    && jobaps_seat(['state' => 'MD'], "Prince George's") === 'Upper Marlboro, MD, US');
check('jobaps: its addresses are recognised', identify_board('https://www.jobapscloud.com/MD/sup/BulPreview.asp?R1=26&R2=1&R3=1')
    === ['system' => 'jobaps', 'agency' => 'MD', 'state' => 'MD'] && identify_board('https://www.jobaps.com/md/jobs/mdod')['agency'] === 'MD'
    && identify_board('https://www.jobapscloud.com/css/site.css') === null);
check('jobaps: the State of Maryland is a default employer', (bool) array_filter(load_employers(), fn($e) => $e['system'] === 'jobaps' && $e['agency'] === 'MD'));
check('jobaps: its name', board_pretty_name(['system' => 'jobaps', 'agency' => 'MD']) === 'State of Maryland');
check('rippling: boards are recognised', identify_board('https://ats.rippling.com/example-co/jobs/0a1b2c3d-1111-2222-3333-444455556666')
    === ['system' => 'rippling', 'slug' => 'example-co'] && identify_board('https://ats.rippling.com/en-GB/example-co/jobs')['slug'] === 'example-co');

// --- Brave Search can be switched off ---
require_once APP_ROOT . '/includes/search/brave.php';
$_saved_tuning = read_tuning_settings();
set_setting_json('tuning', array_merge($_saved_tuning, ['brave_search_enabled' => false]));
check('brave: switched off, no search may be made', str_contains((string) brave_blocked_reason(), 'switched off'));
set_setting_json('tuning', array_merge($_saved_tuning, ['brave_search_enabled' => true]));
check('brave: switched on, the usual reasons apply', !str_contains((string) brave_blocked_reason(), 'switched off'));
set_setting_json('tuning', $_saved_tuning);
