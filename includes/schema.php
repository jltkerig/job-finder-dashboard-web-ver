<?php
// Creates the tables the first time the site runs (SQLite on your PC, MySQL on the host). The columns match the
// desktop Job Finder's, so the pages and scripts carried over from it work the same way. Times are stored as UTC
// text ("2026-10-04 19:26:49").

declare(strict_types=1);

const SCHEMA_VERSION = '6';

// Optional details some applications ask for: where the job was, its phone and website, and the supervisor.
const WORK_DETAIL_COLUMNS = ['street' => 200, 'city' => 100, 'state' => 50, 'zip' => 20, 'phone' => 40, 'website' => 255,
    'supervisor_name' => 150, 'supervisor_title' => 150, 'supervisor_email' => 255, 'supervisor_phone' => 40];
// Education: the degree is one of these (the drop-down's choices), and each field's maximum length.
const EDUCATION_DEGREES = ["High School Diploma", "GED", "Certificate", "Associate's Degree", "Bachelor's Degree",
    "Master's Degree", "Doctorate", "Professional Degree", "Some College (No Degree)", "Other"];
const EDUCATION_FIELDS = ['school' => 200, 'degree' => 60, 'major' => 150, 'minor' => 150, 'start_date' => 20,
    'end_date' => 20, 'gpa' => 10];

