<?php
// Résumé Builder's buttons and files: uploads, reference documents, references, writing rules, the design, the
// saved PDFs and the connector switch. $rb is the address after /resume-builder (see app.php). Each form comes
// back to the page with a message at the top.

declare(strict_types=1);
require_once APP_ROOT . '/includes/connector.php';
require_once APP_ROOT . '/includes/resume/suggestions.php';

$back = fn(string $anchor = '') => redirect('/resume-builder' . ($anchor !== '' ? "#$anchor" : ''));

/** Sends a file from data/resume; inline (shown in the browser) unless $download. */
function send_resume_file(string $path, string $name, string $type, bool $download = false): void
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('That file is gone. <a href="/resume-builder">Back to Résumé Builder</a>');
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $name) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/** An uploaded file's [name, bytes], or null with a message flashed. */
function uploaded(string $field, int $limit): ?array
{
    $file = $_FILES[$field] ?? null;
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > $limit) {
        flash('That file is larger than ' . intdiv($limit, 1024 * 1024) . ' MB.', 'error');
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        flash('Choose a file first.', 'error');
        return null;
    }
    return [(string) $file['name'], (string) file_get_contents($file['tmp_name'])];
}

function form_lines(string $name): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST[$name] ?? ''))), 'strlen'));
}

function reference_from_form(): array
{
    return array_combine(REFERENCE_FIELDS, array_map(fn($f) => trim((string) ($_POST[$f] ?? '')), REFERENCE_FIELDS));
}

/** The Résumé Design form as layout settings; throws InvalidArgumentException with a message to show. */
function design_from_form(): array
{
    $data = [];
    foreach (array_keys(DESIGN_FONT_ROLES) as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if (!preg_match("/^[A-Za-z0-9 .&'\\-]{0,60}$/", $value)) {
            throw new InvalidArgumentException("font names can only use letters, numbers, spaces and . & ' -");
        }
        $data[$key] = $value;
    }
    if ($data['font_family'] === '') {
        throw new InvalidArgumentException('choose a body text font.');
    }
    foreach (DESIGN_NUMBERS as $key) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    foreach (DESIGN_CHOICES as $key) {
        $data[$key] = (string) ($_POST[$key] ?? '') ?: null;
    }
    $data['heading_rule'] = ($_POST['heading_rule'] ?? '') === 'on';
    $data['header_rule'] = ($_POST['header_rule'] ?? '') === 'on';
    $data['font_kind'] = font_kind($data['font_family']);
    return clean_layout($data);
}

function resume_from_form(): array
{
    $sections = [];
    for ($i = 0, $n = min((int) ($_POST['section_count'] ?? 0), 40); $i < $n; $i++) {
        $items = [];
        for ($j = 0, $m = min((int) ($_POST["s{$i}_count"] ?? 0), 60); $j < $m; $j++) {
            $key = "s{$i}_i{$j}_";
            $item = [];
            foreach (['heading', 'subheading', 'location', 'dates', 'text'] as $field) {
                $item[$field] = trim((string) ($_POST[$key . $field] ?? ''));
            }
            $item['bullets'] = form_lines($key . 'bullets');
            if (array_filter($item)) {
                $items[] = $item;
            }
        }
        $title = trim((string) ($_POST["s{$i}_title"] ?? ''));
        $text = trim((string) ($_POST["s{$i}_text"] ?? ''));
        if ($title !== '' || $text !== '' || $items) {
            $sections[] = ['title' => $title, 'text' => $text, 'items' => $items];
        }
    }
    $references = [];
    for ($i = 0, $n = min((int) ($_POST['ref_count'] ?? 0), 50); $i < $n; $i++) {
        $reference = array_combine(PRINTED_REFERENCE_FIELDS, array_map(fn($f) => trim((string) ($_POST["ref{$i}_$f"] ?? '')), PRINTED_REFERENCE_FIELDS));
        if ($reference['name'] !== '') {
            $references[] = $reference;
        }
    }
    return ['full_name' => trim((string) ($_POST['full_name'] ?? '')), 'headline' => trim((string) ($_POST['headline'] ?? '')),
        'contact' => form_lines('contact'), 'sections' => $sections, 'references' => $references];
}

