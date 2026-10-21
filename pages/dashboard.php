<?php
// The Dashboard: your profile, saved jobs and recent searches. Same markup as the desktop Job Finder's
// templates/user-dashboard.html.

declare(strict_types=1);

$companies = get_kept_companies('', '', '', 'date_desc');
$profile = get_user_profile();
require_once APP_ROOT . '/includes/education.php';
if (!$profile['education']) {
    // No schools saved yet: the ones the uploaded résumé lists are saved, so every page shows the same education.
    try {
        require_once APP_ROOT . '/includes/resume/store.php';
        $saved_schools = save_schools_if_none(parse_education(current_resume_text()));
        if ($saved_schools) {
            $profile['education'] = $saved_schools;
        }
    } catch (Throwable $error) {
        error_log('Education from the résumé: ' . $error->getMessage());
    }
}
add_job_fit($companies, $profile['skills']);
require_once APP_ROOT . '/includes/skill_fitting.php';
add_skill_fit($companies, $profile['skills'], load_skill_weights());  // the filters' Job Fit Minus Titles sort
add_drive_times($companies, $profile['home_zip'], home_state());
// Applied jobs (any stage) are listed under Saved Jobs in their own panel, as on the desktop.
$applied_companies = array_values(array_filter($companies, fn($c) => in_array($c['application_status'], APPLIED_STATUSES, true)));
$companies = array_values(array_filter($companies, fn($c) => !in_array($c['application_status'], APPLIED_STATUSES, true)));
require_once APP_ROOT . '/includes/resume/store.php';
require_once APP_ROOT . '/includes/companies.php';
$application_files = files_by_job(); // résumés and cover letters made for each job
$demanded_skills = array_map(fn($pair) => ['skill' => $pair[0], 'count' => $pair[1]], listing_skill_demand($profile['skills']));
$counts = get_dashboard_counts();
$applied_months = applied_by_month();
$applied_elsewhere_count = count(applied_elsewhere());
$skill_suggestions = profile_skill_suggestions($profile);
$fit_updated = isset($_GET['fit_updated']) ? (int) $_GET['fit_updated'] : null;
$profile_changed = !empty($_GET['profile_changed']);
$form_examples = setting_json('form_examples', ['home_zip' => '', 'home_location' => '']);

$title = 'Dashboard';
$page = 'dashboard';
$body_attrs = ' data-form-examples="' . json_attr($form_examples) . '"';

function credibility_badge($score): string
{
    $score = (int) ($score ?? 0);
    $class = $score >= 8 ? 'high' : ($score >= 5 ? 'medium' : 'low');
    return '<a class="credibility-link" href="/credibility-scores" aria-label="How this credibility score is calculated"><span class="credibility-badge credibility-'
        . $class . '">' . ($score * 10) . '%</span></a>';
}

