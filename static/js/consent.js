// Cookie consent for Google Analytics. Analytics loads only after "Accept"; the choice is kept in localStorage
// (not a cookie) and "Cookie settings" in the footer brings the banner back. Only included when config.php has a
// 'google_analytics' measurement ID, which arrives as data-ga-id on this script's tag.
(function () {
  const script = document.currentScript;
  const gaId = script && script.dataset.gaId;
  if (!gaId) return;
  const KEY = 'cookie-consent';

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  function remember(choice) {
    try { localStorage.setItem(KEY, choice); } catch (e) { /* private window: ask again next visit */ }
  }

  let loaded = false;
  function loadAnalytics() {
    if (loaded) return;
    loaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', gaId, { anonymize_ip: true });
    const tag = document.createElement('script');
    tag.async = true;
    tag.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gaId);
    document.head.appendChild(tag);
  }

  // Declining after accepting: drop Google's cookies (_ga, _ga_*) and reload so the script is gone.
  function clearAnalyticsCookies() {
    const host = location.hostname.replace(/^www\./, '');
    document.cookie.split(';').forEach(function (part) {
      const name = part.split('=')[0].trim();
      if (name === '_ga' || name.indexOf('_ga_') === 0 || name === '_gid') {
        ['', '; domain=' + host, '; domain=.' + host].forEach(function (domain) {
          document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + domain;
        });
      }
    });
  }

  let banner = null;
  function showBanner() {
    if (banner) { banner.hidden = false; banner.querySelector('button').focus(); return; }
    banner = document.createElement('div');
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Cookie consent');
    banner.innerHTML =
      '<p>This site uses one cookie to keep you signed in. With your OK it also uses Google Analytics cookies to ' +
      'count visits and see which pages are used. Nothing is sold or used for ads.</p>' +
      '<div class="cookie-actions"><button type="button" data-choice="denied">Decline</button>' +
      '<button type="button" data-choice="granted" class="cookie-accept">Accept</button></div>';
    banner.addEventListener('click', function (event) {
      const choice = event.target.dataset && event.target.dataset.choice;
      if (!choice) return;
      const before = stored();
      remember(choice);
      banner.hidden = true;
      if (choice === 'granted') loadAnalytics();
      else if (before === 'granted') { clearAnalyticsCookies(); location.reload(); }
    });
    document.body.appendChild(banner);
  }

  function start() {
    document.querySelectorAll('.cookie-settings').forEach(function (link) {
      link.hidden = false;
      link.addEventListener('click', function (event) { event.preventDefault(); showBanner(); });
    });
    const choice = stored();
    if (choice === 'granted') loadAnalytics();
    else if (choice !== 'denied') showBanner();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
