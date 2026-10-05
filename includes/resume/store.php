<?php
// Résumé Builder's own files, all in data/resume (never uploaded to GitHub, and closed to the web by
// data/.htaccess): the uploaded résumé, reference documents, references, writing rules, the saved design, and the
// finished PDFs with their editable drafts. Ported from the desktop Résumé Builder (config, models, builds,
// references, documents, writing_rules and resume_file).

declare(strict_types=1);

require_once APP_ROOT . '/includes/documents.php';
require_once __DIR__ . '/fonts.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/analyze.php';

const RESUME_MAX_UPLOAD = 10 * 1024 * 1024;

/** A path inside data/resume (JOBFINDER_RESUME_DIR moves it, so tests never touch the real files). */
function resume_dir(string $sub = ''): string
{
    $root = getenv('JOBFINDER_RESUME_DIR') ?: data_path('resume');
    return rtrim($root, '/\\') . ($sub === '' ? '' : '/' . $sub);
}

function ensure_resume_dirs(): void
{
    foreach (['', 'current-resume', 'drafts', 'builds', 'documents', 'fonts'] as $sub) {
        if (!is_dir(resume_dir($sub))) {
            mkdir(resume_dir($sub), 0775, true);
        }
    }
}

function read_json_file(string $path, $default = [])
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data === null ? $default : $data;
}

/** Writes through a temporary file, so a half-written file is never left behind. */
function write_file_atomic(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $temp = $path . '.tmp';
    file_put_contents($temp, $content);
    rename($temp, $path);
}

function write_json_file(string $path, $data): void
{
    write_file_atomic($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** A message shown once at the top of the Résumé Builder page after a button is used. */
function flash(string $message, string $kind = 'ok'): void
{
    start_session();
    $_SESSION['flashes'][] = [$kind, $message];
}

function take_flashes(): array
{
    start_session();
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

// --- Layout settings ------------------------------------------------------------------------------------------

const DEFAULT_LAYOUT = [
    'font_family' => 'Calibri', 'font_kind' => 'sans', 'body_size' => 10.5, 'name_size' => 22.0,
    'heading_size' => 12.0, 'accent_color' => '#1F3A5F', 'text_color' => '#222222', 'name_align' => 'left',
    'heading_case' => 'upper', 'heading_rule' => true, 'margin_in' => 0.75,
    'name_font' => '', 'heading_font' => '', 'detail_font' => '',
    'margin_top' => null, 'margin_bottom' => null, 'margin_left' => null, 'margin_right' => null,
    'line_spacing' => 1.25, 'section_gap' => null, 'bullet_char' => '•', 'contact_separator' => '|',
    'page_size' => 'letter', 'header_rule' => true,
];
const LAYOUT_NUMBERS = ['body_size' => [7, 14], 'name_size' => [10, 40], 'heading_size' => [8, 24], 'margin_in' => [0.3, 1.5],
    'margin_top' => [0.3, 1.5], 'margin_bottom' => [0.3, 1.5], 'margin_left' => [0.3, 1.5], 'margin_right' => [0.3, 1.5],
    'line_spacing' => [1.0, 1.8], 'section_gap' => [2, 40]];
const LAYOUT_CHOICES = ['font_kind' => ['serif', 'sans'], 'name_align' => ['left', 'center'], 'heading_case' => ['upper', 'title'],
    'bullet_char' => ['•', '–', '-', '·', '▪', '○', '›'], 'contact_separator' => ['|', '•', '·', '/', ',', '–', '-'],
    'page_size' => ['letter', 'a4']];
const LAYOUT_FLAGS = ['heading_rule', 'header_rule'];
const LAYOUT_FONTS = ['font_family', 'name_font', 'heading_font', 'detail_font'];
// The font boxes on the Résumé Design form, in the order shown.
const DESIGN_FONT_ROLES = ['name_font' => 'Name Font', 'heading_font' => 'Section Heading Font', 'font_family' => 'Body Text Font',
    'detail_font' => 'Dates, Sub-headings and Contact Font'];
const DESIGN_NUMBERS = ['body_size', 'name_size', 'heading_size', 'line_spacing', 'section_gap', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left'];
const DESIGN_CHOICES = ['page_size', 'name_align', 'heading_case', 'bullet_char', 'contact_separator', 'accent_color', 'text_color'];

/** The layout values given, typed and checked; throws InvalidArgumentException naming the first bad one. */
function clean_layout(array $values): array
{
    $clean = [];
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, DEFAULT_LAYOUT) || $value === null || $value === '' && isset(LAYOUT_NUMBERS[$key])) {
            continue;
        }
        $label = str_replace('_', ' ', $key);
        if (isset(LAYOUT_NUMBERS[$key])) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException("$label must be a number.");
            }
            [$low, $high] = LAYOUT_NUMBERS[$key];
            if ((float) $value < $low || (float) $value > $high) {
                throw new InvalidArgumentException("$label must be between $low and $high.");
            }
            $clean[$key] = (float) $value;
        } elseif (isset(LAYOUT_CHOICES[$key])) {
            if (!in_array($value, LAYOUT_CHOICES[$key], true)) {
                throw new InvalidArgumentException("$label must be one of " . implode(' ', LAYOUT_CHOICES[$key]) . '.');
            }
            $clean[$key] = $value;
        } elseif (in_array($key, LAYOUT_FLAGS, true)) {
            $clean[$key] = (bool) $value;
        } elseif ($key === 'accent_color' || $key === 'text_color') {
            if (!is_string($value) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                throw new InvalidArgumentException("$label must be a color like #1F3A5F.");
            }
            $clean[$key] = strtoupper($value);
        } else {
            $value = trim((string) $value);
            if (mb_strlen($value) > 60) {
                throw new InvalidArgumentException("$label can be up to 60 characters.");
            }
            $clean[$key] = $value;
        }
    }
    return $clean;
}

