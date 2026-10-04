<?php
// Copy this to config.php on the web host and fill in the MySQL details from hPanel > Databases.
// On your own PC you don't need config.php: a local SQLite file in data/ is used instead.
return [
    'timezone' => 'America/New_York',  // for times shown on the site
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
    'login' => [
        'username' => 'admin',
        'password_hash' => '',
    ],
];
