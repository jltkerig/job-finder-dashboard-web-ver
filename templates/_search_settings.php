<?php
// The Search Settings panel (on the Settings page). Needs $values (read_tuning_settings()), $saved and $error.
?>
  <details class="panel search-settings history-collapse" id="search-settings" data-remember="jobFinder.searchSettingsOpen"<?= $saved || $error !== '' ? ' data-open-now' : '' ?>>
  <summary>
        <h2>Search Settings
        </h2><i class="summary-chevron" aria-hidden="true"></i>
      </summary>
  <?php if ($saved): ?>
      <p class="field-help" role="status"><strong>Saved.</strong> The next search uses these settings.
      </p><?php endif; ?>
  <?php if ($error !== ''): ?>
      <p class="field-help" role="alert"><strong><?= h($error) ?></strong>
      </p><?php endif; ?>
  <form method="post" action="/tuning/settings">
    <?= csrf_field() ?>
    <?php foreach (TUNING_FIELDS as $key => [$label, $help, $kind, $low, $high, $default]): ?>
    <div class="form-field"><label for="tune-<?= h($key) ?>"><?= h($label) ?></label>
      <p class="field-help"><?= h($help) ?> (<?= $low ?>–<?= $high ?>, standard <?= $default ?>)
          </p>
      <input id="tune-<?= h($key) ?>" name="<?= h($key) ?>" type="number" step="<?= $kind === 'int' ? '1' : '0.1' ?>" min="<?= $low ?>" max="<?= $high ?>" value="<?= h($values[$key] ?? $default) ?>" required />
        </div>
    <?php endforeach; ?>
    <?php foreach (TUNING_SWITCHES as $key => [$label, $help, $default]): ?>
    <div class="form-field"><label class="switch-row"><input type="checkbox" name="<?= h($key) ?>"<?= ($values[$key] ?? $default) ? ' checked' : '' ?> /> <?= h($label) ?></label>
      <p class="field-help"><?= h($help) ?>
          </p>
        </div>
    <?php endforeach; ?>
    <button class="primary-button" type="submit">Save settings</button>
  </form>
  <p class="field-help">Changes apply to the next search; a search that is running keeps the settings it started with.
      </p>
    </details>
