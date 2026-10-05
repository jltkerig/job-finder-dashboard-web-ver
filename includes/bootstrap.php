<?php
// Loaded by every request: settings, the database, the session and small helpers.

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_VERSION = '0.8.0';
const DATA_DIR = APP_ROOT . '/resources';     // bundled reference data (O*NET, Census places and ZIPs)

// Choices for a saved job's Application Status, in the order the drop-downs show them.
const APPLICATION_STATUSES = ['None', 'Saved', 'Applied', 'Talking With Recruiter', 'Interview', 'Rejected', 'Closed'];

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = getenv('JOBFINDER_CONFIG') ?: APP_ROOT . '/config.php';
        // No config.php yet (on your own PC): a local SQLite file in data/.
        $config = is_file($file) ? require $file : ['db' => ['driver' => 'sqlite']];
    }
    return $config;
}

/**
 * Where your own data lives: the SQLite file, uploaded résumés, made PDFs and caches. data/ by default;
 * config.php 'data_dir' moves it out of the public web folder.
 */
function data_path(string $sub = ''): string
{
    $root = rtrim((string) (config()['data_dir'] ?? '') ?: APP_ROOT . '/data', '/\\');
    return $sub === '' ? $root : "$root/$sub";
}

define('CACHE_DIR', data_path('cache'));   // quick-loading copies of the bundled data, made on first use

date_default_timezone_set(config()['timezone'] ?? 'America/New_York');

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config()['db'];
    if ($c['driver'] === 'sqlite') {
        $path = getenv('JOBFINDER_DB') ?: ($c['path'] ?? data_path('jobfinder.sqlite'));
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass']);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    require_once __DIR__ . '/schema.php';
    ensure_schema($pdo);
    return $pdo;
}

function is_mysql(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

/** Runs a statement with ? placeholders and returns it. */
function q(string $sql, array $args = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($args));
    return $stmt;
}

function rows(string $sql, array $args = []): array
{
    return q($sql, $args)->fetchAll();
}

function row(string $sql, array $args = []): ?array
{
    $found = q($sql, $args)->fetch();
    return $found === false ? null : $found;
}

function value(string $sql, array $args = [])
{
    $found = q($sql, $args)->fetchColumn();
    return $found === false ? null : $found;
}

/** The time now in UTC as the database stores it ("2026-10-04 19:26:49"). */
function now_utc(int $offset_seconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
}

function setting(string $name, ?string $default = null): ?string
{
    $found = value('SELECT value FROM settings WHERE name = ?', [$name]);
    return $found === null ? $default : (string) $found;
}

function set_setting(string $name, string $value): void
{
    q('DELETE FROM settings WHERE name = ?', [$name]);
    q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
}

function setting_json(string $name, $default = [])
{
    $decoded = json_decode((string) setting($name, ''), true);
    return $decoded === null ? $default : $decoded;
}

