<?php
// The Companies page: businesses, offices, plants and schools near a place, plus your own companies. Same markup as
// the desktop Job Finder's templates/companies.html; the lists are filled in by /static/js/companies.js.

declare(strict_types=1);
require_once APP_ROOT . '/includes/companies.php';

$profile = get_user_profile();
try {  // the employers in your work history join your companies, once each, and show what your Profile has for them
    sync_my_companies_from_work_history($profile['work_history'], $profile['state']);
    refresh_companies_from_work_history($profile['work_history'], $profile['state']);
} catch (Throwable $error) {
    error_log('Companies sync: ' . $error->getMessage());
}
$home_place = companies_home_place($profile);
$kinds = [KNOWN_KIND => 'Employers from your searches', MINE_KIND => 'My companies'];
foreach (company_kinds() as $key => [$label]) {
    $kinds[$key] = $label;
}
$default_kinds = array_merge([KNOWN_KIND, MINE_KIND], DEFAULT_COMPANY_KINDS);

$title = 'Companies';
$page = 'companies';
$subtitle = 'Businesses, offices, plants and schools near a place, whether or not they have a job posted';
$scripts = ['companies.js', 'address-suggest.js'];
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <section class="company-section companies-search">
    <details class="companies-searchbox history-collapse" id="companies-searchbox" data-remember="jobFinder.companiesSearchOpen" data-open-default>
    <summary><h2>Search</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <form id="companies-form" class="companies-form" autocomplete="off">
      <div class="form-field companies-place">
        <label for="companies-place">Search around</label>
        <input id="companies-place" name="place" type="text" list="city-state-options" placeholder="Harrisburg, PA or a ZIP code" value="<?= h($home_place) ?>" />
      </div>
      <div class="form-field companies-miles">
        <label for="companies-miles">Within</label>
        <select id="companies-miles" name="miles"><?php foreach (COMPANY_MILE_CHOICES as $miles): ?><option value="<?= $miles ?>"<?= $miles === 15 ? ' selected' : '' ?>><?= $miles ?> miles</option><?php endforeach; ?></select>
      </div>
      <div class="companies-filter-box">
      <div class="filter-heading"><span class="filter-icon" aria-hidden="true">⌕</span><strong>Filters</strong></div>
      <div class="companies-filters">
        <label class="companies-website-only" title="Tick to hide every company that has no website listed."><input id="companies-website-only" type="checkbox" /> Hide companies with no website</label>
        <button class="bordered-button secondary-action" id="companies-show-hidden" type="button" hidden>Show hidden</button>
      </div>
      <p class="companies-filter-label">Source</p>
      <div class="companies-kind-tags companies-source-tags" id="companies-source-tags" role="group" aria-label="Where the companies come from"></div>
      <p class="companies-filter-label">Type of business</p>
      <div class="companies-kind-tags" id="companies-kind-tags" role="group" aria-label="Kinds of place: pick some to show only those in the results"></div>
      </div>
      <fieldset class="companies-kinds" hidden>
        <?php foreach ($kinds as $key => $label): ?><label class="companies-kind"><input type="checkbox" name="kinds" value="<?= h($key) ?>"<?= in_array($key, $default_kinds, true) ? ' checked' : '' ?> /> <?= h($label) ?></label><?php endforeach; ?>
      </fieldset>
      <div class="companies-actions"><button class="bordered-button primary-action" id="companies-search" type="submit">Search</button></div>
    </form>
    </details>
    <details class="companies-mine history-collapse" id="companies-mine" data-remember="jobFinder.myCompaniesOpen" data-open-default>
    <summary><h2 id="companies-mine-heading">My Companies <span class="field-help" id="companies-mine-count"></span></h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <p class="field-help" id="companies-mine-empty" hidden>None yet. The employers in your work history are added here, and you can add your own above.</p>
    <div class="table-wrapper companies-table-wrapper" id="companies-mine-table-wrapper" hidden>
      <table class="companies-table companies-mine-table">
        <thead><tr><th>Company</th><th>Type</th><th>Address</th><th id="companies-mine-miles-header">Miles</th><th>Website and jobs</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="companies-mine-body"></tbody>
      </table>
    </div>
  </details>
    <details class="companies-add history-collapse" id="companies-add" data-remember="jobFinder.addCompanyOpen">
      <summary><h2>Add a Company of Your Own</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
      <form id="companies-add-form" class="companies-add-form" autocomplete="off">
        <input type="hidden" id="companies-add-id" value="" />
        <div class="form-field"><label for="companies-add-name">Company name</label><input id="companies-add-name" name="name" type="text" maxlength="150" required /></div>
        <div class="form-field"><label for="companies-add-website">Website</label><input id="companies-add-website" name="website" type="text" maxlength="200" placeholder="acme.com" /></div>
        <div class="form-field"><label for="companies-add-jobs-board">Job board</label><input id="companies-add-jobs-board" name="jobs_board" type="text" maxlength="400" placeholder="Indeed, or a link such as jobs.lever.co/acme" title="Where the company posts jobs, if you know: a link to its jobs page, or just the name of the board" /></div>
        <div class="form-field companies-add-address"><label for="companies-add-street">Street</label><input id="companies-add-street" name="street" type="text" maxlength="120" placeholder="Start typing: 10 Main Street" /></div>
        <div class="form-field"><label for="companies-add-city">City</label><input id="companies-add-city" name="city" type="text" list="city-state-options" maxlength="80" placeholder="Harrisburg" /></div>
        <div class="form-field"><label for="companies-add-state">State</label><input id="companies-add-state" name="state" type="text" maxlength="30" placeholder="MD" title="Two letters, such as MD" /></div>
        <div class="form-field"><label for="companies-add-zip">ZIP code</label><input id="companies-add-zip" name="zip" type="text" inputmode="numeric" maxlength="10" placeholder="17101" title="Used to measure how far the company is from the place you search" /></div>
        <div class="form-field"><label for="companies-add-phone">Phone</label><input id="companies-add-phone" name="phone" type="text" maxlength="40" /></div>
        <div class="form-field companies-add-notes companies-add-notes-wide"><label for="companies-add-notes">Notes</label><textarea id="companies-add-notes" name="notes" rows="2" maxlength="500" placeholder="Who you know there, what they make, when to check back"></textarea></div>
        <div class="companies-add-actions">
          <button class="bordered-button primary-action" id="companies-add-save" type="submit">Add company</button>
          <button class="bordered-button secondary-action" id="companies-add-cancel" type="button" hidden>Cancel</button>
          <span id="companies-add-status" class="field-help" role="status"></span>
        </div>
      </form>
      <p class="field-help companies-add-help">The city and state (or the ZIP code) measure the distance. For an employer from your résumé, the address, phone and website are shared with that job in your Profile: what you save here shows in the Résumé Builder too, and what the Profile has shows here. Kept on this site, in your settings. They are listed in the My Companies box above, nearest first when a town or ZIP code is given.</p>
    </details>
  </section>
  <section class="company-section companies-results" id="companies-results" hidden aria-live="polite">
    <details class="companies-collapse" id="companies-results-panel" open>
    <summary class="companies-toolbar companies-collapse-summary"><strong id="companies-count"></strong><span class="summary-chevron" aria-hidden="true"></span></summary>
    <div class="table-wrapper companies-table-wrapper">
      <table class="companies-table">
        <thead><tr><th>Company</th><th>Type</th><th>Address</th><th id="companies-miles-header">Miles</th><th>Website</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="companies-body"></tbody>
      </table>
    </div>
    <p class="field-help">Places and addresses are from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>:
      as complete as they have made it for the area, so a company that is missing may just not be mapped yet.</p>
    </details>
  </section>
  <p id="companies-status" class="field-help companies-status" role="status"></p>
</main>
<datalist id="city-state-options"></datalist>
<?php require APP_ROOT . '/templates/_bottom.php';