/** Measured settings brought into range (a 6 pt résumé becomes 7 pt) instead of refused; unknown choices are dropped. */
function fit_layout(array $values): array
{
    foreach ($values as $key => $value) {
        if (isset(LAYOUT_NUMBERS[$key]) && is_numeric($value)) {
            $values[$key] = max(LAYOUT_NUMBERS[$key][0], min(LAYOUT_NUMBERS[$key][1], (float) $value));
        } elseif (isset(LAYOUT_CHOICES[$key]) && !in_array($value, LAYOUT_CHOICES[$key], true)) {
            unset($values[$key]);
        } elseif (in_array($key, LAYOUT_FONTS, true)) {
            $values[$key] = mb_substr(trim((string) $value), 0, 60);
        } elseif (($key === 'accent_color' || $key === 'text_color') && !preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $value)) {
            unset($values[$key]);
        }
    }
    return $values;
}

/** The defaults with each layer's settings on top (later layers win; empty values are skipped). */
function merge_layout(?array ...$layers): array
{
    $layout = DEFAULT_LAYOUT;
    foreach ($layers as $layer) {
        foreach (clean_layout($layer ?? []) as $key => $value) {
            $layout[$key] = $value;
        }
    }
    return $layout;
}

// --- The saved design (Résumé Design) ---------------------------------------------------------------------------

function design_state(): array
{
    $state = read_json_file(resume_dir('design.json'));
    return ['overrides' => $state['overrides'] ?? [], 'notes' => (string) ($state['notes'] ?? ''), 'portfolio' => $state['portfolio'] ?? null];
}

function write_design_state(array $state): void
{
    ensure_resume_dirs();
    write_json_file(resume_dir('design.json'), $state);
}

function save_design(array $overrides, string $notes): void
{
    $state = design_state();
    $state['overrides'] = $overrides;
    $state['notes'] = $notes;
    write_design_state($state);
}

function reset_design(): void
{
    $state = design_state();
    $state['overrides'] = [];
    write_design_state($state);
}

