<?php
// Résumé Builder: upload your résumé, keep reference documents, references and writing rules, adjust the design,
// connect Claude, and review the résumés and cover letters Claude saved. Ported from the desktop app's page;
// your profile is edited on the Dashboard here, so this page only shows it.

declare(strict_types=1);
require_once APP_ROOT . '/includes/connector.php';
require_once APP_ROOT . '/includes/resume/suggestions.php';

$title = 'Résumé Builder';
$page = 'resume';
$subtitle = 'Tailored résumés and cover letters from your Dashboard profile';
$extra_css = ['/static/css/resume.css'];

$flashes = take_flashes();
$profile = get_user_profile();
$resume = current_resume();
$documents = load_documents();
$references = load_references();
$builds = list_builds();
$enabled = connector_enabled();
$connections = $enabled ? connector_connections() : [];
$stamp = fn(?string $iso) => $iso ? date('M j, Y g:i A', strtotime($iso)) : '';

// The design section, when there is a résumé to measure.
if ($resume) {
    $report = current_design() ?? ['rows' => [], 'notes' => '', 'facts' => null];
    $layout = effective_layout();
    $unsaved = ($_GET['preset'] ?? '') === 'portfolio';
    if ($unsaved) { // the form is filled with the portfolio's type; nothing is saved until Save Design
        $layout = merge_layout($layout, portfolio_preset());
    }
    [$font_list, $google_list] = font_choices(array_merge(fonts_seen($report['facts']), array_map(fn($k) => $layout[$k], array_keys(DESIGN_FONT_ROLES))));
    $design_notes = design_state()['notes'] ?: $report['notes'];
    $site = portfolio();
}
// What the résumé and reference documents have that the profile doesn't: offered with tick boxes, added only on request.
$resume_jobs = [];
$finds = ['jobs' => [], 'skills' => [], 'details' => [], 'people' => []];
try {
    $resume_jobs = $resume ? resume_new_jobs(current_resume_text(), $profile) : [];
    $texts = document_texts();
    $finds = document_suggestions($profile, $texts) + ['details' => detail_suggestions($profile, $texts),
        'people' => reference_suggestions($profile, $references, $texts)];
} catch (Throwable $error) { // an unreadable file just means no suggestions
    error_log('Résumé Builder suggestions: ' . $error->getMessage());
}
// Suggestions for "your job together": the profile's work history, then jobs already typed.
$past_jobs = array_merge(array_map(fn($w) => implode(' at ', array_filter([$w['role'], $w['company']], 'strlen')), $profile['work_history']),
    array_column($references, 'user_job'));
$past_jobs = array_values(array_unique(array_filter($past_jobs, 'strlen')));

function reference_fields(array $r): void
{
    $value = fn(string $key) => h($r[$key] ?? '');
    echo <<<HTML
  <div class="ref-grid">
    <label>Name <input name="name" value="{$value('name')}" autocomplete="off" placeholder="e.g. Sam Lee" required /></label>
    <label>Who they are to you <input name="relationship" value="{$value('relationship')}" list="relationships" placeholder="e.g. Supervisor, CEO / Owner, Co-worker" /></label>
    <label>Your job together <input name="user_job" value="{$value('user_job')}" list="past-jobs" placeholder="e.g. Web Developer at Example Co" /></label>
    <label>Their job title <input name="job_title" value="{$value('job_title')}" placeholder="e.g. IT Director" /></label>
    <label>Their company <input name="company" value="{$value('company')}" placeholder="e.g. Example Co" /></label>
    <label>Phone <input name="phone" value="{$value('phone')}" type="tel" autocomplete="off" placeholder="e.g. (410) 555-0123" /></label>
    <label>Email <input name="email" value="{$value('email')}" type="email" autocomplete="off" placeholder="e.g. sam.lee@example.com" /></label>
    <label class="ref-notes">Private notes <input name="notes" value="{$value('notes')}" placeholder="e.g. asked 9/2026, prefers a text first" /></label>
  </div>
HTML;
}

require APP_ROOT . '/templates/_top.php';
?>
<main>
<?php foreach ($flashes as [$kind, $message]): ?>
  <p class="flash flash-<?= h($kind) ?>" role="status"><?= h($message) ?></p>
<?php endforeach; ?>
<div class="grid">

