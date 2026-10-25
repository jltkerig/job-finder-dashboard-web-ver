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

    case '/job-skill-fitting/weight':
        // One skill from the Dashboard's skill boxes: {"skill": ..., "weight": core|normal|nice}.
        $data = request_json();
        $skill = mb_substr(trim((string) ($data['skill'] ?? '')), 0, 100);
        $weight = $data['weight'] ?? null;
        if ($skill === '' || !is_string($weight) || !isset(SKILL_WEIGHT_LABELS[$weight])) {
            api_error('skill_weight_invalid', 'Choose Core, Normal or Nice to have for a skill.', 400);
        }
        try {
            $choices = load_skill_weights();
            $choices[$skill] = $weight;
            save_skill_weights($choices);
        } catch (Throwable $problem) {
            error_log('Could not save the skill weight: ' . $problem->getMessage());
            api_error('skill_weight_failed', 'Could not save the skill weight.', 500);
        }
        json_out(['skill' => $skill, 'weight' => $weight]);

    case '/job-skill-fitting.json':
        try {
            [$skills, $jobs, $weights] = skill_fitted_jobs();
        } catch (Throwable $problem) {
            api_error('skill_fitting_read_failed', 'Could not read jobs for skill fitting.', 500);
        }
        json_out(['skills' => $skills, 'weights' => (object) $weights, 'jobs' => $jobs]);
}
