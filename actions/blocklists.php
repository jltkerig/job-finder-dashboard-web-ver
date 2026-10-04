<?php
// The Settings page forms that add to or remove from the blocked domains and companies.

declare(strict_types=1);

$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['add', 'remove'], true)) {
    http_response_code(400);
    exit('Unknown action.');
}

if ($path === '/settings/blocked-domains') {
    $domain = clean_domain($_POST['domain'] ?? '');
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        redirect('/rejected-listings?domain_notice=invalid#blocked-domains');
    }
    $action === 'add' ? block_domain($domain) : unblock_domain($domain);
    redirect("/rejected-listings?domain_notice=$action#blocked-domains");
}

$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['company'] ?? ''))), 0, 150);
if ($name === '') {
    redirect('/rejected-listings?company_notice=invalid#blocked-companies');
}
$action === 'add' ? block_company($name) : unblock_company($name);
redirect("/rejected-listings?company_notice=$action#blocked-companies");
