<?php
// Copy this to config.php on the web host and fill in the MySQL details from hPanel > Databases.
// On your own PC you don't need config.php: a local SQLite file in data/ is used instead.
return [
    'timezone' => 'America/New_York',  // for times shown on the site
    'base_url' => '',  // optional: the site's https address, if the Claude connector shows http:// instead
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'name' => 'u000000000_jobfinder',
        'user' => 'u000000000_jobfinder',
        'pass' => 'your-database-password',
    ],
    'brave' => ['key' => '', 'monthly' => 0, 'limit' => 0],  // Brave Search API key; monthly = your plan's searches a month; limit = total allowed while testing (0 = none)
    // Sign-in. Make the hash with: php -r "echo password_hash('your password', PASSWORD_DEFAULT);"
    // USAJOBS (free key: developer.usajobs.gov) and Adzuna (free keys: developer.adzuna.com). Leave blank to skip them.
    'usajobs' => ['key' => '', 'email' => ''],
    'adzuna' => ['app_id' => '', 'app_key' => ''],
    // First sign-in on the web: type this code to choose your password (anything long and random; delete it after).
    // Or skip it by putting a password hash below (php -r "echo password_hash('your password', PASSWORD_DEFAULT);").
    'setup_code' => '',
    // Your own data (database file, résumés, made PDFs). Best outside the public web folder on the host,
    // for example '/home/you/jobfinder-data'. Empty = the data/ folder here, closed to the web by data/.htaccess.
    'data_dir' => '',
    // Sends http:// visits to https://. Turn off only if the site has no certificate yet.
    'force_https' => true,
    // On Cloudflare: true turns away visits that go straight to the host instead of through Cloudflare.
    // Turn it on once the domain works through Cloudflare.
    'cloudflare_only' => false,
    'google_analytics' => '',  // optional: a GA4 measurement ID (G-XXXXXXXXXX); visitors see a cookie banner and analytics loads only if they accept
    'login' => [
        'username' => 'admin',
        'password_hash' => '',
    ],
];