<section class="card wide how-to">
  <div class="how-to-head">
    <h2>How to Use It</h2>
    <?php if ($connections): ?><span class="how-to-status status-ok">Connected to Claude</span>
    <?php else: ?><span class="how-to-status status-warn">Not connected to Claude yet</span><?php endif; ?>
  </div>
  <ol class="steps">
    <li><span class="step-number" aria-hidden="true">1</span><h3>Upload Your Résumé</h3>
      <p>Choose your current résumé below. Its layout is copied: fonts, sizes, colors and section order.</p></li>
    <li><span class="step-number" aria-hidden="true">2</span><h3>Check Your Profile</h3>
      <p>Make sure your name, location, job titles, work history and skills are right on the <a href="/dashboard">Dashboard</a>.</p></li>
    <li><span class="step-number" aria-hidden="true">3</span><h3>Ask Claude</h3>
      <p>Connect Claude below, then ask it to write for a job you saved:</p>
      <q>Use Résumé Builder to write a résumé and cover letter for the Acme web developer job.</q></li>
    <li><span class="step-number" aria-hidden="true">4</span><h3>Review and Download</h3>
      <p>Come back here to read, edit and download the finished PDFs.</p></li>
  </ol>
</section>

<section class="card wide" id="connect-claude">
  <h2>Connect Claude</h2>
  <p class="field-help">Claude (claude.ai or the Claude app) adds this site as a connector. It can then read your profile, saved jobs, résumé,
    references, documents and writing rules, and save résumés and cover letters here. Claude can only connect after you approve it
    with your Job Finder password, and you can cut it off here at any time.</p>
  <form method="post" action="/resume-builder/connector/toggle" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>" />
    <p><strong>The connector is <?= $enabled ? 'on' : 'off' ?>.</strong>
      <button class="bordered-button<?= $enabled ? '' : ' primary' ?>" type="submit"><?= $enabled ? 'Turn Off and Disconnect Everything' : 'Turn On' ?></button></p>
  </form>
<?php if ($enabled): ?>
  <ol class="connect-steps">
    <li>In Claude, open <strong>Settings → Connectors</strong> and choose <strong>Add custom connector</strong>.</li>
    <li>Name it <strong>Résumé Builder</strong> and paste this address: <code class="connector-url"><?= h(connector_url()) ?></code>
      <button type="button" class="bordered-button" data-copy="<?= h(connector_url()) ?>">Copy</button></li>
    <li>Click <strong>Connect</strong>. This site opens and asks for your Job Finder password; Claude is connected once you allow it.</li>
  </ol>
  <?php if (!str_starts_with(connector_url(), 'https://')): ?>
  <p class="status status-warn">Claude can only connect to an https:// address, so this works once the site is online (on Hostinger), not on this PC.</p>
  <?php endif; ?>
  <?php if ($connections): ?>
  <h3>Connected</h3>
  <ul class="ref-list">
  <?php foreach ($connections as $c): ?>
    <li class="ref-entry">
      <div class="ref-summary">
        <p class="ref-name"><strong><?= h($c['client_name']) ?></strong></p>
        <p class="field-help">Connected <?= h(local_time($c['connected_at'])) ?><?= $c['last_used_at'] ? ' · last used ' . h(how_long($c['last_used_at'])) : '' ?></p>
      </div>
      <form method="post" action="/resume-builder/connector/disconnect/<?= h($c['client_id']) ?>" data-confirm="Disconnect <?= h($c['client_name']) ?>?">
        <?= csrf_field() ?>
        <button class="bordered-button danger" type="submit">Disconnect</button>
      </form>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php endif; ?>
</section>

<section class="card" id="your-resume">
  <h2>Your Current Résumé</h2>
<?php if ($resume): ?>
  <p><strong><a href="/resume-builder/current-resume" target="_blank" rel="noopener"><?= h($resume['original_name']) ?></a></strong>
    · uploaded <?= h($stamp($resume['uploaded_at'])) ?><?= $resume['pages'] ? ' · ' . h(plural((int) $resume['pages'], 'page', 'pages')) : '' ?></p>
  <?php foreach ($resume['notes'] ?? [] as $note): ?><p class="field-help"><?= h($note) ?></p><?php endforeach; ?>
<?php else: ?>
  <p>No résumé uploaded yet.</p>
<?php endif; ?>
  <form method="post" action="/resume-builder/upload" enctype="multipart/form-data" class="upload">
    <?= csrf_field() ?>
    <label for="resume"><?= $resume ? 'Replace with' : 'Upload' ?> a PDF or Word (.docx) file</label>
    <input id="resume" name="resume" type="file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required />
    <button class="bordered-button primary" type="submit">Upload</button>
  </form>
</section>

