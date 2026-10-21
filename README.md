# Job Finder (web)

The web version of Job Finder: the same pages, scripts and search as the desktop app, rebuilt in plain PHP so it
runs on shared hosting such as Hostinger. No Docker, Python or background programs.

## Try it on your PC

    .\run-local.ps1

It opens http://jobfinder.localhost:8080 (browsers send any name ending in .localhost to this PC). Jobs, your
profile and settings are kept in `data/jobfinder.sqlite`, which is never uploaded to GitHub.

## Put it on Hostinger

1. In hPanel, create a MySQL database and user.
2. Upload every file into `public_html` (the site must be at the top of its domain or subdomain).
3. Copy `config.sample.php` to `config.php` and fill in the database details, a long random `setup_code`, a
   `data_dir` outside `public_html` (for example `/home/USER/jobfinder-data`), and your API keys. Make `config.php`
   readable only by you (permissions 600 or 640), and give the MySQL user this one database only.
4. Open the site (it always switches to https), type the setup code and choose your password. Then delete the
   setup code from `config.php`.
5. Turn on backups in hPanel.
6. In hPanel > Advanced > Cron Jobs, run `php /home/USER/public_html/cron.php` every minute. A search moves on
   while the Search page is open; the cron job keeps it going if you close the page.

## How it differs from the desktop app

- **Web search:** Brave Search instead of SearXNG. Each query uses a Brave credit; the Tuning page counts them
  (this month, all time, and an optional testing limit set in `config.php`).
- **Searching in steps:** a web host can't keep a program running, so a search is a list of small tasks worked
  through a few seconds at a time (`includes/search/runner.php`), in the desktop app's order: remote feeds, employers'
  career sites, job sites (National Labor Exchange, plus USAJOBS and Adzuna when their keys are in `config.php`),
  then web results.
- **Places:** the bundled Census places and ZIP codes are used first; only unknown places are looked up online.
- **Résumé Builder:** part of the site (`/resume-builder`), not a separate app. Its files (your uploaded résumé,
  documents, references, rules and the finished PDFs) are kept in `data/resume`, which is never uploaded to GitHub.
  PDFs are drawn in plain PHP (`includes/resume/render.php`), and the uploaded résumé's design is measured the same way
  the desktop measures it with PyMuPDF (`includes/resume/analyze.php`). Claude gets the résumé's text and design notes,
  but not pictures of its pages.
- **Web Job Scraper jobs:** the server can't see your Desktop, so on the Search page open "Import jobs from the Web
  Job Scraper" and choose the extension's `jobs.json` files. They are filtered like the desktop's import, and a
  file already imported unchanged is skipped. Each job's company website is looked up for about 20 seconds after
  the upload; the cron job finishes the rest a few a minute.

## What changed in 1.1.286 - 1.1.300

The web version caught up to the desktop Job Finder's v1.1.300 (it was at 1.1.285):

- **Dashboard:** Applied, Talking With Recruiter and Interview jobs move out of Saved Jobs into their own **Applied
  Jobs** panel. The Search page's filter bar (text, state, work location, listing status, source, credibility, work
  type, sort including Job fit and Job Fit Minus Titles) filters each list in the browser. Saved Jobs, Applied Jobs
  and each Profile section fold from their headings and remember it. A collapsed saved job has a **Delete** button, and
  **Clear closed jobs** deletes every saved job whose posting has closed.
- **Settings and Tuning:** the Search Settings panel is now the first panel on the Settings page (saving returns
  there); Recent Searches moved from the Dashboard to Tuning. The Companies and Tuning pages lost their subtitle.
- **Companies:** one **Search** card with a Filters box; *Employers from your searches* and *My companies* are Source
  pills that combine with the type-of-business pills. My Companies, Add a Company and the results fold.
- **Search:** the Hacker News "Ask HN: Who is hiring?" thread is a remote feed (`hacker_news_*` in
  `includes/search/sources.php`; only remote openings open to U.S. applicants whose title matches); a **Source** filter;
  Job Titles, places, Results, Top 10 Picks and Listings fold; a listing's Source links to the page the job was found
  on; its Notes can be edited and web addresses in them are clickable. iCIMS jobs are checked on their framed page so
  they no longer read as closed, and the closed-job clean-up keeps rejected jobs.
- **Skill Fitting:** a new page ranks open jobs by how many of each listing's skills you have, with Core / Normal /
  Nice to have weights (`includes/skill_fitting.php`). The weights also drive the *Job Fit Minus Titles* sort.
- **Résumé Builder:** *Add common rules* on the résumé and cover letter rules boxes, and greeting suggestions in the
  cover letter editor (`includes/resume/presets.php`).