require_once APP_ROOT . '/templates/_dash_filter.php';
$scripts = ['dashboard-filters.js', 'bulk-delete.js', 'applied-date.js', 'reject-reason.js', 'clear-closed.js'];
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <section class="company-section">
    <div class="profile-layout">
      <section class="profile-panel">
        <div class="profile-heading">
          <div>
            <h2>User Profile
            </h2>
            <?php if ($fit_updated !== null): ?><p class="fit-updated" role="status">Skills saved. Job Fit is up to date<?php if ($fit_updated): ?>: <?= $fit_updated ?> saved listing<?= $fit_updated !== 1 ? 's' : '' ?> now list<?= $fit_updated !== 1 ? '' : 's' ?> newly recognized skills<?php endif; ?>. A Search page that is already open shows the new percentages after you reload it.</p><?php endif; ?>
            <p>These job titles and locations are shared with the Search page.
            </p>
          </div>
        </div>
        <?php if ($profile_changed): ?><p class="profile-changed-note" role="alert">Your profile was changed somewhere else after this page loaded, so nothing was saved here. It now shows the latest version: make your change again, then Save Profile.</p><?php endif; ?>
        <form action="/save-profile" method="post" class="profile-form" id="profile-form"><?= csrf_field() ?><input type="hidden" name="profile_version" value="<?= h(profile_version($profile)) ?>" /><input type="hidden" name="cities_json" id="profile-cities-json" value="<?= json_attr($profile['cities']) ?>" /><input type="hidden" name="skills_json" id="skills-json" value="<?= json_attr($profile['skills']) ?>" /><input type="hidden" name="work_history_json" id="work-history-json" value="<?= json_attr($profile['work_history']) ?>" /><input type="hidden" name="education_json" id="education-json" value="<?= json_attr($profile['education']) ?>" /><input type="hidden" name="avatar_data" id="avatar-data" value="<?= h($profile['avatar_data']) ?>" />
          <div class="profile-name-grid">
            <div class="form-field"><label for="first-name">First Name</label><input id="first-name" name="first_name" type="text" value="<?= h($profile['first_name']) ?>" />
            </div>
            <div class="form-field"><label for="last-name">Last Name</label><input id="last-name" name="last_name" type="text" value="<?= h($profile['last_name']) ?>" />
            </div>
            <div class="form-field"><label for="home-zip">Home ZIP</label><input id="home-zip" name="home_zip" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" value="<?= h($profile['home_zip']) ?>" placeholder="<?= h($form_examples['home_zip'] ?? '') ?>" autocomplete="postal-code" />
            </div>
            <div class="form-field"><label for="home-location">Home City, State</label><input id="home-location" name="home_location" type="text" list="city-state-options" value="<?= h($profile['home_location']) ?>" placeholder="<?= h($form_examples['home_location'] ?? '') ?>" autocomplete="off" /><input id="profile-state" name="state" type="hidden" value="<?= h($profile['state']) ?>" />
            </div>
            <div class="form-field profile-link-field"><label for="linkedin-url">LinkedIn Profile</label><input id="linkedin-url" name="linkedin_url" type="text" inputmode="url" maxlength="255" value="<?= h($profile['linkedin_url']) ?>" placeholder="e.g. linkedin.com/in/your-name" autocomplete="url" />
            </div>
            <div class="form-field profile-link-field"><label for="portfolio-url">Portfolio or Website</label><input id="portfolio-url" name="portfolio_url" type="text" inputmode="url" maxlength="255" value="<?= h($profile['portfolio_url']) ?>" placeholder="e.g. yourname.com" autocomplete="url" />
            </div>
          </div>
          <details class="profile-subsection profile-collapse" open data-section="job-titles"><summary class="profile-collapse-summary"><h3>Job Titles</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <div class="form-field"><label for="primary-job-title">Primary Job Title</label><input id="primary-job-title" name="primary_job_title" type="text" data-title-suggest="single" value="<?= h($profile['primary_job_title']) ?>" placeholder="e.g. Web Designer" autocomplete="off" />
            </div>
            <div class="form-field"><label for="job-titles">Other Job Titles to Search For</label>
          <p class="field-help">Separate titles with commas.
              </p><input id="job-titles" name="job_titles" type="text" data-title-suggest="list" data-also-from="primary-job-title" value="<?= h(implode(', ', array_filter($profile['job_titles'], fn($t) => $t !== $profile['primary_job_title']))) ?>" placeholder="Front End Developer, Visual Designer" />
              <div id="title-suggestion-chips" class="title-chips" hidden><span class="field-help">Suggested:</span>
                <div class="chip-row">
                </div>
              </div>
            </div>
          </details>
          <details class="profile-subsection profile-collapse" open data-section="work-preferences"><summary class="profile-collapse-summary"><h3>Work Preferences</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <fieldset class="preference-group">
              <legend>Work Type
              </legend>
              <div class="profile-preferences"><?php foreach (['Part-time', 'Full-time', 'Contract', 'Freelance / Gig'] as $preference): ?><label><input type="checkbox" name="work_preferences[]" value="<?= h($preference) ?>"<?= in_array($preference, $profile['work_preferences'], true) ? ' checked' : '' ?> /><?= h($preference) ?></label><?php endforeach; ?>
              </div>
            </fieldset>
            <fieldset class="preference-group">
              <legend>Work Location
              </legend>
              <div class="profile-preferences"><?php foreach (['Remote', 'Hybrid', 'Onsite'] as $preference): ?><label><input type="checkbox" name="work_preferences[]" value="<?= h($preference) ?>"<?= in_array($preference, $profile['work_preferences'], true) ? ' checked' : '' ?> /><?= h($preference) ?></label><?php endforeach; ?>
              </div>
            </fieldset>
          </details>
          <details class="profile-subsection profile-collapse" open data-section="work-history"><summary class="profile-collapse-summary"><h3>Work History</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <p class="field-help">Add past jobs manually or review suggestions from a résumé.
            </p>
            <p class="field-help" id="work-history-note" data-source="" data-history="[]" hidden>
            </p>
            <div id="work-history-list">
            </div><button class="bordered-button secondary-action" id="add-work-history" type="button">Add Job</button>
          </details>
          <details class="profile-subsection profile-collapse" open data-section="education"><summary class="profile-collapse-summary"><h3>Education</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <p class="field-help">Schools you attended. GPA and minor are optional.
            </p>
            <div id="education-list" data-degrees="<?= json_attr(EDUCATION_DEGREES) ?>">
            </div><button class="bordered-button secondary-action" id="add-education" type="button">Add School</button>
          </details>
          <details class="profile-subsection profile-collapse" open data-section="skills"><summary class="profile-collapse-summary"><h3>Skills</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <p class="field-help">The skills you list here are compared with what each job asks for to work out its Job Fit. Add the ones you really have; suggestions come from your résumé, the jobs Job Finder found and the skills you already added, and appear as you type.
            </p>
            <div id="profile-skill-list" class="skill-chip-list">
            </div>
            <div class="skill-add-row"><input id="new-skill" type="text" list="skill-options" placeholder="Add a skill" aria-label="Add a skill" />
              <datalist id="skill-options"><?php foreach ($skill_suggestions as $skill): ?><option value="<?= h($skill) ?>"></option><?php endforeach; ?>
              </datalist><button class="bordered-button secondary-action" id="add-skill" type="button">Add Skill</button>
            </div>
            <div id="skill-suggestions" class="skill-chip-list" aria-live="polite" data-resume="[]" data-demand="<?= json_attr($demanded_skills) ?>" hidden>
            </div>
          </details>
          <details class="profile-subsection profile-collapse" open data-section="locations"><summary class="profile-collapse-summary"><h3>Locations</h3><span class="summary-chevron" aria-hidden="true"></span></summary>
            <p class="field-help">Job Finder looks for jobs around each city, out to the distance you pick.
            </p>
            <div class="city-add-row"><input id="profile-city-input" type="text" list="city-state-options" placeholder="e.g. Wilmington, DE" autocomplete="off" /><select id="profile-city-radius"><?php foreach (CITY_RADII as $radius): ?><option value="<?= $radius ?>"<?= $radius === 50 ? ' selected' : '' ?>><?= $radius ?> miles</option><?php endforeach; ?></select><button id="add-profile-city" class="bordered-button secondary-action city-add-button" type="button">Add Location</button>
            </div>
            <div id="profile-city-list" class="city-list city-list-below">
            </div>
          </details>
          <datalist id="city-state-options">
          </datalist>
          <div class="profile-save-actions"><button class="bordered-button primary-action" type="submit">Save Profile</button><div class="unsaved-note" id="unsaved-note" role="status" hidden><span>Unsaved changes. Click Save Profile to keep them.</span><button class="bordered-button primary-action" type="submit" form="profile-form">Save Profile</button></div><button class="bordered-button secondary-action" id="upload-resume" type="button">Upload Résumé</button><a class="bordered-button secondary-action" href="/extension-profile" title="A file to load in the Web Job Scraper extension (Load profile), so it can mark jobs that fit and show distances">Export for Web Job Scraper</a><input id="resume-file" type="file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden />
          </div>
        </form>
      </section>
      <div class="profile-side">
      <aside class="profile-summary-card"><button class="profile-avatar" id="change-avatar" type="button" aria-label="Upload or change profile photo"><?php if ($profile['avatar_data']): ?><img src="<?= h($profile['avatar_data']) ?>" alt="" /><?php elseif ($profile['first_name']): ?><?= h(mb_strtoupper(mb_substr($profile['first_name'], 0, 1))) ?><?php else: ?>U<?php endif; ?></button><input id="avatar-file" type="file" accept="image/png,image/jpeg,image/webp" hidden /><small>Click photo to change</small><strong><?= h(trim($profile['first_name'] . ' ' . $profile['last_name']) ?: 'Username') ?></strong><span><?= h($profile['primary_job_title'] ?: 'Add a primary job title') ?></span><span><?= h($profile['home_location'] ?: 'Add your location') ?></span>
      </aside>
      <aside class="profile-stats-card" aria-labelledby="apply-stats-heading">
        <?php $stage = ['applied' => (int) ($counts['applied'] ?? 0) + $applied_elsewhere_count, 'recruiter' => (int) ($counts['recruiter'] ?? 0), 'interview' => (int) ($counts['interview'] ?? 0)]; ?>
        <h2 id="apply-stats-heading">Job Apply Stats</h2>
        <dl>
          <div class="stat-total"><dt>Applied In Total</dt><dd data-stat="total"><?= $stage['applied'] + $stage['recruiter'] + $stage['interview'] ?></dd></div>
          <div><dt>Applied</dt><dd data-stat="applied"><?= $stage['applied'] ?></dd></div>
          <div><dt>Talking With Recruiter</dt><dd><?= $stage['recruiter'] ?></dd></div>
          <div><dt>Interview</dt><dd><?= $stage['interview'] ?></dd></div>
        </dl>
        <h3>Applied By Month</h3>
        <dl>
          <?php foreach ($applied_months['months'] as $month): ?><div><dt><?= h($month['label']) ?></dt><dd data-stat="month-<?= h($month['key']) ?>"><?= (int) $month['count'] ?></dd></div>
          <?php endforeach; ?><?php if ($applied_months['earlier']): ?><div><dt>Earlier</dt><dd><?= (int) $applied_months['earlier'] ?></dd></div><?php endif; ?>
          <?php if ($applied_months['undated']): ?><div><dt>No Date Saved</dt><dd><?= (int) $applied_months['undated'] ?></dd></div><?php endif; ?>
        </dl>
      </aside>
      </div>
    </div>

    <details class="saved-jobs-panel history-collapse" id="saved-jobs-panel" open>
    <summary class="section-heading-row saved-jobs-summary"><h2>Saved Jobs</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <?php if ($companies): ?>
      <div class="collapse-all-actions"><button class="bordered-button secondary-action" id="expand-all-jobs" type="button">Expand all</button><button class="bordered-button secondary-action" id="collapse-all-jobs" type="button">Collapse all</button>
      </div><?php endif; ?>
    <?= dash_legend('saved') ?>
    <?= dash_toolbar('saved', '#saved-jobs-grid', '.saved-job-card', $companies, '<div class="form-field compact-field dash-actions"><button class="bordered-button secondary-action" id="refresh-dashboard" type="button" data-total="' . count($companies) . '">Refresh Saved Listings</button><button class="bordered-button secondary-action" id="clear-closed" type="button">Clear closed jobs</button></div>') ?>
    <p id="dashboard-refresh-status" class="field-help" role="status" hidden>
    </p>