function letter_from_form(): array
{
    $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''));
    return ['full_name' => trim((string) ($_POST['full_name'] ?? '')), 'contact' => form_lines('contact'),
        'date' => trim((string) ($_POST['date'] ?? '')), 'recipient' => form_lines('recipient'),
        'greeting' => trim((string) ($_POST['greeting'] ?? '')),
        'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $body)), 'strlen')),
        'closing' => trim((string) ($_POST['closing'] ?? '')), 'signature' => trim((string) ($_POST['signature'] ?? ''))];
}

/** A made-up résumé in the current design, so fonts, margins and spacing can be seen at once. */
function design_preview_pdf(): string
{
    return render_resume(clean_resume_content([
        'full_name' => 'Your Name Here', 'headline' => 'Frontend Web Developer and Graphic Designer',
        'contact' => ['(555) 555-0100', 'you@example.com', 'yourportfolio.com'],
        'sections' => [
            ['title' => 'Professional Summary', 'text' => 'Frontend developer with years of experience building responsive, accessible sites and campaigns.'],
            ['title' => 'Employment History', 'items' => [
                ['heading' => 'Web Designer', 'subheading' => 'Example Company', 'location' => 'Remote', 'dates' => 'January 2025 - May 2025',
                    'bullets' => ['Rebuilt and migrated pages using Bootstrap.', 'Improved layout and user experience across the site.']],
                ['heading' => 'Front End Web Developer', 'subheading' => 'Sample Research LLC', 'location' => 'Baltimore, MD',
                    'dates' => 'May 2015 - October 2024', 'bullets' => ['Designed responsive landing pages and emails.']]]],
            ['title' => 'Skills', 'text' => 'HTML, CSS, JavaScript, Bootstrap, Figma'],
        ]]), effective_layout());
}

// --- Files ---------------------------------------------------------------------------------------------------------

if ($method === 'GET') {
    if (preg_match('#^/files/([^/]+)$#', $rb, $m)) {
        try {
            $path = build_pdf_path($m[1]);
        } catch (InvalidArgumentException) {
            http_response_code(404);
            exit;
        }
        send_resume_file($path, $m[1], 'application/pdf', isset($_GET['download']));
    }
    if ($rb === '/current-resume') {
        $info = current_resume();
        $path = current_resume_file();
        send_resume_file((string) $path, (string) ($info['original_name'] ?? 'resume'), str_ends_with((string) $path, '.pdf')
            ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', !str_ends_with((string) $path, '.pdf'));
    }
    if (preg_match('#^/documents/([A-Za-z0-9_-]+)/file$#', $rb, $m)) {
        $doc = find_document($m[1]);
        if (!$doc) {
            http_response_code(404);
            exit;
        }
        $types = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8'];
        $extension = strtolower(pathinfo($doc['file'], PATHINFO_EXTENSION));
        send_resume_file(resume_dir("documents/{$doc['id']}/{$doc['file']}"), $doc['original_name'], $types[$extension] ?? 'application/octet-stream', $extension === 'docx');
    }
    if ($rb === '/design/preview.pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="design-preview.pdf"');
        header('Cache-Control: no-store');
        echo design_preview_pdf();
        exit;
    }
    http_response_code(404);
    exit('Page not found. <a href="/resume-builder">Back to Résumé Builder</a>');
}

// --- Buttons -------------------------------------------------------------------------------------------------------