- **Menu:** Companies sits right of Search; Skill Fitting is last.

Not in the web version: the Apply Queue and what is built on it (adding jobs by link or from saved jobs, Job Link
Résumé Creation, *Rewrite with current rules*, the queue's filters and buttons), the Quit button and the home page
stats feed (desktop-only), the handwriting signature style, typed extra links and Move up / Move down on the desktop
Résumé Builder's own profile editor (the web version edits the profile on the Dashboard), and the Ignore / Avoid terms.

## What changed since 1.1.273

- **1.1.274:** Companies results can be filtered by kind of place with tags.
- **1.1.275:** Brave Search can be switched off on the Tuning page (no credits are used while it is off).
- **1.1.276:** the Open All Details button sits level with the filter boxes.
- **1.1.277:** the Résumé Builder's person cards wrap their dropdowns on a narrow screen.

## What changed in 1.1.281 - 1.1.283

The web version caught up to the desktop Job Finder's v1.1.283 on the Companies page and the footer:

- **Tags filter the results.** Every kind of place is a tag from the start (with a count after a search); picking tags
  narrows the results already found, with no new search. The text filter box is gone.
- **Edit on any company.** A search result or a Saved Company has an Edit button too (to add its job board, say);
  saving puts it in My Companies, and a saved one leaves Saved Companies.
- **Saved Companies** (the Save button on a result) are kept in the settings table.
- **Results stay.** The last search and the hidden results are kept in the browser and on the server, so a refresh or
  clearing the browser keeps them. Changing the town in the box measures the results again from it (no new search).
- **Eight more tags** (Technology and data, Arts and entertainment, Accounting and tax, Print and signs, Engineering and
  architecture, Marketing and media, Churches and nonprofits, Transport and shipping). Every kind is searched, so each tag has
  results; the first search takes about two minutes (one question a second) and the answers are kept 6 hours.
- **Footer** lists the geocoding, address, job feed and career page sources, and the Figma link.

## What changed in 1.1.273

The web version caught up to the desktop Job Finder's v1.1.273 (it was at 1.1.240):

- **Companies page** (`/companies`): businesses, offices, plants and schools near a place (OpenStreetMap, read through
  Nominatim, one question a second, answers kept 6 hours), the employers from your own searches, and **My Companies**
  (kept in the settings table). Add a company with Street, City, State and ZIP boxes (the Street box suggests addresses
  as you type, through Photon), a website, a job board (a link or just a name), a phone number and notes. The
  employers in your work history join the list once each. **Recruiters and staffing** is a kind of place.
  What you save for an employer from your résumé (address, phone, website) is written to that job in your Profile,
  and what the Profile has shows on the page (`includes/companies.php`).
- **Job Apply Stats** on the Dashboard: Applied In Total, each stage, and Applied By Month. Applications made outside
  Job Finder can be listed in the `applied_elsewhere` setting (a JSON list of `{"company", "title", "applied_on"}`).
- **Education from your résumé:** the schools under its education heading are saved when none are saved yet
  (`includes/education.php`), and the upload review lists them too. A profile form that lacks the skills, jobs or
  schools boxes no longer clears them.
- **Search page:** job titles are bubbles under an Add Title box (related titles in blue), Open All Details in the filter
  bar, a tighter panel, and Clear Results / Refreshing tidied. Saved, applied and queued jobs (and copies of them on other
  boards) leave the results. The skipped-pages list moved to Tuning. LinkedIn Easy Apply jobs show a badge.
- **Boards:** the State of Maryland's job openings (JobAps, `includes/search/jobaps.php`: the portal's RSS feed, the
  working title in "CLASS (Working title)", counties turned into their seats so distances work) and Rippling boards.