<section class="card" id="documents">
  <h2>Reference Documents</h2>
  <p class="field-help">Anything that helps Claude write about you: past cover letters, performance reviews, award letters, certificates, a list of projects.
    Claude can read these in every chat and uses only what is actually in them.</p>
<?php if ($documents): ?>
  <ul class="ref-list">
  <?php foreach ($documents as $d): ?>
    <li class="ref-entry">
      <div class="ref-summary">
        <p class="ref-name"><strong><?= h($d['label']) ?></strong> <span class="chip"><?= h($d['type']) ?></span></p>
        <p class="field-help"><?= h($d['original_name']) ?> · uploaded <?= h($stamp($d['uploaded_at'])) ?><?= $d['pages'] ? ' · ' . h(plural((int) $d['pages'], 'page', 'pages')) : '' ?>
          · <?= $d['chars'] ? number_format($d['chars']) . ' characters of text' : '<span class="status-warn">no text found (scan or image)</span>' ?></p>
      </div>
      <div class="ref-actions">
        <a class="bordered-button" href="/resume-builder/documents/<?= h($d['id']) ?>/file" target="_blank" rel="noopener"><?= $d['type'] === 'DOCX' ? 'Download' : 'View' ?></a>
        <details class="ref-edit">
          <summary class="bordered-button">Rename</summary>
          <form method="post" action="/resume-builder/documents/<?= h($d['id']) ?>/rename">
            <?= csrf_field() ?>
            <div class="ref-grid"><label class="ref-notes">Label <input name="label" value="<?= h($d['label']) ?>" maxlength="120" required /></label></div>
            <button class="bordered-button primary" type="submit">Save label</button>
          </form>
        </details>
        <form method="post" action="/resume-builder/documents/<?= h($d['id']) ?>/delete" data-confirm="Delete this document?">
          <?= csrf_field() ?>
          <button class="bordered-button danger" type="submit">Delete</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php else: ?>
  <p>No documents yet.</p>
<?php endif; ?>
  <form method="post" action="/resume-builder/documents/add" enctype="multipart/form-data" class="reference reference-new">
    <h3>Add a Document</h3>
    <?= csrf_field() ?>
    <div class="ref-grid">
      <label>Label <input name="label" maxlength="120" placeholder="e.g. 2024 performance review" /></label>
      <label>File <span class="field-help">PDF, Word .docx, .txt or .md, up to 10 MB</span>
        <input name="document" type="file" accept=".pdf,.docx,.txt,.md" required /></label>
    </div>
    <button class="bordered-button primary" type="submit">Add document</button>
  </form>
</section>

