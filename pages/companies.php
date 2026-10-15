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
$kinds = [KNOWN_KIND => 'Employers from your searches'];
foreach (company_kinds() as $key => [$label]) {
    $kinds[$key] = $label;
}
$default_kinds = array_merge([KNOWN_KIND], DEFAULT_COMPANY_KINDS);

$title = 'Companies';
$page = 'companies';
$subtitle = 'Businesses, offices, plants and schools near a place, whether or not they have a job posted';
$scripts = ['companies.js', 'address-suggest.js'];
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <section class="company-section companies-search">
    <form id="companies-form" class="companies-form" autocomplete="off">
      <div class="form-field companies-place">
        <label for="companies-place">Search around</label>
        <input id="companies-place" name="place" type="text" list="city-state-options" placeholder="Bel Air, MD or a ZIP code" value="<?= h($home_place) ?>" />
      </div>
      <div class="form-field companies-miles">
        <label for="companies-miles">Within</label>
        <select id="companies-miles" name="miles"><?php foreach (COMPANY_MILE_CHOICES as $miles): ?><option value="<?= $miles ?>"<?= $miles === 15 ? ' selected' : '' ?>><?= $miles ?> miles</option><?php endforeach; ?></select>
      </div>
      <fieldset class="companies-kinds">
        <legend>Kinds of place</legend>
        <span class="companies-kinds-tools"><button class="chip-button" id="companies-kinds-all" type="button">All</button><button class="chip-button" id="companies-kinds-none" type="button">None</button></span>
        <?php foreach ($kinds as $key => $label): ?><label class="companies-kind"><input type="checkbox" name="kinds" value="<?= h($key) ?>"<?= in_array($key, $default_kinds, true) ? ' checked' : '' ?> /> <?= h($label) ?></label><?php endforeach; ?>
      </fieldset>
      <div class="companies-actions"><button class="bordered-button primary-action" id="companies-search" type="submit">Search</button></div>
    </form>
    <p class="field-help companies-origin">One place at a time: it starts at your home. The miles in the list are straight-line miles from the place in the box.</p>
    <section class="companies-mine" id="companies-mine" aria-labelledby="companies-mine-heading">
    <h2 id="companies-mine-heading">My Companies <span class="field-help" id="companies-mine-count"></span></h2>
    <p class="field-help" id="companies-mine-empty" hidden>None yet. The employers in your work history are added here, and you can add your own above.</p>
    <div class="table-wrapper companies-table-wrapper" id="companies-mine-table-wrapper" hidden>
      <table class="companies-table companies-mine-table">
        <thead><tr><th>Company</th><th>Type</th><th>Address</th><th id="companies-mine-miles-header">Miles</th><th>Website and jobs</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="companies-mine-body"></tbody>
      </table>
    </div>
  </section>
    <details class="companies-add" id="companies-add">
      <summary>Add a company of your own</summary>
      <form id="companies-add-form" class="companies-add-form" autocomplete="off">
        <input type="hidden" id="companies-add-id" value="" />
        <div class="form-field"><label for="companies-add-name">Company name</label><input id="companies-add-name" name="name" type="text" maxlength="150" required /></div>
        <div class="form-field"><label for="companies-add-website">Website</label><input id="companies-add-website" name="website" type="text" maxlength="200" placeholder="acme.com" /></div>
        <div class="form-field"><label for="companies-add-jobs-board">Job board</label><input id="companies-add-jobs-board" name="jobs_board" type="text" maxlength="400" placeholder="Indeed, or a link such as jobs.lever.co/acme" title="Where the company posts jobs, if you know: a link to its jobs page, or just the name of the board" /></div>
        <div class="form-field companies-add-address"><label for="companies-add-street">Street</label><input id="companies-add-street" name="street" type="text" maxlength="120" placeholder="Start typing: 10 Main Street" /></div>
        <div class="form-field"><label for="companies-add-city">City</label><input id="companies-add-city" name="city" type="text" list="city-state-options" maxlength="80" placeholder="Bel Air" /></div>
        <div class="form-field"><label for="companies-add-state">State</label><input id="companies-add-state" name="state" type="text" maxlength="30" placeholder="MD" title="Two letters, such as MD" /></div>
        <div class="form-field"><label for="companies-add-zip">ZIP code</label><input id="companies-add-zip" name="zip" type="text" inputmode="numeric" maxlength="10" placeholder="21014" title="Used to measure how far the company is from the place you search" /></div>
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
    <div class="companies-toolbar">
      <strong id="companies-count"></strong>
      <label class="companies-filter"><span class="visually-hidden">Filter these results</span><input id="companies-filter" type="search" placeholder="Filter by name, type or street" /></label>
      <label class="companies-website-only"><input id="companies-website-only" type="checkbox" /> Only with a website</label>
      <button class="bordered-button secondary-action" id="companies-show-hidden" type="button" hidden>Show hidden</button>
    </div>
    <div class="table-wrapper companies-table-wrapper">
      <table class="companies-table">
        <thead><tr><th>Company</th><th>Type</th><th>Address</th><th id="companies-miles-header">Miles</th><th>Website</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="companies-body"></tbody>
      </table>
    </div>
    <p class="field-help">Places and addresses are from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>:
      as complete as they have made it for the area, so a company that is missing may just not be mapped yet.</p>
  </section>
  <p id="companies-status" class="field-help companies-status" role="status"></p>
</main>
<datalist id="city-state-options"></datalist>
<?php require APP_ROOT . '/templates/_bottom.php';
