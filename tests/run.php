<?php
// Tests: php -d extension=pdo_sqlite tests/run.php
// They use a throwaway database in the temp folder, never data/jobfinder.sqlite, and make no network requests.

declare(strict_types=1);

$db = tempnam(sys_get_temp_dir(), 'jobfinder-test');
$test_config = tempnam(sys_get_temp_dir(), "jobfinder-config");  // never the real config.php (its password, keys)
file_put_contents($test_config, "<?php return ['db' => ['driver' => 'sqlite']];");
putenv("JOBFINDER_CONFIG=$test_config");
putenv("JOBFINDER_DB=$db");
require __DIR__ . '/../includes/bootstrap.php';
require APP_ROOT . '/includes/listings.php';
require APP_ROOT . '/includes/search/runner.php';
require APP_ROOT . '/includes/documents.php';

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failed += $ok ? 0 : 1;
}

function job(array $over = []): array
{
    return $over + ['name' => 'Acme', 'title' => 'Web Designer', 'career_credibility' => 6, 'domain' => 'acme.com',
        'career_url' => 'https://acme.com/jobs/1', 'source_url' => 'https://acme.com/jobs/1', 'country' => 'United States', 'state' => 'MD',
        'usa_credibility' => 7, 'work_arrangement' => 'Hybrid', 'skills' => ['HTML', 'CSS'], 'details' => ['location' => 'Bel Air, MD']];
}

// --- Saving jobs and the buttons on them ---
check('a new job is saved', save_company(job()));
check('the same posting again only updates it', !save_company(job(['title' => 'Senior Web Designer'])));
check('tracking codes do not make it a new posting', !save_company(job(['source_url' => 'https://acme.com/jobs/1/?utm_source=x', 'title' => 'Senior Web Designer'])));
$id = (int) value('SELECT id FROM companies');
check('updated in place', value('SELECT career_job_title FROM companies WHERE id = ?', [$id]) === 'Senior Web Designer');
check('shows in Search results', count(get_companies()) === 1);
keep_listings([$id]);
check('Keep saves it', count(get_kept_companies()) === 1 && get_dashboard_counts()['saved'] === 1);
update_kept($id, 'Applied', 'Sent résumé');
check('status and notes saved', row('SELECT application_status, notes FROM companies WHERE id = ?', [$id]) == ['application_status' => 'Applied', 'notes' => 'Sent résumé']);
update_kept($id, 'Nonsense', '');
check('unknown status becomes None', value('SELECT application_status FROM companies WHERE id = ?', [$id]) === 'None');
reject_listing($id, 'wrong_role');
check('reject hides it and remembers why', !get_companies() && get_rejected_companies()[0]['rejection_reason'] === 'wrong_role');
check('rejected postings are not found again', isset(rejected_posting_urls()['https://acme.com/jobs/1']));
restore_rejected($id);
check('restore brings it back as it was', count(get_kept_companies()) === 1);
save_company(job(['source_url' => 'https://other.com/jobs/2', 'name' => 'Other Co', 'domain' => 'other.com']));
block_company('Other Co');
check('a blocked company is hidden', count(get_companies()) === 1);
unblock_company('other co');
check('unblocking shows it again', count(get_companies()) === 2);
block_domain('www.Other.com');
check('a blocked domain is hidden', count(get_companies()) === 1 && domain_is_blocked('jobs.other.com'));
check('recommended domains are blocked from the start', domain_is_blocked('indeed.com') && block_details('domains')['indeed.com']['source'] === 'Recommended');
q("UPDATE companies SET job_open_status = 'Closed', closed_at = ? WHERE source_url = ?", [now_utc(-9 * 86400), 'https://other.com/jobs/2']);
check('unsaved jobs closed over a week ago are deleted', tidy_closed_jobs() === 1);

