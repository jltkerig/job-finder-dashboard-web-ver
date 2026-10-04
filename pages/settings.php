<?php
// Settings: rejected listings, search skips, blocked domains and blocked companies. Same markup as the desktop
// Job Finder's templates/rejected-listings.html.

declare(strict_types=1);

$companies = get_rejected_companies();
$skip_query = mb_substr(trim((string) ($_GET['skip_q'] ?? '')), 0, 200);
[$search_skips, $skip_total, $skip_page, $skip_pages] = search_skips_page($skip_query, (int) ($_GET['skip_page'] ?? 1));
$blocked_domains = blocked_domain_list();
$blocked_companies = blocked_company_list();
$domain_info = block_details('domains');
$company_info = block_details('companies');
$company_notice = (string) ($_GET['company_notice'] ?? '');
$domain_notice = (string) ($_GET['domain_notice'] ?? '');

function reason_label(?string $reason): string
{
    return ucwords(str_replace('_', ' ', (string) $reason));
}

function block_when(?array $info): string
{
    $source = $info['source'] ?? 'User';
    $when = !empty($info['blocked_at']) ? str_replace('T', ' ', substr($info['blocked_at'], 0, 16)) : 'Date unavailable';
    return "$source · $when";
}

function skip_link(int $page, string $query): string
{
    return '/rejected-listings?skip_page=' . $page . ($query !== '' ? '&skip_q=' . rawurlencode($query) : '') . '#search-skips';
}