Not in the web version: the Apply Queue (and so Combine, the queue's Reject button and "saved on LinkedIn" queueing),
parallel board reading and log rotation (the desktop's own process features).

## What changed in 1.1.240

The web version now uses the desktop Job Finder's version number: this release is the desktop's v1.1.240 (it was
0.9.0 before). Keep the two the same: when the web version catches up to a desktop release, set `APP_VERSION` in
`includes/bootstrap.php` to that release's number. This release brings the Dashboard and imports in line with it:

- **Saved jobs:** a cleaner row (title, then company and place, then status chips) with a Listing button, one
  "Job Listing" button when the career page and listing are the same address, and a **Date applied** field. Marking a
  job Applied fills in today's date; you can change it any time. The date is kept in the job's `listing_details`.
- **Filters and delete:** filter by **Applied (any stage)** or **Not applied**; tick saved jobs (or "Select all") and
  delete them together after an "Are you sure?".
- **Top 10 Picks:** jobs you kept are not picked again, and each pick shows the listing's facts.
- **Imports:** a repost of a job you saved or applied to is linked instead of added; a closed job in an upload marks
  the saved job Closed even when filters skip it; a job already imported and unchanged is left alone.
- **Old results:** unsaved results are deleted 14 days after this site first saved them (saved, applied and rejected
  jobs stay).
- **Not on the web version yet:** the Apply Queue (with Combine and Download), because it is built on the desktop's
  local Claude Code runs.

## Connect Claude to the Résumé Builder

Instead of a connector installed on your PC, Claude adds the site by its address:

1. On the Résumé Builder page, under Connect Claude, click **Turn On**. It is off until you do.
2. In Claude, go to Settings > Connectors > Add custom connector, and paste `https://your-site/mcp`.
3. Claude opens the site's approval page; type your Job Finder password to allow it.

Claude signs in with OAuth 2.1 (PKCE, codes sent back only to claude.ai or claude.com, tokens stored as hashes,
access tokens lasting an hour). The page lists every connection with a Disconnect button, and turning the
connector off cuts them all. Claude only connects to https addresses, so this works on Hostinger, not on your PC.
If the site sits behind a proxy that hides https, set `'base_url' => 'https://your-site'` in `config.php`.

## What's where

- `app.php`: every address comes here and goes to a page (`pages/`) or an action (`actions/`).
- `templates/`: the menu, header and footer shared by the pages.
- `static/`: the desktop app's stylesheet and scripts, unchanged.
- `includes/`: database, profile, listings and lookups; `includes/search/` is the search itself; `includes/resume/` is the
  Résumé Builder and `includes/connector.php` its Claude connector (MCP and OAuth).
- `resources/`: O*NET job titles and skills, Census places and ZIP codes, default block lists and watched employers.
- `tests/run.php`: `php -d extension=pdo_sqlite tests/run.php` (uses a throwaway database; no network).

## Sign-in security

- Five wrong passwords from one address lock it for 15 minutes; 30 from everywhere pause sign-in for everyone.
- You're signed out after 8 hours without using the site, and after 30 days in any case.
- Pages can't be shown inside other sites, and every form needs the page's token.
- Forgot your password? Put a `setup_code` in `config.php` (hPanel > File Manager), click "Forgot password?" on the
  sign-in page, type the code and choose a new one. Changing the password (here or in Settings > Password) signs out
  every other browser and disconnects Claude. Delete the setup code afterwards.

## Cloudflare

The site expects to sit behind Cloudflare. It reads the visitor's real address from `CF-Connecting-IP`, but only
when the request comes from one of Cloudflare's own addresses (https://www.cloudflare.com/ips/), so it can't be faked.
In the Cloudflare dashboard:

1. **SSL/TLS > Overview:** set the mode to **Full (strict)**, and keep Hostinger's free certificate on. Don't use
   Flexible: it leaves the trip from Cloudflare to Hostinger unencrypted.
2. **SSL/TLS > Edge Certificates:** turn on Always Use HTTPS.
3. **Security > WAF > Rate limiting rules:** one rule for `URI Path equals /login` with method POST, 5 requests per
   10 seconds per IP, action Block. This stops guessing before it reaches the site.
4. **Bots:** leave Bot Fight Mode off if you use the Claude connector; it can block Claude's servers calling `/mcp`
   and `/oauth/token`, and it can't be skipped with a rule.
5. **Caching:** nothing to do. The site sends `Cache-Control: private, no-store` on every page, so Cloudflare
   keeps only the files in `static/` and `assets/`.
6. Once the domain works through Cloudflare, set `'cloudflare_only' => true` in `config.php`, so nobody can reach
   the site by Hostinger's address and go around Cloudflare.

Cloudflare's IP list changes rarely; if it does, update `CLOUDFLARE_RANGES` in `includes/bootstrap.php`.

## Uploading to Hostinger

GitHub uploads the site over FTPS when started: **Actions > Deploy to Hostinger > Run workflow**
(or `gh workflow run deploy.yml`). The tests run first; after the first upload only changed files are sent.
Repository secrets: `FTP_SERVER` (the FTP IP, not ftp.yourdomain, which goes through Cloudflare), `FTP_USERNAME`,
`FTP_PASSWORD`; variable `FTP_DIR` is the site folder as the FTP account sees it (`/` for an account that opens in
`public_html`). Hostinger's FTP won't create a folder named `lib`, which is why the code lives in `includes/`.