<?php if ($resume): $l = $layout; ?>
<section class="card wide" id="design">
<details class="section-collapse" id="design-panel">
  <summary><h2>Résumé Design</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
  <p class="field-help">What Résumé Builder sees in your uploaded résumé is written out below. Change anything, then Save Design: new résumés
    and cover letters are drawn with it, and Claude reads your notes.</p>

  <h3>What It Sees</h3>
  <dl class="design-rows">
    <?php foreach ($report['rows'] as [$label, $text]): ?><div><dt><?= h($label) ?></dt><dd><?= h($text) ?></dd></div><?php endforeach; ?>
  </dl>
  <form method="post" action="/resume-builder/design/reread" class="inline-form">
    <?= csrf_field() ?>
    <button class="bordered-button" type="submit">Look at My Résumé Again</button>
  </form>

  <form method="post" action="/resume-builder/design/save" class="design-form">
    <?= csrf_field() ?>
    <?php if ($unsaved): ?><p class="flash flash-warn">These are the fonts from jamiekerig.com. They are not saved yet: check them, then Save Design.</p><?php endif; ?>

    <h3>Fonts</h3>
    <p class="field-help">Pick from the list or type a name. The list has the fonts this site has and Google Fonts; a Google Font is saved here the first time you use it.</p>
    <datalist id="font-list">
      <?php foreach ($font_list as $f): ?><option value="<?= h($f) ?>" label="Installed"></option><?php endforeach; ?>
      <?php foreach ($google_list as $f): ?><option value="<?= h($f) ?>" label="Google Font"></option><?php endforeach; ?>
    </datalist>
    <div class="fields font-fields">
    <?php foreach (DESIGN_FONT_ROLES as $key => $label): $st = font_status($l[$key]); ?>
      <div class="font-field">
        <label><?= h($label) ?>
          <input name="<?= $key ?>" list="font-list" value="<?= h($l[$key]) ?>" maxlength="60" autocomplete="off"<?= $key === 'font_family' ? ' required' : '' ?> /></label>
        <p class="font-status font-<?= h($st['status']) ?>"><?= h($st['text']) ?></p>
        <?php if ($st['similar']): ?><p class="field-help">Similar fonts to try:
          <?php foreach ($st['similar'] as $s): ?><button type="button" class="chip-button" data-fill="<?= $key ?>" data-font="<?= h($s) ?>"><?= h($s) ?></button><?php endforeach; ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="field-help">Leave a font empty to use the body text font. Dates, sub-headings and the contact lines use the last one.</p>

    <h3>Sizes and Spacing</h3>
    <div class="fields">
      <label>Name Size (pt) <input name="name_size" type="number" step="0.5" min="10" max="40" value="<?= h($l['name_size']) ?>" /></label>
      <label>Section Heading Size (pt) <input name="heading_size" type="number" step="0.5" min="8" max="24" value="<?= h($l['heading_size']) ?>" /></label>
      <label>Body Text Size (pt) <input name="body_size" type="number" step="0.5" min="7" max="14" value="<?= h($l['body_size']) ?>" /></label>
      <label>Line Height (times the text size) <input name="line_spacing" type="number" step="0.05" min="1" max="1.8" value="<?= h($l['line_spacing']) ?>" /></label>
      <label>Space Above Headings (pt) <input name="section_gap" type="number" step="1" min="2" max="40" value="<?= h($l['section_gap'] ?? '') ?>" placeholder="same as text" /></label>
    </div>

    <h3>Page and Margins</h3>
    <div class="fields">
      <label>Paper <select name="page_size"><option value="letter"<?= $l['page_size'] === 'letter' ? ' selected' : '' ?>>Letter</option><option value="a4"<?= $l['page_size'] === 'a4' ? ' selected' : '' ?>>A4</option></select></label>
      <?php foreach (['top', 'right', 'bottom', 'left'] as $side): $key = "margin_$side"; ?>
      <label>Margin, <?= ucfirst($side) ?> (in) <input name="<?= $key ?>" type="number" step="0.05" min="0.3" max="1.5" value="<?= h($l[$key] ?? '') ?>" placeholder="<?= h($l['margin_in']) ?>" /></label>
      <?php endforeach; ?>
    </div>

    <h3>Header and Headings</h3>
    <div class="fields">
      <label>Name Position <select name="name_align"><option value="left"<?= $l['name_align'] === 'left' ? ' selected' : '' ?>>Left</option><option value="center"<?= $l['name_align'] === 'center' ? ' selected' : '' ?>>Centered</option></select></label>
      <label>Contact Items Separated By <select name="contact_separator"><?php foreach (LAYOUT_CHOICES['contact_separator'] as $c): ?><option<?= $l['contact_separator'] === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
      <label>Headings <select name="heading_case"><option value="upper"<?= $l['heading_case'] === 'upper' ? ' selected' : '' ?>>CAPITALS</option><option value="title"<?= $l['heading_case'] === 'title' ? ' selected' : '' ?>>As Written</option></select></label>
      <label>Bullet Style <select name="bullet_char"><?php foreach (LAYOUT_CHOICES['bullet_char'] as $c): ?><option<?= $l['bullet_char'] === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
      <label>Heading and Name Color <input name="accent_color" type="color" value="<?= h($l['accent_color']) ?>" /></label>
      <label>Text Color <input name="text_color" type="color" value="<?= h($l['text_color']) ?>" /></label>
      <label class="check"><input name="header_rule" type="checkbox"<?= $l['header_rule'] ? ' checked' : '' ?> /> Line Under the Header</label>
      <label class="check"><input name="heading_rule" type="checkbox"<?= $l['heading_rule'] ? ' checked' : '' ?> /> Line Under Each Heading</label>
    </div>

    <h3>Design Notes</h3>
    <p class="field-help">Written from your résumé. Edit it as you like; Claude reads it when it writes for you.</p>
    <textarea name="notes" rows="12" maxlength="6000"><?= h($design_notes) ?></textarea>

    <div class="form-actions">
      <button class="bordered-button primary" type="submit">Save Design</button>
      <a class="bordered-button" href="/resume-builder/design/preview.pdf" target="_blank" rel="noopener">Preview With Sample Text</a>
      <a class="bordered-button" href="/resume-builder?preset=portfolio#design">Match My Portfolio (jamiekerig.com)</a>
    </div>
  </form>
  <form method="post" action="/resume-builder/design/reset" class="inline-form" data-confirm="Go back to the design measured from your résumé?">
    <?= csrf_field() ?>
    <button class="bordered-button" type="submit">Reset to My Résumé</button>
  </form>

  <h3>Type on jamiekerig.com</h3>
  <p class="field-help">Read from the site’s stylesheet on <?= h($site['checked_at']) ?><?= $site['live'] ? '' : ' (when this was built)' ?>. “Match My Portfolio” fills the fonts
    above with these; the site’s pale yellow is too light for white paper, so headings use its dark gray.</p>
  <table class="design-table">
    <thead><tr><th>Used For</th><th>Font</th><th>Available</th></tr></thead>
    <tbody>
    <?php foreach ($site['roles'] as $label => $font): $st = font_status($font); ?>
      <tr><td><?= h($label) ?></td><td><strong><?= h($font) ?></strong></td>
        <td><span class="font-status font-<?= h($st['status']) ?>"><?= h($st['text']) ?></span><?= $st['similar'] ? '<br />Similar: ' . h(implode(', ', $st['similar'])) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($site['accents']): ?><p class="field-help">Also on the site: <?= h(implode(', ', $site['accents'])) ?>.</p><?php endif; ?>
  <form method="post" action="/resume-builder/design/portfolio" class="inline-form">
    <?= csrf_field() ?>
    <button class="bordered-button" type="submit">Check the Site Again</button>
  </form>