function design_overrides(): array
{
    return design_state()['overrides'];
}

/** The design new PDFs are drawn with: what was measured from the uploaded résumé, with your changes on top. */
function effective_layout(): array
{
    return merge_layout(current_resume()['layout'] ?? null, design_overrides());
}

// --- References -------------------------------------------------------------------------------------------------

const REFERENCE_FIELDS = ['relationship', 'name', 'job_title', 'company', 'user_job', 'phone', 'email', 'notes'];
const PRINTED_REFERENCE_FIELDS = ['name', 'relationship', 'job_title', 'company', 'user_job', 'phone', 'email'];
const RELATIONSHIPS = ['Supervisor', 'Manager', 'CEO / Owner', 'Co-worker', 'Client', 'Teacher / Professor', 'Other'];

function load_references(): array
{
    $rows = [];
    foreach (read_json_file(resume_dir('references.json')) as $row) {
        $clean = ['id' => (string) ($row['id'] ?? '') ?: bin2hex(random_bytes(6))];
        foreach (REFERENCE_FIELDS as $field) {
            $clean[$field] = (string) ($row[$field] ?? '');
        }
        $rows[] = $clean;
    }
    return $rows;
}

function write_references(array $rows): void
{
    ensure_resume_dirs();
    write_json_file(resume_dir('references.json'), array_values($rows));
}

function clean_reference(array $row): array
{
    $clean = [];
    foreach (REFERENCE_FIELDS as $field) {
        $clean[$field] = mb_substr(trim((string) ($row[$field] ?? '')), 0, 300);
    }
    if ($clean['name'] === '' && $clean['phone'] === '' && $clean['email'] === '') {
        throw new InvalidArgumentException('Enter at least a name, phone or email.');
    }
    return $clean;
}

function add_reference(array $row): array
{
    $reference = ['id' => bin2hex(random_bytes(6))] + clean_reference($row);
    write_references(array_merge(load_references(), [$reference]));
    return $reference;
}

function update_reference(string $id, array $row): ?array
{
    $rows = load_references();
    foreach ($rows as $i => $existing) {
        if ($existing['id'] === $id) {
            $rows[$i] = ['id' => $id] + clean_reference($row);
            write_references($rows);
            return $rows[$i];
        }
    }
    return null;
}

function delete_reference(string $id): bool
{
    $rows = load_references();
    $kept = array_filter($rows, fn($r) => $r['id'] !== $id);
    if (count($kept) === count($rows)) {
        return false;
    }
    write_references($kept);
    return true;
}

// --- Writing rules ------------------------------------------------------------------------------------------------

const RULE_FILES = ['resume' => 'resume-rules.md', 'cover_letter' => 'cover-letter-rules.md'];
const RULE_TITLES = ['resume' => 'Résumé Rules', 'cover_letter' => 'Cover Letter Rules'];
const RULES_MAX_CHARS = 60000;

