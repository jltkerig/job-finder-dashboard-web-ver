<?php
// The end of every page: the message area, the footer and the scripts ($scripts lists extra ones).
?>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true">
</div>
<?= $after_main ?? '' ?>
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-main"><span>© <span id="copyright-year"><?= date('Y') ?></span> Jamie Lee Thomas Kerig · Job Finder v<?= h(APP_VERSION) ?></span>
      <div class="footer-social"><a href="https://jamiekerig.com" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>Portfolio</a><a href="https://github.com/jltkerig" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 19c-4 .8-4-2-6-2m12 4v-3.1a2.7 2.7 0 0 0-.8-2.1c2.7-.3 5.5-1.3 5.5-6A4.7 4.7 0 0 0 18.4 6a4.3 4.3 0 0 0-.1-3s-1 0-3.1 1.6a10.6 10.6 0 0 0-6.4 0C6.7 3 5.7 3 5.7 3a4.3 4.3 0 0 0-.1 3 4.7 4.7 0 0 0-1.3 3.8c0 4.7 2.8 5.7 5.5 6a2.7 2.7 0 0 0-.8 2.1V21"/></svg>GitHub</a><a href="https://www.linkedin.com/in/jamieleedesign/" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M7 10v8m0-11v.1M11 18v-8m0 3c0-4 6-4 6 0v5"/></svg>LinkedIn</a>
      </div>
    </div>
    <div class="footer-tech">Built with PHP, MySQL, HTML, CSS &amp; JavaScript · Web search by <a href="https://brave.com/search/api/" target="_blank" rel="noopener noreferrer">Brave Search</a> · Places from the U.S. Census Bureau Gazetteer · Remote listings: <a href="https://remoteok.com/" target="_blank" rel="noopener noreferrer">Remote OK</a> · <a href="https://remotive.com/" target="_blank" rel="noopener noreferrer">Remotive</a> · <a href="https://weworkremotely.com/" target="_blank" rel="noopener noreferrer">We Work Remotely</a> · Skill and title suggestions include modified information from the <a href="https://www.onetcenter.org/database.html" target="_blank" rel="noopener noreferrer">O*NET 31.0 Database</a> by the U.S. Department of Labor, Employment and Training Administration, used under <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">CC BY 4.0</a>. USDOL/ETA has not approved or tested these modifications.
    </div>
  </div>
</footer>
<script src="/static/js/page.js?v=<?= APP_VERSION ?>"></script>
<script src="/static/js/charts.js?v=<?= APP_VERSION ?>"></script>
<?php foreach ($scripts ?? [] as $script): ?><script src="/static/js/<?= h($script) ?>?v=<?= APP_VERSION ?>"></script>
<?php endforeach; ?>
</body></html>