</details>
</section>
<?php endif; ?>

<section class="card wide" id="profile">
<details class="section-collapse" id="profile-panel">
  <summary><h2>Profile</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
  <?php $others = array_values(array_filter($profile['job_titles'], fn($t) => strcasecmp($t, $profile['primary_job_title']) !== 0)); ?>
  <?php if (!($profile['first_name'] && $profile['last_name'] && $profile['home_location'])): ?>
    <p class="status status-warn">Add your name and location on the <a href="/dashboard">Dashboard</a>. Résumés, cover letters and file names use them.</p>
  <?php endif; ?>
  <dl class="facts">
    <dt>Name</dt><dd><?= h(trim("{$profile['first_name']} {$profile['last_name']}") ?: '—') ?></dd>
    <dt>Location</dt><dd><?= h($profile['home_location'] ?: '—') ?></dd>
    <dt>Links</dt><dd><?php if ($profile['linkedin_url']): ?><div><a href="<?= h($profile['linkedin_url']) ?>" target="_blank" rel="noopener">LinkedIn</a></div><?php endif; ?>
      <?php if ($profile['portfolio_url']): ?><div><a href="<?= h($profile['portfolio_url']) ?>" target="_blank" rel="noopener"><?= h(rtrim(preg_replace('#^https?://#', '', $profile['portfolio_url']), '/')) ?></a></div><?php endif; ?>
      <?= !$profile['linkedin_url'] && !$profile['portfolio_url'] ? '—' : '' ?></dd>
    <dt>Primary job</dt><dd><?= h($profile['primary_job_title'] ?: '—') ?></dd>
    <dt>Other titles</dt><dd><?= h(implode(', ', $others) ?: '—') ?></dd>
    <dt>Work history</dt><dd><?php foreach ($profile['work_history'] as $w): ?><div><?= h(implode(' at ', array_filter([$w['role'], $w['company']], 'strlen'))) ?><?= $w['dates'] ? ' <span class="field-help">(' . h($w['dates']) . ')</span>' : '' ?></div><?php endforeach; ?><?= $profile['work_history'] ? '' : '—' ?></dd>
    <dt>Education</dt><dd><?php foreach ($profile['education'] as $e): ?><div><?= h(($e['degree'] ?: 'School') . ($e['major'] ? " in {$e['major']}" : '') . ($e['minor'] ? " (minor: {$e['minor']})" : '') . ", {$e['school']}") ?><?= $e['start_date'] || $e['end_date'] ? ' <span class="field-help">(' . h(($e['start_date'] ?: '?') . ' – ' . ($e['end_date'] ?: '?')) . ')</span>' : '' ?><?= $e['gpa'] ? ' <span class="field-help">GPA ' . h($e['gpa']) . '</span>' : '' ?></div><?php endforeach; ?><?= $profile['education'] ? '' : '—' ?></dd>
    <dt>Skills</dt><dd><?php foreach ($profile['skills'] as $s): ?><span class="chip"><?= h($s) ?></span><?php endforeach; ?><?= $profile['skills'] ? '' : '—' ?></dd>
  </dl>
  <?php if ($resume_jobs): ?>
  <p class="status status-warn">Your résumé lists <?= h(plural(count($resume_jobs), 'job', 'jobs')) ?> not in your profile yet:
    <?= h(implode('; ', array_map('job_label', $resume_jobs))) ?>.</p>
  <form method="post" action="/resume-builder/profile/fill-from-resume"><?= csrf_field() ?><button class="bordered-button" type="submit">Add them to my profile</button></form>
  <?php endif; ?>
  <p><a class="bordered-button" href="/dashboard">Edit on the Dashboard</a></p>

  <?php if ($finds['jobs'] || $finds['skills'] || $finds['details']): ?>
  <form method="post" action="/resume-builder/profile/add-from-documents" class="document-finds" id="document-suggestions">
    <?= csrf_field() ?>
    <h3>Found in Your Reference Documents</h3>
    <p class="field-help">These aren't in your profile yet. Tick what you want, then add it. Nothing is added until you click the button.</p>
    <?php if ($finds['jobs']): ?>
    <h4>Work History</h4>
    <ul class="find-list"><?php foreach ($finds['jobs'] as $j): ?>
      <li><label><input type="checkbox" name="job[]" value="<?= h($j['key']) ?>" checked />
        <span><strong><?= h($j['role']) ?></strong><?= $j['company'] ? ' at ' . h($j['company']) : '' ?><?= $j['dates'] ? ' <span class="field-help">(' . h($j['dates']) . ')</span>' : '' ?>
        <?php if ($j['description']): ?><small><?= h(mb_substr($j['description'], 0, 160)) ?><?= mb_strlen($j['description']) > 160 ? '…' : '' ?></small><?php endif; ?>
        <small class="find-source">From <?= h($j['source']) ?></small></span></label><button class="dismiss-button" type="submit" formaction="/resume-builder/suggestions/dismiss" formnovalidate name="dismiss" value="job:<?= h($j['dismiss']) ?>" title="Don't suggest this job again">Dismiss</button></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($finds['skills']): ?>
    <h4>Skills</h4>
    <div class="find-skills"><?php foreach ($finds['skills'] as $s): ?><span class="find-skill"><label><input type="checkbox" name="skill[]" value="<?= h($s) ?>" checked /> <?= h($s) ?></label><button class="dismiss-x" type="submit" formaction="/resume-builder/suggestions/dismiss" formnovalidate name="dismiss" value="skill:<?= h(mb_strtolower($s)) ?>" title="Don't suggest <?= h($s) ?> again" aria-label="Don't suggest <?= h($s) ?> again">&times;</button></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($finds['details']): ?>
    <h4>Missing Details for Jobs You Already Have</h4>
    <p class="field-help">These jobs are already in your work history. Your documents have details their empty boxes are missing; tick a job to fill them in. Nothing you've already typed is changed.</p>
    <ul class="find-list"><?php foreach ($finds['details'] as $d): ?>
      <li><label><input type="checkbox" name="detail[]" value="<?= (int) $d['index'] ?>" checked />
        <span><strong><?= h($d['role']) ?></strong><?= $d['company'] ? ' at ' . h($d['company']) : '' ?>
        <small><b>Fill in:</b> <?= h(implode(' · ', array_map(fn($k, $v) => DETAIL_LABELS[$k] . " $v", array_keys($d['details']), $d['details']))) ?></small>
        <small class="find-source">From <?= h($d['source']) ?></small></span></label><button class="dismiss-button" type="submit" formaction="/resume-builder/suggestions/dismiss" formnovalidate name="dismiss" value="detail:<?= h($d['dismiss']) ?>" title="Don't suggest these details again">Dismiss</button></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <button class="bordered-button primary" type="submit">Add Ticked Items</button>
  </form>
  <?php endif; ?>

  <?php if ($finds['people']): ?>
  <section class="found-people" id="found-people">
    <h3>People in Your Documents</h3>
    <p class="field-help">Choose where each person belongs: a job's supervisor, your References, both, or neither. Nothing is added until you click.</p>
    <ul class="person-list"><?php foreach ($finds['people'] as $p): ?>
      <li class="person-card">
        <div class="person-info">
          <p class="ref-name"><strong><?= h($p['name']) ?></strong><?php if ($p['title'] || $p['company']): ?> <span class="person-role"><?= h(implode(', ', array_filter([$p['title'], $p['company']], 'strlen'))) ?></span><?php endif; ?></p>
          <?php if ($p['user_job']): ?><p class="field-help"><?= $p['relationship'] === 'Supervisor' ? 'Your supervisor' : 'Worked with you' ?> when you were <?= h($p['user_job']) ?></p><?php endif; ?>
          <?php if ($p['phone'] || $p['email']): ?><p class="ref-contact"><?= h(implode(' · ', array_filter([$p['phone'], $p['email']], 'strlen'))) ?></p><?php endif; ?>
          <p class="field-help">From <?= h($p['source']) ?></p>
          <?php if ($p['supervisor_on'] || $p['is_reference']): ?><p class="person-placed"><?= $p['supervisor_on'] ? '✓ Supervisor on ' . h(implode(', ', $p['supervisor_on'])) : '' ?><?= $p['supervisor_on'] && $p['is_reference'] ? ' · ' : '' ?><?= $p['is_reference'] ? '✓ In your References' : '' ?></p><?php endif; ?>
        </div>
        <div class="person-choices">
          <?php if ($profile['work_history']): ?>
          <form method="post" action="/resume-builder/profile/set-supervisor" class="supervisor-form">
            <?= csrf_field() ?>
            <div class="supervisor-row"><label>Supervisor for <select name="sup_job_<?= h($p['key']) ?>"><option value="">Choose a job…</option><?php foreach ($profile['work_history'] as $n => $w): ?><option value="<?= $n ?>"<?= $n === $p['job_index'] ? ' selected' : '' ?>><?= h(job_label($w)) ?></option><?php endforeach; ?></select></label><button class="bordered-button" type="submit" name="person" value="suggestion:<?= h($p['key']) ?>">Set as Supervisor</button></div>
          </form>
          <?php endif; ?>
          <div class="person-buttons">
            <?php if (!$p['is_reference']): ?>
            <form method="post" action="/resume-builder/references/add-suggested"><?= csrf_field() ?><button class="bordered-button" type="submit" name="key" value="<?= h($p['key']) ?>">Add to References</button></form>
            <?php endif; ?>
            <form method="post" action="/resume-builder/suggestions/dismiss"><?= csrf_field() ?><button class="bordered-button quiet-button" type="submit" name="dismiss" value="reference:<?= h($p['dismiss']) ?>" title="Stop showing <?= h($p['name']) ?> here"><?= $p['supervisor_on'] || $p['is_reference'] ? 'Done' : 'Ignore' ?></button></form>
          </div>
        </div>
      </li>
    <?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