function load_rules(string $kind): string
{
    $path = resume_dir(RULE_FILES[$kind]);
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function save_rules(string $kind, string $text): void
{
    $text = trim(str_replace("\r\n", "\n", $text));
    if (mb_strlen($text) > RULES_MAX_CHARS) {
        throw new InvalidArgumentException('Rules can be up to ' . number_format(RULES_MAX_CHARS) . ' characters.');
    }
    ensure_resume_dirs();
    write_file_atomic(resume_dir(RULE_FILES[$kind]), $text === '' ? '' : "$text\n");
}

// --- Reference documents ---------------------------------------------------------------------------------------------

const DOCUMENT_TYPES = ['pdf', 'docx', 'txt', 'md'];
const MAX_DOCUMENTS = 40;
const DOCUMENT_MAX_TEXT = 60000; // a long document is cut here so one file can't swamp Claude

function load_documents(): array
{
    return read_json_file(resume_dir('documents/index.json'));
}

function write_documents(array $rows): void
{
    ensure_resume_dirs();
    write_json_file(resume_dir('documents/index.json'), array_values($rows));
}

function check_file_signature(string $data, string $extension): void
{
    if ($extension === 'pdf' && !str_starts_with($data, '%PDF')) {
        throw new InvalidArgumentException('That file does not look like a PDF.');
    }
    if ($extension === 'docx' && !str_starts_with($data, 'PK')) {
        throw new InvalidArgumentException('That file does not look like a Word .docx file.');
    }
}

/** Text of a .txt or .md file, as UTF-8 whatever it was saved as. */
function plain_text(string $data): string
{
    if (str_starts_with($data, "\xEF\xBB\xBF")) {
        $data = substr($data, 3);
    }
    return mb_check_encoding($data, 'UTF-8') ? $data : mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
}

function pdf_page_count(string $data): int
{
    $count = 0;
    foreach (pdf_objects($data) as $object) {
        if (preg_match('#/Type\s*/Page(?!s)\b#', $object['dict'])) {
            $count++;
        }
    }
    return $count;
}

function add_document(string $filename, string $data, string $label = ''): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, DOCUMENT_TYPES, true)) {
        throw new InvalidArgumentException('Upload a PDF, Word .docx, or a .txt / .md text file.');
    }
    if (strlen($data) > RESUME_MAX_UPLOAD) {
        throw new InvalidArgumentException('That file is larger than 10 MB.');
    }
    check_file_signature($data, $extension);
    $rows = load_documents();
    if (count($rows) >= MAX_DOCUMENTS) {
        throw new InvalidArgumentException('You already have ' . MAX_DOCUMENTS . ' documents. Delete one first.');
    }
    $pages = null;
    try {
        if ($extension === 'pdf') {
            $text = pdf_text($data);
            $pages = pdf_page_count($data);
        } elseif ($extension === 'docx') {
            $text = docx_text($data);
        } else {
            $text = plain_text($data);
        }
    } catch (Throwable $error) {
        throw new InvalidArgumentException('Could not read that file: ' . $error->getMessage());
    }
    $text = trim(preg_replace("/[ \t]+\n/", "\n", $text));
    $id = bin2hex(random_bytes(6));
    $folder = resume_dir("documents/$id");
    mkdir($folder, 0775, true);
    file_put_contents("$folder/original.$extension", $data);
    file_put_contents("$folder/text.txt", $text);
    $record = [
        'id' => $id,
        'label' => mb_substr(trim($label), 0, 120) ?: mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 120),
        'original_name' => mb_substr(basename($filename), 0, 200),
        'type' => strtoupper($extension),
        'file' => "original.$extension",
        'pages' => $pages,
        'chars' => mb_strlen($text),
        'uploaded_at' => date('Y-m-d\TH:i:s'),
    ];
    write_documents(array_merge($rows, [$record]));
    return $record;
}

function find_document(string $id): ?array
{
    foreach (load_documents() as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function rename_document(string $id, string $label): bool
{
    $rows = load_documents();
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) {
            $rows[$i]['label'] = mb_substr(trim($label), 0, 120) ?: $row['label'];
            write_documents($rows);
            return true;
        }
    }
    return false;
}

function delete_document(string $id): bool
{
    $rows = load_documents();
    $kept = array_filter($rows, fn($r) => $r['id'] !== $id);
    if (count($kept) === count($rows) || !preg_match('/^[0-9a-f]{12}$/', $id)) {
        return false;
    }
    write_documents($kept);
    remove_tree(resume_dir("documents/$id"));
    return true;
}

function document_text(string $id): string
{
    if (!find_document($id)) {
        return '';
    }
    $path = resume_dir("documents/$id/text.txt");
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function remove_tree(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                remove_tree("$path/$name");
            }
        }
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
}

// --- The uploaded résumé -----------------------------------------------------------------------------------------------

function current_resume(): ?array
{
    $info = read_json_file(resume_dir('current-resume/info.json'), null);
    return is_array($info) ? $info : null;
}