// --- Profile ---
save_user_profile(['first_name' => 'Jamie', 'last_name' => 'K', 'state' => 'MD', 'job_titles' => ['Web Designer', 'UX Designer'],
    'cities' => [['city' => 'Bel Air, MD', 'radius' => 30], ['city' => 'Nowhere', 'radius' => 7]], 'skills' => ['html5', 'Figma', 'figma'],
    'home_zip' => '21014', 'linkedin_url' => 'linkedin.com/in/me', 'portfolio_url' => 'not a link', 'work_preferences' => ['Remote'],
    'education' => [['school' => 'Towson University', 'degree' => 'Wizard'], ['school' => '']],
    'work_history' => [['company' => 'Acme', 'role' => 'Designer', 'dates' => '2020 – 2022', 'city' => 'Towson']]]);
$profile = get_user_profile();
check('profile saved', $profile['first_name'] === 'Jamie' && $profile['job_titles'] === ['UX Designer', 'Web Designer']);
check('only allowed radii are kept', $profile['cities'] === [['city' => 'Bel Air, MD', 'radius_miles' => 30]]);
check('skills written the standard way, no repeats', $profile['skills'] === ['Figma', 'HTML']);
check('links tidied', $profile['linkedin_url'] === 'https://linkedin.com/in/me' && $profile['portfolio_url'] === '');
check('education cleaned', count($profile['education']) === 1 && $profile['education'][0]['degree'] === 'Other');
check('work history details kept', $profile['work_history'][0]['city'] === 'Towson');
check('home state read from the profile', home_state() === 'MD');
$version = profile_version($profile);
save_user_profile(['first_name' => 'Jamie', 'last_name' => 'K', 'state' => 'MD', 'job_titles' => ['Web Designer'], 'cities' => []]);
check('the profile fingerprint changes when it changes', profile_version(get_user_profile()) !== $version);

// --- Recent searches and skips ---
record_search_history('web designer, ux designer', 'MD', [['city' => 'Bel Air, MD', 'radius' => 30]]);
record_search_history('Web Designer, UX Designer', 'MD', [['city' => 'Bel Air, MD', 'radius' => 30]]);
$history = get_search_history();
check('the same search is listed once', count($history) === 1 && $history[0]['main_title'] === 'Web Designer' && $history[0]['other_titles'] === ['UX Designer']);
record_skip('https://x.com/a', 'A page', 'Directory or marketplace page');
check('a fresh skip is not checked again yet', recently_skipped('https://x.com/a'));
record_skip('https://x.com/b', 'B page', 'Page unavailable');
check('a temporary failure is retried next search', !recently_skipped('https://x.com/b'));
[$items, $total] = search_skips_page('marketplace', 1);
check('skips can be searched', $total === 1 && $items[0]['url'] === 'https://x.com/a');

// --- Brave usage ---
$sep30 = gmmktime(23, 59, 0, 9, 30, 2026);
$oct1 = gmmktime(0, 0, 1, 10, 1, 2026);
brave_record_search($sep30);
brave_record_search($sep30);
check('brave month count', brave_counts($sep30)['month'] === 2);
check('brave month rolls over at 00:00 UTC on the 1st', brave_counts($oct1)['month'] === 0);
brave_record_search($oct1);
$c = brave_counts($oct1);
check('brave total keeps counting across months', $c['month'] === 1 && $c['total'] === 3 && $c['since'] === '2026-09-30');
check('brave history per month', $c['months'] === ['2026-09' => 2, '2026-10' => 1]);
check('brave next reset', gmdate('Y-m-d H:i', brave_next_reset(gmmktime(5, 0, 0, 12, 15, 2026))) === '2027-01-01 00:00');

// --- Tuning ---
[$updated, $error] = apply_tuning_form(['search_time_limit_minutes' => '30', 'max_search_results' => '5', 'max_search_pages' => '1',
    'request_delay_seconds' => '1.5', 'search_query_delay_seconds' => '1.1', 'website_timeout_seconds' => '15', 'parallel_page_fetches' => '6',
    'stop_after_empty_queries' => '8', 'usa_only' => 'on'], []);
check('tuning form saved', $error === null && $updated['request_delay_seconds'] === 1.5 && $updated['usa_only'] === true && $updated['related_titles'] === false);
[, $error] = apply_tuning_form(['search_time_limit_minutes' => '999'] + $updated, []);
check('out-of-range tuning refused', str_contains((string) $error, 'between'));