function ensure_schema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(64) PRIMARY KEY, value $text NOT NULL)$tail");
    $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'");
    if ($stmt && $stmt->fetchColumn() === SCHEMA_VERSION) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id $id,
        source_key VARCHAR(64) NOT NULL,
        name VARCHAR(255) NULL,
        career_job_title VARCHAR(255) NULL,
        career_credibility INT NULL,
        domain VARCHAR(255) NULL,
        career_url TEXT NULL,
        source_url TEXT NULL,
        source_type VARCHAR(50) NOT NULL DEFAULT 'Brave Search',
        country VARCHAR(100) NULL,
        state VARCHAR(100) NULL,
        city VARCHAR(150) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        distance_miles DECIMAL(8,2) NULL,
        work_arrangement VARCHAR(20) NULL,
        listing_skills TEXT NULL,
        listing_details $text NULL,
        usa_credibility INT NULL,
        date_found VARCHAR(19) NOT NULL,
        last_checked VARCHAR(19) NULL,
        result_updated_at VARCHAR(19) NULL,
        is_kept TINYINT NOT NULL DEFAULT 0,
        job_open_status VARCHAR(20) NOT NULL DEFAULT 'Open',
        application_status VARCHAR(30) NOT NULL DEFAULT 'None',
        notes TEXT NULL,
        is_rejected TINYINT NOT NULL DEFAULT 0,
        rejection_reason VARCHAR(40) NULL,
        rejected_at VARCHAR(19) NULL,
        rejected_by VARCHAR(10) NULL,
        pre_reject_kept TINYINT NULL,
        pre_reject_status VARCHAR(30) NULL,
        closed_at VARCHAR(19) NULL,
        UNIQUE (source_key)
    )$tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_history (
        id $id,
        job_title TEXT NOT NULL,
        state VARCHAR(100) NOT NULL,
        cities_json TEXT NULL,
        searched_at VARCHAR(19) NOT NULL
    )$tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile (
        id TINYINT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        state VARCHAR(100) NOT NULL DEFAULT '',
        home_location VARCHAR(150) NOT NULL DEFAULT '',
        home_zip VARCHAR(10) NOT NULL DEFAULT '',
        linkedin_url VARCHAR(255) NOT NULL DEFAULT '',
        portfolio_url VARCHAR(255) NOT NULL DEFAULT '',
        primary_job_title VARCHAR(255) NOT NULL DEFAULT '',
        avatar_data $text NULL,
        work_preferences TEXT NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile_job_titles (
        id $id, profile_id TINYINT NOT NULL, job_title VARCHAR(255) NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile_cities (
        id $id, profile_id TINYINT NOT NULL, city VARCHAR(150) NOT NULL, radius_miles INT NOT NULL DEFAULT 50)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile_skills (
        id $id, profile_id TINYINT NOT NULL, skill VARCHAR(80) NOT NULL)$tail");
    $details = '';
    foreach (WORK_DETAIL_COLUMNS as $column => $size) {
        $details .= ", $column VARCHAR($size) NOT NULL DEFAULT ''";
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile_work_history (
        id $id, profile_id TINYINT NOT NULL,
        company VARCHAR(150) NOT NULL DEFAULT '', role VARCHAR(150) NOT NULL DEFAULT '',
        dates VARCHAR(100) NOT NULL DEFAULT '', description TEXT NULL$details)$tail");
    $education = '';
    foreach (EDUCATION_FIELDS as $column => $size) {
        $education .= ", $column VARCHAR($size) NOT NULL DEFAULT ''";
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile_education (id $id, profile_id TINYINT NOT NULL$education)$tail");
    if (!$pdo->query('SELECT 1 FROM user_profile WHERE id = 1')->fetchColumn()) {
        $pdo->exec("INSERT INTO user_profile (id) VALUES (1)");
    }

    // Block lists: what you blocked, where it came from (you, or the recommended list) and when.
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_domains (
        domain VARCHAR(253) PRIMARY KEY, source VARCHAR(20) NOT NULL DEFAULT 'User', blocked_at VARCHAR(25) NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_companies (
        name_key VARCHAR(150) PRIMARY KEY, name VARCHAR(150) NOT NULL, source VARCHAR(20) NOT NULL DEFAULT 'User',
        blocked_at VARCHAR(25) NULL)$tail");

    // Pages a search checked that did not qualify, with the latest decision for each.
    $pdo->exec("CREATE TABLE IF NOT EXISTS search_skips (
        url_key VARCHAR(64) PRIMARY KEY, url TEXT NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '',
        reason VARCHAR(80) NOT NULL, checked_at VARCHAR(25) NOT NULL)$tail");

    // A search's to-do list while it runs, worked through a slice at a time (see includes/search/runner.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS search_tasks (
        id $id, run_id VARCHAR(32) NOT NULL, kind VARCHAR(30) NOT NULL, payload $text NOT NULL,
        priority INT NOT NULL DEFAULT 0, done TINYINT NOT NULL DEFAULT 0)$tail");

    // Pages fetched recently, so a search or refresh doesn't download the same page twice in a day.
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_cache (
        url_key VARCHAR(64) PRIMARY KEY, url TEXT NOT NULL, status INT NOT NULL, body $text NULL,
        final_url TEXT NULL, fetched_at VARCHAR(19) NOT NULL)$tail");

    // The Résumé Builder connector (includes/connector.php): apps Claude registered, one-time sign-in codes, and access
    // tokens. Codes and tokens are kept only as SHA-256 hashes, so a copy of the database can't be used to sign in.
    $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_clients (
        client_id VARCHAR(64) PRIMARY KEY, client_name VARCHAR(200) NOT NULL DEFAULT '', redirect_uris TEXT NOT NULL,
        created_at VARCHAR(19) NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_codes (
        code_hash VARCHAR(64) PRIMARY KEY, client_id VARCHAR(64) NOT NULL, redirect_uri TEXT NOT NULL,
        code_challenge VARCHAR(128) NOT NULL, resource TEXT NULL, expires_at VARCHAR(19) NOT NULL)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_tokens (
        token_hash VARCHAR(64) PRIMARY KEY, kind VARCHAR(10) NOT NULL, client_id VARCHAR(64) NOT NULL,
        grant_id VARCHAR(32) NOT NULL, expires_at VARCHAR(19) NOT NULL, created_at VARCHAR(19) NOT NULL,
        last_used_at VARCHAR(19) NULL)$tail");

    seed_defaults($pdo);
    $pdo->exec("DELETE FROM settings WHERE name = 'schema_version'");
    $pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?)")->execute([SCHEMA_VERSION]);
}

/** The first time: the recommended blocked domains (job aggregators and the like), as the desktop app ships them. */
function seed_defaults(PDO $pdo): void
{
    if ($pdo->query('SELECT COUNT(*) FROM blocked_domains')->fetchColumn() > 0) {
        return;
    }
    $file = APP_ROOT . '/resources/recommended_domains.txt';
    if (!is_file($file)) {
        return;
    }
    $pdo->exec("INSERT INTO blocked_companies (name_key, name, source) VALUES ('bark', 'Bark', 'Recommended')");
    $insert = $pdo->prepare("INSERT INTO blocked_domains (domain, source, blocked_at) VALUES (?, 'Recommended', NULL)");
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = strtolower(trim($line));
        if ($line !== '' && $line[0] !== '#') {
            $insert->execute([$line]);
        }
    }
}
