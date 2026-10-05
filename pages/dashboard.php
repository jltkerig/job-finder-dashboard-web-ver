<?php
// The Dashboard: your profile, saved jobs and recent searches. Same markup as the desktop Job Finder's
// templates/user-dashboard.html.

declare(strict_types=1);

$filters = [
    'status' => mb_substr(trim((string) ($_GET['status'] ?? '')), 0, 30),
    'state' => mb_substr(trim((string) ($_GET['state'] ?? '')), 0, 100),
    'title' => mb_substr(trim((string) ($_GET['title'] ?? '')), 0, 255),
    'sort' => mb_substr(trim((string) ($_GET['sort'] ?? 'date_desc')), 0, 30),
];
$companies = get_kept_companies($filters['status'], $filters['state'], $filters['title'], $filters['sort']);
$profile = get_user_profile();
add_job_fit($companies, $profile['skills']);
add_drive_times($companies, $profile['home_zip'], home_state());
require_once APP_ROOT . '/includes/resume/store.php';
$application_files = files_by_job(); // résumés and cover letters made for each job
$demanded_skills = array_map(fn($pair) => ['skill' => $pair[0], 'count' => $pair[1]], listing_skill_demand($profile['skills']));
$search_history = get_search_history();
$skill_suggestions = profile_skill_suggestions($profile);
$fit_updated = isset($_GET['fit_updated']) ? (int) $_GET['fit_updated'] : null;
$profile_changed = !empty($_GET['profile_changed']);
$saved_titles = array_map('mb_strtolower', $profile['job_titles']);
$form_examples = setting_json('form_examples', ['home_zip' => '', 'home_location' => '']);
$filtered = $filters['title'] !== '' || $filters['state'] !== '' || $filters['status'] !== '';

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
          <section class="profile-subsection">
            <h3>Job Titles
            </h3>
            <div class="form-field"><label for="primary-job-title">Primary Job Title</label><input id="primary-job-title" name="primary_job_title" type="text" data-title-suggest="single" value="<?= h($profile['primary_job_title']) ?>" placeholder="e.g. Web Designer" autocomplete="off" />
            </div>
            <div class="form-field"><label for="job-titles">Other Job Titles to Search For</label>
          <p class="field-help">Separate titles with commas.
              </p><input id="job-titles" name="job_titles" type="text" data-title-suggest="list" value="<?= h(implode(', ', array_filter($profile['job_titles'], fn($t) => $t !== $profile['primary_job_title']))) ?>" placeholder="Front End Developer, Visual Designer" />
              <div id="title-suggestion-chips" class="title-chips" hidden><span class="field-help">Suggested:</span>
                <div class="chip-row">
                </div>
              </div>
            </div>
          </section>
          <section class="profile-subsection">
            <h3>Work Preferences
            </h3>
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
          </section>
          <section class="profile-subsection">
            <h3>Work History
            </h3>
            <p class="field-help">Add past jobs manually or review suggestions from a résumé.
            </p>
            <p class="field-help" id="work-history-note" data-source="" data-history="[]" hidden>
            </p>
            <div id="work-history-list">
            </div><button class="bordered-button secondary-action" id="add-work-history" type="button">Add Job</button>
          </section>
          <section class="profile-subsection">
            <h3>Education
            </h3>
            <p class="field-help">Schools you attended. GPA and minor are optional.
            </p>
            <div id="education-list" data-degrees="<?= json_attr(EDUCATION_DEGREES) ?>">
            </div><button class="bordered-button secondary-action" id="add-education" type="button">Add School</button>
          </section>
          <section class="profile-subsection">
            <h3>Skills
            </h3>
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
          </section>
          <section class="profile-subsection">
            <h3><label for="profile-city-input">Locations</label>
            </h3>
            <p class="field-help">Job Finder looks for jobs around each city, out to the distance you pick.
            </p>
            <div class="city-add-row"><input id="profile-city-input" type="text" list="city-state-options" placeholder="e.g. Wilmington, DE" autocomplete="off" /><select id="profile-city-radius"><?php foreach (CITY_RADII as $radius): ?><option value="<?= $radius ?>"<?= $radius === 50 ? ' selected' : '' ?>><?= $radius ?> miles</option><?php endforeach; ?></select><button id="add-profile-city" class="bordered-button secondary-action city-add-button" type="button">Add Location</button>
            </div>
            <div id="profile-city-list" class="city-list city-list-below">
            </div>
          </section>
          <datalist id="city-state-options">
          </datalist>
          <div class="profile-save-actions"><button class="bordered-button primary-action" type="submit">Save Profile</button><div class="unsaved-note" id="unsaved-note" role="status" hidden><span>Unsaved changes. Click Save Profile to keep them.</span><button class="bordered-button primary-action" type="submit" form="profile-form">Save Profile</button></div><button class="bordered-button secondary-action" id="upload-resume" type="button">Upload Résumé</button><a class="bordered-button secondary-action" href="/extension-profile" title="A file to load in the Web Job Scraper extension (Load profile), so it can mark jobs that fit and show distances">Export for Web Job Scraper</a><input id="resume-file" type="file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden />
          </div>
        </form>
      </section>
      <aside class="profile-summary-card"><button class="profile-avatar" id="change-avatar" type="button" aria-label="Upload or change profile photo"><?php if ($profile['avatar_data']): ?><img src="<?= h($profile['avatar_data']) ?>" alt="" /><?php elseif ($profile['first_name']): ?><?= h(mb_strtoupper(mb_substr($profile['first_name'], 0, 1))) ?><?php else: ?>U<?php endif; ?></button><input id="avatar-file" type="file" accept="image/png,image/jpeg,image/webp" hidden /><small>Click photo to change</small><strong><?= h(trim($profile['first_name'] . ' ' . $profile['last_name']) ?: 'Username') ?></strong><span><?= h($profile['primary_job_title'] ?: 'Add a primary job title') ?></span><span><?= h($profile['home_location'] ?: 'Add your location') ?></span>
      </aside>
    </div>

    <div class="section-heading-row">
      <h2>Saved Jobs
      </h2><?php if ($companies): ?>
      <div class="collapse-all-actions"><button class="bordered-button secondary-action" id="expand-all-jobs" type="button">Expand all</button><button class="bordered-button secondary-action" id="collapse-all-jobs" type="button">Collapse all</button>
      </div><?php endif; ?>
    </div>
    <form class="filter-panel dashboard-filter-panel" method="get" action="/dashboard">
      <div class="filter-heading"><span class="filter-icon" aria-hidden="true">⌕</span><strong>Filters</strong>
      </div>
      <div class="form-field"><label for="filter-title">Job Title</label><input id="filter-title" name="title" value="<?= h($filters['title']) ?>" placeholder="Filter title" data-title-suggest="single" />
      </div>
      <div class="form-field"><label for="filter-state">State</label><input id="filter-state" name="state" value="<?= h($filters['state']) ?>" placeholder="Filter state" />
      </div>
      <div class="form-field"><label for="filter-status">Application Status</label><select id="filter-status" name="status"><option value="">All</option><?php foreach (APPLICATION_STATUSES as $option): ?><option value="<?= h($option) ?>"<?= $filters['status'] === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-field"><label for="filter-sort">Sort</label><select id="filter-sort" name="sort"><?php foreach (['date_desc' => 'Newest', 'date_asc' => 'Oldest', 'company' => 'Company', 'title' => 'Job Title', 'status' => 'Status', 'verified' => 'Last Verified'] as $value => $label): ?><option value="<?= $value ?>"<?= $filters['sort'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
      </div>
      <div class="filter-actions"><button class="bordered-button primary-action" type="submit">Filter</button><a class="bordered-button secondary-link" href="/dashboard">Clear</a><button class="bordered-button secondary-action" id="refresh-dashboard" type="button" data-total="<?= count($companies) ?>">Refresh Saved Listings</button>
      </div>
    </form>
    <p id="dashboard-refresh-status" class="field-help" role="status" hidden>
    </p>

