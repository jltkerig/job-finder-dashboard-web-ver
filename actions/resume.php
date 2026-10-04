<?php
// Reads an uploaded résumé (PDF or Word) and suggests profile entries from it. Nothing is saved: the Dashboard
// shows the suggestions for you to review, and the file itself is never kept.

declare(strict_types=1);
require_once APP_ROOT . '/lib/documents.php';

$upload = $_FILES['resume'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($upload['name'] ?? '') === '') {
    api_error('E3310', 'Choose a PDF or Word document.');
}
$extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['pdf', 'docx'], true)) {
    api_error('E3311', 'Use a PDF or DOCX résumé.');
}
if (($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
    api_error('E3312', 'The résumé must be under 5 MB.');
}
$data = (string) file_get_contents($upload['tmp_name']);
try {
    $text = $extension === 'pdf' ? pdf_text($data) : docx_text($data);
} catch (Throwable $error) {
    error_log('Could not parse résumé: ' . $error->getMessage());
    api_error('E3313', 'Could not read that résumé. Try another PDF or DOCX.');
}
if (trim($text) === '') {
    api_error('E3314', 'No selectable text was found. A scanned image résumé needs OCR.');
}
json_out(['status' => 'ok', 'suggestions' => resume_suggestions(mb_substr($text, 0, 250000))]);