// --- Search helpers ---
check('web queries start with the company boards', str_contains(web_queries(['state' => 'Maryland', 'cities' => [], 'selected_titles' => ['Web Designer'],
    'query_titles' => ['Web Designer']])[0], 'site:greenhouse.io'));
check('same job across sites', job_match_key('Acme Inc.', 'Web Designer (Hybrid)', 'Towson, MD', 'Hybrid') === job_match_key('ACME', 'web designer', 'Towson, Maryland', 'Hybrid'));
check('remote jobs match without a city', job_match_key('Acme', 'Web Designer', '', 'Remote') === 'acme|web designer|remote');
check('board recognised from its address', identify_board('https://jobs.ashbyhq.com/aegis/123') === ['system' => 'ashby', 'slug' => 'aegis']);
check('board names a company might use', board_slug_candidates('Acme Labs Inc') === ['acme-labs', 'acmelabs', 'acme']);
check('verification label', verification_label(['ats_posting' => ['system' => 'lever']], null, 'Acme', 'https://x.com') === "Posted on the company's own Lever hiring board");
check('job site places', search_places('Maryland', [['city' => 'Bel Air, MD', 'radius' => 30], ['city' => 'Delaware', 'radius' => 50]]) === [['Bel Air, MD', 30], ['Delaware', null]]);
check('city targets from the bundled map data', prepare_city_targets('MD', [['city' => 'Bel Air', 'radius' => 30]])[0]['lat'] > 39);
$validated = validate_search_criteria('Web Designer', 'MD', [['city' => 'Towson, MD', 'radius' => 7], ['city' => 'Towson, MD', 'radius' => 10]]);
check('search criteria cleaned', $validated[2] === [['city' => 'Towson, MD', 'radius' => 10]] && $validated[3] === null);
check('drive time from home', describe_trip('21014', null, 'Towson, MD')['miles'] > 10);

// --- Résumés ---
$zip = new ZipArchive();
$docx = sys_get_temp_dir() . '/test-resume.docx';
$zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Jane Doe</w:t></w:r></w:p><w:p><w:r><w:t>HTML, CSS &amp; Figma</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$text = docx_text((string) file_get_contents($docx));
check('Word résumé read', str_contains($text, 'Jane Doe') && str_contains($text, 'HTML, CSS & Figma'));
$suggestions = resume_suggestions($text);
check('résumé suggestions', $suggestions['first_name'] === 'Jane' && $suggestions['skills'] === ['HTML', 'CSS', 'Figma']);
$pdf = "%PDF-1.4\n1 0 obj << /Type /Page /Contents 2 0 R >> endobj\n2 0 obj << /Length 60 >> stream\nBT /F1 12 Tf 72 720 Td (Jane Doe) Tj 0 -14 Td (Web Designer) Tj ET\nendstream endobj\n%%EOF";
check('PDF résumé read', str_contains(pdf_text($pdf), "Jane Doe\nWeb Designer"));
@unlink($docx);

// --- Résumé Builder: drawing PDFs, measuring them, the connector, suggestions ---
// Its files go to a throwaway folder, never data/resume; fonts are never downloaded.
$rb_dir = sys_get_temp_dir() . '/jobfinder-test-resume-' . getmypid();
putenv("JOBFINDER_RESUME_DIR=$rb_dir");
putenv('JOBFINDER_OFFLINE=1');
require_once APP_ROOT . '/includes/connector.php';
require_once APP_ROOT . '/includes/resume/suggestions.php';
q("UPDATE user_profile SET first_name = 'Jane', last_name = 'Doe' WHERE id = 1");

$resume = ['full_name' => 'Jane Doe', 'headline' => 'Web Designer', 'contact' => ['jane@example.com', 'Bel Air, MD'],
    'sections' => [['title' => 'Experience', 'items' => array_fill(0, 3, ['heading' => 'Web Designer', 'subheading' => 'Acme', 'dates' => '2020 – Present',
        'bullets' => ['Redesigned the main site and cut load time by half.', 'Led a team of three designers.']])],
        ['title' => 'Skills', 'text' => 'HTML, CSS, Figma']]];