<?php if ($companies): ?>
    <div class="saved-job-grid"><?php foreach ($companies as $company):
        $id = (int) $company['id'];
        $listing_status = strtolower($company['job_open_status'] ?: 'Unknown');
        $domain = (string) $company['domain'];
        $domain_url = $domain === '' ? '' : (str_contains($domain, '://') ? $domain : "https://$domain/");
        $is_feed = in_array($company['source_type'], FEED_NAMES, true);
        $place = ($company['city'] ? $company['city'] . ', ' : '');
        $fit = $company['job_fit'];
        $fit_text = $fit['score'] !== null ? $fit['score'] . '%' : '?';
        $status_class = str_replace(' ', '-', strtolower($company['application_status'] ?: 'None'));
    ?>
      <article class="saved-job-card" id="job-<?= $id ?>" data-company-id="<?= $id ?>">
        <details class="saved-job-collapse">
          <summary class="saved-job-summary">
            <div class="summary-text">
              <p class="summary-line summary-title"><strong><?= h($company['career_job_title'] ?: 'Unknown Job') ?></strong><span class="summary-company"><?= h($company['name'] ?: 'Unknown Company') ?></span>
              </p>
              <p class="summary-line summary-facts"><span><?= h($place) ?><?= h($company['state'] ?: 'Location unknown') ?></span><?php if ($company['drive']): ?><span title="Estimated from your home ZIP, driving at 6 a.m."><?= h($company['drive']['text']) ?></span><?php elseif ($company['distance_miles'] !== null): ?><span><?= number_format((float) $company['distance_miles']) ?> mi</span><?php endif; ?><?php if ($company['work_arrangement'] && strtolower($company['work_arrangement']) !== strtolower((string) $company['state'])): ?><span><?= h($company['work_arrangement']) ?></span><?php endif; ?><span>Job Fit <?= $fit_text ?></span>
              </p>
              <p class="summary-line summary-status"><span class="application-indicator status-<?= h($status_class) ?>"><?= h(in_array($company['application_status'], ['Saved', 'None', null], true) ? 'Not applied' : $company['application_status']) ?></span><span class="job-open-state"><span class="status-dot <?= $listing_status === 'closed' ? 'closed' : ($listing_status === 'open' ? 'open' : 'unknown') ?>" data-tooltip="<?= $listing_status === 'open' ? 'This listing appears to still be active.' : ($listing_status === 'closed' ? 'This listing appears to no longer be active.' : 'The current listing status could not be confirmed.') ?>"></span><?= h($company['job_open_status'] ?: 'Unknown') ?></span><span>Found <?= h(local_time($company['date_found'], 'M d, Y') ?: '?') ?></span><?php if ($company['notes']): ?><span class="summary-notes" title="<?= h(mb_substr($company['notes'], 0, 200)) ?>">Notes</span><?php endif; ?>
              </p>
            </div><span class="summary-chevron" aria-hidden="true"></span>
          </summary>
          <div class="saved-job-body">
            <dl class="job-facts">
              <div><dt>Location</dt><dd><?= h($place) ?><?= h($company['state'] ?: 'Unknown') ?></dd></div>
              <div><dt>Work</dt><dd><?= h($company['work_arrangement'] ?: '—') ?></dd></div>
              <div><dt>From Home</dt><dd><?php if ($company['drive']): ?><span title="Estimated from your home ZIP (<?= h($profile['home_zip']) ?>), driving at 6 a.m."><?= h($company['drive']['text']) ?></span><?php elseif ($company['distance_miles'] !== null): ?><?= number_format((float) $company['distance_miles']) ?> mi<?php else: ?>—<?php endif; ?></dd></div>
              <div><dt>Job Fit</dt><dd><span class="job-fit" tabindex="0" title="<?= h($fit['reason'] . ($fit['matched'] ? ' Matched: ' . implode(', ', $fit['matched']) . '.' : '') . ($fit['missing'] ? ' Other page skills: ' . implode(', ', $fit['missing']) . '.' : '')) ?>"><?= $fit_text ?></span></dd></div>
              <div><dt>Career Credibility</dt><dd><?= credibility_badge($company['career_credibility']) ?></dd></div>
              <div><dt>USA Credibility</dt><dd><?= credibility_badge($company['usa_credibility']) ?></dd></div>
              <div><dt>Source</dt><dd><?= h($company['source_type'] ?: 'Brave Search') ?></dd></div>
              <div><dt>Last Verified</dt><dd><?= h(local_time($company['last_checked'], 'M d, Y') ?: 'Never') ?></dd></div>
            </dl>
            <div class="job-links"><?php if ($company['career_url'] && !$is_feed): ?><a class="bordered-button" href="<?= h($company['career_url']) ?>" target="_blank" rel="noopener noreferrer">Career Page</a><?php endif; ?><?php if ($company['source_url']): ?><a class="bordered-button" href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($is_feed ? 'View on ' . $company['source_type'] : 'Listing') ?></a><?php endif; ?><button class="bordered-button details-action" type="button" data-details-target="dashboard-details-<?= $id ?>">View Details</button>
            </div>
            <div id="dashboard-details-<?= $id ?>" class="card-details" hidden>
              <div class="detail-section"><strong>Listing Details</strong><span><strong>Domain:</strong> <?php if ($domain_url): ?><a href="<?= h($domain_url) ?>" target="_blank" rel="noopener noreferrer"><?= h($domain) ?></a><?php else: ?>Unknown<?php endif; ?></span><span><strong>Country:</strong> <?= h($company['country'] ?: 'Unknown') ?></span><span><strong>Found:</strong> <?= h(local_time($company['date_found']) ?: 'Unknown') ?></span><?php if ($company['result_updated_at']): ?><span><strong>Updated:</strong> <?= h(local_time($company['result_updated_at'])) ?></span><?php endif; ?><?php if ($company['last_checked']): ?><span><strong>Last verified:</strong> <?= h(local_time($company['last_checked'])) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="card-panels">
              <section class="card-panel application-files">
                <h4>Résumé and Cover Letter</h4>
                <?php $files = $application_files[(int) $id] ?? []; ?>
                <?php if ($files): ?><ul class="file-list"><?php foreach ($files as $file): $name = rawurlencode($file['pdf']); ?><li><span class="file-kind"><?= h($file['label']) ?></span><a href="/resume-builder/files/<?= $name ?>" target="_blank" rel="noopener"><?= h($file['pdf']) ?></a><a class="file-edit" href="/resume-builder/build/<?= $name ?>">Edit</a></li><?php endforeach; ?></ul>
                <?php else: ?><p class="field-help">None yet. Apply copies a request for Claude; what it makes through the Résumé Builder connector shows up here.</p><?php endif; ?>
                <button class="bordered-button apply-action" type="button" data-job-id="<?= $id ?>" data-job-title="<?= h($company['career_job_title']) ?>" data-company-name="<?= h($company['name']) ?>" title="Copy a request for a tailored résumé and cover letter, then open Claude to paste it."><?= $files ? 'Make New Versions' : 'Apply' ?></button>
              </section>
              <section class="card-panel application-section">
                <h4>Your Application</h4>
                <form action="/update-kept/<?= $id ?>" method="post" class="saved-job-editor"><?= csrf_field() ?>
                  <label for="status-<?= $id ?>">Status</label><select id="status-<?= $id ?>" name="application_status"><?php foreach (APPLICATION_STATUSES as $option): ?><option value="<?= h($option) ?>"<?= $company['application_status'] === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select>
                  <label for="notes-<?= $id ?>">Notes</label><textarea id="notes-<?= $id ?>" name="notes" rows="4" placeholder="Add notes about this job..."><?= h($company['notes']) ?></textarea>
                  <button class="bordered-button primary-action" type="submit">Save Changes</button>
                </form>
              </section>
            </div>
            <div class="card-footer-actions" aria-label="Remove or block"><?php if (!$is_feed): ?><button class="text-action block-action destructive-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="text-action block-company-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button><button class="text-action reject-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name'] ?: 'this listing') ?>" title="Hide this job, but allow other jobs from this company.">Reject</button>
              <form action="/delete-kept/<?= $id ?>" method="post" class="delete-result-form" onsubmit="return confirm('Permanently delete this saved job? This cannot be undone.');"><?= csrf_field() ?><button class="text-action danger-action destructive-action" type="submit">Delete</button></form>
            </div>
          </div>
        </details>
      </article><?php endforeach; ?>
    </div><?php else: ?>
    <div class="empty-dashboard">
      <h3><?= $filtered ? 'No Saved Jobs Match These Filters' : 'No Saved Jobs Yet' ?>
      </h3>
      <p><?= $filtered ? 'Try clearing or changing the filters above.' : 'Use the green Keep button on Search results to add jobs here.' ?>
      </p><a class="bordered-button button-link" href="/">Search for Jobs</a>
    </div><?php endif; ?>
    <details class="history-panel history-collapse" id="recent-searches">
      <summary class="history-summary">
        <h2>Recent Searches
        </h2><span class="summary-chevron" aria-hidden="true"></span>
      </summary><?php if ($search_history): ?>
      <div class="history-list"><?php foreach ($search_history as $search): $is_saved = in_array(mb_strtolower($search['main_title']), $saved_titles, true); ?>
        <div class="history-item">
          <div class="history-main"><strong class="history-title" title="<?= h($search['job_title']) ?>"><?= h($search['main_title']) ?></strong><?php if ($search['other_titles']): ?><small class="history-titles"><?= h(implode(' • ', $search['other_titles'])) ?></small><?php endif; ?><span><?= h($search['state']) ?></span><?php if ($search['cities_json']): ?><small class="history-cities" data-cities="<?= h($search['cities_json'] ?: '[]') ?>"></small><?php endif; ?><small><?= h(local_time($search['searched_at'], 'M d, Y h:i A')) ?></small>
          </div>
          <div class="history-actions"><button class="bordered-button save-history-title" type="button" data-job-title="<?= h($search['main_title']) ?>"<?= $is_saved ? ' disabled' : '' ?>><?= $is_saved ? 'Saved' : 'Save Job Title' ?></button><button class="bordered-button search-again-button" type="button" data-job-title="<?= h($search['job_title']) ?>" data-state="<?= h($search['state']) ?>" data-cities="<?= h($search['cities_json'] ?: '[]') ?>">Search Again</button>
          </div>
        </div><?php endforeach; ?>
      </div><?php else: ?>
      <div class="empty-inline">No searches recorded yet.
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