function current_resume_text(): string
{
    $path = resume_dir('current-resume/text.txt');
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function current_resume_file(): ?string
{
    $info = current_resume();
    return $info ? resume_dir('current-resume/' . $info['file']) : null;
}

/** Replace the current résumé with a new upload and measure its layout. Returns the info record. */
function save_resume_upload(string $filename, string $data): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'docx'], true)) {
        throw new InvalidArgumentException('Upload a PDF or a Word .docx file. Older .doc files: open in Word and Save As .docx.');
    }
    if (strlen($data) > RESUME_MAX_UPLOAD) {
        throw new InvalidArgumentException('That file is larger than 10 MB.');
    }
    check_file_signature($data, $extension);
    $notes = [];
    $facts = null;
    $pages = null;
    try {
        if ($extension === 'pdf') {
            $text = pdf_text($data);
            $page = first_pdf_page($data);
            $layout = measure_pdf_layout($page);
            $facts = analyze_pdf_page($page);
            $layout = array_merge($layout, layout_from_facts($facts));
            $pages = $page['pages'];
        } else {
            [$text, $layout] = docx_text_and_layout($data);
            $notes[] = 'The layout was read from the Word file\'s styles. Upload it as a PDF for a closer match.';
        }
    } catch (InvalidArgumentException $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('Could not read résumé: ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
        throw new InvalidArgumentException('Could not read that file. Try another PDF or DOCX.');
    }
    $layout = clean_layout(fit_layout($layout)) + DEFAULT_LAYOUT;
    ensure_resume_dirs();
    $folder = resume_dir('current-resume');
    foreach (glob("$folder/*") ?: [] as $old) {
        unlink($old);
    }
    file_put_contents("$folder/resume.$extension", $data);
    file_put_contents("$folder/text.txt", trim($text));
    $info = [
        'original_name' => mb_substr(basename($filename), 0, 200),
        'file' => "resume.$extension",
        'preview_pdf' => $extension === 'pdf' ? "resume.$extension" : '',
        'uploaded_at' => date('Y-m-d\TH:i:s'),
        'pages' => $pages,
        'layout' => $layout,
        'design' => design_record($facts, $layout),
        'notes' => $notes,
    ];
    write_json_file("$folder/info.json", $info);
    return $info;
}

function design_record(?array $facts, array $layout): array
{
    $rows = describe_design($facts, $layout);
    return ['facts' => $facts, 'rows' => $rows, 'notes' => design_notes_text($rows)];
}

/** Look at the uploaded file again (also fills in the design of a résumé uploaded before this existed). */
function reanalyze_resume_design(): ?array
{
    $info = current_resume();
    if (!$info) {
        return null;
    }
    $facts = null;
    $file = resume_dir('current-resume/' . ($info['preview_pdf'] ?? ''));
    if (!empty($info['preview_pdf']) && is_file($file)) {
        try {
            $facts = analyze_pdf_page(first_pdf_page((string) file_get_contents($file)));
        } catch (Throwable $error) {
            error_log('Could not look at the résumé again: ' . $error->getMessage());
        }
    }
    $info['layout'] = array_merge($info['layout'], clean_layout(fit_layout(layout_from_facts($facts))));
    $info['design'] = design_record($facts, $info['layout']);
    write_json_file(resume_dir('current-resume/info.json'), $info);
    return $info;
}

function current_design(): ?array
{
    $info = current_resume();
    if (!$info) {
        return null;
    }
    if (!isset($info['design'])) {
        $info = reanalyze_resume_design();
    }
    return $info['design'];
}

// --- Finished résumés and cover letters ---------------------------------------------------------------------------------

const BUILD_KINDS = ['resume' => '', 'cover_letter' => 'Cover_Letter'];

function name_part(?string $text): string
{
    return mb_substr(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $text), '_'), 0, 60);
}

/**
 * Firstname_Lastname_Company_JobTitle_MM-DD-YYYY.pdf for a résumé (never the word "ATS"), and
 * Firstname_Lastname_Company_JobTitle_Cover_Letter_MM-DD-YYYY.pdf for a cover letter; _2, _3 ... when taken.
 */
