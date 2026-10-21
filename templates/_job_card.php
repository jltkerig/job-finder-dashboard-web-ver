<?php
// One saved or applied job card on the Dashboard (the same markup as the desktop app's job_card macro).
// Needs $company and $pick (whether it gets a delete tick box); reads $application_files and $profile from the page.
        $id = (int) $company['id'];
        $listing_status = strtolower($company['job_open_status'] ?: 'Unknown');
        $domain = (string) $company['domain'];
        $domain_url = $domain === '' ? '' : (str_contains($domain, '://') ? $domain : "https://$domain/");
        $is_feed = in_array($company['source_type'], FEED_NAMES, true);
        $place = ($company['city'] ? $company['city'] . ', ' : '');
        $fit = $company['job_fit'];
        $fit_text = $fit['score'] !== null ? $fit['score'] . '%' : '?';
        $status_class = str_replace(' ', '-', strtolower($company['application_status'] ?: 'None'));
        $career_href = ($company['career_url'] && !$is_feed) ? (string) $company['career_url'] : '';
        $source_href = (string) $company['source_url'];
        $same_link = $career_href !== '' && $source_href !== '' && rtrim($career_href, '/') === rtrim($source_href, '/');
        $listing_link = $career_href !== '' ? $career_href : ($source_href !== '' ? $source_href : (string) $company['career_url']);
    ?>
      <article class="saved-job-card" id="job-<?= $id ?>" data-company-id="<?= $id ?>" <?= dash_item_attrs($company) ?>>
        <details class="saved-job-collapse">
          <summary class="saved-job-summary">
            <?php if ($pick): ?><label class="bulk-pick" title="Select to delete"><input type="checkbox" class="bulk-check" value="<?= $id ?>" aria-label="Select <?= h($company['career_job_title'] ?: 'this job') ?>" /></label><?php endif; ?>
            <div class="summary-text">
              <p class="summary-line summary-title"><strong><?= h($company['career_job_title'] ?: 'Unknown Job') ?></strong>
              </p>
              <p class="summary-line summary-facts"><span class="summary-company"><?= h($company['name'] ?: 'Unknown Company') ?></span><span><?= h($place) ?><?= h($company['state'] ?: 'Location unknown') ?></span><?php if ($company['drive']): ?><span title="Estimated from your home ZIP, driving at 6 a.m."><?= h($company['drive']['text']) ?></span><?php elseif ($company['distance_miles'] !== null): ?><span><?= number_format((float) $company['distance_miles']) ?> mi</span><?php endif; ?><?php if ($company['work_arrangement'] && strtolower($company['work_arrangement']) !== strtolower((string) $company['state'])): ?><span><?= h($company['work_arrangement']) ?></span><?php endif; ?>
              </p>
              <p class="summary-line summary-status"><span class="application-indicator status-<?= h($status_class) ?>"><?= h(in_array($company['application_status'], ['Saved', 'None', null], true) ? 'Not applied' : $company['application_status']) ?></span><?php if ($company['applied_on'] !== ''): ?><span class="applied-on" title="Date you applied. Change it under Your Application below.">Applied <?= h($company['applied_label']) ?></span><?php endif; ?><span class="summary-fit" title="Job Fit">Fit <?= $fit_text ?></span><span class="job-open-state"><span class="status-dot <?= $listing_status === 'closed' ? 'closed' : ($listing_status === 'open' ? 'open' : 'unknown') ?>" data-tooltip="<?= $listing_status === 'open' ? 'This listing appears to still be active.' : ($listing_status === 'closed' ? 'This listing appears to no longer be active.' : 'The current listing status could not be confirmed.') ?>"></span><?= h($company['job_open_status'] ?: 'Unknown') ?></span><span>Found <?= h(local_time($company['date_found'], 'M d, Y') ?: '?') ?></span><?php if ($company['notes']): ?><span class="summary-notes" title="<?= h(mb_substr($company['notes'], 0, 200)) ?>">Notes</span><?php endif; ?>
              </p>
            </div>
            <div class="summary-actions"><?php if ($listing_link !== ''): ?><a class="bordered-button summary-listing-link" href="<?= h($listing_link) ?>" target="_blank" rel="noopener noreferrer" title="Open the job listing">Listing</a><?php endif; ?><?php if ($pick): ?><form action="/delete-kept/<?= $id ?>" method="post" class="delete-result-form summary-delete-form" data-confirm="Permanently delete this saved job? This cannot be undone."><?= csrf_field() ?><button class="bordered-button destructive-action summary-delete" type="submit" title="Delete this saved job">Delete</button></form><?php endif; ?></div>
            <span class="summary-chevron" aria-hidden="true"></span>
          </summary>
          <div class="saved-job-body">
            <dl class="job-facts">
              <div><dt>Location</dt><dd><?= h($place) ?><?= h($company['state'] ?: 'Unknown') ?></dd></div>
              <div><dt>Work</dt><dd><?= h($company['work_arrangement'] ?: '—') ?></dd></div>
              <div><dt>From Home</dt><dd><?php if ($company['drive']): ?><span title="Estimated from your home ZIP (<?= h($profile['home_zip']) ?>), driving at 6 a.m."><?= h($company['drive']['text']) ?></span><?php elseif ($company['distance_miles'] !== null): ?><?= number_format((float) $company['distance_miles']) ?> mi<?php else: ?>—<?php endif; ?></dd></div>
              <div><dt>Job Fit</dt><dd><span class="job-fit" tabindex="0" title="<?= h($fit['reason'] . ($fit['matched'] ? ' Matched: ' . implode(', ', $fit['matched']) . '.' : '') . ($fit['missing'] ? ' Other page skills: ' . implode(', ', $fit['missing']) . '.' : '')) ?>"><?= $fit_text ?></span></dd></div>
              <div><dt>Career Credibility</dt><dd><?= credibility_badge($company['career_credibility']) ?></dd></div>
              <div><dt>USA Credibility</dt><dd><?= credibility_badge($company['usa_credibility']) ?></dd></div>
              <div><dt>Source</dt><dd><?php $source_name = $company['source_type'] ?: 'Brave Search'; ?><?php if ($company['source_url']): ?><a href="<?= h($company['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($source_name) ?></a><?php else: ?><?= h($source_name) ?><?php endif; ?></dd></div>
              <div><dt>Last Verified</dt><dd><?= h(local_time($company['last_checked'], 'M d, Y') ?: 'Never') ?></dd></div>
            </dl>
            <div class="job-links"><?php if ($same_link): ?><a class="bordered-button" href="<?= h($career_href) ?>" target="_blank" rel="noopener noreferrer">Job Listing</a><?php else: ?><?php if ($career_href !== ''): ?><a class="bordered-button" href="<?= h($career_href) ?>" target="_blank" rel="noopener noreferrer">Employer Career Page</a><?php endif; ?><?php if ($source_href !== ''): ?><a class="bordered-button" href="<?= h($source_href) ?>" target="_blank" rel="noopener noreferrer"><?= h($is_feed ? 'View on ' . $company['source_type'] : 'Job Listing') ?></a><?php endif; ?><?php endif; ?><button class="bordered-button details-action" type="button" data-details-target="dashboard-details-<?= $id ?>">View Details</button>
            </div>
            <div id="dashboard-details-<?= $id ?>" class="card-details" hidden>
              <div class="detail-section"><strong>Listing Details</strong><span><strong>Domain:</strong> <?php if ($domain_url): ?><a href="<?= h($domain_url) ?>" target="_blank" rel="noopener noreferrer"><?= h($domain) ?></a><?php else: ?>Unknown<?php endif; ?></span><span><strong>Country:</strong> <?= h($company['country'] ?: 'Unknown') ?></span><span><strong>Found:</strong> <?= h(local_time($company['date_found']) ?: 'Unknown') ?></span><?php if ($company['result_updated_at']): ?><span><strong>Updated:</strong> <?= h(local_time($company['result_updated_at'])) ?></span><?php endif; ?><?php if ($company['last_checked']): ?><span><strong>Last verified:</strong> <?= h(local_time($company['last_checked'])) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="card-panels">
              <section class="card-panel application-files">
                <h4>Résumé and Cover Letter</h4>
                <?php $files = $application_files[(int) $id] ?? []; ?>
                <?php if ($files): ?><ul class="file-list"><?php foreach ($files as $file): $name = rawurlencode($file['pdf']); ?><li><span class="file-kind"><?= h($file['label']) ?></span><a href="/resume-builder/files/<?= $name ?>" target="_blank" rel="noopener"><?= h($file['pdf']) ?></a><a class="file-edit" href="/resume-builder/build/<?= $name ?>">Edit</a></li><?php endforeach; ?></ul>
                <?php else: ?><p class="field-help">None yet. Apply copies a request for Claude; what it makes through the Résumé Builder connector shows up here.</p><?php endif; ?>
                <button class="bordered-button apply-action" type="button" data-job-id="<?= $id ?>" data-job-title="<?= h($company['career_job_title']) ?>" data-company-name="<?= h($company['name']) ?>" title="Copy a request for a tailored résumé and cover letter, then open Claude to paste it."><?= $files ? 'Make New Versions' : 'Apply' ?></button>
              </section>
              <section class="card-panel application-section">
                <h4>Your Application</h4>
                <form action="/update-kept/<?= $id ?>" method="post" class="saved-job-editor"><?= csrf_field() ?>
                  <label for="status-<?= $id ?>">Status</label><select id="status-<?= $id ?>" name="application_status"><?php foreach (APPLICATION_STATUSES as $option): ?><option value="<?= h($option) ?>"<?= $company['application_status'] === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select>
                  <label for="applied-on-<?= $id ?>">Date applied</label><input id="applied-on-<?= $id ?>" name="applied_on" type="date" value="<?= h($company['applied_on']) ?>" title="Saved when you mark the job Applied. Change it here any time." />
                  <label for="notes-<?= $id ?>">Notes</label><textarea id="notes-<?= $id ?>" name="notes" rows="4" placeholder="Add notes about this job..."><?= h($company['notes']) ?></textarea>
                  <button class="bordered-button primary-action" type="submit">Save Changes</button>
                </form>
              </section>
            </div>
            <div class="card-footer-actions" aria-label="Remove or block"><?php if (!$is_feed): ?><button class="text-action block-action destructive-action" type="button" data-company-id="<?= $id ?>" data-domain="<?= h($domain) ?>" title="Hide this website and all future jobs from it.">Block Domain</button><?php endif; ?><button class="text-action block-company-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name']) ?>" title="Exclude this company name from future searches.">Block Company</button><button class="text-action reject-action destructive-action" type="button" data-company-id="<?= $id ?>" data-company-name="<?= h($company['name'] ?: 'this listing') ?>" title="Hide this job, but allow other jobs from this company.">Reject</button>
              <form action="/delete-kept/<?= $id ?>" method="post" class="delete-result-form" data-confirm="Permanently delete this saved job? This cannot be undone."><?= csrf_field() ?><button class="text-action danger-action destructive-action" type="submit">Delete</button></form>
            </div>
          </div>
        </details>
      </article>
