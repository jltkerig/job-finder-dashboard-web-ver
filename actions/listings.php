<?php
// What the buttons on a listing do: keep, reject, restore, block its domain or company, unsave, update notes and
// status, delete. $path and $route_id come from app.php.

declare(strict_types=1);

switch ($path) {
    case '/save-kept':
        $ids = request_json()['company_ids'] ?? null;
        if (!is_array($ids)) {
            json_out(['status' => 'error', 'message' => 'Invalid company list.'], 400);
        }
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            json_out(['status' => 'error', 'message' => 'Select at least one result to keep.'], 400);
        }
        json_out(['status' => 'saved', 'count' => keep_listings($ids)]);

    case '/reject-listing':
        $reason = (string) (request_json()['reason'] ?? $_POST['reason'] ?? 'other');
        $row = reject_listing($route_id, $reason);
        if (!wants_json()) {
            redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }
        $row ? json_out(['status' => 'rejected', 'company_id' => $route_id, 'domain' => $row['domain']])
            : api_error('E3201', 'The listing could not be found.', 404);

    case '/restore-rejected':
        $row = restore_rejected($route_id);
        if (!wants_json()) {
            redirect('/rejected-listings');
        }
        $row ? json_out(['status' => 'restored', 'company_id' => $route_id, 'domain' => $row['domain'], 'kept' => (bool) $row['pre_reject_kept']])
            : api_error('E3203', 'The rejected listing could not be found.', 404);

    case '/block-domain':
        $row = row('SELECT domain, source_type FROM companies WHERE id = ?', [$route_id]);
        if ($row && in_array($row['source_type'], FEED_NAMES, true)) {
            api_error('E3212', 'This is the feed domain, not the employer domain. Block the company instead.');
        }
        if (!$row || !$row['domain']) {
            api_error('E3210', 'No domain was available to block.', 404);
        }
        block_domain($row['domain']);
        json_out(['status' => 'blocked', 'domain' => $row['domain']]);

    case '/block-company':
        $row = row('SELECT name FROM companies WHERE id = ?', [$route_id]);
        if (!$row || !block_company($row['name'])) {
            api_error('E3220', 'No company name was available to block.', 404);
        }
        json_out(['status' => 'blocked', 'company' => $row['name']]);

    case '/unsave-kept':
        unsave_listing($route_id);
        json_out(['status' => 'unsaved', 'company_id' => $route_id]);

    case '/update-kept':
        update_kept($route_id, trim((string) ($_POST['application_status'] ?? 'None')), trim((string) ($_POST['notes'] ?? '')));
        // The #job anchor reopens this card on the Dashboard, where saved jobs start collapsed.
        redirect(preg_replace('/#.*$/', '', $_SERVER['HTTP_REFERER'] ?? '/dashboard') . "#job-$route_id");

    case '/delete-kept':
        delete_kept($route_id);
        redirect('/dashboard');
}