$layout = merge_layout(['font_family' => 'Arial', 'accent_color' => '#1F5F8B', 'heading_rule' => true]);
$pdf = render_resume(clean_resume_content($resume), $layout);
check('résumé PDF drawn', str_starts_with($pdf, '%PDF-1.4') && str_contains(pdf_text($pdf), 'Jane Doe'));
$page = first_pdf_page($pdf);
$measured = measure_pdf_layout($page);
check('its layout measures back', $measured['body_size'] == 10.5 && $measured['name_size'] == 22.0 && $measured['accent_color'] === '#1F5F8B'
    && $measured['heading_case'] === 'upper' && $measured['heading_rule'] && $measured['margin_in'] == 0.75);
$facts = analyze_pdf_page($page);
check('its design is described', $facts['page'] === 'Letter' && $facts['headings']['items'] === ['EXPERIENCE', 'SKILLS']
    && $facts['bullets']['count'] === 6 && $facts['dates']['right'] === 3 && $facts['body']['spacing'] == 1.3);
check('design rows written', str_contains(design_notes_text(describe_design($facts, $measured)), 'Section Order: EXPERIENCE → SKILLS'));
$long = $resume;
$long['sections'][0]['items'] = array_fill(0, 14, $resume['sections'][0]['items'][0]);
check('a long résumé runs onto a second page', first_pdf_page(render_resume(clean_resume_content($long), $layout))['pages'] === 2);
$letter = render_cover_letter(clean_letter_content(['full_name' => 'Jane Doe', 'paragraphs' => ['Hello.', 'Thanks.']]), $layout);
check('cover letter drawn', str_contains(pdf_text($letter), 'Hello.') && str_contains(pdf_text($letter), date('F j, Y')));
check('measured layouts are brought into range', fit_layout(['body_size' => 6, 'bullet_char' => '?'])['body_size'] == 7
    && !isset(fit_layout(['bullet_char' => '?'])['bullet_char']));

check('connector starts off', !connector_enabled() && oauth_register(['redirect_uris' => ['https://claude.ai/cb']])[0] === 403);
set_setting('connector_enabled', '1');
check('only Claude can register', oauth_register(['redirect_uris' => ['https://evil.example/cb']])[0] === 400
    && oauth_register(['redirect_uris' => ['https://claude.ai.evil.example/cb']])[0] === 400
    && oauth_register(['redirect_uris' => ['http://claude.ai/cb']])[0] === 400);
[$status, $client] = oauth_register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);
$verifier = str_repeat('v', 50);
$params = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'state' => 's1'];
check('approval request checked', check_authorize_request($params)[1] === null
    && check_authorize_request(['code_challenge_method' => 'plain'] + $params)[1] !== null
    && check_authorize_request(['redirect_uri' => 'https://claude.ai/other'] + $params)[1] !== null);
parse_str((string) parse_url(issue_auth_code($params), PHP_URL_QUERY), $back);
$exchange = ['grant_type' => 'authorization_code', 'client_id' => $client['client_id'], 'code' => $back['code'],
    'redirect_uri' => $params['redirect_uri'], 'code_verifier' => $verifier];
[$status, $tokens] = oauth_token($exchange);
check('code becomes tokens once', $status === 200 && $back['state'] === 's1' && oauth_token($exchange)[0] === 400);
check('only hashes are stored', !value('SELECT COUNT(*) FROM oauth_tokens WHERE token_hash = ?', [$tokens['access_token']])
    && value('SELECT COUNT(*) FROM oauth_tokens WHERE token_hash = ?', [token_hash($tokens['access_token'])]) == 1);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
check('access token accepted', bearer_client() === $client['client_id']);
$reply = mcp_message(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'save_resume', 'arguments' => ['content' => $resume, 'company' => 'Acme', 'job_title' => 'Web Designer']]]);
check('connector saves a résumé', !$reply['result']['isError'] && str_contains($reply['result']['content'][0]['text'], 'Jane_Doe_Acme_Web_Designer_'));
$reply = mcp_message(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_build', 'arguments' => ['file_name' => '../config.php']]]);
check('connector stays in its folder', $reply['result']['isError']);
check('tools listed', count(mcp_message(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])['result']['tools']) === 12);
[$status, $refreshed] = oauth_token(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => $tokens['refresh_token']]);
check('refresh token works once', $status === 200 && bearer_client() === null
    && oauth_token(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => $tokens['refresh_token']])[0] === 400);
