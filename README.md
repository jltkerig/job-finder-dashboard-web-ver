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
3. Copy `config.sample.php` to `config.php` and fill in the database details, your sign-in, and your API keys.
4. Open the site and sign in.
5. In hPanel > Advanced > Cron Jobs, run `php /home/USER/public_html/cron.php` every minute. A search moves on
   while the Search page is open; the cron job keeps it going if you close the page.

## How it differs from the desktop app

- **Web search:** Brave Search instead of SearXNG. Each query uses a Brave credit; the Tuning page counts them
  (this month, all time, and an optional testing limit set in `config.php`).
- **Searching in steps:** a web host can't keep a program running, so a search is a list of small tasks worked
  through a few seconds at a time (`lib/search/runner.php`), in the desktop app's order: remote feeds, employers'
  career sites, job sites (National Labor Exchange, plus USAJOBS and Adzuna when their keys are in `config.php`),
  then web results.
- **Places:** the bundled Census places and ZIP codes are used first; only unknown places are looked up online.
- **Résumé Builder:** part of the site (`/resume-builder`), not a separate app. Its files (your uploaded résumé,
  documents, references, rules and the finished PDFs) are kept in `data/resume`, which is never uploaded to GitHub.
  PDFs are drawn in plain PHP (`lib/resume/render.php`), and the uploaded résumé's design is measured the same way
  the desktop measures it with PyMuPDF (`lib/resume/analyze.php`). Claude gets the résumé's text and design notes,
  but not pictures of its pages.
- **Not here yet:** importing jobs from the Web Job Scraper extension.

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
- `lib/`: database, profile, listings and lookups; `lib/search/` is the search itself; `lib/resume/` is the
  Résumé Builder and `lib/connector.php` its Claude connector (MCP and OAuth).
- `resources/`: O*NET job titles and skills, Census places and ZIP codes, default block lists and watched employers.
- `tests/run.php`: `php -d extension=pdo_sqlite tests/run.php` (uses a throwaway database; no network).
