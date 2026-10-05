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
