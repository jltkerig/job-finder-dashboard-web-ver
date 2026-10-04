<?php
// Saving your profile from the Dashboard, and saving one job title from Recent Searches.

declare(strict_types=1);

if ($path === '/profile/save-title') {
    $title = proper_title(mb_substr(trim((string) (request_json()['title'] ?? '')), 0, 255));
    if ($title === '') {
        api_error('E3301', 'Enter a job title.');
    }
    $profile = get_user_profile();
    if (in_array(mb_strtolower($title), array_map('mb_strtolower', $profile['job_titles']), true)) {
        json_out(['status' => 'already_saved']);
    }
    $profile['job_titles'][] = $title;
    if (!save_user_profile(['first_name' => $profile['first_name'], 'last_name' => $profile['last_name'],
        'state' => $profile['state'], 'job_titles' => $profile['job_titles'], 'cities' => $profile['cities']])) {
        api_error('E3302', 'Could not save the job title.', 500);
    }
    json_out(['status' => 'saved', 'profile_version' => profile_version(get_user_profile())]);
}

$read_list = function (string $key): array {
    $value = json_decode((string) ($_POST[$key] ?? '[]'), true);
    return is_array($value) ? $value : [];
};
$titles = [];
foreach (preg_split('/[,\n]+/', mb_substr((string) ($_POST['job_titles'] ?? ''), 0, 5000)) as $part) {
    $title = proper_title(mb_substr(trim($part), 0, 255));
    if ($title !== '' && !in_array(mb_strtolower($title), array_map('mb_strtolower', $titles), true)) {
        $titles[] = $title;
    }
}
// The primary title has its own box; it is always searched too, so it leads the title list.
$primary = proper_title(mb_substr(trim((string) ($_POST['primary_job_title'] ?? '')), 0, 255));
if ($primary !== '') {
    $titles = array_merge([$primary], array_values(array_filter($titles, fn($t) => mb_strtolower($t) !== mb_strtolower($primary))));
}
$saved = get_user_profile();
$sent_version = (string) ($_POST['profile_version'] ?? '');
if ($sent_version !== '' && $sent_version !== profile_version($saved)) {
    redirect('/dashboard?profile_changed=1'); // changed elsewhere since this page loaded, so it isn't overwritten
}
$home_zip = substr(preg_replace('/\D/', '', (string) ($_POST['home_zip'] ?? '')), 0, 5);
if ($home_zip !== '' && strlen($home_zip) !== 5) {
    $home_zip = ''; // a half-typed ZIP is dropped rather than saved
}
$avatar = (string) ($_POST['avatar_data'] ?? '');
if ($avatar !== '' && (!preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $avatar) || strlen($avatar) > 550000)) {
    http_response_code(400);
    exit('That photo could not be saved.');
}
$skills = $read_list('skills_json');
save_user_profile([
    'first_name' => mb_substr(trim((string) ($_POST['first_name'] ?? '')), 0, 100),
    'last_name' => mb_substr(trim((string) ($_POST['last_name'] ?? '')), 0, 100),
    'state' => mb_substr(trim((string) ($_POST['state'] ?? '')), 0, 100),
    'job_titles' => $titles,
    'cities' => $read_list('cities_json'),
    'home_location' => mb_substr(trim((string) ($_POST['home_location'] ?? '')), 0, 150),
    'home_zip' => $home_zip,
    'primary_job_title' => $primary,
    'skills' => $skills,
    'work_history' => $read_list('work_history_json'),
    'education' => $read_list('education_json'),
    'linkedin_url' => (string) ($_POST['linkedin_url'] ?? ''),
    'portfolio_url' => (string) ($_POST['portfolio_url'] ?? ''),
    'avatar_data' => array_key_exists('avatar_data', $_POST) ? $avatar : null,
    'work_preferences' => array_values(array_intersect((array) ($_POST['work_preferences'] ?? []), WORK_PREFERENCES)),
]);
// Changed skills: saved listings are re-read so they also list skills the current skills list recognizes.
$before = array_map('mb_strtolower', $saved['skills']);
$after = array_values(array_unique(array_map('mb_strtolower', array_map('strval', $skills))));
sort($before);
sort($after);
redirect($before !== $after ? '/dashboard?fit_updated=' . refresh_job_fit() : '/dashboard');