function set_setting_json(string $name, $value): void
{
    set_setting($name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function h($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** A value for an HTML attribute holding JSON (like Jinja's tojson|forceescape). */
function json_attr($value): string
{
    return h(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** JSON inside a <script type="application/json"> block. */
function json_script($value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

/** A saved UTC time shown on the local clock, formatted with PHP date() letters. */
function local_time(?string $utc, string $format = 'M d, Y h:i A'): string
{
    if (!$utc) {
        return '';
    }
    $time = strtotime($utc . ' UTC');
    return $time === false ? '' : date($format, $time);
}

/** "3 days", "5 hours", "20 minutes": how long ago a saved UTC time was. */
function how_long(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $seconds = max(0, time() - (int) strtotime($utc . ' UTC'));
    foreach (['week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $size) {
        if ($seconds >= $size) {
            $count = intdiv($seconds, $size);
            return "$count $unit" . ($count === 1 ? '' : 's');
        }
    }
    return 'less than a minute';
}

function plural(int $n, string $one, string $many): string
{
    return $n === 1 ? $one : $many;
}

// --- Requests and responses --------------------------------------------------------------------------------

function request_json(): array
{
    static $data = null;
    if ($data === null) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($data) ? $data : [];
    }
    return $data;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $code, string $message, int $status = 400): void
{
    error_log("[$code] $message");
    json_out(['status' => 'error', 'error_code' => $code, 'message' => $message], $status);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function wants_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
}

// --- Session, sign-in and form protection ------------------------------------------------------------------

/** True when this request came over https (directly, or through the host's proxy). */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// Cloudflare's addresses (https://www.cloudflare.com/ips/, checked 2026-10-04). Only requests from these may say who
// the visitor really is (CF-Connecting-IP); anyone else could fake that header.
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
    '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

function ip_in_range(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bytes = intdiv((int) $bits, 8);
    $rest = (int) $bits % 8;
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
}

function from_cloudflare(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    foreach (CLOUDFLARE_RANGES as $range) {
        if (ip_in_range($ip, $range)) {
            return true;
        }
    }
    return false;
}

/** The visitor's address: Cloudflare's CF-Connecting-IP when the request really came through Cloudflare. */
function client_ip(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) && from_cloudflare()) {
        return $forwarded;
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** True on this PC (php -S from run-local.ps1, opened from this computer). */
function is_local_request(): bool
{
    return PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** The Google Analytics measurement ID from config.php (G-...), or '' when analytics is off. */
function google_analytics_id(): string
{
    $id = trim((string) (config()['google_analytics'] ?? ''));
    return preg_match('/^G-[A-Z0-9]+$/i', $id) ? $id : '';
}

/**
 * Browser rules sent with every page. The Content-Security-Policy lets pages run only this site's own scripts
 * (no inline code, nothing from elsewhere), so text that sneaks into a page can't run as a script. $framing is who
 * may show the page in a frame: this site (the Résumé Builder's PDF preview) or nobody (the Claude approval page).
 */
function send_security_headers(string $framing = "'self'"): void
{
    header_remove('X-Powered-By');
    // Google Analytics (only when config.php has an ID, and it still loads only after the visitor accepts cookies)
    $ga = google_analytics_id() === '' ? ''
        : ' https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com';
    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'self'", "script-src 'self'$ga", "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob:$ga",
        "font-src 'self' data:", "connect-src 'self'$ga", "frame-src 'self'", "object-src 'none'", "base-uri 'self'",
        "form-action 'self' https://claude.ai https://claude.com", "frame-ancestors $framing",
        ...(is_https() ? ['upgrade-insecure-requests'] : []),
    ]));
    header('X-Frame-Options: ' . ($framing === "'none'" ? 'DENY' : 'SAMEORIGIN'));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');  // a private site: search engines and AI crawlers keep out
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');   // never accept a session id the site didn't make
    ini_set('session.use_only_cookies', '1');
    // On https the cookie gets the __Host- prefix: browsers then refuse it unless it's secure, for this host only.
    session_name(is_https() ? '__Host-jobfinder' : 'jobfinder');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => is_https(),
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '" />';
}

/** Every POST must carry the page's token (a form field, or the X-CSRF-Token header from the scripts). */
function check_csrf(): void
{
    start_session();
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    if ($sent === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        if (wants_json()) {
            api_error('E2201', 'Your dashboard session expired. The page will refresh automatically.', 403);
        }
        http_response_code(403);
        exit('This form expired. Go back, reload the page and try again.');
    }
}

/** The password hash: config.php's when filled in, otherwise the one chosen on the first visit (or null). */
function password_hash_in_use(): ?string
{
    $configured = trim((string) (config()['login']['password_hash'] ?? ''));
    return $configured !== '' ? $configured : setting('password_hash');
}

const LOGIN_TRIES = 5;          // wrong passwords from one address before it is locked out
const LOGIN_TRIES_ALL = 30;     // wrong passwords from everyone together before sign-in pauses
const LOGIN_LOCK_SECONDS = 15 * 60;

/**
 * Checks the sign-in username and password (the sign-in page and the connector's approval page both use this).
 * Returns null when right, or the message to show. Five wrong tries from one address lock that address for
 * 15 minutes; 30 from everywhere together pause sign-in for everyone, so a guesser can't keep you locked out alone.
 */
function check_password(string $username, string $password): ?string
{
    if (($locked = login_locked()) !== null) {
        return $locked;
    }
    $hash = (string) password_hash_in_use();
    $username_ok = hash_equals((string) (config()['login']['username'] ?? 'admin'), trim($username));
    if ($hash !== '' && password_verify($password, $hash) && $username_ok) {
        set_setting(login_fails_key(), '0:0');
        return null;
    }
    login_failed();
    return 'That username or password is wrong.';
}

/** Checks the setup code from config.php (first sign-in and password reset); the same lockout as passwords. */
function check_setup_code(string $code): ?string
{
    $setup_code = trim((string) (config()['setup_code'] ?? ''));
    if ($setup_code === '') {
        return "Put a 'setup_code' in config.php first (in hPanel's File Manager), then use it here.";
    }
    if (($locked = login_locked()) !== null) {
        return $locked;
    }
    if (hash_equals($setup_code, trim($code))) {
        return null;
    }
    login_failed();
    return "That setup code doesn't match the one in config.php.";
}

function login_fails_key(): string
{
    return 'login_fails_' . substr(hash('sha256', client_ip()), 0, 16);
}

/** [this address's [count, since], everyone's [count, since]], with counts older than the lock time reset. */
function login_fail_counts(): array
{
    $now = time();
    $counts = [];
    foreach ([login_fails_key() => LOGIN_TRIES, 'login_fails_all' => LOGIN_TRIES_ALL] as $key => $limit) {
        [$count, $since] = array_map('intval', explode(':', (string) setting($key, '0:0')) + [1 => 0]);
        $counts[$key] = $now - $since > LOGIN_LOCK_SECONDS ? [0, $now, $limit] : [$count, $since, $limit];
    }
    return $counts;
}

/** The "too many tries" message while locked, otherwise null. */
function login_locked(): ?string
{
    foreach (login_fail_counts() as [$count, $since, $limit]) {
        if ($count >= $limit) {
            return 'Too many tries. Wait ' . max(1, (int) ceil(($since + LOGIN_LOCK_SECONDS - time()) / 60)) . ' minutes and try again.';
        }
    }
    return null;
}

function login_failed(): void
{
    log_sign_in(false);
    sleep(1);
    foreach (login_fail_counts() as $key => [$count, $since]) {
        set_setting($key, ($count + 1) . ':' . $since);
    }
}

/**
 * Saves a new password: every other signed-in browser is signed out and Claude is disconnected.
 * Returns null when saved, or the message to show.
 */
function change_password(string $password, string $confirm): ?string
{
    if (trim((string) (config()['login']['password_hash'] ?? '')) !== '') {
        return "Your password is set in config.php. Empty 'password_hash' there first, then try again.";
    }
    if (strlen($password) < 10) {
        return 'Use at least 10 characters.';
    }
    if ($password !== $confirm) {
        return "The two passwords don't match.";
    }
    set_setting('password_hash', password_hash($password, PASSWORD_DEFAULT));
    set_setting('signed_out_before', (string) time());
    require_once APP_ROOT . '/includes/connector.php';
    disconnect_all();
    sign_in();
    return null;
}
const SESSION_IDLE_SECONDS = 8 * 3600;       // signed out after 8 hours without using the site
const SESSION_MAX_SECONDS = 30 * 24 * 3600;  // and after 30 days in any case

/** Keeps the last 30 sign-ins and wrong tries (time, address, browser) for Settings > Password. */
function log_sign_in(bool $ok): void
{
    $log = json_decode((string) setting('sign_in_log', '[]'), true) ?: [];
    array_unshift($log, ['at' => date('Y-m-d H:i'), 'ok' => $ok, 'ip' => client_ip(),
        'browser' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
    set_setting('sign_in_log', json_encode(array_slice($log, 0, 30)));
}

function sign_in(): void
{
    log_sign_in(true);
    start_session();
    session_regenerate_id(true);
    $_SESSION['user'] = 'me';
    $_SESSION['since'] = $_SESSION['seen'] = time();
}

function sign_out(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function logged_in(): bool
{
    start_session();
    if (empty($_SESSION['user'])) {
        return false;
    }
    $now = time();
    $since = (int) ($_SESSION['since'] ?? 0);
    if ($now - (int) ($_SESSION['seen'] ?? 0) > SESSION_IDLE_SECONDS || $now - $since > SESSION_MAX_SECONDS
        || $since < (int) setting('signed_out_before', '0')) {  // the password changed since this browser signed in
        sign_out();
        return false;
    }
    $_SESSION['seen'] = $now;
    return true;
}
function require_login(): void
{
    if (logged_in()) {
        return;
    }
    if (wants_json() || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('E2202', 'You were signed out. Reload the page to sign in again.', 403);
    }
    redirect('/login');
}

/** Sends a fragment of the page HTML (templates/<name>.php) with the given variables. */
function render(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . "/templates/$name.php";
    exit;
}
