<?php
// Job Skill Fitting: saving how much each skill counts, and the same ranking as JSON.

declare(strict_types=1);
require_once APP_ROOT . '/includes/skill_fitting.php';

switch ($path) {
    case '/job-skill-fitting/weights':
        // The form sends weight-<skill> = core, normal or nice for each of your skills.
        $skills = get_user_profile()['skills'] ?? [];
        $choices = [];
        foreach ($skills as $skill) {
            $key = 'weight-' . str_replace(['.', ' '], '_', $skill);  // PHP turns dots and spaces in field names into underscores
            $choices[$skill] = (string) ($_POST[$key] ?? $_POST['weight-' . $skill] ?? 'normal');
        }
        try {
            save_skill_weights($choices);
        } catch (Throwable $problem) {
            error_log('Could not save skill weights: ' . $problem->getMessage());
        }
        redirect('/job-skill-fitting');

    case '/job-skill-fitting.json':
        try {
            [$skills, $jobs, $weights] = skill_fitted_jobs();
        } catch (Throwable $problem) {
            api_error('skill_fitting_read_failed', 'Could not read jobs for skill fitting.', 500);
        }
        json_out(['skills' => $skills, 'weights' => (object) $weights, 'jobs' => $jobs]);
}
