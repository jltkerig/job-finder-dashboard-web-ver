<?php
// Saving the Settings page's search settings, and resetting the Brave testing count.

declare(strict_types=1);
require_once APP_ROOT . '/includes/tuning.php';

if (isset($_POST['reset_brave'])) {
    set_setting('brave_used', '0');
    redirect('/tuning#brave-usage');
}
[$updated, $error] = apply_tuning_form($_POST, read_tuning_settings());
if ($error !== null) {
    redirect('/rejected-listings?error=' . rawurlencode($error) . '#search-settings');
}
set_setting_json('tuning', $updated);
redirect('/rejected-listings?saved=1#search-settings');
