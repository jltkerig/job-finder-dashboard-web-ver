<?php
// One saved résumé or cover letter: edit its words and layout, redraw it, download or delete it. $rb_file is the
// PDF's file name (see app.php). Ported from the desktop's build.html.

declare(strict_types=1);
require_once APP_ROOT . '/includes/resume/store.php';

try {
    $draft = load_draft($rb_file);
} catch (InvalidArgumentException | RuntimeException) {
    http_response_code(404);
    exit('That file is gone. <a href="/resume-builder">Back to Résumé Builder</a>');
}
$c = $draft['content'];
$l = full_layout($draft['layout']);
$file = rawurlencode($draft['pdf']);
$fonts = installed_fonts();
foreach (array_keys(GOOGLE_FONTS) as $font) {
    if (!in_array($font, $fonts, true)) {
        $fonts[] = $font;
    }
}
if (!in_array($l['font_family'], $fonts, true)) {
    array_unshift($fonts, $l['font_family']);
}
$saved_references = array_map(fn($r) => array_intersect_key($r, array_flip(PRINTED_REFERENCE_FIELDS)), load_references());

$title = ($draft['kind'] === 'resume' ? 'Résumé' : 'Cover Letter') . ($draft['job_title'] ? ": {$draft['job_title']}" : '');
$subtitle = $draft['pdf'] . ($draft['company'] ? " · {$draft['company']}" : '');
$page = 'resume';
$extra_css = ['/static/css/resume.css'];
$flashes = take_flashes();
$selected = fn(bool $on) => $on ? ' selected' : '';
require APP_ROOT . '/templates/_top.php';
?>
<main>
<?php foreach ($flashes as [$kind, $message]): ?>
  <p class="flash flash-<?= h($kind) ?>" role="status"><?= h($message) ?></p>
<?php endforeach; ?>
<p><a href="/resume-builder#saved">&larr; Back to Résumé Builder</a></p>
<div class="editor">
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="form-actions">
    <button class="bordered-button primary" type="submit">Save and redraw PDF</button>
    <a class="bordered-button" href="/resume-builder/files/<?= $file ?>?download=1">Download PDF</a>
  </div>

  <details>
    <summary>Layout</summary>
    <div class="fields">
      <label>Font <select name="font_family"><?php foreach ($fonts as $f): ?><option<?= $selected($f === $l['font_family']) ?>><?= h($f) ?></option><?php endforeach; ?></select></label>
      <input type="hidden" name="font_kind" value="<?= h($l['font_kind']) ?>" />
      <label>Text size <input name="body_size" type="number" step="0.5" min="7" max="14" value="<?= h($l['body_size']) ?>" /></label>
      <label>Name size <input name="name_size" type="number" step="0.5" min="10" max="40" value="<?= h($l['name_size']) ?>" /></label>
      <label>Heading size <input name="heading_size" type="number" step="0.5" min="8" max="24" value="<?= h($l['heading_size']) ?>" /></label>
      <label>Accent colour <input name="accent_color" type="color" value="<?= h($l['accent_color']) ?>" /></label>
      <label>Text colour <input name="text_color" type="color" value="<?= h($l['text_color']) ?>" /></label>
      <label>Name position <select name="name_align"><option value="left"<?= $selected($l['name_align'] === 'left') ?>>Left</option><option value="center"<?= $selected($l['name_align'] === 'center') ?>>Centred</option></select></label>
      <label>Headings <select name="heading_case"><option value="upper"<?= $selected($l['heading_case'] === 'upper') ?>>CAPITALS</option><option value="title"<?= $selected($l['heading_case'] === 'title') ?>>As written</option></select></label>
      <label>Margins (inches) <input name="margin_in" type="number" step="0.05" min="0.3" max="1.5" value="<?= h($l['margin_in']) ?>" /></label>
      <label class="check"><input name="heading_rule" type="checkbox"<?= $l['heading_rule'] ? ' checked' : '' ?> /> Line under headings</label>
    </div>
  </details>

  <label>Full name <input name="full_name" value="<?= h($c['full_name']) ?>" required /></label>
  <label>Contact lines <span class="field-help">one per line</span>
    <textarea name="contact" rows="3"><?= h(implode("\n", $c['contact'])) ?></textarea></label>