$title = 'Settings';
$page = 'settings';
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <details class="company-section settings-panel rejected-section history-collapse" id="rejected-listings" data-remember="jobFinder.rejectedListingsOpen">
    <summary><h2>Rejected Listings</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <p class="section-intro">Listings you manually reject are kept here for review. Rejecting a listing does not automatically block its domain.
    </p><?php if ($companies): ?>
    <div class="settings-search"><label for="rejected-search">Find a rejected listing</label><input id="rejected-search" type="search" placeholder="Company, job title, or domain" autocomplete="off" />
    </div>
    <div class="saved-job-grid rejected-grid" data-page-size="5"><?php foreach ($companies as $company):
        $id = (int) $company['id'];
        $domain = (string) $company['domain'];
        $domain_url = $domain === '' ? '' : (str_contains($domain, '://') ? $domain : "https://$domain/");
        $place = ($company['city'] ? $company['city'] . ', ' : '');
        $reason = $company['rejection_reason'];
        $is_feed = in_array($company['source_type'], FEED_NAMES, true);
    ?>
      <article class="saved-job-card rejected-card" data-company-id="<?= $id ?>">
        <details class="saved-job-collapse">
          <summary class="saved-job-summary">
            <div class="summary-text">
              <p class="summary-line summary-title"><strong><?= h($company['career_job_title'] ?: 'Unknown Job') ?></strong><span class="summary-company"><?= h($company['name'] ?: 'Unknown Company') ?></span>
              </p>
              <p class="summary-line summary-facts"><span><?= h($place) ?><?= h($company['state'] ?: 'Location unknown') ?></span><?php if ($reason && $reason !== 'other'): ?><span><?= h(reason_label($reason)) ?></span><?php endif; ?><span>Rejected <?= h(local_time($company['rejected_at'], 'M d, Y') ?: '?') ?></span>
              </p>
            </div><span class="summary-chevron" aria-hidden="true"></span>
          </summary>
          <div class="saved-job-body">
            <div class="job-meta"><span><strong>Domain:</strong> <?php if ($domain_url): ?><a href="<?= h($domain_url) ?>" target="_blank" rel="noopener noreferrer"><?= h($domain) ?></a><?php else: ?>Unknown<?php endif; ?></span><span><strong>Location:</strong> <?= h($place) ?><?= h($company['state'] ?: 'Unknown') ?></span><span><strong>Source:</strong> <?= h($company['source_type'] ?: 'Brave Search') ?></span><?php if ($reason && $reason !== 'other'): ?><span><strong>Reason:</strong> <?= h(reason_label($reason)) ?></span><?php endif; ?><span><strong>Rejected:</strong> <?= h(local_time($company['rejected_at']) ?: 'Unknown') ?></span>
            </div>
            <div class="job-links equal-action-group"><?php if ($company['career_url']): ?><a class="bordered-button" href="<?= h($company['career_url']) ?>" target="_blank" rel="noopener noreferrer">View Career Page</a><?php endif; ?><?php if ($company['source_url']): ?><a class="bordered-button" href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer">View Source</a><?php endif; ?><button class="bordered-button details-action" type="button" data-details-target="rejected-details-<?= $id ?>">View Details</button>
            </div>
            <div id="rejected-details-<?= $id ?>" class="card-details" hidden><span><strong>Career credibility:</strong> <a class="credibility-link" href="/credibility-scores" aria-label="How career credibility is calculated"><?= (int) $company['career_credibility'] * 10 ?>%</a></span><span><strong>USA credibility:</strong> <a class="credibility-link" href="/credibility-scores" aria-label="How usa credibility is calculated"><?= (int) $company['usa_credibility'] * 10 ?>%</a></span><span><strong>Work Arrangement:</strong> <?= h($company['work_arrangement'] ?: '—') ?></span><span><strong>Last verified:</strong> <?= h(local_time($company['last_checked']) ?: 'Never') ?></span><?php if ($company['result_updated_at']): ?><span><strong>Updated:</strong> <?= h(local_time($company['result_updated_at'])) ?></span><?php endif; ?>
            </div>
            <div class="card-actions"><button class="bordered-button restore-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name'] ?: 'this listing') ?>">Restore</button><?php if (!$is_feed): ?><button class="bordered-button block-action destructive-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>">Block Company Domain</button><?php endif; ?><button class="bordered-button block-company-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>">Block Company</button>
            </div>
          </div>
        </details>
      </article><?php endforeach; ?>
    </div>
    <nav class="pagination-controls" data-pagination="rejected-grid" aria-label="Rejected listing pages">
    </nav><?php else: ?>
    <div class="empty-dashboard">
      <h3>No Rejected Listings
      </h3>
      <p>Listings you reject will appear here.
      </p><a class="bordered-button button-link" href="/">Go to Search</a>
    </div><?php endif; ?>
  </details>
  <details class="settings-panel history-collapse" id="search-skips" data-remember="jobFinder.searchSkipsOpen"<?= $skip_query !== '' || $skip_page > 1 ? ' data-open-now' : '' ?>>
    <summary><h2>Search Skips</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <p>Pages checked by Job Finder that did not qualify. Clear non-job pages are checked again after a short wait; temporary errors are retried on the next search. A page that later qualifies moves into Search results.
    </p><?php if ($search_skips || $skip_query !== ''): ?>
    <form class="settings-search" method="get" action="/rejected-listings#search-skips"><label for="search-skip-search">Find a skipped page</label><input id="search-skip-search" name="skip_q" type="search" value="<?= h($skip_query) ?>" placeholder="Search title, reason, or any part of the URL" autocomplete="off" /><button class="bordered-button secondary-action" type="submit">Search</button><?php if ($skip_query !== ''): ?> <a class="bordered-button" href="/rejected-listings#search-skips">Clear</a><?php endif; ?>
    </form>
    <ul class="search-skip-list"><?php foreach ($search_skips as $item): ?>
      <li>
        <div><strong><a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($item['title'] ?: 'Untitled page') ?></a></strong>
          <p class="skip-meta"><small>Reason: <?= h($item['reason']) ?> · Last checked: <?= h($item['checked_at'] ? str_replace('T', ' ', substr($item['checked_at'], 0, 16)) : 'Earlier search') ?> UTC · Next check: <?= h($item['next_check']) ?></small><span class="skip-url" title="<?= h($item['url']) ?>"><?= h($item['url']) ?></span>
          </p>
        </div>
      </li><?php endforeach; ?>
    </ul>
    <nav class="pagination-controls" aria-label="Search skip pages"><span><?= $skip_total ?> skipped page<?= $skip_total === 1 ? '' : 's' ?><?php if ($skip_query !== ''): ?> match<?= $skip_total === 1 ? 'es' : '' ?> your search<?php endif; ?></span><?php if ($skip_pages > 1): ?>
      <?php if ($skip_page > 1): ?><a class="bordered-button secondary-action" href="<?= h(skip_link($skip_page - 1, $skip_query)) ?>">Previous</a><?php endif; ?>
      <span>Page <?= $skip_page ?> of <?= $skip_pages ?></span>
      <?php if ($skip_page < $skip_pages): ?><a class="bordered-button secondary-action" href="<?= h(skip_link($skip_page + 1, $skip_query)) ?>">Next</a><?php endif; ?><?php endif; ?>
    </nav><?php else: ?>
    <p>No skipped pages have been recorded yet.
    </p><?php endif; ?>
  </details>
  <details class="settings-panel history-collapse" id="blocked-domains" data-remember="jobFinder.blockedDomainsOpen"<?= $domain_notice !== '' ? ' data-open-now' : '' ?>>
    <summary><h2>Blocked Domains</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <p>Domains on this list are excluded from future searches. Removing a domain allows future results from it; previously rejected listings stay rejected.
    </p><?php if ($domain_notice === 'invalid'): ?>
    <p class="form-error" role="alert">Enter a domain such as example.com.
    </p><?php elseif ($domain_notice === 'add'): ?>
    <p class="settings-notice" role="status">Domain blocked.
    </p><?php elseif ($domain_notice === 'remove'): ?>
    <p class="settings-notice" role="status">Domain unblocked.
    </p><?php endif; ?>
    <form class="blocked-domain-add" action="/settings/blocked-domains" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add" /><label for="new-blocked-domain">Block a domain</label><input id="new-blocked-domain" name="domain" type="text" placeholder="example.com" required /><button class="bordered-button secondary-action" type="submit">Block Domain</button>
    </form><?php if ($blocked_domains): ?>
    <div class="settings-search"><label for="blocked-domain-search">Find a blocked domain</label><input id="blocked-domain-search" type="search" placeholder="Type any part of a domain" autocomplete="off" />
    </div>
    <ul class="blocked-domain-list" data-page-size="10"><?php foreach ($blocked_domains as $domain): ?>
      <li><span class="block-entry"><strong><?= h($domain) ?></strong><small><?= h(block_when($domain_info[$domain] ?? null)) ?></small></span>
        <form action="/settings/blocked-domains" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove" /><input type="hidden" name="domain" value="<?= h($domain) ?>" /><button class="bordered-button secondary-action" type="submit">Unblock</button>
        </form>
      </li><?php endforeach; ?>
    </ul>
    <nav class="pagination-controls" data-pagination="blocked-domain-list" aria-label="Blocked domain pages">
    </nav><?php else: ?>
    <p>No domains are blocked.
    </p><?php endif; ?>
  </details>
  <details class="settings-panel history-collapse" id="blocked-companies" data-remember="jobFinder.blockedCompaniesOpen"<?= $company_notice !== '' ? ' data-open-now' : '' ?>>
    <summary><h2>Blocked Companies</h2><span class="summary-chevron" aria-hidden="true"></span></summary>
    <p>Company names here are skipped in future searches. Blocking a company does not remove listings already found.
    </p><?php if ($company_notice === 'invalid'): ?>
    <p class="form-error" role="alert">Enter a company name.
    </p><?php elseif ($company_notice === 'add'): ?>
    <p class="settings-notice" role="status">Company blocked.
    </p><?php elseif ($company_notice === 'remove'): ?>
    <p class="settings-notice" role="status">Company unblocked.
    </p><?php endif; ?>
    <form class="blocked-domain-add" action="/settings/blocked-companies" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add" /><label for="new-blocked-company">Block a company</label><input id="new-blocked-company" name="company" type="text" placeholder="Company name" required /><button class="bordered-button secondary-action" type="submit">Block Company</button>
    </form><?php if ($blocked_companies): ?>
    <div class="settings-search"><label for="blocked-company-search">Find a blocked company</label><input id="blocked-company-search" type="search" placeholder="Type any part of a name" autocomplete="off" />
    </div>
    <ul class="blocked-company-list blocked-domain-list" data-page-size="10"><?php foreach ($blocked_companies as $name): ?>
      <li><span class="block-entry"><strong><?= h($name) ?></strong><small><?= h(block_when($company_info[$name] ?? null)) ?></small></span>
        <form action="/settings/blocked-companies" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove" /><input type="hidden" name="company" value="<?= h($name) ?>" /><button class="bordered-button secondary-action" type="submit">Unblock</button>
        </form>
      </li><?php endforeach; ?>
    </ul>
    <nav class="pagination-controls" data-pagination="blocked-company-list" aria-label="Blocked company pages">
    </nav><?php else: ?>
    <p>No companies are blocked.
    </p><?php endif; ?>
  </details>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
