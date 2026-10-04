<?php
// Type-ahead and suggestions as you type: job titles, U.S. places, related titles and related skills.

declare(strict_types=1);

$typed = (string) ($_GET['q'] ?? '');
switch ($path) {
    case '/job-title-matches':
        json_out(['matches' => title_matches(mb_substr($typed, 0, 100))]);
    case '/city-matches':
        json_out(['matches' => city_matches(mb_substr($typed, 0, 100), home_state())]);
    case '/job-title-suggestions':
        json_out(['suggestions' => related_job_title_suggestions(mb_substr(trim((string) ($_GET['titles'] ?? '')), 0, 1000))]);
    case '/skill-related':
        json_out(['skills' => related_skills_for(mb_substr(trim($typed), 0, 80))]);
}