<?php if ($draft['kind'] === 'resume'): ?>
  <label>Line under your name <input name="headline" value="<?= h($c['headline']) ?>" /></label>
  <?php $sections = array_merge($c['sections'], [['title' => '', 'text' => '', 'items' => []]]); ?>
  <input type="hidden" name="section_count" value="<?= count($sections) ?>" />
  <?php foreach ($sections as $i => $s): $last_section = $i === count($sections) - 1; ?>
  <fieldset class="section">
    <legend><?= h($s['title'] ?: 'New section (leave empty to skip)') ?></legend>
    <label>Heading <input name="s<?= $i ?>_title" value="<?= h($s['title']) ?>" /></label>
    <label>Paragraph <textarea name="s<?= $i ?>_text" rows="2"><?= h($s['text']) ?></textarea></label>
    <?php $items = array_merge($s['items'], [['heading' => '', 'subheading' => '', 'location' => '', 'dates' => '', 'text' => '', 'bullets' => []]]); ?>
    <input type="hidden" name="s<?= $i ?>_count" value="<?= count($items) ?>" />
    <?php foreach ($items as $j => $it): $key = "s{$i}_i{$j}_"; $new = $j === count($items) - 1; ?>
    <div class="item<?= $new ? ' item-new' : '' ?>">
      <?php if ($new): ?><p class="field-help">Add an entry (leave empty to skip). To remove an entry, clear all its boxes.</p><?php endif; ?>
      <div class="row">
        <label>Title <input name="<?= $key ?>heading" value="<?= h($it['heading']) ?>" /></label>
        <label>Employer / school <input name="<?= $key ?>subheading" value="<?= h($it['subheading']) ?>" /></label>
      </div>
      <div class="row">
        <label>Location <input name="<?= $key ?>location" value="<?= h($it['location']) ?>" /></label>
        <label>Dates <input name="<?= $key ?>dates" value="<?= h($it['dates']) ?>" /></label>
      </div>
      <label>Paragraph <textarea name="<?= $key ?>text" rows="2"><?= h($it['text']) ?></textarea></label>
      <label>Bullet points <span class="field-help">one per line</span>
        <textarea name="<?= $key ?>bullets" rows="<?= max(count($it['bullets']) + 1, 3) ?>"><?= h(implode("\n", $it['bullets'])) ?></textarea></label>
    </div>
    <?php endforeach; ?>
  </fieldset>
  <?php endforeach; ?>
  <?php $ref_rows = array_merge($c['references'], [array_fill_keys(PRINTED_REFERENCE_FIELDS, '')]); ?>
  <fieldset class="section">
    <legend>References (Printed Last)</legend>
    <p class="field-help">Leave empty for no references section. To remove one, clear its name.</p>
    <input type="hidden" name="ref_count" value="<?= count($ref_rows) ?>" />
    <?php foreach ($ref_rows as $i => $r): $key = "ref{$i}_"; $new = $i === count($ref_rows) - 1; ?>
    <div class="item<?= $new ? ' item-new' : '' ?>"<?= $new ? ' id="new-reference"' : '' ?>>
      <?php if ($new && $saved_references): ?>
      <label>Add one of your saved references
        <select id="pick-reference">
          <option value="">Choose…</option>
          <?php foreach ($saved_references as $n => $s): ?><option value="<?= $n ?>"><?= h($s['name']) ?><?= $s['relationship'] ? ' (' . h($s['relationship']) . ')' : '' ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <div class="row">
        <label>Name <input name="<?= $key ?>name" value="<?= h($r['name']) ?>" /></label>
        <label>Who they are to you <input name="<?= $key ?>relationship" value="<?= h($r['relationship']) ?>" /></label>
      </div>
      <label>Your job together <input name="<?= $key ?>user_job" value="<?= h($r['user_job']) ?>" /></label>
      <div class="row">
        <label>Their job title <input name="<?= $key ?>job_title" value="<?= h($r['job_title']) ?>" /></label>
        <label>Their company <input name="<?= $key ?>company" value="<?= h($r['company']) ?>" /></label>
      </div>
      <div class="row">
        <label>Phone <input name="<?= $key ?>phone" value="<?= h($r['phone']) ?>" /></label>
        <label>Email <input name="<?= $key ?>email" value="<?= h($r['email']) ?>" /></label>
      </div>
    </div>
    <?php endforeach; ?>
  </fieldset>
  <script type="application/json" id="saved-references"><?= json_encode($saved_references, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  <script>
    (function () {
      var pick = document.getElementById("pick-reference");
      if (!pick) return;
      var saved = JSON.parse(document.getElementById("saved-references").textContent);
      pick.addEventListener("change", function () {
        var ref = saved[pick.value];
        if (!ref) return;
        document.querySelectorAll("#new-reference input").forEach(function (input) {
          var field = input.name.replace(/^ref\d+_/, "");
          if (field in ref) input.value = ref[field];
        });
      });
    })();
  </script>
<?php else: ?>
  <label>Date <span class="field-help">empty means today</span><input name="date" value="<?= h($c['date']) ?>" /></label>
  <label>Recipient <span class="field-help">one line each</span>
    <textarea name="recipient" rows="3"><?= h(implode("\n", $c['recipient'])) ?></textarea></label>
  <label>Greeting <input name="greeting" value="<?= h($c['greeting']) ?>" /></label>
  <label>Letter <span class="field-help">leave a blank line between paragraphs</span>
    <textarea name="body" rows="18"><?= h(implode("\n\n", $c['paragraphs'])) ?></textarea></label>
  <label>Closing <input name="closing" value="<?= h($c['closing']) ?>" /></label>
  <label>Signature <span class="field-help">empty means your full name</span><input name="signature" value="<?= h($c['signature']) ?>" /></label>
<?php endif; ?>
  <div class="form-actions">
    <button class="bordered-button primary" type="submit">Save and redraw PDF</button>
  </div>
</form>

<div class="preview card">
  <iframe title="PDF preview" src="/resume-builder/files/<?= $file ?>?v=<?= h(substr(md5((string) ($draft['updated'] ?? '')), 0, 8)) ?>#view=FitH"></iframe>
  <form method="post" action="/resume-builder/build/<?= $file ?>/delete" onsubmit="return confirm('Delete <?= h($draft['pdf']) ?> and its draft? This cannot be undone.');">
    <?= csrf_field() ?>
    <button class="bordered-button danger" type="submit">Delete this file</button>
  </form>
</div>
</div>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
