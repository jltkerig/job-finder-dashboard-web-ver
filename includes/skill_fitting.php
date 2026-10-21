<?php
// Job Skill Fitting: open jobs already found, ranked by the skills you have. Job titles are not used. Ported from the
// desktop app's skill_fitting package (scoring.py, sorting.py, weights.py, routes.py).

declare(strict_types=1);

require_once __DIR__ . '/skills.php';

const MIN_JOB_SKILLS = 2;  // a listing naming fewer skills says too little to judge
const SKILL_WEIGHT_LABELS = ['core' => 'Core', 'normal' => 'Normal', 'nice' => 'Nice to have'];
const SKILL_WEIGHT_VALUES = ['core' => 3.0, 'nice' => 0.5];  // a skill you haven't changed counts 1
const SKILL_FIT_PAGE_LIMIT = 200;
// Short forms the shared alias list doesn't know.
const SKILL_EXTRA_ALIASES = ['js' => 'JavaScript', 'ts' => 'TypeScript', 'node' => 'Node.js', 'postgres' => 'PostgreSQL', 'k8s' => 'Kubernetes'];

/** Skill names in one spelling: the extra short forms first, then the shared alias list. */
function skill_fit_normalize(array $values): array
{
    return normalize_skills(array_map(fn($value) => SKILL_EXTRA_ALIASES[mb_strtolower(trim((string) $value))] ?? $value, $values));
}

/** {skill: 'core'|'nice'} with the skill names spelled the way the listings are, dropping anything but core or nice. */
function skill_fit_normalize_weights(?array $weights): array
{
    $out = [];
    foreach ($weights ?? [] as $skill => $kind) {
        $names = skill_fit_normalize([(string) $skill]);
        if (isset(SKILL_WEIGHT_VALUES[$kind]) && $names) {
            $out[$names[0]] = $kind;
        }
    }
    return $out;
}

/**
 * How well your skills cover the job's. Both sides go through the same alias list (JS = JavaScript).
 * $weights: {skill: 'core'|'nice'}. A skill you have counts 3 if Core and half if Nice to have; every other skill
 * counts 1. Returns ['score', 'matched', 'missing']; score is the weighted percent of the job's skills you have, or null
 * when the listing names too few skills to judge.
 */
function skill_fit_score(array $user_skills, array $job_skills, ?array $weights = null): array
{
    $mine = array_flip(array_map('mb_strtolower', skill_fit_normalize($user_skills)));
    $theirs = skill_fit_normalize($job_skills);
    if (count($theirs) < MIN_JOB_SKILLS) {
        return ['score' => null, 'matched' => [], 'missing' => []];
    }
    $by_skill = [];
    foreach (skill_fit_normalize_weights($weights) as $skill => $kind) {
        $by_skill[mb_strtolower($skill)] = SKILL_WEIGHT_VALUES[$kind];
    }
    $matched = array_values(array_filter($theirs, fn($s) => isset($mine[mb_strtolower($s)])));
    $missing = array_values(array_filter($theirs, fn($s) => !isset($mine[mb_strtolower($s)])));
    $have = 0.0;
    foreach ($matched as $skill) {
        $have += $by_skill[mb_strtolower($skill)] ?? 1.0;
    }
    $total = $have + count($missing);
    return ['score' => (int) round(100 * $have / $total), 'matched' => $matched, 'missing' => $missing];
}

/** A listing's skills from the JSON text saved with it; [] when it is missing or malformed. */
function skill_fit_parse($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $value = json_decode((string) ($raw ?: '[]'), true);
    return is_array($value) ? $value : [];
}

/** Jobs that could be scored, each with the score added, best fit first (ties: more matched skills first). */
function skill_fit_rank(array $user_skills, array $jobs, ?array $weights = null): array
{
    $ranked = [];
    foreach ($jobs as $job) {
        $fit = skill_fit_score($user_skills, skill_fit_parse($job['listing_skills'] ?? null), $weights);
        if ($fit['score'] !== null) {
            $ranked[] = $job + $fit;
        }
    }
    usort($ranked, fn($a, $b) => [$b['score'], count($b['matched']), (string) ($a['career_job_title'] ?? '')]
        <=> [$a['score'], count($a['matched']), (string) ($b['career_job_title'] ?? '')]);
    return $ranked;
}

/** Adds ['skill_fit'] to each result: the Job Fit Minus Titles score, counting your Core and Nice to have skills. */
function add_skill_fit(array &$companies, array $skills, ?array $weights = null): void
{
    foreach ($companies as &$company) {
        $company['skill_fit'] = skill_fit_score($skills, skill_fit_parse($company['listing_skills'] ?? null), $weights);
    }
    unset($company);
}

/** {skill: 'core'|'nice'} for the skills you changed from Normal. */
function load_skill_weights(): array
{
    $stored = setting_json('skill_weights', []);
    return is_array($stored) ? array_filter($stored, fn($kind) => isset(SKILL_WEIGHT_VALUES[$kind])) : [];
}

/** $choices: {skill: 'core'|'normal'|'nice'}. Normal is not stored, so only changes are kept. */
function save_skill_weights(array $choices): void
{
    $weights = [];
    foreach ($choices as $skill => $kind) {
        if (isset(SKILL_WEIGHT_VALUES[$kind])) {
            $weights[(string) $skill] = $kind;
        }
    }
    set_setting_json('skill_weights', $weights);
}

/** Open, unrejected jobs with listing skills, ranked by your skills. [$skills, $jobs, $weights] */
function skill_fitted_jobs(): array
{
    $skills = get_user_profile()['skills'] ?? [];
    $weights = load_skill_weights();
    $jobs = rows("SELECT id, name, career_job_title, career_url, source_url, city, state, work_arrangement, listing_skills
        FROM companies WHERE is_rejected = 0 AND job_open_status = 'Open' AND listing_skills IS NOT NULL");
    return [$skills, array_slice(skill_fit_rank($skills, $jobs, $weights), 0, SKILL_FIT_PAGE_LIMIT), $weights];
}