disconnect_all();
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshed['access_token'];
check('disconnecting cuts tokens off', bearer_client() === null);
unset($_SERVER['HTTP_AUTHORIZATION']);
set_setting('connector_enabled', '0');

$contacts = "Bright Studio\n12 Harbor Way\n(410) 555-0100\n2019 - Present\nDana Fields\nCreative Director\ndana@bright.example.com\n\n"
    . "Sam Lee, IT Director\n(410) 555-0123\nsam@example.com\nSupervisor reference known for 3 year(s).";
$found = contacts_in($contacts);
check('contact list read', $found['jobs'][0]['street'] === '12 Harbor Way' && $found['jobs'][0]['supervisor_name'] === 'Dana Fields'
    && array_column($found['people'], 'name') === ['Dana Fields', 'Sam Lee']);
$profile = ['first_name' => 'Jane', 'last_name' => 'Doe', 'skills' => [], 'work_history' => [['role' => 'Designer', 'company' => 'Bright Studio']]];
$texts = [[['id' => 'x', 'label' => 'Contacts'], $contacts]];
check('missing job details suggested', detail_suggestions($profile, $texts)[0]['details']['street'] === '12 Harbor Way');
check('people suggested as references', reference_suggestions($profile, [], $texts)[1]['relationship'] === 'Supervisor');
dismiss_suggestion('reference', 'sam lee');
check('a dismissed person stays gone', count(reference_suggestions($profile, [], $texts)) === 1);
check('jobs sort newest first', job_start(['dates' => 'Mar 2020 - Present']) > job_start(['dates' => '12/2019 - 2020']));

remove_tree($rb_dir);

// --- Web Job Scraper uploads (no network: company-website lookups are not run here) ---
require_once APP_ROOT . '/includes/captures.php';
q('DELETE FROM user_profile_job_titles');
q("INSERT INTO user_profile_job_titles (profile_id, job_title) VALUES (1, 'Web Designer')");
q("INSERT INTO blocked_companies (name_key, name, source) VALUES ('shady', 'Shady Co', 'User')");
$capture = fn(string $site, array $jobs) => json_encode(['source' => 'web-job-scraper', 'site' => $site, 'jobs' => $jobs]);
$job = fn(array $over) => $over + ['title' => 'Web Designer', 'company' => 'Pixel Works Inc', 'location' => 'Austin, TX',
    'url' => 'https://www.linkedin.com/jobs/view/111/', 'description' => 'Build sites with HTML and CSS.'];
$first_file = $capture('linkedin', [
    $job([]),
    $job(['title' => 'Plumber', 'url' => 'https://www.linkedin.com/jobs/view/222/']),
    $job(['company' => 'Shady Co', 'url' => 'https://www.linkedin.com/jobs/view/333/']),
    $job(['title' => 'Accountant', 'applied' => true, 'url' => 'https://www.linkedin.com/jobs/view/444/']),
    $job(['title' => 'Web Designer (Verified job)', 'company' => 'Far Away LLC', 'location' => '',
        'description' => 'This role is full time in Irving, TX.', 'url' => 'https://www.linkedin.com/jobs/view/555/']),
]);
$first = import_capture_files([['jobs.json', $first_file]]);
check('capture file imported', $first['counts']['added'] === 3 && $first['counts']['skipped'] === 2);
check('wrong titles and blocked companies filtered out', !value("SELECT COUNT(*) FROM companies WHERE career_job_title = 'Plumber' OR name = 'Shady Co'"));
check('a job you applied to is saved as Applied', row("SELECT is_kept, application_status FROM companies WHERE career_job_title = 'Accountant'")
    == ['is_kept' => 1, 'application_status' => 'Applied']);
$far = row("SELECT career_job_title, listing_details FROM companies WHERE name = 'Far Away LLC'");
check('badge dropped and place read from the description', $far['career_job_title'] === 'Web Designer'
    && json_decode($far['listing_details'], true)['location'] === 'Irving, TX');
