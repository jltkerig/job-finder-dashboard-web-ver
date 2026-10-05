<?php
// "Export for Web Job Scraper": a small file with what the extension needs to mark jobs that may fit you and show how
// far they are, so it works on its own with no connection to this site or the desktop app. Load it in the extension's
// popup (Load profile). Same fields as the desktop's /extension/fit-profile, plus your home's map point.

declare(strict_types=1);
require_once APP_ROOT . '/lib/onet.php';
require_once APP_ROOT . '/lib/places.php';
require_once APP_ROOT . '/lib/skill_data.php';

$profile = get_user_profile();
$titles = [];
foreach (array_merge([$profile['primary_job_title']], $profile['job_titles']) as $title) {
    $title = spelling_fix((string) $title); // typos fixed, as in searches
    if ($title !== '' && !in_array(mb_strtolower($title), array_map('mb_strtolower', $titles), true)) {
        $titles[] = $title;
    }
}
$point = zip_point($profile['home_zip']);
$ambiguous = AMBIGUOUS_SKILLS;
sort($ambiguous);
header('Content-Disposition: attachment; filename="job-finder-profile.json"');
json_out([
    'source' => 'job-finder', 'kind' => 'fit-profile', 'exported_at' => date('c'),
    'titles' => $titles, 'skills' => $profile['skills'], 'work_preferences' => $profile['work_preferences'],
    'blocked_companies' => blocked_company_list(), // hidden on LinkedIn too
    'skill_aliases' => SKILL_ALIASES, 'ambiguous_skills' => $ambiguous,
    'home' => ['zip' => $profile['home_zip'], 'state' => home_state(), 'lat' => $point[0] ?? null, 'lon' => $point[1] ?? null],
]);