</details>
</section>

<section class="card wide" id="references">
<details class="section-collapse" id="references-panel">
  <summary><h2>References</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
  <p class="field-help">People who agreed to vouch for you. When you ask, Claude adds the ones you choose as the last section of the résumé.
    Otherwise they stay off résumés, and never go in cover letters. Private notes are never sent to Claude.</p>
  <datalist id="relationships"><?php foreach (RELATIONSHIPS as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?></datalist>
  <datalist id="past-jobs"><?php foreach ($past_jobs as $j): ?><option value="<?= h($j) ?>"><?php endforeach; ?></datalist>
<?php if ($references): ?>
  <ul class="ref-list">
  <?php foreach ($references as $r): ?>
    <li class="ref-entry">
      <div class="ref-summary">
        <p class="ref-name"><strong><?= h($r['name'] ?: '(no name)') ?></strong><?= $r['relationship'] ? ' <span class="chip">' . h($r['relationship']) . '</span>' : '' ?></p>
        <?php if ($r['job_title'] || $r['company']): ?><p><?= h(implode(', ', array_filter([$r['job_title'], $r['company']], 'strlen'))) ?></p><?php endif; ?>
        <?php if ($r['user_job']): ?><p class="ref-together">Your job together: <?= h($r['user_job']) ?></p><?php endif; ?>
        <p class="ref-contact"><?php if ($r['phone']): ?><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a><?php endif; ?><?= $r['phone'] && $r['email'] ? ' · ' : '' ?><?php if ($r['email']): ?><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a><?php endif; ?></p>
        <?php if ($r['notes']): ?><p class="field-help">Note: <?= h($r['notes']) ?></p><?php endif; ?>
        <?php $supervised = array_filter($profile['work_history'], fn($w) => ($w['supervisor_name'] ?? '') !== '' && $w['supervisor_name'] === $r['name']); ?>
        <?php if ($supervised): ?><p class="ref-together">Supervisor on: <?= h(implode(', ', array_map('job_label', $supervised))) ?></p><?php endif; ?>
        <?php if ($profile['work_history'] && $r['name']): $at = job_index_for($r, $profile); ?>
        <form method="post" action="/resume-builder/profile/set-supervisor" class="supervisor-form">
          <?= csrf_field() ?>
          <div class="supervisor-row"><label>Supervisor for <select name="sup_job_<?= h($r['id']) ?>"><option value="">Choose a job…</option><?php foreach ($profile['work_history'] as $n => $w): ?><option value="<?= $n ?>"<?= $n === $at ? ' selected' : '' ?>><?= h(job_label($w)) ?></option><?php endforeach; ?></select></label><button class="dismiss-button" type="submit" name="person" value="reference:<?= h($r['id']) ?>">Set as Supervisor</button></div>
        </form>
        <?php endif; ?>
      </div>
      <div class="ref-actions">
        <details class="ref-edit">
          <summary class="bordered-button">Edit</summary>
          <form method="post" action="/resume-builder/references/<?= h($r['id']) ?>/update">
            <?= csrf_field() ?>
            <?php reference_fields($r); ?>
            <button class="bordered-button primary" type="submit">Save changes</button>
          </form>
        </details>
        <form method="post" action="/resume-builder/references/<?= h($r['id']) ?>/delete" data-confirm="Delete this reference?">
          <?= csrf_field() ?>
          <button class="bordered-button danger" type="submit">Delete</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php else: ?>
  <p>No references yet.</p>
<?php endif; ?>
  <details class="add-reference-panel"<?= $references ? '' : ' open' ?>>
    <summary class="bordered-button">Add a Reference</summary>
    <form method="post" action="/resume-builder/references/add" class="reference add-box">
      <?= csrf_field() ?>
      <?php reference_fields([]); ?>
      <button class="bordered-button primary" type="submit">Add reference</button>
    </form>
  </details>
</details>
</section>

<?php foreach (['resume' => ['resume-rules', 18, "Technical Skills\n- Use a Technical Skills section instead of a Professional Summary.\n- Never invent metrics.",
    'Your standing instructions for every résumé. Claude reads them before writing, and they win over its built-in rules. To change one for a single job, say so when you ask.'],
    'cover_letter' => ['cover-letter-rules', 14, "Tone\n- Keep it short and low-key.\n- Use specific experience from the résumé.",
    'Your standing instructions for every cover letter, including how to explain an employment gap. Claude reads them before writing.']] as $kind => [$anchor, $rows, $example, $help]): ?>
<section class="card wide" id="<?= $anchor ?>">
<details class="section-collapse" id="<?= $anchor ?>-panel">
  <summary><h2><?= h(RULE_TITLES[$kind]) ?></h2><span class="summary-chevron" aria-hidden="true"></span></summary>
  <p class="field-help"><?= h($help) ?></p>
  <p class="field-help rules-format"><strong>How to write them:</strong> put each rule on its own line, starting with a dash (-). A line without a dash
    is a heading that groups the rules under it. Leave a blank line between groups. Write them the way you would tell a person; no special symbols are needed.</p>
  <form method="post" action="/resume-builder/writing-rules/<?= $kind ?>" class="writing-rules-form">
    <?= csrf_field() ?>
    <textarea name="rules" rows="<?= $rows ?>" aria-label="<?= h(RULE_TITLES[$kind]) ?>" placeholder="<?= h($example) ?>"><?= h(load_rules($kind)) ?></textarea>
    <button class="bordered-button primary" type="submit">Save <?= h(RULE_TITLES[$kind]) ?></button>
  </form>
</details>
</section>
<?php endforeach; ?>

<section class="card wide" id="saved">
  <h2>Saved Résumés and Cover Letters</h2>
<?php if ($builds): ?>
  <div class="table-wrapper"><table>
    <thead><tr><th>File</th><th>Type</th><th>Job</th><th>Updated</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($builds as $b): $file = rawurlencode($b['pdf']); ?>
      <tr>
        <td data-label="File"><?= h($b['pdf']) ?><?= $b['pdf_exists'] ? '' : ' <span class="status-warn">(PDF missing)</span>' ?></td>
        <td data-label="Type"><?= $b['kind'] === 'resume' ? 'Résumé' : 'Cover letter' ?></td>
        <td data-label="Job"><?= h($b['job_title']) ?><?= $b['company'] ? ' at ' . h($b['company']) : '' ?></td>
        <td data-label="Updated"><?= h($stamp($b['updated'])) ?></td>
        <td class="actions">
          <a class="bordered-button" href="/resume-builder/build/<?= $file ?>">Review &amp; edit</a>
          <a class="bordered-button" href="/resume-builder/files/<?= $file ?>?download=1">Download</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <p>Nothing yet. Ask Claude to write one.</p>
<?php endif; ?>
</section>

</div>
</main>
<?php
$scripts = ['design.js', 'resume-panels.js'];
require APP_ROOT . '/templates/_bottom.php';