check('the same file twice is skipped', import_capture_files([['jobs.json', $first_file]])['error'] === 'Nothing new to import.');
$second = import_capture_files([['indeed.json', $capture('indeed', [$job(['company' => 'Pixel Works', 'url' => 'https://www.indeed.com/viewjob?jk=9'])])]]);
$details = json_decode((string) value("SELECT listing_details FROM companies WHERE source_url LIKE '%linkedin.com/jobs/view/111%'"), true);
check('the same job on another site is linked, not added', $second['counts']['linked'] === 1 && $details['also_on'][0]['site'] === 'Indeed');
check('not a capture file', str_contains(import_capture_files([['x.json', '{"a":1}']])['log'][0], 'E6005'));
check('company lookups waiting', count(capture_lookups_pending()) === 3);
check('description wording read', arrangement_from_description('in our office 3 days a week') === 'Hybrid'
    && arrangement_from_description('This is a remote position.') === 'Remote' && location_from_description('Greater Boston area') === 'Boston, MA');
echo "\nsign-in\n";
set_setting('password_hash', password_hash('correct horse battery', PASSWORD_DEFAULT));
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
check('right password', check_password('admin', 'correct horse battery') === null);
for ($i = 0; $i < LOGIN_TRIES; $i++) {
    check_password('admin', 'wrong');
}
check('one address locked after 5 wrong', str_starts_with((string) check_password('admin', 'correct horse battery'), 'Too many'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check('another address still signs in', check_password('admin', 'correct horse battery') === null);
set_setting('login_fails_all', LOGIN_TRIES_ALL . ':' . time());
check('everyone paused after 30 wrong', str_starts_with((string) check_password('admin', 'correct horse battery'), 'Too many'));
set_setting('login_fails_all', LOGIN_TRIES_ALL . ':' . (time() - LOGIN_LOCK_SECONDS - 1));
check('the pause ends', check_password('admin', 'correct horse battery') === null);
$_SESSION = ['user' => 'me', 'since' => time(), 'seen' => time() - SESSION_IDLE_SECONDS - 1];
check('idle session signed out', !logged_in());
$_SESSION = ['user' => 'me', 'since' => time() - SESSION_MAX_SECONDS - 1, 'seen' => time()];
check('old session signed out', !logged_in());
$_SESSION = ['user' => 'me', 'since' => time(), 'seen' => time()];
check('fresh session kept', logged_in());
check('data folder', data_path('resume') === rtrim(APP_ROOT, '/') . '/data/resume' || str_ends_with(data_path('resume'), '/data/resume'));
echo "\npassword change and Cloudflare\n";
$_SESSION = ['user' => 'me', 'since' => time() - 5, 'seen' => time()];
check('short password refused', change_password('short', 'short') === 'Use at least 10 characters.');
check('mismatch refused', change_password('new password 1', 'new password 2') === "The two passwords don't match.");
q("INSERT INTO oauth_tokens (token_hash, kind, client_id, grant_id, expires_at, created_at) VALUES ('x', 'access', 'c', 'g', '2099-01-01 00:00:00', '2026-10-04 00:00:00')");
$old_session = $_SESSION;
check('new password saved', change_password('new password 1', 'new password 1') === null
    && check_password('admin', 'new password 1') === null && check_password('admin', 'correct horse battery') !== null);
check('Claude disconnected', (int) value('SELECT COUNT(*) FROM oauth_tokens') === 0);
$_SESSION = $old_session;
check('other browsers signed out', !logged_in());
check('setup code needed in config', str_contains((string) check_setup_code('anything'), 'config.php'));
check('IPv4 range', ip_in_range('104.16.5.9', '104.16.0.0/13') && !ip_in_range('104.24.0.1', '104.16.0.0/13'));
check('IPv6 range', ip_in_range('2a06:98c7::1', '2a06:98c0::/29') && !ip_in_range('2a06:98c8::1', '2a06:98c0::/29'));
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check('visitor address not faked from outside Cloudflare', client_ip() === '198.51.100.7');
$_SERVER['REMOTE_ADDR'] = '162.158.1.1';
check('visitor address from Cloudflare', client_ip() === '203.0.113.9');
@unlink($db);
@unlink($test_config);
echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