<?php if ($companies): ?>
    <div class="bulk-bar" id="bulk-bar"><label class="bulk-all"><input type="checkbox" id="bulk-all" /> Select all <?= count($companies) ?> shown</label><span id="bulk-count" class="field-help" role="status">None selected</span><button id="bulk-delete" class="bordered-button destructive-action" type="button" disabled>Delete Selected</button></div>
    <div class="saved-job-grid" id="saved-jobs-grid"><?php foreach ($companies as $company): $pick = true; include APP_ROOT . '/templates/_job_card.php'; endforeach; ?>
    </div><?php else: ?>
    <div class="empty-dashboard">
      <h3>No Saved Jobs Yet
      </h3>
      <p>Use the green Keep button on Search results to add jobs here.
      </p><a class="bordered-button button-link" href="/">Search for Jobs</a>
    </div><?php endif; ?>
    </details>
    <details class="saved-jobs-panel history-collapse applied-jobs-panel" id="applied-jobs-panel" open>
    <summary class="section-heading-row saved-jobs-summary"><h2>Applied Jobs</h2><span class="summary-chevron" aria-hidden="true"></span></summary><?php if ($applied_companies): ?>
      <?= dash_legend('applied') ?>
      <?= dash_toolbar('applied', '#applied-jobs-grid', '.saved-job-card', $applied_companies) ?>
      <div class="saved-job-grid" id="applied-jobs-grid"><?php foreach ($applied_companies as $company): $pick = false; include APP_ROOT . '/templates/_job_card.php'; endforeach; ?>
      </div><?php else: ?>
      <div class="empty-dashboard">
        <h3>No Applied Jobs Yet
        </h3>
        <p>Jobs you mark Applied show up here.
        </p>
      </div><?php endif; ?>
    </details>
  </section>
</main>
<dialog id="resume-review" class="review-dialog">
  <h2>Review Résumé Suggestions
  </h2>
  <p>Would you like to autofill your profile from the résumé you uploaded? Review and choose each section before applying it. Existing information stays in place.
  </p>
  <div id="resume-review-content">
  </div>
  <div class="review-actions"><button class="bordered-button secondary-action" id="cancel-resume-review" type="button">Cancel</button><button class="bordered-button primary-action" id="apply-resume-review" type="button">Add Selected to Profile</button>
  </div>
</dialog>
<dialog id="avatar-crop" class="review-dialog">
  <h2>Crop Profile Photo
  </h2>
  <div class="crop-preview">
    <canvas id="avatar-canvas" width="320" height="320">
    </canvas>
  </div><label for="avatar-zoom">Zoom</label><input id="avatar-zoom" type="range" min="1" max="3" step="0.01" value="1" />
  <p class="field-help">Drag the image to reposition it.
  </p>
  <div class="review-actions"><button class="bordered-button secondary-action" id="cancel-avatar" type="button">Cancel</button><button class="bordered-button primary-action" id="save-avatar" type="button">Save Photo</button>
  </div>
</dialog>
<?php require APP_ROOT . '/templates/_bottom.php';