function build_filename(string $first, string $last, string $kind, string $job_title, string $company = '', ?int $when = null): string
{
    $parts = [name_part($first), name_part($last), name_part($company), name_part($job_title) ?: 'General', BUILD_KINDS[$kind],
        date('m-d-Y', $when ?? time())];
    $stem = implode('_', array_filter($parts, 'strlen'));
    $stem = trim(preg_replace('/(?i)(?:^|_)ATS(?=_|$)/', '', $stem), '_');
    $name = "$stem.pdf";
    for ($n = 2; is_file(resume_dir("builds/$name")) || is_file(resume_dir('drafts/' . pathinfo($name, PATHINFO_FILENAME) . '.json')); $n++) {
        $name = "{$stem}_$n.pdf";
    }
    return $name;
}

function split_name(string $full_name, array $profile): array
{
    $first = (string) ($profile['first_name'] ?? '');
    $last = (string) ($profile['last_name'] ?? '');
    if ($first !== '' || $last !== '') {
        return [$first, $last];
    }
    $words = preg_split('/\s+/', trim($full_name), -1, PREG_SPLIT_NO_EMPTY);
    return $words ? [$words[0], implode(' ', array_slice($words, 1))] : ['', ''];
}

function safe_build_name(string $filename): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.-]+\.pdf$/', $filename) && !str_contains($filename, '..');
}

function build_draft_path(string $filename): string
{
    if (!safe_build_name($filename)) {
        throw new InvalidArgumentException('Not a Résumé Builder file name.');
    }
    return resume_dir('drafts/' . pathinfo($filename, PATHINFO_FILENAME) . '.json');
}

function build_pdf_path(string $filename): string
{
    build_draft_path($filename); // checks the name
    return resume_dir("builds/$filename");
}

function texts(array $list, int $limit = 100): array
{
    return array_slice(array_values(array_map(fn($v) => is_scalar($v) ? trim((string) $v) : '', $list)), 0, $limit);
}

/** A résumé as Claude or the editor sent it, checked and with every field present. */
function clean_resume_content(array $content): array
{
    $full_name = trim((string) ($content['full_name'] ?? ''));
    if ($full_name === '') {
        throw new InvalidArgumentException('full_name is required.');
    }
    $sections = [];
    foreach (array_slice((array) ($content['sections'] ?? []), 0, 40) as $section) {
        if (!is_array($section) || !isset($section['title'])) {
            throw new InvalidArgumentException('Each section needs a title.');
        }
        $items = [];
        foreach (array_slice((array) ($section['items'] ?? []), 0, 60) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clean = [];
            foreach (['heading', 'subheading', 'location', 'dates', 'text'] as $field) {
                $clean[$field] = trim((string) ($item[$field] ?? ''));
            }
            $clean['bullets'] = array_values(array_filter(texts((array) ($item['bullets'] ?? [])), 'strlen'));
            $items[] = $clean;
        }
        $sections[] = ['title' => trim((string) $section['title']), 'text' => trim((string) ($section['text'] ?? '')), 'items' => $items];
    }
    $references = [];
    foreach (array_slice((array) ($content['references'] ?? []), 0, 50) as $reference) {
        if (!is_array($reference) || trim((string) ($reference['name'] ?? '')) === '') {
            continue;
        }
        $references[] = array_combine(PRINTED_REFERENCE_FIELDS, array_map(fn($f) => trim((string) ($reference[$f] ?? '')), PRINTED_REFERENCE_FIELDS));
    }
    return ['full_name' => $full_name, 'headline' => trim((string) ($content['headline'] ?? '')),
        'contact' => array_values(array_filter(texts((array) ($content['contact'] ?? [])), 'strlen')),
        'sections' => $sections, 'references' => $references];
}