try {
    switch (true) {
        case $rb === '/upload':
            if ($file = uploaded('resume', RESUME_MAX_UPLOAD)) {
                $info = save_resume_upload(...$file);
                save_design(design_overrides(), $info['design']['notes']);
                flash("Uploaded {$info['original_name']}. Its layout and design were measured.");
            }
            $back('your-resume');

        case $rb === '/documents/add':
            if ($file = uploaded('document', RESUME_MAX_UPLOAD)) {
                $doc = add_document($file[0], $file[1], (string) ($_POST['label'] ?? ''));
                flash("Added {$doc['label']}." . ($doc['chars'] ? '' : ' No text was found in it (a scan or picture), so Claude can\'t read it yet.'),
                    $doc['chars'] ? 'ok' : 'warn');
            }
            $back('documents');

        case (bool) preg_match('#^/documents/([A-Za-z0-9_-]+)/rename$#', $rb, $m):
            rename_document($m[1], (string) ($_POST['label'] ?? '')) ? flash('Renamed.') : flash('That document is gone.', 'error');
            $back('documents');

        case (bool) preg_match('#^/documents/([A-Za-z0-9_-]+)/delete$#', $rb, $m):
            delete_document($m[1]) ? flash('Deleted the document.') : flash('That document is gone.', 'error');
            $back('documents');

        case $rb === '/references/add':
            $reference = add_reference(reference_from_form());
            flash("Added {$reference['name']}.");
            $back('references');

        case (bool) preg_match('#^/references/([A-Za-z0-9_-]+)/update$#', $rb, $m):
            update_reference($m[1], reference_from_form()) ? flash('Saved the reference.') : flash('That reference is gone.', 'error');
            $back('references');

        case (bool) preg_match('#^/references/([A-Za-z0-9_-]+)/delete$#', $rb, $m):
            delete_reference($m[1]) ? flash('Deleted the reference.') : flash('That reference is gone.', 'error');
            $back('references');

        case (bool) preg_match('#^/writing-rules/(resume|cover_letter)$#', $rb, $m):
            save_rules($m[1], str_replace("\r\n", "\n", (string) ($_POST['rules'] ?? '')));
            flash('Saved your ' . RULE_TITLES[$m[1]] . '. Claude reads them before writing.');
            $back($m[1] === 'resume' ? 'resume-rules' : 'cover-letter-rules');

        case $rb === '/design/save':
            try {
                $overrides = design_from_form();
            } catch (InvalidArgumentException $error) {
                flash('Design not saved: ' . $error->getMessage(), 'error');
                $back('design');
            }
            save_design($overrides, mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['notes'] ?? ''))), 0, 6000));
            $fetched = $missing = [];
            foreach (array_unique(array_filter(array_map(fn($k) => $overrides[$k] ?? '', array_keys(DESIGN_FONT_ROLES)))) as $name) {
                $status = font_status($name)['status'];
                if ($status === 'google') {
                    // saved now, so making a PDF never waits on the network
                    if (download_google_font($name)) {
                        $fetched[] = $name;
                    } else {
                        $missing[] = $name;
                    }
                } elseif ($status === 'missing') {
                    $missing[] = $name;
                }
            }
            $message = 'Saved your design. New résumés and cover letters use it.';
            if ($fetched) {
                $message .= ' Fonts saved from Google Fonts: ' . implode(', ', $fetched) . '.';
            }
            if ($missing) {
                $message .= ' Not found: ' . implode(', ', $missing) . '. A similar built-in font is used until you pick one of the suggestions.';
            }
            flash($message, $missing ? 'warn' : 'ok');
            $back('design');

        case $rb === '/design/reset':
            reset_design();
            flash('Your design now matches your uploaded résumé again.');
            $back('design');

        case $rb === '/design/reread':
            if ($info = reanalyze_resume_design()) {
                save_design(design_overrides(), $info['design']['notes']);
                flash('Looked at your résumé again and wrote out its design.');
            }
            $back('design');

        case $rb === '/design/portfolio':
            try {
                $result = check_portfolio();
                flash('Read the type on jamiekerig.com: ' . implode(', ', array_unique(array_values($result['roles']))) . '.');
            } catch (Throwable $error) {
                flash("Couldn't read jamiekerig.com just now ({$error->getMessage()}). The type read last time is still shown.", 'error');
            }
            $back('design');

        case $rb === '/profile/add-from-documents':
            // only the jobs, skills, job details and people that were ticked
            $profile = get_user_profile();
            $texts = document_texts();
            $ticked = fn(string $name) => array_map('strval', (array) ($_POST[$name] ?? []));
            $found = document_suggestions($profile, $texts);
            $jobs = array_values(array_filter($found['jobs'], fn($j) => in_array($j['key'], $ticked('job'), true)));
            $skills = array_values(array_filter($found['skills'], fn($s) => in_array($s, $ticked('skill'), true)));
            $details = array_values(array_filter(detail_suggestions($profile, $texts), fn($d) => in_array((string) $d['index'], $ticked('detail'), true)));
            if (!$jobs && !$skills && !$details) {
                flash('Tick at least one item to add.', 'error');
                $back('document-suggestions');
            }
            if (!($profile['first_name'] && $profile['last_name'])) {
                flash('Save your first and last name on the Dashboard first.', 'error');
                $back('profile');
            }
            $history = $profile['work_history'];
            foreach ($details as $item) { // only fills what the job is missing
                $history[$item['index']] = array_merge($history[$item['index']], $item['details']);
            }
            $history = array_merge($history, array_map(fn($j) => array_intersect_key($j, array_flip(['role', 'company', 'dates', 'description'])), $jobs));
            usort($history, fn($a, $b) => job_start($b) <=> job_start($a));
            save_work_history($profile, $history, $skills);
            $count = fn(int $n, string $one, string $many) => $n ? "$n " . ($n === 1 ? $one : $many) : '';
            flash('Added ' . implode(', ', array_filter([$count(count($jobs), 'job', 'jobs'), $count(count($skills), 'skill', 'skills'),
                $count(count($details), "job's details", "jobs' details")])) . '.');
            $back('profile');

        case $rb === '/profile/set-supervisor':
            // one job's supervisor name, title, email and phone from a person: a suggested one, or a saved reference
            [$source, $key] = array_pad(explode(':', (string) ($_POST['person'] ?? ''), 2), 2, '');
            $profile = get_user_profile();
            if ($source === 'suggestion') {
                $person = current(array_filter(reference_suggestions($profile, load_references()), fn($p) => $p['key'] === $key)) ?: null;
                $title = $person['title'] ?? '';
                $anchor = 'found-people';
            } else {
                $person = current(array_filter(load_references(), fn($r) => $r['id'] === $key)) ?: null;
                $title = $person['job_title'] ?? '';
                $anchor = 'references';
            }
            $index = is_numeric($_POST["sup_job_$key"] ?? '') ? (int) $_POST["sup_job_$key"] : -1;
            if ($person === null || !isset($profile['work_history'][$index])) {
                flash('Choose which job they supervised.', 'error');
                $back($anchor);
            }
            if (!($profile['first_name'] && $profile['last_name'])) {
                flash('Save your first and last name on the Dashboard first.', 'error');
                $back('profile');
            }
            $history = $profile['work_history'];
            $job = &$history[$index];
            $job = array_merge($job, ['supervisor_name' => $person['name'], 'supervisor_title' => $title ?: ($job['supervisor_title'] ?? ''),
                'supervisor_email' => ($person['email'] ?? '') ?: ($job['supervisor_email'] ?? ''),
                'supervisor_phone' => ($person['phone'] ?? '') ?: ($job['supervisor_phone'] ?? '')]);
            unset($job);
            save_work_history($profile, $history);
            flash("{$person['name']} is now the supervisor on " . job_label($history[$index]) . '.');
            $back($anchor);

        case $rb === '/suggestions/dismiss':
            // "Dismiss": that job, skill, set of job details or person isn't offered again
            [$kind, $key] = array_pad(explode(':', (string) ($_POST['dismiss'] ?? ''), 2), 2, '');
            dismiss_suggestion($kind, $key);
            $back($kind === 'reference' ? 'found-people' : 'document-suggestions');

        case $rb === '/references/add-suggested':
            $person = current(array_filter(reference_suggestions(get_user_profile(), load_references()), fn($p) => $p['key'] === ($_POST['key'] ?? ''))) ?: null;
            if ($person === null) {
                flash('That person is no longer suggested.', 'error');
                $back('found-people');
            }
            add_reference(['relationship' => $person['relationship'], 'name' => $person['name'], 'job_title' => $person['title'],
                'company' => $person['company'], 'user_job' => $person['user_job'], 'phone' => $person['phone'], 'email' => $person['email'], 'notes' => '']);
            flash("Added {$person['name']} to your References.");
            $back('found-people');

        case $rb === '/profile/fill-from-resume':
            // the uploaded résumé's jobs the profile doesn't have yet
            $profile = get_user_profile();
            $jobs = resume_new_jobs(current_resume_text(), $profile);
            if (!$jobs) {
                flash('Nothing new to add: your profile already has the jobs the résumé lists.');
                $back('profile');
            }
            if (!($profile['first_name'] && $profile['last_name'])) {
                flash('Save your first and last name on the Dashboard first.', 'error');
                $back('profile');
            }
            save_work_history($profile, array_merge($profile['work_history'], array_map(fn($j) => array_intersect_key($j + ['description' => ''],
                array_flip(['role', 'company', 'dates', 'description'])), $jobs)));
            flash('Added ' . count($jobs) . (count($jobs) === 1 ? ' job' : ' jobs') . ' from your résumé to your profile. Check them on the Dashboard.');
            $back('profile');

        case $rb === '/connector/toggle':
            $on = ($_POST['enabled'] ?? '') === '1';
            set_setting('connector_enabled', $on ? '1' : '0');
            if (!$on) {
                disconnect_all();
            }
            flash($on ? 'The connector is on. Add it in Claude with the address below.' : 'The connector is off, and every connection was cut off.');
            $back('connect-claude');

        case (bool) preg_match('#^/connector/disconnect/([A-Za-z0-9_-]+)$#', $rb, $m):
            disconnect_client($m[1]);
            flash('Disconnected. Claude will ask you to approve it again next time.');
            $back('connect-claude');

        case (bool) preg_match('#^/build/([^/]+)/delete$#', $rb, $m):
            $draft = load_draft($m[1]);
            delete_build($m[1]);
            flash("Deleted {$draft['pdf']}.");
            $back('saved');

        case (bool) preg_match('#^/build/([^/]+)$#', $rb, $m):
            $draft = load_draft($m[1]);
            $content = $draft['kind'] === 'resume' ? resume_from_form() : letter_from_form();
            $layout = $draft['layout'];
            foreach (array_keys(DEFAULT_LAYOUT) as $key) {
                if (isset($_POST[$key]) && $_POST[$key] !== '') {
                    $layout[$key] = $_POST[$key];
                }
            }
            $layout['heading_rule'] = ($_POST['heading_rule'] ?? '') === 'on';
            try {
                save_build($draft['kind'], $content, ['job_title' => $draft['job_title'] ?? '', 'company' => $draft['company'] ?? '',
                    'job_id' => $draft['job_id'] ?? null, 'layout' => clean_layout($layout), 'replace' => $m[1]]);
                flash('Saved and redrawn.');
            } catch (InvalidArgumentException $error) {
                flash('Not saved: ' . $error->getMessage(), 'error');
            }
            redirect('/resume-builder/build/' . rawurlencode($m[1]));
    }
} catch (InvalidArgumentException | RuntimeException $error) {
    flash($error->getMessage(), 'error');
    $back();
}
http_response_code(404);
exit('Page not found. <a href="/resume-builder">Back to Résumé Builder</a>');
