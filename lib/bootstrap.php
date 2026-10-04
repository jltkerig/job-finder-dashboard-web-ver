<?php
// Loaded by every request: settings, the database, the session and small helpers.

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_VERSION = '0.5.1';
const DATA_DIR = APP_ROOT . '/resources';     // bundled reference data (O*NET, Census places and ZIPs)
const CACHE_DIR = APP_ROOT . '/data/cache';   // quick-loading copies of that data, made on first use

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

date_default_timezone_set(config()['timezone'] ?? 'America/New_York');

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config()['db'];
    if ($c['driver'] === 'sqlite') {
        $path = getenv('JOBFINDER_DB') ?: ($c['path'] ?? APP_ROOT . '/data/jobfinder.sqlite');
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

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('jobfinder');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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

function logged_in(): bool
{
    start_session();
    return !empty($_SESSION['user']);
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