function clean_letter_content(array $content): array
{
    $full_name = trim((string) ($content['full_name'] ?? ''));
    if ($full_name === '') {
        throw new InvalidArgumentException('full_name is required.');
    }
    if (!isset($content['paragraphs']) || !is_array($content['paragraphs'])) {
        throw new InvalidArgumentException('paragraphs is required.');
    }
    return ['full_name' => $full_name, 'contact' => array_values(array_filter(texts((array) ($content['contact'] ?? [])), 'strlen')),
        'date' => trim((string) ($content['date'] ?? '')),
        'recipient' => array_values(array_filter(texts((array) ($content['recipient'] ?? [])), 'strlen')),
        'greeting' => trim((string) ($content['greeting'] ?? 'Dear Hiring Manager,')),
        'paragraphs' => array_values(array_filter(texts($content['paragraphs'], 40), 'strlen')),
        'closing' => trim((string) ($content['closing'] ?? 'Sincerely,')),
        'signature' => trim((string) ($content['signature'] ?? ''))];
}

/**
 * Draw the PDF and store its draft. $options: job_title, company, job_id, layout, profile, replace (a file name
 * to overwrite). Returns the draft record.
 */
function save_build(string $kind, array $content, array $options = []): array
{
    if (!isset(BUILD_KINDS[$kind])) {
        throw new InvalidArgumentException("Unknown kind: $kind");
    }
    $content = $kind === 'resume' ? clean_resume_content($content) : clean_letter_content($content);
    ensure_resume_dirs();
    $layout = merge_layout(current_resume()['layout'] ?? null, design_overrides(), $options['layout'] ?? null);
    $job_title = (string) ($options['job_title'] ?? '');
    $company = (string) ($options['company'] ?? '');
    if (!empty($options['replace'])) {
        $existing = load_draft($options['replace']);
        $filename = $existing['pdf'];
        $created = $existing['created'] ?? null;
    } else {
        [$first, $last] = split_name($content['full_name'], $options['profile'] ?? []);
        $filename = build_filename($first, $last, $kind, $job_title, $company);
        $created = date('Y-m-d\TH:i:s');
    }
    $pdf = $kind === 'resume' ? render_resume($content, $layout) : render_cover_letter($content, $layout);
    write_file_atomic(build_pdf_path($filename), $pdf);
    $record = ['kind' => $kind, 'pdf' => $filename, 'job_id' => $options['job_id'] ?? null, 'job_title' => $job_title,
        'company' => $company, 'created' => $created, 'updated' => date('Y-m-d\TH:i:s'), 'content' => $content, 'layout' => $layout];
    write_json_file(build_draft_path($filename), $record);
    return $record;
}

function load_draft(string $filename): array
{
    $path = build_draft_path($filename);
    if (!is_file($path)) {
        throw new RuntimeException("No draft for $filename.");
    }
    return read_json_file($path);
}

function list_builds(): array
{
    $records = [];
    foreach (glob(resume_dir('drafts/*.json')) ?: [] as $path) {
        $record = read_json_file($path, null);
        if (!is_array($record) || empty($record['pdf'])) {
            continue;
        }
        $record['pdf_exists'] = is_file(resume_dir('builds/' . $record['pdf']));
        $records[] = $record;
    }
    usort($records, fn($a, $b) => strcmp($b['updated'] ?? '', $a['updated'] ?? ''));
    return $records;
}

/** [job id => [['kind', 'label', 'pdf', 'updated'], ...]] for the Dashboard's job cards: PDFs that still exist, newest first. */
function files_by_job(): array
{
    $found = [];
    foreach (list_builds() as $record) {
        if ($record['pdf_exists'] && is_numeric($record['job_id'] ?? null)) {
            $found[(int) $record['job_id']][] = ['kind' => $record['kind'], 'pdf' => $record['pdf'], 'updated' => $record['updated'] ?? '',
                'label' => $record['kind'] === 'resume' ? 'Résumé' : 'Cover Letter'];
        }
    }
    return $found;
}

function delete_build(string $filename): void
{
    @unlink(build_pdf_path($filename));
    @unlink(build_draft_path($filename));
}
